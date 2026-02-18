<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();
redirect('dj/settings.php#message-statuses');
