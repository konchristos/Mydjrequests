<?php
// app/controllers/AuthController.php

class AuthController extends BaseController
{
    protected User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

public function register(array $data): array
{
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $name     = trim($data['name'] ?? '');
    $djName   = trim($data['dj_name'] ?? '');
    $country  = trim($data['country'] ?? '');
    $city     = trim($data['city'] ?? '');
    $djSoftware = strtolower(trim((string)($data['dj_software'] ?? '')));
    $djSoftwareOther = trim((string)($data['dj_software_other'] ?? ''));
    $hasSpotify = !empty($data['sub_spotify']) ? 1 : 0;
    $hasAppleMusic = !empty($data['sub_apple_music']) ? 1 : 0;
    $hasBeatport = !empty($data['sub_beatport']) ? 1 : 0;

    if ($email === '' || $password === '' || $name === '' || $country === '' || $djSoftware === '') {
        return ['success' => false, 'message' => 'Missing required fields.'];
    }

    $allowedSoftware = ['rekordbox', 'serato', 'traktor', 'virtualdj', 'djay', 'other'];
    if (!in_array($djSoftware, $allowedSoftware, true)) {
        return ['success' => false, 'message' => 'Please select a valid DJ software option.'];
    }
    if ($djSoftware === 'other' && $djSoftwareOther === '') {
        return ['success' => false, 'message' => 'Please specify your DJ software when selecting Other.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address.'];
    }

    if ($this->userModel->findByEmail($email)) {
        return ['success' => false, 'message' => 'Email already in use.'];
    }

    // Trial config
    $config = require __DIR__ . '/../config/subscriptions.php';
    $trialDays = (int)($config['trial_days'] ?? 30);

    $trialEndsAt = (new DateTime())
        ->modify("+{$trialDays} days")
        ->format('Y-m-d H:i:s');

    // Create user (UNVERIFIED)
    $userId = $this->userModel->create(
        $email,
        $password,
        $name,
        $djName ?: null,
        $country,
        $city ?: null,
        $trialEndsAt
    );

    // Save onboarding profile answers for future product personalization.
    $this->saveUserOnboardingProfile(
        $userId,
        $djSoftware,
        $djSoftware === 'other' ? $djSoftwareOther : null,
        $hasSpotify,
        $hasAppleMusic,
        $hasBeatport
    );

    // Create initial trial subscription record.
    $subscriptionModel = new Subscription();
    $subscriptionModel->createFree($userId, $trialDays);

    // Generate email verification token
    $rawToken = $this->userModel->createEmailVerificationToken($userId);

    // Send verification email
    require_once __DIR__ . '/../mail.php';

    $verifyUrl = mdjr_url('dj/verify_email.php?token=' . urlencode($rawToken));

    $subject = 'Verify your MyDJRequests account';

    $html = "
        <p>Hi {$name},</p>
        <p>Thanks for signing up to <strong>MyDJRequests</strong>.</p>
        <p>Please verify your email address to activate your account:</p>
        <p>
            <a href='{$verifyUrl}' style='
                display:inline-block;
                padding:12px 20px;
                background:#ff2fd2;
                color:#ffffff;
                text-decoration:none;
                border-radius:6px;
            '>Verify Email</a>
        </p>
        <p>If the button doesn’t work, copy this link:</p>
        <p><a href='{$verifyUrl}'>{$verifyUrl}</a></p>
        <p>— MyDJRequests Team</p>
    ";

    $text = "Verify your MyDJRequests account:\n\n{$verifyUrl}";

    mdjr_send_mail($email, $subject, $html, $text);

    return [
        'success' => true,
        'message' => 'Registration successful. Please check your email to verify your account.'
    ];
}

private function ensureUserOnboardingProfileTable(): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS user_onboarding_profiles (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            dj_software VARCHAR(32) NOT NULL,
            dj_software_other VARCHAR(255) DEFAULT NULL,
            has_spotify TINYINT(1) NOT NULL DEFAULT 0,
            has_apple_music TINYINT(1) NOT NULL DEFAULT 0,
            has_beatport TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    db()->exec($sql);
}

private function saveUserOnboardingProfile(
    int $userId,
    string $djSoftware,
    ?string $djSoftwareOther,
    int $hasSpotify,
    int $hasAppleMusic,
    int $hasBeatport
): void {
    $this->ensureUserOnboardingProfileTable();

    $stmt = db()->prepare("
        INSERT INTO user_onboarding_profiles
            (user_id, dj_software, dj_software_other, has_spotify, has_apple_music, has_beatport, created_at, updated_at)
        VALUES
            (:user_id, :dj_software, :dj_software_other, :has_spotify, :has_apple_music, :has_beatport, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            dj_software = VALUES(dj_software),
            dj_software_other = VALUES(dj_software_other),
            has_spotify = VALUES(has_spotify),
            has_apple_music = VALUES(has_apple_music),
            has_beatport = VALUES(has_beatport),
            updated_at = UTC_TIMESTAMP()
    ");

    $stmt->execute([
        'user_id' => $userId,
        'dj_software' => substr($djSoftware, 0, 32),
        'dj_software_other' => $djSoftwareOther ? substr($djSoftwareOther, 0, 255) : null,
        'has_spotify' => $hasSpotify ? 1 : 0,
        'has_apple_music' => $hasAppleMusic ? 1 : 0,
        'has_beatport' => $hasBeatport ? 1 : 0,
    ]);
}



public function login(array $data): array
{
    
        // 🚦 IP-based rate limiting (DDOS protection)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    require_once APP_ROOT . '/app/helpers/rate_limit.php';

    if (!mdjr_check_rate_limit($ip)) {
        return [
            'success' => false,
            'code'    => 'RATE_LIMITED',
            'message' => 'Too many login attempts. Please wait a moment.'
        ];
    }
    
    
    
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if ($email === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'Missing email or password.'
        ];
    }

  $user = $this->userModel->findByEmail($email);

    $recoveryCode = trim($data['recovery_code'] ?? '');
    if ($recoveryCode !== '') {
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid recovery code.'
            ];
        }

        if (!$this->userModel->useRecoveryCode((int)$user['id'], $recoveryCode)) {
            return [
                'success' => false,
                'message' => 'Invalid recovery code.'
            ];
        }

        // Login using recovery code (single-session)
        $sessionId = bin2hex(random_bytes(32));
        $_SESSION['dj_id']      = (int)$user['id'];
        $_SESSION['dj_email']   = $user['email'];
        $_SESSION['dj_name']    = $user['name'];
        $_SESSION['dj_alias']   = $user['dj_name'] ?? '';
        $_SESSION['session_id'] = $sessionId;
        $_SESSION['force_email_update'] = true;

        $this->userModel->updateActiveSession((int)$user['id'], $sessionId);

        return [
            'success'  => true,
            'recovery' => true
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | 1️⃣ Account lock check (BEFORE password verify)
    |--------------------------------------------------------------------------
    */
if ($user && !empty($user['locked_until'])) {
    $lockedUntilTs = strtotime($user['locked_until'] . ' UTC');
    $now = time();

    if ($lockedUntilTs > $now) {
        return [
            'success'  => false,
            'code'     => 'ACCOUNT_LOCKED',
            'retry_in' => $lockedUntilTs - $now
        ];
    }

    // 🔓 Lock expired → clean up
    $this->userModel->resetLoginFailures((int)$user['id']);
}
    
    /*
    |--------------------------------------------------------------------------
    | 2️⃣ Password verification
    |--------------------------------------------------------------------------
    */
    if (!$user || !password_verify($password, $user['password_hash'])) {
    
        if ($user) {
            $this->userModel->recordFailedLogin((int)$user['id']);
        }
    
        return [
            'success' => false,
            'message' => 'Invalid login credentials.'
        ];
    }
    
    /*
    |--------------------------------------------------------------------------
    | 3️⃣ Email verification check  ✅ MUST be BEFORE reset
    |--------------------------------------------------------------------------
    */
    if ((int)($user['is_verified'] ?? 0) !== 1) {
        return [
            'success' => false,
            'code'    => 'EMAIL_NOT_VERIFIED',
            'message' => 'Your email address has not been verified yet.'
        ];
    }
    
    /*
    |--------------------------------------------------------------------------
    | 4️⃣ NOW (and only now) reset login failures
    |--------------------------------------------------------------------------
    */
    $this->userModel->resetLoginFailures((int)$user['id']);




    // -------------------------------------------------
    // 🔐 CHECK TRUSTED DEVICE (SKIP 2FA IF VALID)
    // -------------------------------------------------
    require_once APP_ROOT . '/app/helpers/trusted_device.php';

    if (is_trusted_device((int)$user['id'])) {

        // ✅ Trusted device → log in immediately
        $sessionId = bin2hex(random_bytes(32));

        $_SESSION['dj_id']      = (int)$user['id'];
        $_SESSION['dj_email']   = $user['email'];
        $_SESSION['dj_name']    = $user['name'];
        $_SESSION['dj_alias']   = $user['dj_name'] ?? '';
        $_SESSION['session_id'] = $sessionId;

        // 🔥 Enforce single-session rule
        $this->userModel->updateActiveSession((int)$user['id'], $sessionId);
        
        // 🧾 LOGIN AUDIT — trusted device (no 2FA)
        require_once APP_ROOT . '/app/helpers/login_audit.php';
        mdjr_log_login(
            (int)$user['id'],
            true,
            $sessionId
        );

        return [
            'success'     => true,
            'skipped_2fa' => true
        ];
    }

    // -------------------------------------------------
    // 🔐 NOT TRUSTED → REQUIRE EMAIL 2FA
    // -------------------------------------------------

    $_SESSION['pending_2fa_user'] = (int)$user['id'];

    // Clear resend timer so page load does NOT auto-send again
    unset($_SESSION['last_2fa_email_sent']);

    // Send initial 2FA code
    require_once APP_ROOT . '/app/helpers/email_2fa.php';
    send_email_2fa_code((int)$user['id'], $user);

    // Start resend cooldown timer
    $_SESSION['last_2fa_email_sent'] = time();

    return [
        'success'      => true,
        'requires_2fa' => true
    ];
}

   


    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
    
    
}
