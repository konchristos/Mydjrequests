<?php
//BPM/test_parse_txt.php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/parse_rekordbox_txt.php';

$file = __DIR__ . '/uploads/test.txt';

try {
    $tracks = parseRekordboxTxt($file);

    echo '<pre>';
    echo 'Tracks parsed: ' . count($tracks) . PHP_EOL;
    print_r(array_slice($tracks, 0, 10));
    echo '</pre>';

} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}