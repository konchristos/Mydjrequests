<?php
// app/controllers/RequestController.php

class RequestController extends BaseController
{
    protected Request $requestModel;
    protected Event $eventModel;

    public function __construct()
    {
        $this->requestModel = new Request();
        $this->eventModel   = new Event();
    }

    public function submit(array $data, string $ip, string $ua): array
    {
        $eventUuid = trim($data['event_uuid'] ?? '');
        $title     = trim($data['song_title'] ?? '');
        $artist    = trim($data['artist'] ?? '');
        $name      = trim($data['requester_name'] ?? '');
        $message   = trim($data['message'] ?? '');

        if ($eventUuid === '' || $title === '') {
            return ['success' => false, 'message' => 'Missing event or song title.'];
        }

        $event = $this->eventModel->getByUuid($eventUuid);
        if (!$event) {
            return ['success' => false, 'message' => 'Event not found.'];
        }

        $id = $this->requestModel->create(
            (int)$event['id'],
            $title,
            $artist ?: null,
            $name ?: null,
            $message ?: null,
            $ip,
            $ua
        );

        return ['success' => true, 'request_id' => $id];
    }
}