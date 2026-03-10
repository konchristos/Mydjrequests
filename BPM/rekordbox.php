<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    die('BPM import is not enabled for this account.');
}

header('Location: /dj/library_import.php', true, 302);
exit;
