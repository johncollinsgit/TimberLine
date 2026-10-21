<?php

declare(strict_types=1);

// The input is the reviewed v3 offline export from source a0e3119c. Build from
// that source/archive; never fetch a remote page or include runtime credentials.
$input = $argv[1] ?? '';
$html = $input !== '' && is_file($input) ? file_get_contents($input) : false;
if ($html === false || hash('sha256', $html) !== '2c9fd4bf642688097a80f771069f54448143ebd59a33eeacb985b641fa0c295e') {
    throw new RuntimeException('Provide the exact reviewed v3 offline HTML export.');
}

$html = str_replace([
    'Lees-McRae · Offline interactive prototype',
    '<b>OFFLINE PROTOTYPE</b>',
    'No native server, college systems or providers connected',
    'Opening the fictional offline prototype…',
    'lees-mcrae-fictional-offline-v4',
    'The local demo server is unavailable. Reopen the downloaded offline prototype.',
    'confirmation creates a real task in the reused Teams engine.',
], [
    'Lees-McRae · Campus project demo',
    '<b>CAMPUS DEMO</b>',
    'Per-tab fictional state · No college systems or providers connected',
    'Opening the fictional campus workspace…',
    'lees-mcrae-fictional-hosted-v3',
    'The fictional demo could not open. Reload this page to try again.',
    'confirmation creates a simulated task in this browser session. The hosted demo does not execute the native Teams engine.',
], $html);

// Match the repository's text normalization so reviewed/deployed bytes agree.
$html = str_replace("\r\n", "\n", $html);

$target = __DIR__.'/../../resources/demos/lees-mcrae-campus.html';
file_put_contents($target, $html);
echo 'Built fictional campus document: '.strlen($html).' bytes; SHA256 '.hash('sha256', $html).PHP_EOL;
