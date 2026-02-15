<?php
require_once __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json');

require_dj_login();

$djId = (int)($_SESSION['dj_id'] ?? 0);
$raw  = $_GET['slug'] ?? '';

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

$slug = slugify($raw);

$stmt = db()->prepare("
    SELECT id
    FROM dj_profiles
    WHERE page_slug = ?
      AND user_id != ?
    LIMIT 1
");

$stmt->execute([$slug, $djId]);

echo json_encode([
    'available' => !$stmt->fetch()
]);