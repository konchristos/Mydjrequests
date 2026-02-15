<?php
declare(strict_types=1);

/**
 * Generate a stable hash for a track based on musical identity
 * Used for deduplication
 */
function makeTrackHash(string $title, string $artist, float $bpm): string
{
    $key = mb_strtolower(trim($title)) . '|' .
           mb_strtolower(trim($artist)) . '|' .
           number_format($bpm, 2, '.', '');

    return hash('sha256', $key);
}

/**
 * Normalise a string for safe comparison/storage
 */
function normaliseString(?string $value): string
{
    return trim((string)$value);
}

/**
 * Validate BPM value
 */
function isValidBpm($bpm): bool
{
    return is_numeric($bpm) && $bpm > 40 && $bpm < 300;
}

/**
 * Convert BPM to tier (future use, not yet enforced)
 */
function bpmToTier(float $bpm): string
{
    if ($bpm < 100) {
        return 'slow';
    }
    if ($bpm < 125) {
        return 'mid';
    }
    if ($bpm < 133) {
        return 'dance';
    }
    return 'hard';
}


/**
 * Normalise genre text
 */
function normaliseGenre(?string $genre): ?string
{
    $genre = trim((string)$genre);
    return $genre !== '' ? $genre : null;
}



function normaliseHeader(string $h): string
{
    // Remove UTF-16 null bytes
    $h = str_replace("\0", '', $h);

    // Remove BOM if present
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);

    // Collapse whitespace
    $h = preg_replace('/\s+/', ' ', $h);

    return trim($h);
}