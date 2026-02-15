<?php
declare(strict_types=1);

define('BPM_UPLOAD_DIR', __DIR__ . '/uploads/');
define('BPM_MAX_MB', 15);          // hard limit
define('BPM_MAX_BYTES', BPM_MAX_MB * 1024 * 1024);
define('BPM_ALLOWED_EXT', ['txt', 'xml']);