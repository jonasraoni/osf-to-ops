<?php

declare(strict_types=1);

namespace PKP\OSF;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use SimpleXMLElement;
use SplFileInfo;

/**
 * An API walker, at the end of the process a XML string will be available for streaming
 */
class Template
{
    private object $preprint;

    private Settings $settings;

    private Client $client;

    private ?array $submissionFiles = null;

    private ?array $supplementaryFiles = null;

    /**
     * Construct
     */
    public function __construct(object $preprint, Settings $settings, Client $client)
    {
        $this->preprint = $preprint;
        $this->settings = $settings;
        $this->client = $client;
    }

    public function process(): SimpleXMLElement
    {
        $rootNode = $this->processPreprint();
        $this->processSubmissionFiles($rootNode);
        $this->processPublication($rootNode);
        $this->ensureSubmissionUploader($rootNode);
        return $rootNode;
    }

    private function processPreprint(): SimpleXMLElement
    {
        $node = new SimpleXMLElement(
            '<?xml version="1.0"?>
            <preprint xmlns="http://pkp.sfu.ca" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://pkp.sfu.ca native.xsd">
            </preprint>'
        );
        $node['date_submitted'] = $this->toDate($this->preprint->attributes->date_created);
        $node['status'] = $this->getStatus();
        $node['submission_progress'] = 0;
        $node['current_publication_id'] = 1;
        $node['stage'] = 'production';

        $this->addIdentifier($node, Identifier::INTERNAL, 1);
        if ($this->settings->includeOsfId) {
            $this->addIdentifier($node, Identifier::PUBLIC, $this->preprint->id);
        }
        return $node;
    }

    public function getSupplementaryFiles(): array
    {
        if ($url = $this->preprint->relationships->node->links->related->href ?? null) {
            $response = json_decode((string) $this->client->get($url)->getBody(), false);
            $url = $response->data->relationships->files->links->related->href ?? null;
        }
        return $this->supplementaryFiles ??= $this->getFiles($url);
    }

    public function getSubmissionFiles(): array
    {
        return $this->submissionFiles ??= $this->getFiles($this->preprint->relationships->files->links->related->href ?? null);
    }

    public function getFiles(?string $url): array
    {
        if (!$url) {
            return [];
        }
        $files = [];
        $folders = PageIterator::create($this->client, $url);
        foreach ($folders as $folder) {
            if (!($url = $folder->relationships->files->links->related->href ?? null)) {
                continue;
            }
            $files = array_merge($files, iterator_to_array(PageIterator::create($this->client, $url)));
        }
        return $files;
    }

    public function getAllFiles(): array
    {
        return [...$this->getSubmissionFiles(), ...$this->getSupplementaryFiles()];
    }

    private function processSubmissionFiles(SimpleXMLElement $parentNode): void
    {
        foreach ($this->getAllFiles() as $position => $file) {
            ++$position;
            $data = $file->attributes;

            if (!$data->size) {
                throw new Exception('Invalid submission, file size is zero');
            }

            $node = $this->addNamespaced($parentNode, 'submission_file');
            $node['id'] = $position;
            $node['created_at'] = $this->toDate($data->date_created);
            $node['date_created'] = null;
            $node['file_id'] = $position;
            $node['stage'] = DefaultValues::STAGE;
            $node['updated_at'] = $this->toDate($data->date_modified);
            $node['viewable'] = 'false';
            $node['genre'] = DefaultValues::GENRE;
            $node['uploader'] = $this->settings->user;
            $node['language'] = $this->settings->locale;

            $node->name = $data->name;
            $node->file['id'] = $position;
            $node->file['filesize'] = $data->size;
            $node->file['extension'] = (new SplFileInfo($data->name))->getExtension();

            $node->file->embed = base64_encode((string) $this->client->get($file->links->download)->getBody());
            $node->file->embed['encoding'] = 'base64';
        }
    }

    private function processPublication(SimpleXMLElement $parentNode): SimpleXMLElement
    {
        $preprint = $this->preprint->attributes;
        $node = $this->addNamespaced($parentNode, 'publication');
        $node['locale'] = $this->settings->locale;
        $node['version'] = 1;
        $node['status'] = $this->getStatus() === State::DECLINED ? State::QUEUED : $this->getStatus();
        $node['url_path'] = '';
        $node['seq'] = 0;
        $node['access_status'] = 0;
        $node['section_ref'] = DefaultValues::GENRE_ABBREVIATION;
        if ($publishedDate = $this->toDate($preprint->original_publication_date ?? $preprint->date_published)) {
            $node['date_published'] = $publishedDate;
        }

        if ($this->preprint->relationships->contributors->links ?? null) {
            $node['primary_contact_id'] = 1;
        }

        $this->addIdentifier($node, Identifier::INTERNAL, 1);
        if ($this->settings->includeOsfId) {
            $this->addIdentifier($node, Identifier::PUBLIC, $this->preprint->id);
        }

        if ($doi = $this->preprint->links->preprint_doi) {
            $this->addIdentifier($node, Identifier::DOI, $doi);
        }

        $this->addLocalized($node, 'title', $preprint->title);
        $this->addLocalized($node, 'abstract', $preprint->description);

        $license = $this->preprint->embeds->license->data;
        if ($rights = $license->attributes->name ?? null) {
            $text = $license->attributes->text ?? '';
            $this->addLocalized($node, 'rights', $text ? "${rights}: ${text}" : $rights);
        }

        if ($licenseUrl = $license->attributes->url ?? null) {
            $node->licenseUrl = $licenseUrl;
        }

        $items = $this->sanitizeList($preprint->license_record->copyright_holders ?? []);
        if (count($items)) {
            $this->addLocalized($node, 'copyrightHolder', implode('; ', $items));
        }

        if (preg_match('/\d{4}/', $preprint->license_record->year ?? '', $match)) {
            $node->copyrightYear = $match[0];
        }

        $this->processKeywords($node);
        $this->processDisciplines($node);
        $this->processAuthors($node);
        $this->processGalleys($node);

        return $node;
    }

    private function ensureSubmissionUploader(SimpleXMLElement $root): void
    {
        if ($this->settings->user || !isset($root->publication->authors->author->email)) {
            return;
        }
        $authorId = strtok((string) $root->publication->authors->author->email, '@');
        foreach ($root->submission_file as $submissionFile) {
            $submissionFile['uploader'] = $authorId;
        }
    }

    private function processKeywords(SimpleXMLElement $parentNode): void
    {
        if (!count($items = $this->sanitizeList($this->preprint->attributes->tags ?? []))) {
            return;
        }
        $keywordsNode = $this->addLocalized($parentNode, 'keywords', null);
        foreach ($items as $keyword) {
            $keywordsNode->keyword[] = $keyword;
        }
    }

    private function processDisciplines(SimpleXMLElement $parentNode): void
    {
        if (($url = $this->preprint->relationships->subjects->links->related->href ?? null)) {
            $subjects = PageIterator::create($this->client, $url);
        } elseif (is_array($subjects = $this->preprint->attributes->subjects)) {
            $subjects = reset($subjects);
        } else {
            return;
        }

        $disciplinesNode = $this->addLocalized($parentNode, 'disciplines', null);
        foreach ($subjects as $subject) {
            $disciplinesNode->discipline[] = $subject->text ?? $subject->attributes->text;
        }
    }

    private function processAuthors(SimpleXMLElement $parentNode): void
    {
        if (!($url = $this->preprint->relationships->contributors->links->related->href ?? null)) {
            return;
        }

        $authorsNode = $this->addNamespaced($parentNode, 'authors');
        $authors = PageIterator::create($this->client, $url);
        foreach ($authors as $position => $author) {
            ++$position;
            $authorNode = $authorsNode->addChild('author');
            $authorNode['include_in_browse'] = 'true';
            $authorNode['primary_contact'] = (int) ($position < 2);
            $authorNode['user_group_ref'] = DefaultValues::USER_GROUP;
            $authorNode['seq'] = $author->attributes->index;
            $authorNode['id'] = $author->attributes->index;

            $data = $author->embeds->users->data ?? null;
            $metadata = $data->attributes ?? $author->embeds->users->errors[0]->meta;
            $name = $metadata->given_name . ($metadata->middle_names ? ' ' . $metadata->middle_names : '');
            $this->addLocalized($authorNode, 'givenname', $name);
            $this->addLocalized($authorNode, 'familyname', $metadata->family_name);

            if ($url = $data->relationships->institutions->links->related->href ?? null) {
                $institutions = PageIterator::create($this->client, $url);
                $list = [];
                foreach ($institutions as $institution) {
                    $list[] = $institution->attributes->name;
                }

                if (count($list)) {
                    $this->addLocalized($authorNode, 'affiliation', implode('; ', $list));
                }
            }

            $authorId = $data->id ?? preg_replace('/^\w+-/', '', $author->id);
            $authorNode->email = sprintf($this->settings->email, $authorId);
            foreach ($data->attributes->social ?? [] as $type => $value) {
                if ($type === 'orcid') {
                    $authorNode->orcid = "https://orcid.org/${value}";
                    break;
                }
            }
        }
    }

    private function processGalleys(SimpleXMLElement $parentNode): void
    {
        $galleys = [];
        $submissionPosition = 0;
        foreach ($this->getSubmissionFiles() as $submissionPosition => $file) {
            $galleys[] = ['label' => strtoupper((new SplFileInfo($file->attributes->name))->getExtension()), 'isRemote' => false, 'data' => ++$submissionPosition];
        }

        foreach ($this->getSupplementaryFiles() as $i => $file) {
            $galleys[] = ['label' => 'Supplementary Material (' . strtoupper((new SplFileInfo($file->attributes->name))->getExtension()) . ')', 'isRemote' => false, 'data' => $submissionPosition + $i + 1];
        }

        foreach ($this->preprint->attributes->data_links ?? [] as $link) {
            $galleys[] = ['label' => 'Data', 'isRemote' => true, 'data' => $link];
        }

        foreach ($this->preprint->attributes->prereg_links ?? [] as $link) {
            $galleys[] = ['label' => 'Preregistration', 'isRemote' => true, 'data' => $link];
        }

        foreach ($galleys as $position => ['label' => $label, 'isRemote' => $isRemote, 'data' => $data]) {
            ++$position;
            $galleyNode = $this->addNamespaced($parentNode, 'preprint_galley');
            $galleyNode['locale'] = $this->settings->locale;
            $galleyNode['url_path'] = '';
            $galleyNode['approved'] = 'false';

            $this->addIdentifier($galleyNode, Identifier::INTERNAL, $position);
            if ($position < 2 && ($doi = $this->preprint->attributes->doi)) {
                $this->addIdentifier($galleyNode, Identifier::DOI, $doi);
            }
            $this->addLocalized($galleyNode, 'name', $label);
            $galleyNode->seq = $position;

            if ($isRemote) {
                $galleyNode->remote['src'] = $data;
            } else {
                $galleyNode->submission_file_ref['id'] = $data;
            }
        }
    }

    private function addLocalized(SimpleXMLElement $node, string $name, $value): SimpleXMLElement
    {
        $node = $node->addChild($name, $value);
        $node['locale'] = $this->settings->locale;
        return $node;
    }

    private function addNamespaced(SimpleXMLElement $node, string $name): SimpleXMLElement
    {
        $node = $node->addChild($name);
        $node['xmlns:xsi'] = 'http://www.w3.org/2001/XMLSchema-instance';
        $node['xsi:schemaLocation'] = 'http://pkp.sfu.ca native.xsd';
        return $node;
    }

    private function addIdentifier(SimpleXMLElement $node, string $type, $value): SimpleXMLElement
    {
        $node = $node->addChild('id', $type === Identifier::DOI ? str_replace('https://doi.org/', '', $value) : (string) $value);
        $node['type'] = $type;
        $node['advice'] = $type === Identifier::INTERNAL ? Advice::IGNORE : Advice::UPDATE;
        return $node;
    }

    private function getStatus(): int
    {
        switch ($this->preprint->attributes->reviews_state) {
            case OsfState::INITIAL:
                return State::QUEUED;
            case OsfState::ACCEPTED:
                return State::PUBLISHED;
            case OsfState::WITHDRAWN:
                return State::DECLINED;
            default:
                throw new Exception('Unknown review state');
        }
    }

    private function sanitizeList(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            foreach (explode(';', $item) as $value) {
                if ($value = trim($value)) {
                    $out[] = $value;
                }
            }
        }
        return $out;
    }

    private static function toDate(string $date): string
    {
        return (new DateTime($date))->format('Y-m-d');
    }
}
