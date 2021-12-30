<?php

declare(strict_types=1);

namespace PKP\OSF;

require __DIR__ . '/../vendor/autoload.php';

use GetOpt\GetOpt;
use GetOpt\Option;
use SplFileObject;

Logger::handleWarnings();

$cli = new GetOpt([
    Option::create('t', 'token', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('OSF API token'),
    Option::create('p', 'provider', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Provider, the institution ID/name from where the preprints will be imported (e.g. "engrxiv")'),
    Option::create('u', 'user', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Import username (if not provided, the first author ID will be used instead'),
    Option::create('c', 'context', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('OPS context'),
    Option::create('o', 'output', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Output folder, files will be generated using the format "OSF-ID.xml"'),
    Option::create('b', 'baseUrl', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Base URL that will be used to issue redirects'),
    Option::create('d', 'requireDoi')
        ->setDescription('Skips preprints without DOIs'),
    Option::create('l', 'locale', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Locale (default "en_US")')
        ->setDefaultValue('en_US'),
    Option::create('e', 'email', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Defines the email pattern to use when creating OSF authors/users, the %s will be replaced by the OSF user ID')
        ->setDefaultValue('osf-%s@ops.publicknowlegeproject.org'),
    Option::create('i', 'includeOsfId')
        ->setDescription('Defines whether to include the OSF preprint ID into the Publisher ID field of OPS'),
    Option::create('a', 'saveSupplementaryFiles')
        ->setDescription('Defines whether to save the supplementary file (if not defined, just a remote galley will be created)'),
    Option::create('n', 'embedSubmissions')
        ->setDescription('Defines whether submissions should be embedded in the XML or saved into external files (faster)'),
    Option::create('m', 'memory', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Memory limit (default "1G")')
        ->setDefaultValue('1G'),
    Option::create('s', 'sleep', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Amount of seconds the script will rest after processing each preprint (default 3 seconds)')
        ->setDefaultValue(3),
    Option::create('r', 'maxRetry', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Amount of retries before skipping an article (default 5)')
        ->setDefaultValue(5),
    Option::create('q', 'quiet')
        ->setDescription('Execute quietly (without status display)'),
    Option::create('h', 'help')
        ->setDescription('Display usage information')
], [GetOpt::SETTING_STRICT_OPERANDS => true]);

try {
    $cli->process();
    if ($cli['help']) {
        echo $cli->getHelpText();
        exit(0);
    }

    $settings = Settings::createFromOptions($cli);
    Logger::$verbose = !$settings->quiet;
    Logger::log('Setting up memory limit');

    ini_set('memory_limit', $settings->memory);
    Logger::log('Creating output folder');

    $output = $settings->output;
    $xmlOutput = $output . '/submissions/';
    $redirectOutput = $output . '/redirects.sql';
    $assignmentsOutput = $output . '/assignments.sql';
    $downloadsOutput = $output . '/downloads.sql';
    $usersOutput = $output . '/users.sql';
    $publicationRelationOutput = $output . '/publication-relation.sql';
    $importOutput = $output . '/import.sh';
    if (!is_dir($xmlOutput)) {
        mkdir($xmlOutput, $settings->defaultPermission, true);
    }

    Logger::log('Creating HTTP client');
    $client = ClientFactory::create($settings->token);

    Logger::log('Retrieving data');
    $preprints = json_decode((string) $client->get('?embed=license&filter[provider]=' . urlencode($settings->provider))->getBody(), false);
    $total = $preprints->meta->total ?? $preprints->links->meta->total;
    $preprints = PageIterator::createFromJson($client, $preprints);
    foreach ($preprints as $index => $preprint) {
        $attempts = abs($settings->maxRetry) + 1;
        ++$index;
        Logger::log("Processing preprint ${index}/${total}: " . $preprint->id, true);
        $folderPath = $xmlOutput . preg_replace('/\W/', '-', $preprint->id);
        if (!is_dir($folderPath)) {
            mkdir($folderPath, $settings->defaultPermission);
        }
        $filename = "${folderPath}/submission.xml";
        if (file_exists($filename)) {
            continue;
        }
        if ($settings->requireDoi && !$preprint->links->preprint_doi) {
            continue;
        }
        while ($attempts--) {
            try {
                $template = new Template($preprint, $settings, $client);
                $root = $template->process();
                file_put_contents($filename, $root->asXML());
                foreach (Generator::users($root, $settings) as $statement) {
                    file_put_contents($usersOutput, $statement . "\n", FILE_APPEND);
                }
                file_put_contents($assignmentsOutput, Generator::linkUsers($preprint) . "\n", FILE_APPEND);
                file_put_contents($publicationRelationOutput, Generator::publicationRelation($preprint) . "\n", FILE_APPEND);
                foreach (Generator::downloadStatistics($preprint, $template) as $statement) {
                    file_put_contents($downloadsOutput, $statement . "\n", FILE_APPEND);
                }
                if ($settings->baseUrl) {
                    file_put_contents($redirectOutput, Generator::redirection($preprint, $settings->baseUrl) . "\n", FILE_APPEND);
                }
                file_put_contents($importOutput, Generator::importCommand($preprint, $root, $settings) . "\n", FILE_APPEND);
                break;
            } catch (\Exception $e) {
                if (!$attempts) {
                    Logger::log("Failed to process the preprint ${index}/${total}: " . $preprint->id);
                    Logger::log((string) $e);
                    continue 2;
                }
            }
        }
        sleep($settings->sleep);
    }
    file_put_contents($redirectOutput, "SELECT ''", FILE_APPEND);

    $file = new SplFileObject($output . '/import-reversed.sh', 'w');
    foreach (array_reverse(file($importOutput)) as $line) {
        $file->fwrite($line);
    }
    $file = null;

    Logger::log("\nFinished");
} catch (\Exception $exception) {
    Logger::log('Error: ' . $exception->getMessage());
    Logger::log($cli->getHelpText());
    exit(-1);
}
