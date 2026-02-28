<?php

require_once __DIR__ . '/../../vendor/autoload.php';

if (!defined('STRIPE_SECRET_KEY')) {
    define('STRIPE_SECRET_KEY', (string)mdjr_secret('STRIPE_SECRET_KEY', ''));
}
if (!defined('STRIPE_PUBLISHABLE_KEY')) {
    define('STRIPE_PUBLISHABLE_KEY', (string)mdjr_secret('STRIPE_PUBLISHABLE_KEY', ''));
}
if (!defined('STRIPE_WEBHOOK_SECRET')) {
    define('STRIPE_WEBHOOK_SECRET', (string)mdjr_secret('STRIPE_WEBHOOK_SECRET', ''));
}
if (!defined('STRIPE_PLATFORM_FEE_BPS')) {
    define('STRIPE_PLATFORM_FEE_BPS', (int)mdjr_secret('STRIPE_PLATFORM_FEE_BPS', 0));
}
