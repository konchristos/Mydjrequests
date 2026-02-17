<?php
// app/controllers/EventController.php

class EventController extends BaseController
{
    // Removed typed property for compatibility with older PHP versions
    protected $eventModel;

    public function __construct()
    {
        $this->eventModel = new Event();
    }

    /**
     * Create new event for DJ
     */
    public function createForUser(int $userId, array $data): array
    {
        $title    = trim($data['title'] ?? '');
        $location = trim($data['location'] ?? '');
        $date     = trim($data['event_date'] ?? '');

        if ($title === '') {
            return ['success' => false, 'message' => 'Event title is required.'];
        }

        $eventId = $this->eventModel->create(
            $userId,
            $title,
            $location ?: null,
            $date ?: null
        );

        $this->applyDefaultEventBroadcastNotice($userId, $eventId);

        return ['success' => true, 'event_id' => $eventId];
    }

    private function applyDefaultEventBroadcastNotice(int $userId, int $eventId): void
    {
        if ($eventId <= 0 || $userId <= 0) {
            return;
        }

        try {
            $db = db();

            $stmt = $db->prepare("
                SELECT default_broadcast_message
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $body = trim((string)($stmt->fetchColumn() ?: ''));

            if ($body === '') {
                return;
            }

            $checkStmt = $db->prepare("
                SELECT id
                FROM event_notices
                WHERE event_id = ?
                  AND notice_type = 'pre_event'
                LIMIT 1
            ");
            $checkStmt->execute([$eventId]);
            $existingId = (int)($checkStmt->fetchColumn() ?: 0);

            if ($existingId > 0) {
                $updateStmt = $db->prepare("
                    UPDATE event_notices
                    SET body = :body,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ");
                $updateStmt->execute([
                    ':body' => $body,
                    ':id' => $existingId
                ]);
            } else {
                $insertStmt = $db->prepare("
                    INSERT INTO event_notices (event_id, notice_type, title, body, created_at, updated_at)
                    VALUES (:event_id, 'pre_event', '', :body, NOW(), NOW())
                ");
                $insertStmt->execute([
                    ':event_id' => $eventId,
                    ':body' => $body
                ]);
            }

            $existingBroadcastStmt = $db->prepare("
                SELECT id
                FROM event_broadcast_messages
                WHERE event_id = :event_id
                ORDER BY id ASC
                LIMIT 1
            ");
            $existingBroadcastStmt->execute([':event_id' => $eventId]);
            $existingBroadcastId = (int)($existingBroadcastStmt->fetchColumn() ?: 0);

            if ($existingBroadcastId <= 0) {
                $broadcastStmt = $db->prepare("
                    INSERT INTO event_broadcast_messages (
                        event_id,
                        dj_id,
                        message,
                        created_at,
                        updated_at
                    ) VALUES (
                        :event_id,
                        :dj_id,
                        :message,
                        NOW(),
                        NOW()
                    )
                ");
                $broadcastStmt->execute([
                    ':event_id' => $eventId,
                    ':dj_id' => $userId,
                    ':message' => $body
                ]);
            }
        } catch (Throwable $e) {
            // Keep event creation resilient if notice tables are unavailable.
        }
    }

    /**
     * List all events for a DJ
     */
    public function listForUser(int $userId): array
    {
        $events = $this->eventModel->getByUser($userId);
        return ['success' => true, 'events' => $events];
    }

    /**
     * Edit an event (UPDATE)
     */
    public function editForUser(int $userId, int $eventId, array $data): array
    {
        $event = $this->eventModel->findById($eventId);

        if (!$event || (int)$event['user_id'] !== $userId) {
            return [
                'success' => false,
                'message' => 'Event not found or permission denied.'
            ];
        }

        $title    = trim($data['title'] ?? '');
        $location = trim($data['location'] ?? '');
        $date     = trim($data['event_date'] ?? '');

        if ($title === '') {
            return ['success' => false, 'message' => 'Event title is required.'];
        }

        $updated = $this->eventModel->updateById(
            $eventId,
            $userId,
            [
                'title'      => $title,
                'location'   => $location,
                'event_date' => $date ?: null
            ]
        );

        if ($updated) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => 'Failed to update event.'
        ];
    }
    
    
    public function deleteForUser(int $userId, int $eventId): array
{
    $event = $this->eventModel->findById($eventId);

    if (!$event || (int)$event['user_id'] !== $userId) {
        return [
            'success' => false,
            'message' => 'Event not found or access denied.'
        ];
    }

    $deleted = $this->eventModel->deleteById($eventId, $userId);

    return [
        'success' => $deleted,
        'message' => $deleted ? 'Event deleted.' : 'Failed to delete event.'
    ];
}
    
    
    
}
