<?php
// app/config/subscriptions.php

return [
    // Initial trial length on registration
    'trial_days' => 30,

    // Auto-renew trial when expired (free access until subscriptions launch)
    'auto_renew_trial' => true,
    'auto_renew_days'  => 30,

    // Require Terms acceptance before accessing the DJ area
    'require_terms' => true,
    'terms_version' => '2026-02-08',
];
