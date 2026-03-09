<?php
declare(strict_types=1);

function bpmSettingValue(PDO $db, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default;
        }
        return (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function bpmSettingEnabled(PDO $db, string $key, bool $default = false): bool
{
    $raw = bpmSettingValue($db, $key, $default ? '1' : '0');
    if ($raw === null) {
        return $default;
    }
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

function bpmRolloutGlobalEnabled(PDO $db): bool
{
    return bpmSettingEnabled($db, 'bpm_rollout_all_enabled', false);
}

function bpmUserHasAccess(PDO $db, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (bpmRolloutGlobalEnabled($db)) {
        return true;
    }

    try {
        $stmt = $db->prepare("
            SELECT is_admin, COALESCE(bpm_access_enabled, 0) AS bpm_access_enabled
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        if ((int)($row['is_admin'] ?? 0) === 1) {
            return true;
        }

        return (int)($row['bpm_access_enabled'] ?? 0) === 1;
    } catch (Throwable $e) {
        try {
            $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $inner) {
            return false;
        }
    }
}

function bpmCurrentUserHasAccess(PDO $db): bool
{
    $userId = (int)($_SESSION['dj_id'] ?? 0);
    return bpmUserHasAccess($db, $userId);
}
