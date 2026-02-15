<?php
//app/helpers/rate_limit.php
function mdjr_check_rate_limit(string $ip): bool
{
    $db = db();

    $stmt = $db->prepare("
        SELECT attempts, last_attempt
        FROM login_rate_limit
        WHERE ip_address = ?
    ");
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $db->prepare("
            INSERT INTO login_rate_limit (ip_address, attempts, last_attempt)
            VALUES (?, 1, NOW())
        ")->execute([$ip]);

        return true;
    }

    $last = strtotime($row['last_attempt'] . ' UTC');
    $now  = time();

    // Reset after 1 minute
    if ($now - $last > 60) {
        $db->prepare("
            UPDATE login_rate_limit
            SET attempts = 1, last_attempt = NOW()
            WHERE ip_address = ?
        ")->execute([$ip]);

        return true;
    }

    if ($row['attempts'] >= 5) {
        return false;
    }

    $db->prepare("
        UPDATE login_rate_limit
        SET attempts = attempts + 1, last_attempt = NOW()
        WHERE ip_address = ?
    ")->execute([$ip]);

    return true;
}