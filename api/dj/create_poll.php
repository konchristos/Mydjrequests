<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

function ensurePollTables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS event_polls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            dj_id BIGINT UNSIGNED NOT NULL,
            question VARCHAR(500) NOT NULL,
            status ENUM('active','closed') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_created (event_id, created_at),
            INDEX idx_event_status (event_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS event_poll_options (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_poll_sort (poll_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS event_poll_votes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            guest_token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_poll_guest (poll_id, guest_token),
            INDEX idx_event_poll (event_id, poll_id),
            INDEX idx_option (option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$eventUuid = trim((string)($_POST['event_uuid'] ?? ''));
$question = trim((string)($_POST['question'] ?? ''));
$optionsRaw = $_POST['options'] ?? [];
$options = is_array($optionsRaw) ? $optionsRaw : [];
$options = array_values(array_filter(array_map(static function ($v): string {
    return trim((string)$v);
}, $options), static function ($v): bool {
    return $v !== '';
}));

if ($eventUuid === '' || $question === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}
if (mb_strlen($question) > 500) {
    echo json_encode(['ok' => false, 'error' => 'Question too long']);
    exit;
}
if (count($options) < 2) {
    echo json_encode(['ok' => false, 'error' => 'At least 2 poll options are required']);
    exit;
}
if (count($options) > 12) {
    echo json_encode(['ok' => false, 'error' => 'Maximum 12 poll options']);
    exit;
}
foreach ($options as $opt) {
    if (mb_strlen($opt) > 255) {
        echo json_encode(['ok' => false, 'error' => 'Option text too long']);
        exit;
    }
}

$db = db();
$stmt = $db->prepare("
    SELECT id
    FROM events
    WHERE uuid = ?
      AND user_id = ?
    LIMIT 1
");
$stmt->execute([$eventUuid, (int)($_SESSION['dj_id'] ?? 0)]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int)$event['id'];
$djId = (int)($_SESSION['dj_id'] ?? 0);

if (mdjr_get_user_plan($db, $djId) !== 'premium') {
    echo json_encode(['ok' => false, 'error' => 'Poll creation is a Premium feature']);
    exit;
}

try {
    ensurePollTables($db);

    $db->beginTransaction();

    $insertPoll = $db->prepare("
        INSERT INTO event_polls (event_id, dj_id, question, status, created_at, updated_at)
        VALUES (:event_id, :dj_id, :question, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $insertPoll->execute([
        ':event_id' => $eventId,
        ':dj_id' => $djId,
        ':question' => $question,
    ]);
    $pollId = (int)$db->lastInsertId();

    $insertOption = $db->prepare("
        INSERT INTO event_poll_options (poll_id, option_text, sort_order, created_at)
        VALUES (:poll_id, :option_text, :sort_order, UTC_TIMESTAMP())
    ");
    foreach ($options as $idx => $optionText) {
        $insertOption->execute([
            ':poll_id' => $pollId,
            ':option_text' => $optionText,
            ':sort_order' => $idx + 1,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => 'Failed to create poll']);
    exit;
}

echo json_encode([
    'ok' => true,
    'poll_id' => $pollId,
    'created_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
