<?php
// public_html/privacy.php
require_once __DIR__ . '/app/bootstrap_public.php';

$pageTitle = 'Privacy Policy';
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo e($pageTitle); ?> | MYDJREQUESTS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            --bg: #070f19;
            --ink: #ecf4ff;
            --muted: #9db3cf;
            --line: rgba(151, 182, 216, 0.24);
            --panel: rgba(12, 21, 34, 0.9);
            --accent: #35b6ff;
            --radius: 16px;
            --max: 900px;
            --header-h: 56px;
            --nav-max: 1160px;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: "Manrope", "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.6;
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
                radial-gradient(900px 420px at 15% -8%, rgba(53, 182, 255, 0.18), transparent 72%),
                radial-gradient(900px 420px at 90% -5%, rgba(45, 210, 190, 0.14), transparent 74%),
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            min-height: var(--header-h);
            padding: 8px 0;
        }

        .brand img { height: 30px; display: block; }

        nav {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        nav a {
            text-decoration: none;
            color: #c9ddf4;
            font-weight: 600;
            font-size: 14px;
        }

        nav a:hover { color: var(--accent); }

        main.wrap {
            width: min(var(--max), calc(100% - 28px));
            margin: calc(var(--header-h) + 24px) auto 56px;
            flex: 1;
        }

        .privacy-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 24px;
        }

        .privacy-card h1 {
            margin: 0 0 6px;
            color: var(--ink);
            font-size: clamp(30px, 5vw, 44px);
            line-height: 1.12;
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
        }

        .privacy-card h2 {
            color: #e6f0fb;
            font-size: 20px;
            margin-top: 28px;
            margin-bottom: 10px;
            border-bottom: 1px solid rgba(151, 182, 216, 0.22);
            padding-bottom: 6px;
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
        }

        .privacy-card p,
        .privacy-card li {
            color: #c9d9ea;
            line-height: 1.62;
        }

        .privacy-card .meta {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 16px;
        }

        .privacy-card a {
            color: var(--accent);
            text-decoration: none;
        }

        .privacy-card a:hover {
            text-decoration: underline;
        }

        footer {
            border-top: 1px solid rgba(151, 182, 216, 0.2);
            color: #86a0bf;
            background: rgba(7, 13, 22, 0.72);
        }

        .footer-inner {
            width: min(var(--nav-max), calc(100% - 28px));
            margin: 0 auto;
            text-align: center;
            padding: 22px 0 34px;
            font-size: 13px;
        }

        @media (max-width: 820px) {
            :root { --header-h: 52px; }
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
    <div class="privacy-card">
        <h1>Privacy Policy</h1>

        <div class="meta">Last updated: February 8, 2026</div>


<h2>1. Introduction</h2>
<p>
    MYDJREQUESTS (“we”, “us”, “our”) is committed to protecting your privacy.
    This Privacy Policy explains how we collect, use, store, and disclose
    personal information when you use the MYDJREQUESTS platform (“Service”).
</p>
<p>
    We comply with the <strong>Privacy Act 1988 (Cth)</strong> and the
    <strong>Australian Privacy Principles (APPs)</strong>.
</p>

<h2>2. Personal Information We Collect</h2>
<ul>
    <li>Name and email address (DJ accounts)</li>
    <li>Account credentials and profile information</li>
    <li>IP address, device information, and browser type</li>
    <li>Cookies, session identifiers, and guest tokens</li>
    <li>Messages, song requests, votes, and event interaction data</li>
    <li>Support enquiries and communications</li>
</ul>
<p>
    We do not intentionally collect sensitive information.
</p>

<h2>3. How We Collect Personal Information</h2>
<ul>
    <li>When you create or manage an account</li>
    <li>When you use the Service or access event pages</li>
    <li>When you submit requests, messages, or feedback</li>
    <li>When you contact us for support</li>
    <li>Through cookies and similar technologies</li>
</ul>

<h2>4. How We Use Personal Information</h2>
<ul>
    <li>Operate, maintain, and improve the Service</li>
    <li>Authenticate users and secure accounts</li>
    <li>Enable event functionality and messaging</li>
    <li>Troubleshoot issues and monitor performance</li>
    <li>Comply with legal and regulatory obligations</li>
</ul>

<h2>5. Cookies</h2>
<p>
    We use cookies and similar technologies to maintain sessions,
    support core functionality, and improve user experience.
    Disabling cookies may affect Service functionality.
</p>

<h2>6. Disclosure of Personal Information</h2>
<ul>
    <li>Service providers (such as hosting and email providers)</li>
    <li>Payment providers (for example, Stripe, when enabled)</li>
    <li>Regulators, courts, or law enforcement where required by law</li>
</ul>
<p>
    We do not sell personal information.
</p>

<h2>7. Overseas Processing</h2>
<p>
    Some third-party service providers may process personal information
    outside Australia. We take reasonable steps to ensure appropriate
    safeguards are in place.
</p>

<h2>8. Data Security</h2>
<p>
    We take reasonable steps to protect personal information from misuse,
    loss, unauthorised access, modification, or disclosure.
    However, no system is completely secure.
</p>

<h2>9. Data Retention</h2>
<p>
    We retain personal information only for as long as reasonably necessary
    to operate the Service, comply with legal obligations, resolve disputes,
    and enforce our agreements.
</p>
<p>
    Information may be retained after account closure where required by law
    or for legitimate business purposes, including security, fraud prevention,
    and dispute resolution.
</p>

<h2>10. Children and Minors</h2>
<p>
    The Service is not directed at children under the age of 13.
    If we become aware that personal information has been collected from
    a child without appropriate consent, we will take reasonable steps
    to delete that information.
</p>

<h2>11. Logs and Analytics</h2>
<p>
    We may collect technical logs and usage data for security, analytics,
    performance monitoring, and troubleshooting purposes.
    This data may be used in identifiable or aggregated form where
    reasonably necessary to operate and protect the Service.
</p>

<h2>12. Access and Correction</h2>
<p>
    You may request access to, or correction of, your personal information
    by contacting us using the details below.
</p>

<h2>13. Complaints</h2>
<p>
    If you have a complaint about how we handle personal information,
    please contact us first so we can attempt to resolve it.
</p>
<p>
    If unresolved, you may lodge a complaint with the
    Office of the Australian Information Commissioner (OAIC).
</p>

<h2>14. Changes to This Policy</h2>
<p>
    We may update this Privacy Policy from time to time.
    The updated version will be published on this page with a revised
    “Last updated” date.
</p>

<h2>15. Contact</h2>
<p>
    For privacy-related enquiries or requests:<br>
    Email: <a href="mailto:info@mydjrequests.com">info@mydjrequests.com</a><br>
    MYDJREQUESTS (ABN 22 842 315 565)
</p>
    </div>
</main>

<footer>
    <div class="footer-inner">
        &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
    </div>
</footer>

</body>
</html>
