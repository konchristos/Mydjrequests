<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_dj_login();

// Stripe sends users here when an onboarding link is expired/visited twice.
// Restart onboarding to generate a fresh Account Link.
header('Location: /dj/stripe/start_onboarding.php?refresh=1');
exit;
