<?php
/**
 * app/helpers/event_state.php
 *
 * Centralised event state + notice resolution logic
 * -------------------------------------------------
 * - Events have a STATE (upcoming | live | ended)
 * - Notices are RESOLVED, not activated
 * - Resolution order:
 *      1. Event-level override
 *      2. DJ default template
 *      3. null (show nothing)
 *
 * This file must be loaded globally (bootstrap.php)
 */

/**
 * Map event_state -> notice_type
 */
function eventStateToNoticeType(string $eventState): ?string
{
    return match ($eventState) {
        'upcoming' => 'pre_event',
        'live'     => 'live',
        'ended'    => 'post_event',
        default    => null,
    };
}

/**
 * Resolve the notice that should be shown to patrons
 *
 * @param PDO    $pdo
 * @param int    $eventId
 * @param int    $djId
 * @param string $eventState
 *
 * @return array|null
 */
function resolveEventNotice(PDO $pdo, int $eventId, int $djId, string $eventState): ?array
{
    $noticeType = eventStateToNoticeType($eventState);
    if (!$noticeType) return null;

    // 1) Event override
    $stmt = $pdo->prepare("
        SELECT id, title, body, updated_at
        FROM event_notices
        WHERE event_id = :event_id AND notice_type = :notice_type
        LIMIT 1
    ");
    $stmt->execute([
        ':event_id'    => $eventId,
        ':notice_type' => $noticeType
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && trim((string)$row['body']) !== '') {
        return [
            'id'         => (int)$row['id'],
            'title'      => (string)($row['title'] ?? ''),
            'body'       => (string)$row['body'],
            'type'       => $noticeType,
            'source'     => 'event',
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    // 2) Platform default fallback
    $stmt = $pdo->prepare("
        SELECT title, body, updated_at
        FROM platform_notice_templates
        WHERE notice_type = :notice_type
        LIMIT 1
    ");
    $stmt->execute([
        ':notice_type' => $noticeType
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $noticeType === 'live') {
        $row['body'] = mdjr_default_platform_live_message();
    }

    if ($row && trim((string)$row['body']) !== '') {
        return [
            'title'      => (string)($row['title'] ?? ''),
            'body'       => (string)$row['body'],
            'type'       => $noticeType,
            'source'     => 'platform',
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    return null;
}



/**
 * Change an event's state safely.
 *
 * Rules enforced:
 * - Only ONE LIVE event per DJ at a time
 * - Going LIVE auto-ends any other LIVE event
 * - State change timestamp is recorded
 */
function setEventState(
    PDO $pdo,
    int $eventId,
    int $djId,
    string $newState
): void {

    $allowed = ['upcoming', 'live', 'ended'];
    if (!in_array($newState, $allowed, true)) {
        throw new InvalidArgumentException('Invalid event state');
    }

    $pdo->beginTransaction();

    try {
        // Lock DJ events to avoid race conditions
        $stmt = $pdo->prepare("
            SELECT id
            FROM events
            WHERE user_id = :dj_id
            FOR UPDATE
        ");
        $stmt->execute([':dj_id' => $djId]);

        // If going LIVE, end all other LIVE events
        if ($newState === 'live') {
            $stmt = $pdo->prepare("
                UPDATE events
                SET event_state = 'ended',
                    state_changed_at = NOW()
                WHERE user_id = :dj_id
                  AND event_state = 'live'
                  AND id != :event_id
            ");
            $stmt->execute([
                ':dj_id'     => $djId,
                ':event_id'  => $eventId
            ]);
        }

        // Update target event
        $stmt = $pdo->prepare("
            UPDATE events
            SET event_state = :state,
                state_changed_at = NOW()
            WHERE id = :event_id
              AND user_id = :dj_id
        ");
        $stmt->execute([
            ':state'     => $newState,
            ':event_id'  => $eventId,
            ':dj_id'     => $djId
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
