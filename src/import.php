<?php

declare(strict_types=1);

namespace PKP\OSF;

require __DIR__ . '/../vendor/autoload.php';

use Exception;
use GetOpt\GetOpt;
use GetOpt\Option;

Logger::handleWarnings();

$cli = new GetOpt([
    Option::create('t', 'token', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('OSF API token'),
    Option::create('p', 'provider', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Provider, the institution ID/name from where the preprints will be imported (e.g. "engrxiv")'),
    Option::create('u', 'user', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Import username'),
    Option::create('o', 'output', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Output folder, files will be generated using the format "OSF-ID.xml"'),
    Option::create('l', 'locale', GetOpt::REQUIRED_ARGUMENT)
        ->setDescription('Locale (default "en_US")')
        ->setDefaultValue('en_US'),
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

    foreach (['token', 'provider', 'user', 'output', 'locale', 'memory', 'sleep'] as $param) {
        if (!$cli[$param]) {
            throw new Exception("Argument $param is required");
        }
    }

    Logger::$verbose = !$cli['quiet'];
    Logger::log('Setting up memory limit');
    ini_set('memory_limit', $cli['memory']);
    Logger::log('Creating output folder');
    if (!is_dir($cli['output'])) {
        mkdir($cli['output'], 0600, true);
    }

    Logger::log('Creating HTTP client');
    $client = ClientFactory::create($cli['token']);

    Logger::log('Retrieving data');
    $preprints = json_decode((string) $client->get('?embed=license&filter[provider]=' . urlencode($cli['provider']))->getBody(), false);
    $total = $preprints->meta->total ?? $preprints->links->meta->total;
    $preprints = PageIterator::createFromJson($client, $preprints);
    $settings = new Settings($cli['user'], $cli['locale']);
    foreach ($preprints as $index => $preprint) {
        $attempts = abs($cli['maxRetry']) + 1;
        ++$index;
        Logger::log("Processing preprint ${index}/${total}: " . $preprint->id, true);
        $filename = $cli['output'] . '/' . preg_replace('/\W/', '-', $preprint->id) . '.xml';
        if (file_exists($filename)) {
            continue;
        }
        while ($attempts--) {
            try {
                $template = new Template($preprint, $settings, $client);
                file_put_contents($filename, $template->process()->asXML());
                break;
            } catch (\Exception $e) {
                if (!$attempts) {
                    Logger::log("Failed to process the preprint ${index}/${total}: " . $preprint->id);
                    Logger::log((string) $e);
                    continue 2;
                }
            }
        }
        sleep($cli['sleep']);
    }
    Logger::log('Finished');
} catch (\Exception $exception) {
    Logger::log('Error: ' . $exception->getMessage());
    Logger::log($cli->getHelpText());
    exit(-1);
}
