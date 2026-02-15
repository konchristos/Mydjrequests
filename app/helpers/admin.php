<?php

function current_user(): ?array
{
    if (empty($_SESSION['dj_id'])) {
        return null;
    }

    static $cachedUser = null;

    if ($cachedUser !== null) {
        return $cachedUser;
    }

    $userModel = new User();
    $cachedUser = $userModel->findById((int)$_SESSION['dj_id']);

    return $cachedUser ?: null;
}

function is_admin(): bool
{
    if (!empty($_SESSION['admin_id'])) {
        return true;
    }

    $user = current_user();
    return !empty($user['is_admin']);
}

function require_admin(): void
{
    if (PHP_SAPI === 'cli') return;

    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied');
    }
}

function is_simulating(): bool
{
    return !empty($_SESSION['simulate_dj_id']);
}