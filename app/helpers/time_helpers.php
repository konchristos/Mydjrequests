<?php
// app/helpers/time_helpers.php

/**
 * Human-readable "time ago" for UTC timestamps
 * Converts to app timezone before comparison
 */
function mdjr_time_ago(string $utcDatetime): string
{
    try {
        $tz = new DateTimeZone(date_default_timezone_get()); // Australia/Melbourne
        $utc = new DateTimeZone('UTC');

        $dt = new DateTime($utcDatetime, $utc);
        $dt->setTimezone($tz);

        $now = new DateTime('now', $tz);

        $diffSeconds = $now->getTimestamp() - $dt->getTimestamp();

        // Today
        if ($dt->format('Y-m-d') === $now->format('Y-m-d')) {
            if ($diffSeconds < 60) {
                return 'Just now';
            }
            if ($diffSeconds < 3600) {
                return floor($diffSeconds / 60) . ' mins ago';
            }
            return floor($diffSeconds / 3600) . ' hrs ago';
        }

        // Yesterday
        $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
        if ($dt->format('Y-m-d') === $yesterday) {
            return 'Yesterday';
        }

        // Fallback
        return $dt->format('d M Y');

    } catch (Exception $e) {
        return '—';
    }
}