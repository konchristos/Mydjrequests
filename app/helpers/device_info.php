<?php
//app/helpers/device_info.php
function mdjr_device_label(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($ua === '') {
        return 'Unknown device';
    }

    $os = 'Unknown OS';
    $browser = 'Unknown browser';

    // OS detection
    if (stripos($ua, 'iPhone') !== false) {
        $os = 'iPhone';
    } elseif (stripos($ua, 'iPad') !== false) {
        $os = 'iPad';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'Mac OS X') !== false) {
        $os = 'Mac';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    // Browser detection
    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
        $browser = 'Safari';
    } elseif (stripos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Edg') !== false) {
        $browser = 'Edge';
    }

    return "{$os} • {$browser}";
}