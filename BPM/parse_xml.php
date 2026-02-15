<?php
// /public_html/BPM/parse_xml.php

declare(strict_types=1);

/**
 * Parse Rekordbox XML and extract track metadata
 * Returns array of tracks
 */

function parseRekordboxXML(string $filePath, int $maxTracks = 15000): array
{
    $reader = new XMLReader();
    $tracks = [];
    $count  = 0;

    if (!$reader->open($filePath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Unable to open XML file');
    }

    while ($reader->read()) {
        if (
            $reader->nodeType === XMLReader::ELEMENT &&
            $reader->name === 'TRACK'
        ) {
            $title  = trim((string)$reader->getAttribute('Name'));
            $artist = trim((string)$reader->getAttribute('Artist'));
            $bpm    = $reader->getAttribute('BPM');
            $key    = $reader->getAttribute('Key');
            $genre = $reader->getAttribute('Genre');

            if ($title === '' || $artist === '') {
                continue;
            }

            if ($bpm === null || !is_numeric($bpm)) {
                continue;
            }

            $tracks[] = [
                'title'  => $title,
                'artist' => $artist,
                'bpm'    => round((float)$bpm, 2),
                'key'    => $key !== null ? trim($key) : null,
                'genre'  => $genre !== null ? trim($genre) : null
            ];

            $count++;

            if ($count >= $maxTracks) {
                break;
            }
        }
    }

    $reader->close();

    return $tracks;
}