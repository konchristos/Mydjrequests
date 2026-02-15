<?php
// app/models/BugReport.php

class BugReport extends BaseModel
{
    public function create(int $userId, string $title, string $description, string $priority): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bug_reports (user_id, title, description, priority, status, created_at, updated_at)
            VALUES (:uid, :title, :description, :priority, 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");

        $stmt->execute([
            'uid' => $userId,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function mergeInto(int $childId, int $parentId): void
    {
        $stmt = $this->db->prepare("
            UPDATE bug_reports
            SET parent_bug_id = :parent, status = 'closed', updated_at = UTC_TIMESTAMP()
            WHERE id = :child
        ");
        $stmt->execute(['parent' => $parentId, 'child' => $childId]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, u.email, u.name
            FROM bug_reports b
            LEFT JOIN users u ON u.id = b.user_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM bug_reports
            WHERE user_id = :uid
            ORDER BY updated_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(): array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, u.email, u.name
            FROM bug_reports b
            LEFT JOIN users u ON u.id = b.user_id
            ORDER BY b.updated_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatusPriority(int $id, string $status, string $priority): void
    {
        $stmt = $this->db->prepare("
            UPDATE bug_reports
            SET status = :status,
                priority = :priority,
                updated_at = UTC_TIMESTAMP()
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $status,
            'priority' => $priority,
            'id' => $id,
        ]);
    }

    public function addComment(int $bugId, int $userId, string $comment, bool $isAdmin): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO bug_comments (bug_id, user_id, comment, is_admin, created_at)
            VALUES (:bid, :uid, :comment, :is_admin, UTC_TIMESTAMP())
        ");

        $stmt->execute([
            'bid' => $bugId,
            'uid' => $userId,
            'comment' => $comment,
            'is_admin' => $isAdmin ? 1 : 0,
        ]);

        $this->db->prepare("UPDATE bug_reports SET updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$bugId]);
    }

    public function getComments(int $bugId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, u.email, u.name
            FROM bug_comments c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.bug_id = :bid
            ORDER BY c.created_at ASC
        ");
        $stmt->execute(['bid' => $bugId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
