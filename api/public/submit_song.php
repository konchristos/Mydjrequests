<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// api/public/submit_song.php
require_once __DIR__ . '/../../app/bootstrap_public.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

require_once APP_ROOT . '/app/config/database.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

function appSettingEnabled(PDO $pdo, string $key, bool $default = false): bool
{
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return $default;
        }
        $val = strtolower(trim((string)$val));
        return in_array($val, ['1', 'true', 'yes', 'on'], true);
    } catch (Throwable $e) {
        return $default;
    }
}

function ensureEnrichmentQueueTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS track_enrichment_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            spotify_track_id VARCHAR(64) NOT NULL,
            status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            next_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_track_enrichment_queue_spotify_id (spotify_track_id),
            KEY idx_track_enrichment_queue_status_next (status, next_attempt_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function queueTrackEnrichment(PDO $pdo, string $spotifyId): void
{
    if ($spotifyId === '') {
        return;
    }

    ensureEnrichmentQueueTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO track_enrichment_queue (
            spotify_track_id, status, attempts, last_error, next_attempt_at
        ) VALUES (
            :sid, 'pending', 0, NULL, NULL
        )
        ON DUPLICATE KEY UPDATE
            status = IF(status = 'processing', status, 'pending'),
            next_attempt_at = IF(status = 'processing', next_attempt_at, NULL),
            last_error = IF(status = 'processing', last_error, NULL),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':sid' => $spotifyId]);
}

function applyLinkedTrackMetadata(PDO $pdo, string $spotifyId, bool $wantYear): void
{
    $stmt = $pdo->prepare("
        SELECT bt.bpm, bt.key_text, bt.year
        FROM track_links tl
        INNER JOIN bpm_test_tracks bt ON bt.id = tl.bpm_track_id
        WHERE tl.spotify_track_id = ?
        LIMIT 1
    ");
    $stmt->execute([$spotifyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $set = [];
    $params = [':sid' => $spotifyId];

    if (isset($row['bpm']) && is_numeric($row['bpm']) && (float)$row['bpm'] > 0) {
        $set[] = "bpm = CASE WHEN bpm IS NULL OR bpm = 0 THEN :bpm ELSE bpm END";
        $params[':bpm'] = (float)$row['bpm'];
    }
    if (!empty($row['key_text'])) {
        $set[] = "musical_key = CASE WHEN musical_key IS NULL OR musical_key = '' THEN :mkey ELSE musical_key END";
        $params[':mkey'] = trim((string)$row['key_text']);
    }
    if ($wantYear && isset($row['year']) && is_numeric($row['year'])) {
        $year = (int)$row['year'];
        $maxYear = (int)date('Y') + 1;
        if ($year >= 1900 && $year <= $maxYear) {
            $set[] = "release_year = CASE WHEN release_year IS NULL OR release_year = 0 THEN :ryear ELSE release_year END";
            $params[':ryear'] = $year;
        }
    }

    if (empty($set)) {
        return;
    }

    $sql = "UPDATE spotify_tracks SET " . implode(', ', $set) . ", last_refreshed_at = NOW() WHERE spotify_track_id = :sid";
    $upd = $pdo->prepare($sql);
    $upd->execute($params);
}

function queueEnrichmentForRequest(PDO $pdo, string $spotifyId): void
{
    if ($spotifyId === '') {
        return;
    }

    $wantFuzzy = appSettingEnabled($pdo, 'bpm_fuzzy_on_request_enabled', false);
    $wantYear = appSettingEnabled($pdo, 'bpm_on_request_fill_year_enabled', false);

    // Fast immediate fill from existing link (no fuzzy, no heavy work).
    applyLinkedTrackMetadata($pdo, $spotifyId, $wantYear);

    $stateStmt = $pdo->prepare("
        SELECT bpm, musical_key, release_year
        FROM spotify_tracks
        WHERE spotify_track_id = ?
        LIMIT 1
    ");
    $stateStmt->execute([$spotifyId]);
    $state = $stateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) {
        return;
    }

    $missingBpm = !isset($state['bpm']) || (float)$state['bpm'] <= 0;
    $missingKey = empty($state['musical_key']);
    $missingYear = $wantYear && (!isset($state['release_year']) || (int)$state['release_year'] <= 0);

    if (!$missingBpm && !$missingKey && !$missingYear) {
        return;
    }

    if ($wantFuzzy) {
        queueTrackEnrichment($pdo, $spotifyId);
    }
}

// -----------------------------
// INPUTS
// -----------------------------
$eventUuid   = trim($_POST['event_uuid'] ?? '');
$songTitle   = trim($_POST['song_title'] ?? '');
$artist      = trim($_POST['artist'] ?? '');
$patronName  = trim($_POST['patron_name'] ?? '');

$spotifyId      = trim($_POST['spotify_track_id'] ?? '');
$spotifyName    = trim($_POST['spotify_track_name'] ?? '');
$spotifyArtist  = trim($_POST['spotify_artist_name'] ?? '');
$spotifyArt     = trim($_POST['spotify_album_art_url'] ?? '');

$spotifyDurationMs = isset($_POST['spotify_duration_ms'])
    ? (int)$_POST['spotify_duration_ms']
    : null;

$spotifyReleaseDate = trim($_POST['spotify_release_date'] ?? '');

$spotifyReleaseYear = null;
if ($spotifyReleaseDate !== '' && preg_match('/^\d{4}/', $spotifyReleaseDate)) {
    $spotifyReleaseYear = (int)substr($spotifyReleaseDate, 0, 4);
}



$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

$guestToken = $_COOKIE['mdjr_guest'] ?? null;

// -----------------------------
// VALIDATION
// -----------------------------
if ($songTitle === '') {
    echo json_encode(['success' => false, 'message' => 'Song title is required.']);
    exit;
}

if ($eventUuid === '') {
    echo json_encode(['success' => false, 'message' => 'Missing event.']);
    exit;
}

// -----------------------------
// LOOKUP EVENT
// -----------------------------
$stmt = $pdo->prepare("SELECT id FROM events WHERE uuid = ?");
$stmt->execute([$eventUuid]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo json_encode(['success' => false, 'message' => 'Event not found.']);
    exit;
}

$eventId = (int)$event['id'];

// -----------------------------
// PREVENT DUPLICATE REQUESTS
// -----------------------------
if ($guestToken) {

    $dupeStmt = $pdo->prepare("
        SELECT 1
        FROM song_requests
        WHERE event_id = ?
          AND guest_token = ?
          AND (
                (spotify_track_id != '' AND spotify_track_id = ?)
             OR (spotify_track_id = ''
                 AND LOWER(song_title) = LOWER(?)
                 AND LOWER(artist) = LOWER(?))
          )
          AND created_at >= (NOW() - INTERVAL 30 MINUTE)
        LIMIT 1
    ");

    $dupeStmt->execute([
        $eventId,
        $guestToken,
        $spotifyId,
        $songTitle,
        $artist
    ]);

    if ($dupeStmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'You already requested this song recently 🙂'
        ]);
        exit;
    }
}



// ---------------------------------------------------------------------
// Increment monthly + lifetime request stats for the DJ owning the event
// ---------------------------------------------------------------------
function incrementMonthlyRequestCount(PDO $pdo, int $eventId): void
{
    // Resolve DJ from event
    $stmt = $pdo->prepare("
        SELECT user_id
        FROM events
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$eventId]);
    $djId = (int)$stmt->fetchColumn();

    if ($djId <= 0) {
        return; // safety guard
    }

    $now   = new DateTimeImmutable('now');
    $year  = (int)$now->format('Y');
    $month = (int)$now->format('n');

    // Atomic upsert
    $stmt = $pdo->prepare("
        INSERT INTO song_request_stats_monthly
            (dj_id, year, month, total_requests)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            total_requests = total_requests + 1
    ");

    $stmt->execute([$djId, $year, $month]);
}


// ------------------------------------
// Increment per-event request counter
// ------------------------------------
function incrementEventRequestCount(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO event_request_stats (event_id, total_requests)
        VALUES (?, 1)
        ON DUPLICATE KEY UPDATE
            total_requests = total_requests + 1
    ");

    $stmt->execute([$eventId]);
}


// -----------------------------
// INSERT REQUEST (FAST — NO SPOTIFY YET)
// -----------------------------
$insert = $pdo->prepare("
    INSERT INTO song_requests (
        uuid,
        event_id,
        song_title,
        artist,
        requester_name,
        guest_token,
        message,
        spotify_track_id,
        spotify_track_name,
        spotify_artist_name,
        spotify_album_art_url,
        ip_address,
        user_agent,
        created_at
    ) VALUES (
        UUID(),
        :event_id,
        :song_title,
        :artist,
        :requester_name,
        :guest_token,
        '',
        :spotify_track_id,
        :spotify_track_name,
        :spotify_artist_name,
        :spotify_album_art_url,
        :ip_address,
        :user_agent,
        NOW()
    )
");

$insert->execute([
    ':event_id'              => $eventId,
    ':song_title'            => $songTitle,
    ':artist'                => $artist,
    ':requester_name'        => $patronName,
    ':guest_token'           => $guestToken,
    ':spotify_track_id'      => $spotifyId,
    ':spotify_track_name'    => $spotifyName,
    ':spotify_artist_name'   => $spotifyArtist,
    ':spotify_album_art_url' => $spotifyArt,
    ':ip_address'            => $ip,
    ':user_agent'            => $ua
]);

$requestId = (int)$pdo->lastInsertId();

// -----------------------------
// INCREMENT REQUEST STATS
// -----------------------------
incrementMonthlyRequestCount($pdo, $eventId);
incrementEventRequestCount($pdo, $eventId);


// -----------------------------
// SPOTIFY TRACK CACHE (LOCAL UPSERT)
// -----------------------------
if ($spotifyId !== '') {

$cache = $pdo->prepare("
    INSERT INTO spotify_tracks (
        spotify_track_id,
        track_name,
        artist_name,
        album_name,
        album_art_url,
        duration_ms,
        release_date,
        release_year,
        preview_url,
        last_refreshed_at
    ) VALUES (
        :track_id,
        :track_name,
        :artist_name,
        :album_name,
        :album_art,
        :duration_ms,
        :release_date,
        :release_year,
        :preview_url,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        track_name      = IF(VALUES(track_name) <> '', VALUES(track_name), track_name),
        artist_name     = IF(VALUES(artist_name) <> '', VALUES(artist_name), artist_name),
        album_art_url   = IF(VALUES(album_art_url) IS NOT NULL, VALUES(album_art_url), album_art_url),
        duration_ms     = IF(VALUES(duration_ms) IS NOT NULL, VALUES(duration_ms), duration_ms),
        release_date    = IF(VALUES(release_date) IS NOT NULL, VALUES(release_date), release_date),
        release_year    = IF(VALUES(release_year) IS NOT NULL, VALUES(release_year), release_year),
        preview_url     = IF(VALUES(preview_url) IS NOT NULL, VALUES(preview_url), preview_url),
        last_refreshed_at = NOW()
");

    $cache->execute([
        ':track_id'       => $spotifyId,
        ':track_name'     => $spotifyName ?: $songTitle,
        ':artist_name'    => $spotifyArtist ?: $artist,
        ':album_name'     => null,
        ':album_art'      => $spotifyArt ?: null,
        ':duration_ms'    => $spotifyDurationMs,
        ':release_date'   => $spotifyReleaseDate ?: null,
        ':release_year'   => $spotifyReleaseYear,
        ':preview_url'    => null
    ]);

    // Fast link-based fill now; fuzzy runs async via queue worker when enabled.
    queueEnrichmentForRequest($pdo, $spotifyId);
}

// -----------------------------
// SYNC EVENT PLAYLIST (AUTO-ADD)
// -----------------------------
if ($spotifyId !== '') {
    require_once APP_ROOT . '/app/lib/spotify_playlist.php';

    // Get DJ for this event
    $djStmt = $pdo->prepare("
        SELECT user_id
        FROM events
        WHERE id = ?
        LIMIT 1
    ");
    $djStmt->execute([$eventId]);
    $djId = (int)$djStmt->fetchColumn();

    if ($djId > 0) {
        syncEventPlaylistFromRequests($pdo, $djId, $eventId);
    }
}

// -----------------------------
// DONE
// -----------------------------
echo json_encode(['success' => true]);
