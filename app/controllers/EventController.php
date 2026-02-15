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

        return ['success' => true, 'event_id' => $eventId];
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