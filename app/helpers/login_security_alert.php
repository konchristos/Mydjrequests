<?php
// app/helpers/login_security_alert.php

function mdjr_should_send_security_alert(
    int $userId,
    string $deviceLabel,
    ?string $countryCode
): bool {

    $db = db();

    // Check if this device OR country has ever been seen before
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM user_login_audit
        WHERE user_id = ?
          AND (
              device_label = ?
              OR country_code = ?
          )
    ");

    $stmt->execute([
        $userId,
        $deviceLabel,
        $countryCode
    ]);

    return (int)$stmt->fetchColumn() === 0;
}