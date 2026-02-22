<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

header('Content-Type: application/json');

$db = db();
$djId = (int)($_SESSION['dj_id'] ?? 0);
if ($djId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing user context']);
    exit;
}
if (!mdjr_user_has_premium($db, $djId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Premium plan required']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$slot = (int)($_POST['slot'] ?? 0);
if ($slot < 1 || $slot > 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid preset slot']);
    exit;
}

try {
    mdjr_ensure_premium_tables($db);

    if ($action === 'save') {
        mdjr_save_user_qr_preset($db, $djId, $slot, $_POST);
        echo json_encode(['ok' => true, 'slot' => $slot]);
        exit;
    }

    if ($action === 'load') {
        $preset = mdjr_get_user_qr_preset($db, $djId, $slot);
        if ($preset === null) {
            echo json_encode(['ok' => false, 'error' => 'No preset saved in this slot']);
            exit;
        }
        echo json_encode(['ok' => true, 'slot' => $slot, 'preset' => $preset]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Preset action failed',
    ]);
}

