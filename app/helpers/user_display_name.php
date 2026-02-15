<?php

function mdjr_display_name(?array $user): string
{
    if (!$user) {
        return 'there';
    }

    if (!empty($user['dj_name'])) {
        return $user['dj_name'];
    }

    if (!empty($user['name'])) {
        // Use first name only if full name
        return explode(' ', trim($user['name']))[0];
    }

    return 'there';
}