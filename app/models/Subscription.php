<?php
// app/models/Subscription.php

class Subscription extends BaseModel
{
    private function syncTrialEndsAtToLatestRenewsAt(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE users u
            JOIN (
                SELECT user_id, renews_at
                FROM subscriptions
                WHERE user_id = :uid
                ORDER BY id DESC
                LIMIT 1
            ) s ON s.user_id = u.id
            SET
                u.trial_ends_at = s.renews_at
            WHERE u.id = :uid2
        ");

        $stmt->execute([
            'uid' => $userId,
            'uid2' => $userId,
        ]);
    }

    public function findLatestByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM subscriptions
            WHERE user_id = :uid
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createFree(int $userId): void
    {
        // Anchor trial renewal to user registration date (same day next month)
        $stmt = $this->db->prepare("
            INSERT INTO subscriptions (user_id, plan, status, renews_at, created_at)
            SELECT id, 'trial', 'active', DATE_ADD(created_at, INTERVAL 1 MONTH), UTC_TIMESTAMP()
            FROM users
            WHERE id = :uid
        ");

        $stmt->execute(['uid' => $userId]);
        $this->syncTrialEndsAtToLatestRenewsAt($userId);
    }

    public function renewFree(int $subscriptionId, string $currentRenewsAt): void
    {
        // Advance renews_at by 1 month from its current value
        $stmt = $this->db->prepare("
            UPDATE subscriptions
            SET
                plan = 'trial',
                status = 'active',
                renews_at = DATE_ADD(:renews_at, INTERVAL 1 MONTH)
            WHERE id = :id
        ");
        $stmt->execute([
            'renews_at' => $currentRenewsAt,
            'id' => $subscriptionId,
        ]);
    }

    public function ensureFreeActive(int $userId): void
    {
        $row = $this->findLatestByUserId($userId);

        if (!$row) {
            $this->createFree($userId);
            return;
        }

        $renewsAt = $row['renews_at'] ?? null;
        $status = strtolower((string)($row['status'] ?? ''));
        $isActive = ($status === 'active');

        if (empty($renewsAt) || !$isActive || ($row['plan'] ?? '') !== 'trial') {
            // If renews_at missing or inactive, reset to next month from NOW as fallback
            $this->db->prepare("UPDATE subscriptions SET renews_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH), status='active', plan='trial' WHERE id = ?")
                ->execute([(int)$row['id']]);
            $this->syncTrialEndsAtToLatestRenewsAt($userId);
            return;
        }

        // If expired, advance month-by-month until in the future
        $current = $renewsAt;
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $next = new DateTime($current, new DateTimeZone('UTC'));

        if ($next < $now) {
            while ($next < $now) {
                $next->modify('+1 month');
            }

            $stmt = $this->db->prepare("
                UPDATE subscriptions
                SET renews_at = :renews_at, status = 'active', plan = 'trial'
                WHERE id = :id
            ");
            $stmt->execute([
                'renews_at' => $next->format('Y-m-d H:i:s'),
                'id' => (int)$row['id'],
            ]);
        }

        $this->syncTrialEndsAtToLatestRenewsAt($userId);
    }
}
