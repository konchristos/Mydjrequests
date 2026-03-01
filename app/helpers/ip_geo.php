<?php
// app/helpers/ip_geo.php

function mdjr_ip_country_code(string $ip): ?string
{
    // Skip local / private IPs
    if (
        $ip === '127.0.0.1' ||
        str_starts_with($ip, '10.') ||
        str_starts_with($ip, '192.168.')
    ) {
        return null;
    }

    $ch = curl_init("https://ipapi.co/{$ip}/country/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'MyDJRequests/1.0',
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $code = strtoupper(trim($response));

    return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
}
