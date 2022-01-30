<?php

declare(strict_types=1);

namespace PKP\OSF;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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

    private ?array $authors = null;

    private ?array $subjects = null;

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
            '<?xml version="1.0" encoding="utf-8"?>
            <preprint xmlns="http://pkp.sfu.ca" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://pkp.sfu.ca native.xsd">
            </preprint>'
        );
        $node['date_submitted'] = $this->toDate($this->preprint->attributes->date_created);
        $node['status'] = $this->getStatus();
        $node['submission_progress'] = 0;
        $node['current_publication_id'] = $this->getVersionCount();
        $node['stage'] = 'production';

        $this->addIdentifier($node, Identifier::INTERNAL, 1);
        if ($this->settings->includeOsfId) {
            $this->addIdentifier($node, Identifier::PUBLIC, $this->preprint->id);
        }
        return $node;
    }

    public function getSupplementaryLink(): ?string
    {
        if ($url = $this->preprint->relationships->node->links->related->href ?? null) {
            try {
                $response = json_decode((string) $this->client->get($url)->getBody(), false);
                $url = $response->data->links->html ?? null;
            } catch (ClientException $e) {
                if (in_array($e->getResponse()->getStatusCode(), [403, 410])) {
                    return null;
                }
                throw $e;
            }
        }
        return $url;
    }

    public function getSupplementaryFiles(): array
    {
        if ($url = $this->preprint->relationships->node->links->related->href ?? null) {
            try {
                $response = json_decode((string) $this->client->get($url)->getBody(), false);
                $url = $response->data->relationships->files->links->related->href ?? null;
            } catch (ClientException $e) {
                if (in_array($e->getResponse()->getStatusCode(), [403, 410])) {
                    return $this->supplementaryFiles = [];
                }
                throw $e;
            }
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
            $folderFiles = array_map(function ($file): array {
                $versions = [];
                foreach (PageIterator::create($this->client, $file->relationships->versions->links->related->href) as $version) {
                    if (!$version->attributes->size) {
                        Logger::log("Skipped empty submission file revision for the file \"{$file->attributes->name}\" at the preprint \"{$this->preprint->id}\"");
                        continue;
                    }
                    $version->attributes->downloads = $file->attributes->extra->downloads ?? 0;
                    array_unshift($versions, $version);
                }
                if (!count($versions)) {
                    Logger::log("Skipped file \"{$file->attributes->name}\" due to invalid file revisions at the preprint \"{$this->preprint->id}\"");
                }
                return $versions;
            }, iterator_to_array(PageIterator::create($this->client, $url)));
            array_push($files, ...array_filter($folderFiles, fn ($versions) => count($versions)));
        }
        return $files;
    }

    public function getAllFiles(): array
    {
        return [...$this->getSubmissionFiles(), ...($this->settings->saveSupplementaryFiles ? $this->getSupplementaryFiles() : [])];
    }

    private function processSubmissionFiles(SimpleXMLElement $parentNode): void
    {
        if (!count($files = $this->getAllFiles())) {
            Logger::log('The preprint "' . $this->preprint->id . '" has no submission file');
        }
        $position = 0;
        array_walk_recursive($files, function (object $file) use (&$position, $parentNode) {
            // Save a "local ID" in order to make it easier to retrieve it when populating the galleys
            $file->localId = ++$position;
            $data = $file->attributes;
            $extension = (new SplFileInfo($data->name))->getExtension();
            $node = $this->addNamespaced($parentNode, 'submission_file');
            $node['id'] = $position;
            $node['date_created'] = $this->toDate($data->date_created);
            $node['file_id'] = $position;
            $node['stage'] = DefaultValues::STAGE;
            $node['viewable'] = 'false';
            $node['genre'] = DefaultValues::GENRE;
            $node['uploader'] = $this->settings->user;
            $node['language'] = $this->settings->locale;
            $node->name = $data->name;
            $node->file['id'] = $position;
            $node->file['filesize'] = $data->size;
            $node->file['extension'] = (new SplFileInfo($data->name))->getExtension();
            if ($this->settings->embedSubmissions) {
                $node->file->embed = base64_encode((string) $this->client->get($file->links->download)->getBody());
                $node->file->embed['encoding'] = 'base64';
            } else {
                $filename = "{$file->localId}.${extension}";
                $outputPath = "{$this->settings->output}/submissions/{$this->preprint->id}/${filename}";
                $folderPath = dirname($outputPath);
                if (!is_dir($folderPath)) {
                    mkdir($folderPath, $this->settings->defaultPermission, true);
                }

                if (!file_exists($outputPath)) {
                    $this->client->get($file->links->download, ['sink' => $outputPath]);
                }

                $node->file->href['src'] = $filename;
            }
        });
    }

    private function processPublication(SimpleXMLElement $parentNode): void
    {
        $versions = $this->getVersionCount();
        $preprint = $this->preprint->attributes;
        for ($version = 0; ++$version <= $versions;) {
            $node = $this->addNamespaced($parentNode, 'publication');
            $node['locale'] = $this->settings->locale;
            $node['version'] = $version;
            $node['status'] = $this->getStatus() === State::DECLINED ? State::QUEUED : $this->getStatus();
            $node['url_path'] = '';
            $node['seq'] = 0;
            $node['access_status'] = 0;
            $node['section_ref'] = DefaultValues::GENRE_ABBREVIATION;
            $preprintPublishedDate = $preprint->date_published;
            $submissionsPublishDate = $this->getPublishDateAtVersion($version);
            $publishedDate = $version === $versions
                ? $preprintPublishedDate ?? $submissionsPublishDate
                : $submissionsPublishDate ?? $preprintPublishedDate;
            $node['date_published'] = $this->toDate($publishedDate);

            if ($authorsCount = count($this->getAuthors())) {
                $node['primary_contact_id'] = $authorsCount * ($version - 1) + 1;
            }

            $this->addIdentifier($node, Identifier::INTERNAL, $version);
            if ($this->settings->includeOsfId) {
                $this->addIdentifier($node, Identifier::PUBLIC, $this->preprint->id);
            }

            if ($doi = $this->preprint->links->preprint_doi) {
                $this->addIdentifier($node, Identifier::DOI, $doi);
            }

            $this->addLocalized($node, 'title', $preprint->title);
            $this->addLocalized($node, 'abstract', $preprint->description);

            $copyrightHolders = implode('; ', $this->sanitizeList($preprint->license_record->copyright_holders ?? []));
            $copyrightYear = preg_match('/\d{4}/', $preprint->license_record->year ?? '', $match) ? $match[0] : null;

            $license = $this->preprint->embeds->license->data ?? null;
            if ($rights = $license->attributes->name ?? null) {
                $text = str_replace(['{{year}}', '{{copyrightHolders}}'], [$copyrightYear, $copyrightHolders], $license->attributes->text ?? '');
                $this->addLocalized($node, 'rights', $text ? "${rights}: ${text}" : $rights);
            }

            if ($licenseUrl = $license->attributes->url ?? null) {
                $node->licenseUrl = $licenseUrl;
            }

            if ($copyrightHolders) {
                $this->addLocalized($node, 'copyrightHolder', $copyrightHolders);
            }

            if ($copyrightYear) {
                $node->copyrightYear = $copyrightYear;
            }

            $this->processKeywords($node);
            $this->processDisciplines($node);
            $this->processAuthors($node, $version);
            $this->processGalleys($node, $version);
        }
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
        if (!$this->subjects) {
            $this->subjects = [];
            if (($url = $this->preprint->relationships->subjects->links->related->href ?? null)) {
                $this->subjects = iterator_to_array(PageIterator::create($this->client, $url));
            } elseif (is_array($subjects = $this->preprint->attributes->subjects)) {
                $this->subjects = reset($subjects);
            }
        }

        if (!count($this->subjects)) {
            return;
        }

        $disciplinesNode = $this->addLocalized($parentNode, 'disciplines', null);
        foreach ($this->subjects as $subject) {
            $disciplinesNode->discipline[] = $subject->text ?? $subject->attributes->text;
        }
    }

    private function getAuthors(): array
    {
        return $this->authors ??= (function () {
            if (!($url = $this->preprint->relationships->bibliographic_contributors->links->related->href ?? null)) {
                return [];
            }
            $authors = iterator_to_array(PageIterator::create($this->client, $url));
            foreach ($authors as $author) {
                $data = $author->embeds->users->data ?? null;
                $author->institutions = ($url = $data->relationships->institutions->links->related->href ?? null)
                    ? iterator_to_array(PageIterator::create($this->client, $url))
                    : [];
            }
            return $authors;
        })();
    }

    private function processAuthors(SimpleXMLElement $parentNode, int $version): void
    {
        $authors = $this->getAuthors();
        if (!count($authors)) {
            return;
        }

        $authorsNode = $this->addNamespaced($parentNode, 'authors');
        $position = count($authors) * ($version - 1);
        foreach ($authors as $author) {
            ++$position;
            $authorNode = $authorsNode->addChild('author');
            $authorNode['include_in_browse'] = 'true';
            $authorNode['primary_contact'] = (int) ($author === reset($authors));
            $authorNode['user_group_ref'] = DefaultValues::USER_GROUP;
            $authorNode['seq'] = $author->attributes->index;
            $authorNode['id'] = $position;

            $data = $author->embeds->users->data ?? null;
            $metadata = $data->attributes ?? $author->embeds->users->errors[0]->meta;
            $name = $metadata->given_name . ($metadata->middle_names ? ' ' . $metadata->middle_names : '');
            $this->addLocalized($authorNode, 'givenname', $name);
            $this->addLocalized($authorNode, 'familyname', $metadata->family_name);

            $list = array_map(fn ($institution) => $institution->attributes->name, $author->institutions);
            if (count($list)) {
                $this->addLocalized($authorNode, 'affiliation', implode('; ', $list));
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

    private function processGalleys(SimpleXMLElement $parentNode, int $version): void
    {
        $galleys = [];
        $versionIndex = $version - 1;
        foreach ($this->getSubmissionFiles() as $versions) {
            $file = $versions[$versionIndex] ?? end($versions);
            $galleys[] = ['label' => strtoupper((new SplFileInfo($file->attributes->name))->getExtension()), 'isRemote' => false, 'data' => $file->localId];
        }

        if ($this->settings->saveSupplementaryFiles) {
            foreach ($this->getSupplementaryFiles() as $versions) {
                $file = $versions[$versionIndex] ?? end($versions);
                $galleys[] = ['label' => 'Supplementary Material', 'isRemote' => false, 'data' => $file->localId];
            }
        } elseif ($link = $this->getSupplementaryLink()) {
            $galleys[] = ['label' => 'Supplementary Material', 'isRemote' => true, 'data' => $link];
        }

        foreach ($this->preprint->attributes->data_links ?? [] as $link) {
            $galleys[] = ['label' => 'Data', 'isRemote' => true, 'data' => $link];
        }

        foreach ($this->preprint->attributes->prereg_links ?? [] as $link) {
            $galleys[] = ['label' => 'Preregistration', 'isRemote' => true, 'data' => $link];
        }

        $position = count($galleys) * $versionIndex;
        foreach ($galleys as $index => ['label' => $label, 'isRemote' => $isRemote, 'data' => $data]) {
            ++$position;
            $galleyNode = $this->addNamespaced($parentNode, 'preprint_galley');
            $galleyNode['locale'] = $this->settings->locale;
            $galleyNode['url_path'] = '';
            $galleyNode['approved'] = 'false';

            $this->addIdentifier($galleyNode, Identifier::INTERNAL, $position);
            $this->addLocalized($galleyNode, 'name', $label);
            $galleyNode->seq = $index;

            if ($isRemote) {
                $galleyNode->remote['src'] = $data;
            } else {
                $galleyNode->submission_file_ref['id'] = $data;
            }
        }
    }

    private function getPublishDateAtVersion(int $version): ?string
    {
        return ($max = array_reduce($this->getAllFiles(), fn ($max, $versions) => max($max, ($date = $versions[$version - 1]->attributes->date_created ?? null) ? strtotime($date) : 0), 0))
            ? date('Y-m-d', $max)
            : null;
    }

    private function getVersionCount(): int
    {
        return array_reduce($this->getAllFiles(), fn ($max, $files) => max($max, count($files)), 1);
    }

    private function addLocalized(SimpleXMLElement $node, string $name, $value): SimpleXMLElement
    {
        $node = $node->addChild($name, (string) $value);
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
