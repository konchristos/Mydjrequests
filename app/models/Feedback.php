<?php
// app/models/Feedback.php

class Feedback extends BaseModel
{
    public function create(?int $userId, string $name, string $email, string $message, string $ip): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO feedback (user_id, name, email, message, ip_address, created_at)
            VALUES (:uid, :name, :email, :message, :ip, UTC_TIMESTAMP())
        ");

        $stmt->execute([
            'uid' => $userId,
            'name' => $name,
            'email' => $email,
            'message' => $message,
            'ip' => $ip,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM feedback
            WHERE user_id = :uid
            ORDER BY created_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM feedback
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
