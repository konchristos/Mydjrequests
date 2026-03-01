<?php
// app/config/subscriptions.php

return [
    // Initial trial length on registration (calendar-month based)
    'trial_months' => 1,

    // Auto-renew trial when expired (calendar month cadence, same day-of-month)
    'auto_renew_trial' => true,

    // Require Terms acceptance before accessing the DJ area
    'require_terms' => true,
    'terms_version' => '2026-02-08',
];
