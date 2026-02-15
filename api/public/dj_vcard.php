<?php
// /api/public/dj_vcard.php
require_once __DIR__ . '/../../app/bootstrap_public.php';

// -----------------------------
// 1. Helper functions
// -----------------------------

/**
 * Escape text for vCard (commas, semicolons, backslashes, newlines).
 */
function vcard_escape(string $str): string
{
    // Normalize line breaks
    $str = str_replace(["\r\n", "\r"], "\n", $str);
    // Escape backslash, comma, semicolon
    $str = str_replace('\\', '\\\\', $str);
    $str = str_replace(';', '\;', $str);
    $str = str_replace(',', '\,', $str);
    // vCard uses \n for newlines inside fields
    $str = str_replace("\n", '\n', $str);
    return $str;
}

/**
 * Normalize a social link: if no scheme, prepend base URL.
 */
function normalize_social(?string $value, ?string $baseUrl = null): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    // If it already looks like a URL, leave it
    if (preg_match('~^https?://~i', $value)) {
        return $value;
    }
    // Remove leading @ for handles
    $value = ltrim($value, '@');

    if ($baseUrl) {
        return rtrim($baseUrl, '/') . '/' . $value;
    }
    return $value;
}


/**
 * Helper to emit both formats  MAximum compatibility for both Apple and Android 
 */
function add_social(
    array &$lines,
    int &$item,
    string $label,
    string $url
): void {
    // Apple / iOS / macOS (reliable)
    $lines[] = "item{$item}.URL:" . vcard_escape($url);
    $lines[] = "item{$item}.X-ABLabel:" . vcard_escape($label);
    $item++;

    // Android / generic
    $lines[] = "X-SOCIALPROFILE;type=" . strtolower($label) . ":" . vcard_escape($url);
}


// -----------------------------
// 2. Get DJ id from query
// -----------------------------
$djId = isset($_GET['dj']) ? (int)$_GET['dj'] : 0;
if ($djId <= 0) {
    http_response_code(400);
    echo "Missing or invalid DJ id.";
    exit;
}


// -----------------------------
// 2.5 Get Event (optional)
// -----------------------------
$event = null;
$eventUuid = $_GET['event'] ?? '';

if ($eventUuid !== '') {
    $eventModel = new Event();
    $event = $eventModel->findByUuid($eventUuid);
}

// -----------------------------
// 3. Load user + profile
// -----------------------------
$userModel    = new User();
$profileModel = new DjProfile();

$user    = $userModel->findById($djId);
$profile = $profileModel->findByUserId($djId);

if (!$user) {
    http_response_code(404);
    echo "DJ not found.";
    exit;
}

// -----------------------------
// 4. Build data fields
// -----------------------------
$displayName =
    ($profile['display_name'] ?? '') !== '' ? $profile['display_name'] :
    (($user['dj_name'] ?? '') !== ''        ? $user['dj_name']        :
     (($user['name'] ?? '') !== ''          ? $user['name']           : 'DJ'));

$email  = trim((string)($profile['public_email'] ?? $user['email'] ?? ''));
$phone  = trim((string)($profile['phone'] ?? ''));
$bio    = trim((string)($profile['bio'] ?? ''));
$website = normalize_social($profile['social_website'] ?? null, null);

// Socials – normalize handles to URLs where sensible
$instagram  = normalize_social($profile['social_instagram'] ?? null, 'https://instagram.com');
$spotify    = normalize_social($profile['social_spotify']   ?? null, 'https://open.spotify.com');
$facebook   = normalize_social($profile['social_facebook']  ?? null, 'https://facebook.com');
$youtube    = normalize_social($profile['social_youtube']   ?? null, 'https://youtube.com');
$soundcloud = normalize_social($profile['social_soundcloud'] ?? null, 'https://soundcloud.com');
$tiktok     = normalize_social($profile['social_tiktok']    ?? null, 'https://www.tiktok.com/@');

// Logo / photo (URL)
$photoUrl = trim((string)($profile['logo_url'] ?? ''));

// For filename, use slug or fallback
$slug = trim((string)($profile['page_slug'] ?? ''));
if ($slug === '') {
    $slug = 'dj-' . $djId;
}
$filename = preg_replace('/[^a-z0-9_\-]+/i', '_', $slug) . '.vcf';

// Split a simple first / last name from displayName
$firstName = $displayName;
$lastName  = '';
if (strpos($displayName, ' ') !== false) {
    $parts = preg_split('/\s+/', $displayName, 2);
    $firstName = $parts[0] ?? '';
    $lastName  = $parts[1] ?? '';
}


// -----------------------------
// 4.5 Build Event + Bio Note
// -----------------------------
$noteParts = [];

if ($event) {
    if (!empty($event['title'])) {
        $noteParts[] = "Event:\n" . $event['title'];
    }
    if (!empty($event['event_date'])) {
        $noteParts[] = "Date: " . $event['event_date'];
    }
    if (!empty($event['location'])) {
        $noteParts[] = "Location: " . $event['location'];
    }
}

if ($bio !== '') {
    $noteParts[] = "\nDJ Bio:\n" . $bio;
}

$finalNote = trim(implode("\n", $noteParts));

// -----------------------------
// 5. Build vCard lines
// -----------------------------
$lines   = [];
$lines[] = 'BEGIN:VCARD';
$lines[] = 'VERSION:3.0';
$lines[] = 'FN:' . vcard_escape($displayName);
$lines[] = 'N:' . vcard_escape($lastName) . ';' . vcard_escape($firstName) . ';;;';

// Organisation – optional branding
$lines[] = 'ORG:' . vcard_escape('MyDJRequests');

// Email
if ($email !== '') {
    $lines[] = 'EMAIL;TYPE=INTERNET,WORK:' . vcard_escape($email);
}

// Phone
if ($phone !== '') {
    $lines[] = 'TEL;TYPE=CELL,VOICE:' . vcard_escape($phone);
}

// Website
if ($website !== '') {
    $lines[] = 'URL:' . vcard_escape($website);
}

// Event + Bio Note (single NOTE for max compatibility)
if ($finalNote !== '') {
    $lines[] = 'NOTE:' . vcard_escape($finalNote);
}

// Photo
if ($photoUrl !== '') {
    // We use a URI reference – phones will fetch the image
    $lines[] = 'PHOTO;VALUE=URI:' . vcard_escape($photoUrl);
}

// Social profiles

$item = 1;

if ($instagram) {
    add_social($lines, $item, 'Instagram', $instagram);
}
if ($spotify) {
    add_social($lines, $item, 'Spotify', $spotify);
}
if ($facebook) {
    add_social($lines, $item, 'Facebook', $facebook);
}
if ($youtube) {
    add_social($lines, $item, 'YouTube', $youtube);
}
if ($soundcloud) {
    add_social($lines, $item, 'SoundCloud', $soundcloud);
}
if ($tiktok) {
    add_social($lines, $item, 'TikTok', $tiktok);
}

$lines[] = 'END:VCARD';

// -----------------------------
// 6. Output with correct headers
// -----------------------------
header('Content-Type: text/vcard; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// vCard expects CRLF line endings
echo implode("\r\n", $lines);
exit;