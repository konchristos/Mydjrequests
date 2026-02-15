<?php
// app/models/Event.php

class Event extends BaseModel
{
    public function create(int $userId, string $title, ?string $location = null, ?string $eventDate = null): int
    {
        $uuid = $this->generateUuid();

        // Normalize eventDate: only YYYY-MM-DD allowed
        if ($eventDate !== null) {
            $eventDate = substr($eventDate, 0, 10); // enforce DATE format
        }

        $stmt = $this->db->prepare('
            INSERT INTO events (uuid, user_id, title, location, event_date)
            VALUES (:uuid, :user_id, :title, :location, :event_date)
        ');

        $stmt->execute([
            'uuid'       => $uuid,
            'user_id'    => $userId,
            'title'      => $title,
            'location'   => $location,
            'event_date' => $eventDate,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM events
            WHERE user_id = :user_id
            ORDER BY event_date DESC, created_at DESC
            LIMIT 50
        ');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Legacy helper – keep for existing code.
     * Internally just calls findByUuid().
     */
    public function getByUuid(string $uuid): ?array
    {
        return $this->findByUuid($uuid);
    }

    /**
     * Used by event_details.php
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM events
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * ✅ New canonical UUID lookup for both DJ & public pages
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM events
            WHERE uuid = :uuid
            LIMIT 1
        ');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function updateById(int $id, int $userId, array $data): bool
    {
        $stmt = $this->db->prepare('
            UPDATE events
            SET title = :title,
                location = :location,
                event_date = :event_date
            WHERE id = :id AND user_id = :user_id
        ');

        return $stmt->execute([
            'id'         => $id,
            'user_id'    => $userId,
            'title'      => $data['title'],
            'location'   => $data['location'],
            'event_date' => $data['event_date'] ?: null,
        ]);
    }

    public function deleteById(int $eventId, int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM events 
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
        ');

        return $stmt->execute([
            'id'      => $eventId,
            'user_id' => $userId
        ]);
    }
}