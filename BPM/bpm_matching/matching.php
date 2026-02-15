<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap_public.php';


//BPM/bpm_matching/matching.php

function normaliseText(string $v): string
{
    $v = mb_strtolower($v, 'UTF-8');

    // Remove remix/edit/version noise
    $v = preg_replace('/\b(remix|edit|revibe|version|mix|extended|radio)\b/i', '', $v);

    // Remove brackets and punctuation
    $v = preg_replace('/[\(\)\[\]\{\}]/', '', $v);
    $v = preg_replace('/[^a-z0-9\s]/i', '', $v);

    // Collapse whitespace
    $v = preg_replace('/\s+/', ' ', trim($v));

    return $v;
}

function splitArtists(string $v): array
{
    $v = mb_strtolower($v, 'UTF-8');

    $v = str_replace([' feat ', ' featuring ', '&'], ',', $v);

    return array_filter(array_map('trim', explode(',', $v)));
}


function scoreTitle(string $a, string $b): int
{
    $a = normaliseText($a);
    $b = normaliseText($b);

    if ($a === $b) {
        return 40;
    }

    similar_text($a, $b, $percent);

    if ($percent >= 90) return 38;
    if ($percent >= 80) return 34;
    if ($percent >= 70) return 28;
    if ($percent >= 60) return 20;
    if ($percent >= 50) return 12;

    return 0;
}

function scoreArtist(string $a, string $b): int
{
    $aSet = splitArtists($a);
    $bSet = splitArtists($b);

    $intersection = array_intersect($aSet, $bSet);

    if (count($intersection) === count($aSet) && count($aSet) === count($bSet)) {
        return 30;
    }

    if (!empty($intersection)) {
        return 22 + min(8, count($intersection) * 4);
    }

    return 0;
}


function scoreDuration(?int $a, ?int $b): int
{
    if (!$a || !$b) return 0;

    $diff = abs($a - $b);

    if ($diff <= 2) return 15;
    if ($diff <= 5) return 12;
    if ($diff <= 10) return 8;
    if ($diff <= 20) return 4;

    return 0;
}


function scoreBpm(?float $a, ?float $b): int
{
    if (!$a || !$b) return 0;

    $diff = abs($a - $b);

    if ($diff <= 0.5) return 10;
    if ($diff <= 1.0) return 8;
    if ($diff <= 2.0) return 5;
    if ($diff <= 4.0) return 2;

    return 0;
}


function scoreYear(?int $a, ?int $b): int
{
    if (!$a || !$b) return 0;

    $diff = abs($a - $b);

    if ($diff === 0) return 5;
    if ($diff === 1) return 3;
    if ($diff === 2) return 1;

    return 0;
}


function matchSpotifyToBpm(array $spotify, array $candidates): ?array
{
    $best = null;
    $bestScore = 0;

    foreach ($candidates as $bpm) {

        if (
    empty($spotify['title']) ||
    empty($spotify['artist']) ||
    empty($bpm['title']) ||
    empty($bpm['artist'])
) {
    continue;
}

$titleScore = scoreTitle($spotify['title'], $bpm['title']);
$artistScore = scoreArtist($spotify['artist'], $bpm['artist']);

        // Hard reject: weak title or artist
        if ($titleScore < 25 || $artistScore < 20) {
            continue;
        }

        $durationScore = scoreDuration(
            isset($spotify['duration_seconds']) ? (int)$spotify['duration_seconds'] : null,
            isset($bpm['time_seconds']) ? (int)$bpm['time_seconds'] : null
        );

        $bpmScore = scoreBpm(
            isset($spotify['bpm']) && is_numeric($spotify['bpm'])
                ? (float)$spotify['bpm']
                : null,
            isset($bpm['bpm']) && is_numeric($bpm['bpm'])
                ? (float)$bpm['bpm']
                : null
        );

        $yearScore = scoreYear(
            isset($spotify['year']) ? (int)$spotify['year'] : null,
            isset($bpm['year']) ? (int)$bpm['year'] : null
        );

        $total =
            $titleScore +
            $artistScore +
            $durationScore +
            $bpmScore +
            $yearScore;

        if ($total > $bestScore) {
            $bestScore = $total;

            $best = [
                'bpm_track_id' => $bpm['id'],
                'score' => $total,
                'confidence' => match (true) {
                    $total >= 85 => 'very_high',
                    $total >= 70 => 'high',
                    $total >= 50 => 'medium',
                    default => 'low',
                },
                'meta' => [
                    'title' => $titleScore,
                    'artist' => $artistScore,
                    'duration' => $durationScore,
                    'bpm' => $bpmScore,
                    'year' => $yearScore
                ]
            ];
        }
    }

    return $bestScore >= 70 ? $best : null;
}
