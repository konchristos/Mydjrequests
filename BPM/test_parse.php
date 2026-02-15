<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/parse_xml.php';

$file = __DIR__ . '/uploads/test.xml'; // temporary hardcoded test

try {
    $tracks = parseRekordboxXML($file);

    echo '<pre>';
    echo 'Tracks found: ' . count($tracks) . PHP_EOL;
    print_r(array_slice($tracks, 0, 10));
    echo '</pre>';

} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}