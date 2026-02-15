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

    public function ensurePublicVerificationTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS feedback_public_verifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                email VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                verified_at DATETIME DEFAULT NULL,
                verified_ip VARCHAR(45) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_feedback_public_token_hash (token_hash),
                KEY idx_feedback_public_email_created (email, created_at),
                KEY idx_feedback_public_ip_created (ip_address, created_at),
                KEY idx_feedback_public_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function countRecentPendingByIp(string $ip, int $minutes = 60): int
    {
        $this->ensurePublicVerificationTable();
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM feedback_public_verifications
            WHERE ip_address = :ip
              AND created_at >= (UTC_TIMESTAMP() - INTERVAL :mins MINUTE)
        ");
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':mins', max(1, $minutes), PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function countRecentPendingByEmail(string $email, int $minutes = 60): int
    {
        $this->ensurePublicVerificationTable();
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM feedback_public_verifications
            WHERE email = :email
              AND created_at >= (UTC_TIMESTAMP() - INTERVAL :mins MINUTE)
        ");
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':mins', max(1, $minutes), PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function createPublicVerification(
        string $name,
        string $email,
        string $message,
        string $ip,
        string $userAgent,
        int $expiresHours = 24
    ): string {
        $this->ensurePublicVerificationTable();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $stmt = $this->db->prepare("
            INSERT INTO feedback_public_verifications
                (name, email, message, ip_address, user_agent, token_hash, expires_at, created_at)
            VALUES
                (:name, :email, :message, :ip, :ua, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :hours HOUR), UTC_TIMESTAMP())
        ");
        $stmt->bindValue(':name', mb_substr($name, 0, 191));
        $stmt->bindValue(':email', mb_substr($email, 0, 255));
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':ip', mb_substr($ip, 0, 45));
        $stmt->bindValue(':ua', mb_substr($userAgent, 0, 255));
        $stmt->bindValue(':token_hash', $tokenHash);
        $stmt->bindValue(':hours', max(1, $expiresHours), PDO::PARAM_INT);
        $stmt->execute();

        return $token;
    }

    public function verifyPublicTokenAndCreateFeedback(string $rawToken, string $verifiedIp): array
    {
        $this->ensurePublicVerificationTable();

        if ($rawToken === '') {
            return ['ok' => false, 'message' => 'Verification link is invalid.'];
        }

        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->db->prepare("
            SELECT *
            FROM feedback_public_verifications
            WHERE token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['ok' => false, 'message' => 'Verification link is invalid or already used.'];
        }

        if (!empty($row['verified_at'])) {
            return ['ok' => false, 'message' => 'This feedback has already been verified.'];
        }

        if (strtotime((string)$row['expires_at'] . ' UTC') < time()) {
            return ['ok' => false, 'message' => 'Verification link has expired. Please submit feedback again.'];
        }

        try {
            $this->db->beginTransaction();

            $insert = $this->db->prepare("
                INSERT INTO feedback (user_id, name, email, message, ip_address, created_at)
                VALUES (NULL, :name, :email, :message, :ip, UTC_TIMESTAMP())
            ");
            $insert->execute([
                'name' => $row['name'],
                'email' => $row['email'],
                'message' => $row['message'],
                'ip' => $verifiedIp !== '' ? $verifiedIp : (string)$row['ip_address'],
            ]);
            $feedbackId = (int)$this->db->lastInsertId();

            $mark = $this->db->prepare("
                UPDATE feedback_public_verifications
                SET verified_at = UTC_TIMESTAMP(),
                    verified_ip = :vip
                WHERE id = :id
                LIMIT 1
            ");
            $mark->execute([
                'vip' => mb_substr($verifiedIp, 0, 45),
                'id' => (int)$row['id'],
            ]);

            $this->db->commit();

            return [
                'ok' => true,
                'feedback_id' => $feedbackId,
                'name' => (string)$row['name'],
                'email' => (string)$row['email'],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'message' => 'Could not verify feedback right now. Please try again.'];
        }
    }
}
