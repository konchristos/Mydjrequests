<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/mail.php';

$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;

$error = '';
$success = '';

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$website = trim((string)($_POST['website'] ?? '')); // honeypot

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Invalid session. Please refresh and try again.';
    } elseif ($website !== '') {
        $error = 'Submission blocked.';
    } elseif ($name === '' || $email === '' || $message === '') {
        $error = 'Please complete name, email, and message.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($message) > 4000) {
        $error = 'Message is too long (max 4000 characters).';
    } else {
        try {
            $feedbackModel = new Feedback();
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

            $ipCount = $feedbackModel->countRecentPendingByIp($ip, 60);
            $emailCount = $feedbackModel->countRecentPendingByEmail($email, 60);

            if ($ipCount >= 5) {
                $error = 'Too many submissions from this IP. Please try again in about an hour.';
            } elseif ($emailCount >= 3) {
                $error = 'Too many submissions for this email right now. Please try again later.';
            } else {
                $token = $feedbackModel->createPublicVerification($name, $email, $message, $ip, $userAgent, 24);
                $verifyUrl = url('feedback_verify.php?token=' . urlencode($token));

                $subject = 'Verify your contact message';
                $html = "
                    <p>Hi " . e($name) . ",</p>
                    <p>Please confirm your contact message by clicking the button below:</p>
                    <p>
                        <a href='" . e($verifyUrl) . "' style='display:inline-block;background:#35b6ff;color:#03243a;text-decoration:none;padding:10px 14px;border-radius:8px;font-weight:700;'>
                            Verify Message
                        </a>
                    </p>
                    <p>This verification link expires in 24 hours.</p>
                    <p>If this was not you, you can ignore this email.</p>
                ";
                $text = "Please verify your contact message:\n\n{$verifyUrl}\n\nThis link expires in 24 hours.";

                if (!mdjr_send_mail($email, $subject, $html, $text)) {
                    $error = 'Could not send verification email right now. Please try again.';
                } else {
                    $success = 'Check your inbox. Please verify your email to submit your message.';
                    $name = '';
                    $email = '';
                    $message = '';
                }
            }
        } catch (Throwable $e) {
            $error = 'Could not send your message right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact | MyDJRequests</title>
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon-v2.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #060b12;
            --panel: rgba(12, 21, 33, 0.9);
            --line: rgba(149, 181, 216, 0.24);
            --text: #eef5ff;
            --muted: #9db1cb;
            --brand: #35b6ff;
            --brand-strong: #1e9fe8;
            --header-h: 56px;
            --nav-max: 1160px;
            --max: 860px;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: #070f19;
            color: var(--text);
            font-family: "Manrope", system-ui, -apple-system, Segoe UI, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .cyber-bg {
            position: fixed;
            inset: 0;
            z-index: -2;
            pointer-events: none;
        }

        .cyber-bg video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: saturate(1.08) contrast(1.06) brightness(.58);
        }

        .bg-wash {
            position: fixed;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 15% -10%, rgba(53, 182, 255, 0.22), transparent 44%),
                radial-gradient(circle at 85% -12%, rgba(45, 210, 190, 0.16), transparent 46%),
                linear-gradient(180deg, rgba(7, 12, 20, 0.35) 0%, rgba(6, 10, 17, 0.9) 76%);
        }

        header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(149, 181, 216, 0.2);
            background: rgba(6, 12, 20, 0.78);
            backdrop-filter: blur(9px);
        }

        .header-row {
            width: min(var(--nav-max), calc(100% - 28px));
            margin: 0 auto;
            min-height: var(--header-h);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 8px 0;
        }

        .brand img {
            height: 30px;
            display: block;
        }

        nav {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        nav a {
            color: #c9ddf4;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        nav a:hover { color: var(--brand); }

        .wrap {
            width: min(var(--max), calc(100% - 28px));
            margin: calc(var(--header-h) + 24px) auto 56px;
        }

        main.wrap {
            flex: 1;
        }

        h1 {
            margin: 0 0 12px;
            color: var(--brand);
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
            font-size: clamp(32px, 5vw, 46px);
        }

        .lead {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: 16px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 20px;
        }

        label {
            display: block;
            margin: 10px 0 6px;
            color: #d2e4f8;
            font-weight: 600;
            font-size: 14px;
        }

        input, textarea {
            width: 100%;
            background: rgba(11, 19, 30, 0.76);
            border: 1px solid var(--line);
            color: var(--text);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 15px;
        }

        input:focus, textarea:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(53, 182, 255, 0.18);
        }

        textarea {
            min-height: 160px;
            resize: vertical;
        }

        .btn {
            margin-top: 14px;
            border: 1px solid rgba(80, 180, 240, 0.85);
            border-radius: 9px;
            padding: 11px 16px;
            font-weight: 700;
            font-size: 14px;
            color: #03243a;
            background: linear-gradient(145deg, var(--brand), var(--brand-strong));
            cursor: pointer;
        }

        .err { color: #8dd8ff; margin: 0 0 10px; }
        .ok { color: #7de8bf; margin: 0 0 10px; }

        .hp {
            position: absolute;
            left: -9999px;
            opacity: 0;
            pointer-events: none;
        }

        footer {
            border-top: 1px solid rgba(149, 181, 216, 0.22);
            background: rgba(7, 13, 22, 0.72);
            color: #9ab1cb;
        }

        .footer-inner {
            width: min(var(--nav-max), calc(100% - 28px));
            margin: 0 auto;
            text-align: center;
            padding: 22px 0 34px;
            font-size: 13px;
        }

        @media (max-width: 720px) {
            :root { --header-h: 52px; }
            nav a { font-size: 13px; }
        }
    </style>
</head>
<body>
<div class="cyber-bg" aria-hidden="true">
    <video muted loop playsinline autoplay preload="auto">
        <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
        <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
    </video>
</div>
<div class="bg-wash" aria-hidden="true"></div>

<header>
    <div class="header-row">
        <a href="/" class="brand">
            <img src="/assets/logo/MYDJRequests_Logo-white.png" alt="MyDJRequests">
        </a>
        <nav>
            <?php if ($loggedIn): ?>
                <a href="/dj/dashboard.php">Dashboard</a>
                <a href="/dj/events.php">My Events</a>
                <a href="/plans.php">Pro vs Premium</a>
                <a href="/about.php">About</a>
                <a href="/contact.php">Contact</a>
                <a href="/dj/terms.php">Terms</a>
                <?php if ($adminUser): ?>
                    <a href="/admin/dashboard.php">Admin</a>
                <?php endif; ?>
                <a href="/dj/logout.php">Logout</a>
            <?php else: ?>
                <a href="/plans.php">Pro vs Premium</a>
                <a href="/about.php">About</a>
                <a href="/contact.php">Contact</a>
                <a href="/dj/login.php">DJ Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="wrap">
    <h1>Contact Us</h1>
    <p class="lead">Send us a message. We will email a verification link before it is submitted.</p>
    <section class="card">
        <?php if ($error): ?><p class="err"><?php echo e($error); ?></p><?php endif; ?>
        <?php if ($success): ?><p class="ok"><?php echo e($success); ?></p><?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">

            <label for="name">Name</label>
            <input id="name" name="name" type="text" maxlength="191" required value="<?php echo e($name); ?>">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="255" required value="<?php echo e($email); ?>">

            <label for="message">Message</label>
            <textarea id="message" name="message" maxlength="4000" required><?php echo e($message); ?></textarea>

            <button class="btn" type="submit">Send Message</button>
        </form>
    </section>
</main>

<footer>
    <div class="footer-inner">
        &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
    </div>
</footer>
</body>
</html>
