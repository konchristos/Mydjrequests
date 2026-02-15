<?php
// app/models/User.php

class User extends BaseModel
{
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

public function create(
    string $email,
    string $password,
    string $name,
    ?string $djName,
    string $countryCode,
    ?string $city,
    string $trialEndsAt
): int {

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $uuid = $this->generateUuid();

    $stmt = $this->db->prepare('
        INSERT INTO users (
            uuid,
            email,
            password_hash,
            name,
            dj_name,
            country_code,
            city,
            subscription,
            trial_ends_at
        ) VALUES (
            :uuid,
            :email,
            :password_hash,
            :name,
            :dj_name,
            :country_code,
            :city,
            "free",
            :trial_ends_at
        )
    ');

    $stmt->execute([
        'uuid'          => $uuid,
        'email'         => $email,
        'password_hash'=> $hash,
        'name'          => $name,
        'dj_name'       => $djName,
        'country_code'  => $countryCode,
        'city'          => $city,
        'trial_ends_at' => $trialEndsAt,
    ]);

    return (int) $this->db->lastInsertId();
}

    /**
     * Generate a password reset token for this user (by email).
     * Stores a SHA‑256 hash in users.reset_token and an expiry in reset_expires.
     * Returns the raw token to be emailed, or null if user not found.
     */
    public function createResetToken(string $email): ?string
    {
        $user = $this->findByEmail($email);
        if (!$user) {
            return null;
        }

        $rawToken = bin2hex(random_bytes(32)); // 64-char string
        $tokenHash = hash('sha256', $rawToken);

        $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))
    ->modify('+15 minutes')
    ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare('
            UPDATE users
            SET reset_token = :token_hash,
                reset_expires = :expires
            WHERE id = :id
        ');

        $stmt->execute([
            'token_hash' => $tokenHash,
            'expires'    => $expiresAt,
            'id'         => $user['id'],
        ]);

        return $rawToken;
    }

    /**
     * Look up a user by reset token if it's valid (not expired).
     */
    public function findByResetToken(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        $stmt = $this->db->prepare('
            SELECT *
            FROM users
            WHERE reset_token = :token_hash
              AND reset_expires IS NOT NULL
              AND reset_expires > NOW()
            LIMIT 1
        ');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Update a user's password and clear any reset token.
     */
    
public function updateEmail(int $id, string $email): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET email = :email, is_verified = 0
        WHERE id = :id
    ");
    $stmt->execute([
        'email' => $email,
        'id' => $id,
    ]);
}

public function updatePassword(int $id, string $newPassword): bool
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $this->db->prepare('
            UPDATE users
            SET password_hash = :hash,
                reset_token   = NULL,
                reset_expires = NULL
            WHERE id = :id
        ');

        return $stmt->execute([
            'hash' => $hash,
            'id'   => $id,
        ]);
    }

    protected function generateUuid(): string
    {
        // Simple UUID v4 generator
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }



public function createEmailVerificationToken(int $userId): string
{
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $this->db->prepare('
        UPDATE users
        SET email_verify_token = :token
        WHERE id = :id
    ');

    $stmt->execute([
        'token' => $tokenHash,
        'id'    => $userId
    ]);

    return $rawToken;
}



public function setEmailVerificationToken(int $id, string $token): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET email_verify_token = :token
        WHERE id = :id
    ");
    $stmt->execute([
        'token' => hash('sha256', $token),
        'id'    => $id,
    ]);
}

public function findByEmailVerificationToken(string $rawToken): ?array
{
    if ($rawToken === '') {
        return null;
    }

    $hash = hash('sha256', $rawToken);

    $stmt = $this->db->prepare("
        SELECT *
        FROM users
        WHERE email_verify_token = :hash
          AND is_verified = 0
        LIMIT 1
    ");
    $stmt->execute(['hash' => $hash]);
    return $stmt->fetch() ?: null;
}

public function markEmailVerified(int $id): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET is_verified = 1,
            email_verify_token = NULL,
            email_verified_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute(['id' => $id]);
}


public function updateActiveSession(int $userId, string $sessionId): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET active_session_id = ?, session_updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$sessionId, $userId]);
}

public function clearActiveSession(int $userId): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET active_session_id = NULL
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
}


public function getTrustedDevices(int $userId): array
{
    $stmt = $this->db->prepare("
        SELECT
            id,
            device_hash,
            device_label,
            ip_address,
            country_code,
            created_at,
            last_used_at,
            expires_at
        FROM user_trusted_devices
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function revokeTrustedDevice(int $userId, int $deviceId): void
{
    $stmt = $this->db->prepare("
        DELETE FROM user_trusted_devices
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$deviceId, $userId]);
}

public function revokeAllTrustedDevices(int $userId): void
{
    $stmt = $this->db->prepare("
        DELETE FROM user_trusted_devices
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
}


public function updateTimezone(int $userId, string $timezone): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET timezone = :tz
        WHERE id = :id
    ");
    $stmt->execute([
        'tz' => $timezone,
        'id' => $userId,
    ]);
}


public function replaceRecoveryCodes(int $userId, array $codes): void
{
    $db = $this->db;
    $db->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$userId]);

    $stmt = $db->prepare("
        INSERT INTO user_recovery_codes (user_id, code_hash, created_at)
        VALUES (:uid, :hash, UTC_TIMESTAMP())
    ");

    foreach ($codes as $code) {
        $hash = hash('sha256', $code);
        $stmt->execute([
            'uid' => $userId,
            'hash' => $hash,
        ]);
    }
}

public function useRecoveryCode(int $userId, string $code): bool
{
    $hash = hash('sha256', $code);
    $stmt = $this->db->prepare("
        SELECT id FROM user_recovery_codes
        WHERE user_id = :uid AND code_hash = :hash AND used_at IS NULL
        LIMIT 1
    ");
    $stmt->execute(['uid' => $userId, 'hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $this->db->prepare("UPDATE user_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?")
        ->execute([(int)$row['id']]);

    return true;
}

public function getRecentLogins(int $userId, int $limit = 3): array
{
    $stmt = $this->db->prepare("
        SELECT
            ip_address,
            country_code,
            device_label,
            trusted_device,
            created_at
        FROM user_login_audit
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT :lim
    ");

    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function recordFailedLogin(int $userId): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET
            failed_login_attempts = failed_login_attempts + 1,
            last_failed_login = NOW(),
            locked_until = CASE
                WHEN failed_login_attempts + 1 >= 5
                THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                ELSE locked_until
            END
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
}

public function resetLoginFailures(int $userId): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET
            failed_login_attempts = 0,
            last_failed_login = NULL,
            locked_until = NULL
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
}

public function renewTrial(int $userId, int $days): void
{
    $days = max(1, (int)$days);

    // Use UTC to keep it consistent with other timestamps
    $sql = "
        UPDATE users
        SET
            trial_ends_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$days} DAY),
            subscription_status = 'trial'
        WHERE id = :id
    ";

    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $userId]);
}

public function acceptTerms(int $userId, string $version): void
{
    $stmt = $this->db->prepare("
        UPDATE users
        SET
            terms_accepted_at = UTC_TIMESTAMP(),
            terms_accepted_version = :version
        WHERE id = :id
    ");

    $stmt->execute([
        'version' => $version,
        'id'      => $userId,
    ]);
}



/*---------------------
//ADMIN USERS
---------------------*/


public function getAllUsers(): array
{
    $sql = "
        SELECT
            u.id,
            u.uuid,
            u.email,
            u.country_code,
            u.city,
            u.subscription_status,
            u.created_at,
            u.session_updated_at,
            s.plan AS subscription_plan,
            s.status AS subscription_status_current,
            s.renews_at AS subscription_renews_at
        FROM users u
        LEFT JOIN subscriptions s
            ON s.user_id = u.id
        ORDER BY u.created_at DESC
    ";

    $stmt = $this->db->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



}