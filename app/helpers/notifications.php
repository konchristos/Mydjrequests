<?php
// app/helpers/notifications.php

function notifications_get_unread_count(int $userId): int
{
    $db = db();
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM notification_recipients r
        WHERE r.user_id = :uid AND r.is_read = 0
    ");
    $stmt->execute(['uid' => $userId]);
    return (int)$stmt->fetchColumn();
}

function notifications_get_recent(int $userId, int $limit = 10): array
{
    $db = db();
    $stmt = $db->prepare("
        SELECT n.*, r.is_read, r.read_at
        FROM notifications n
        INNER JOIN notification_recipients r ON r.notification_id = n.id
        WHERE r.user_id = :uid
          AND r.is_read = 0
        ORDER BY n.created_at DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function notifications_mark_read(int $userId, int $notificationId): void
{
    $db = db();
    $stmt = $db->prepare("
        UPDATE notification_recipients
        SET is_read = 1, read_at = UTC_TIMESTAMP()
        WHERE user_id = :uid AND notification_id = :nid
    ");
    $stmt->execute(['uid' => $userId, 'nid' => $notificationId]);
}

function notifications_create(string $type, string $title, string $body, string $url = ''): int
{
    $db = db();
    $stmt = $db->prepare("
        INSERT INTO notifications (type, title, body, url, created_at)
        VALUES (:type, :title, :body, :url, UTC_TIMESTAMP())
    ");
    $stmt->execute([
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'url' => $url,
    ]);
    return (int)$db->lastInsertId();
}

function notifications_add_recipients(int $notificationId, array $userIds): void
{
    if (empty($userIds)) return;

    $db = db();
    $stmt = $db->prepare("
        INSERT INTO notification_recipients (notification_id, user_id, is_read)
        VALUES (:nid, :uid, 0)
    ");

    foreach ($userIds as $uid) {
        $stmt->execute([
            'nid' => $notificationId,
            'uid' => (int)$uid,
        ]);
    }
}

function notifications_add_all_users(int $notificationId): void
{
    $db = db();
    $users = $db->query("SELECT id FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(fn($u) => (int)$u['id'], $users);
    notifications_add_recipients($notificationId, $ids);
}

function notifications_add_admins(int $notificationId): void
{
    $db = db();
    $admins = $db->query("SELECT id FROM users WHERE is_admin = 1")->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(fn($u) => (int)$u['id'], $admins);
    notifications_add_recipients($notificationId, $ids);
}
