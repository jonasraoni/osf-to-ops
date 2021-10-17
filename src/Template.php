<?php

declare(strict_types=1);

namespace PKP\OSF;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use SimpleXMLElement;
use SplFileInfo;

class Template
{
    /** @var object */
    private $preprint;

    /** @var Settings */
    private $settings;

    /** @var Client */
    private $client;

    /** @var array */
    private $files;

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
        $this->addIdentifier($node, Identifier::PUBLIC, $this->preprint->id);
        return $node;
    }


    private function getFiles(): array
    {
        if ($this->files !== null) {
            return $this->files ?? [];
        }
        if (!($url = $this->preprint->relationships->files->links->related->href ?? null)) {
            return $this->files = [];
        }
        $this->files = [];
        $folders = PageIterator::create($this->client, $url);
        foreach ($folders as $folder) {
            if (!($url = $folder->relationships->files->links->related->href ?? null)) {
                continue;
            }
            $this->files = array_merge($this->files, iterator_to_array(PageIterator::create($this->client, $url)));
        }
        return $this->files;
    }

    private function processSubmissionFiles(SimpleXMLElement $parentNode): void
    {
        foreach ($this->getFiles() as $position => $file) {
            ++$position;
            $data = $file->attributes;
            $node = $this->addNamespaced($parentNode, 'submission_file');
            $node['id'] = $position;
            $node['created_at'] = $this->toDate($data->date_created);
            $node['date_created'] = null;
            $node['file_id'] = $position;
            $node['stage'] = DefaultValues::STAGE;
            $node['updated_at'] = $this->toDate($data->date_modified);
            $node['viewable'] = 'false';
            $node['genre'] = DefaultValues::GENRE;
            $node['uploader'] = $this->settings->username;
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
        if ($preprint->doi) {
            $this->addIdentifier($node, Identifier::DOI, $preprint->doi);
        }

        $this->addLocalized($node, 'title', $preprint->title);
        $this->addLocalized($node, 'abstract', $preprint->description);
        if ($rights = $preprint->embeds->license->data->attributes->name ?? null) {
            $this->addLocalized($node, 'rights', $rights);
        }

        $items = $this->sanitizeList($preprint->license_record->copyright_holders ?? []);
        if (count($items)) {
            $this->addLocalized($node, 'copyrightHolder', implode('; ', $items));
        }

        if ($preprint->license_record->year ?? null) {
            $node->copyrightYear = $preprint->license_record->year;
        }

        $this->processKeywords($node);
        $this->processSubjects($node);
        $this->processAuthors($node);
        $this->processGalleys($node);

        return $node;
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

    private function processSubjects(SimpleXMLElement $parentNode): void
    {
        if (!($url = $this->preprint->relationships->subjects->links->related->href ?? null)) {
            return;
        }

        $subjectsNode = $this->addLocalized($parentNode, 'subjects', null);
        $subjects = PageIterator::create($this->client, $url);
        foreach ($subjects as $subject) {
            $subjectsNode->subject[] = $subject->attributes->text;
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
            $authorNode['primary_contact'] = $position < 2;
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

            $authorNode->email = ($data->id ?? preg_replace('/^\w+-/', '', $author->id)) . '@engrxiv.publicknowlegeproject.org';
            foreach ($data->attributes->social ?? [] as $type => $value) {
                if ($type === 'orcid') {
                    $authorNode->orcid = $value;
                    break;
                }
            }
        }
    }

    private function processGalleys(SimpleXMLElement $parentNode): void
    {
        $galleys = [];
        foreach ($this->getFiles() as $position => $file) {
            ++$position;
            $galleys[] = ['label' => strtoupper((new SplFileInfo($file->attributes->name))->getExtension()), 'isRemote' => false, 'data' => $position];
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
            if ($position < 2 && ($doi = $this->preprint->links->preprint_doi)) {
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
        $node->$name = $value;
        $node = $node->$name;
        $node['locale'] = $this->settings->locale;
        return $node;
    }

    private function addNamespaced(SimpleXMLElement $node, string $name): SimpleXMLElement
    {
        $node->$name = null;
        $node = $node->$name;
        $node['xmlns:xsi'] = 'http://www.w3.org/2001/XMLSchema-instance';
        $node['xsi:schemaLocation'] = 'http://pkp.sfu.ca native.xsd';
        return $node;
    }

    private function addIdentifier(SimpleXMLElement $node, string $type, $value): SimpleXMLElement
    {
        $node->id[] = $type === Identifier::DOI ? str_replace('https://doi.org/', '', $value) : $value;
        $node = $node->id[count($node->id) - 1];
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
