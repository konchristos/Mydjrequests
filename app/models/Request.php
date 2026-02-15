<?php
// app/models/Request.php

class Request extends BaseModel
{
    public function create(
        int $eventId,
        string $songTitle,
        ?string $artist,
        ?string $requesterName,
        ?string $message,
        string $ip,
        string $ua
    ): int {
        $uuid = $this->generateUuid();

        $stmt = $this->db->prepare('
            INSERT INTO requests
                (uuid, event_id, song_title, artist, requester_name, message, ip_address, user_agent)
            VALUES
                (:uuid, :event_id, :song_title, :artist, :requester_name, :message, :ip_address, :user_agent)
        ');

        $stmt->execute([
            'uuid'          => $uuid,
            'event_id'      => $eventId,
            'song_title'    => $songTitle,
            'artist'        => $artist,
            'requester_name'=> $requesterName,
            'message'       => $message,
            'ip_address'    => $ip,
            'user_agent'    => $ua,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getByEvent(int $eventId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM requests
            WHERE event_id = :event_id
            ORDER BY created_at ASC
        ');
        $stmt->execute(['event_id' => $eventId]);
        return $stmt->fetchAll();
    }

    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}