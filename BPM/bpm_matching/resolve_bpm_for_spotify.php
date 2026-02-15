<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap_public.php';
require_once __DIR__ . '/matching.php';

$db = db();

/**
 * Input:
 *  - spotify_track_id (string)
 */
$spotifyId = $_GET['spotify_track_id'] ?? null;

if (!$spotifyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing spotify_track_id']);
    exit;
}

/* =========================
   Load Spotify track (DB)
========================= */

$stmt = $db->prepare("
    SELECT
        spotify_track_id,
        track_name     AS title,
        artist_name    AS artist,
        duration_ms,
        bpm,
        YEAR(release_date) AS year
    FROM spotify_tracks
    WHERE spotify_track_id = ?
    LIMIT 1
");

$stmt->execute([$spotifyId]);
$spotify = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spotify) {
    http_response_code(404);
    echo json_encode(['error' => 'Spotify track not found']);
    exit;
}

$spotifyTrack = [
    'title' => $spotify['title'],
    'artist' => $spotify['artist'],
    'duration_seconds' => $spotify['duration_ms']
        ? (int) round($spotify['duration_ms'] / 1000)
        : null,
    'bpm' => $spotify['bpm'] !== null
        ? (float)$spotify['bpm']
        : null,
    'year' => $spotify['year'] !== null
        ? (int)$spotify['year']
        : null
];

/* =========================
   Fetch BPM candidates
========================= */

$match = null;

/* =========================
   1️⃣ Primary: Artist-based
========================= */

$stmt = $db->prepare("
    SELECT
        id,
        title,
        artist,
        bpm,
        year,
        time_seconds
    FROM bpm_test_tracks
    WHERE artist LIKE ?
    LIMIT 25
");
$stmt->execute(['%' . $spotifyTrack['artist'] . '%']);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($candidates) {
    $match = matchSpotifyToBpm($spotifyTrack, $candidates);
}

/* =========================
   2️⃣ Fallback: Title-based
========================= */

if (!$match) {
    $stmt = $db->prepare("
        SELECT
            id,
            title,
            artist,
            bpm,
            year,
            time_seconds
        FROM bpm_test_tracks
        WHERE title LIKE ?
        LIMIT 25
    ");
    $stmt->execute(['%' . $spotifyTrack['title'] . '%']);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($candidates) {
        $match = matchSpotifyToBpm($spotifyTrack, $candidates);
    }
}

/* =========================
   Run matcher
========================= */


if (!$match) {
    echo json_encode(['matched' => false]);
    exit;
}

/* =========================
   Persist link
========================= */

$stmt = $db->prepare(
    "
    INSERT INTO track_links
        (spotify_track_id, bpm_track_id, confidence_score, confidence_level, match_meta)
    VALUES
        (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        confidence_score = VALUES(confidence_score),
        confidence_level = VALUES(confidence_level),
        match_meta = VALUES(match_meta)
    "
);

$stmt->execute([
    $spotifyId,
    $match['bpm_track_id'],
    $match['score'],
    $match['confidence'],
    json_encode($match['meta'])
]);

echo json_encode([
    'matched' => true,
    'confidence' => $match['confidence'],
    'score' => $match['score'],
    'meta' => $match['meta']
]);