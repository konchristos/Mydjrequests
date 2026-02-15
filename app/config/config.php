<?php
// app/config/config.php

// Toggle debug mode (do NOT leave on in production)
define('APP_DEBUG', true);

// Base URL of your site (no trailing slash)
define('BASE_URL', 'https://mydjrequests.com');

// Database config (replace with your own)
define('DB_HOST', 'localhost');
define('DB_NAME', 'mydjrequests_mydjrequests');
define('DB_USER', 'mydjrequests_konc');
define('DB_PASS', 'd%SnlGS#9#3');
define('DB_CHARSET', 'utf8mb4');

// Trusted device cookie name
define('MDJR_TRUSTED_COOKIE', 'mdjr_trusted_device');

// Optional: default timezone
date_default_timezone_set('Australia/Melbourne');

