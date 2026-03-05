<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDJRequests | Premium Hybrid Dark</title>

    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon-v2.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #060b12;
            --ink: #eef5ff;
            --muted: #9db1cb;
            --line: rgba(149, 181, 216, 0.24);
            --panel: rgba(12, 21, 33, 0.86);
            --panel-strong: rgba(10, 17, 28, 0.95);
            --accent: #35b6ff;
            --accent-strong: #1e9fe8;
            --teal: #2ad4be;
            --gold: #ffc76f;
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 12px;
            --max: 1160px;
            --shadow: 0 30px 78px rgba(0, 0, 0, 0.42);
            --city-opacity: 0.08;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: "Manrope", "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.58;
            overflow-x: hidden;
        }

        .bg-gradient {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -4;
            background:
                radial-gradient(1200px 520px at 15% -10%, rgba(53, 182, 255, 0.18), transparent 72%),
                radial-gradient(900px 460px at 88% -8%, rgba(42, 212, 190, 0.16), transparent 75%),
                linear-gradient(180deg, #070f19 0%, #05090f 100%);
        }

        .city-bg {
            position: fixed;
            inset: 0;
            z-index: -3;
            pointer-events: none;
            opacity: var(--city-opacity);
            transition: opacity 0.7s ease;
        }

        .city-bg video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scale(1.03);
            filter: brightness(0.74) saturate(1.1) contrast(1.06);
        }

        .city-wash {
            position: fixed;
            inset: 0;
            z-index: -2;
            pointer-events: none;
            background:
                radial-gradient(900px 520px at 20% 12%, rgba(107, 21, 162, 0.27), transparent 70%),
                radial-gradient(900px 520px at 84% 18%, rgba(17, 119, 159, 0.24), transparent 72%),
                linear-gradient(180deg, rgba(4, 8, 13, 0.4) 0%, rgba(5, 8, 14, 0.88) 100%);
        }

        .grid-overlay {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            opacity: 0.24;
            background-image:
                linear-gradient(rgba(142, 175, 210, 0.16) 1px, transparent 1px),
                linear-gradient(90deg, rgba(142, 175, 210, 0.14) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, 0.7), transparent 82%);
        }

        .wrap { width: min(var(--max), calc(100% - 40px)); margin: 0 auto; }

        header {
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(149, 181, 216, 0.2);
            background: rgba(6, 12, 20, 0.78);
            backdrop-filter: blur(9px);
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 13px 0;
        }

        .brand img { height: 34px; display: block; }

        nav { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: flex-end; }

        nav a {
            text-decoration: none;
            color: #c7dbf1;
            font-size: 14px;
            font-weight: 600;
        }

        nav a:hover { color: var(--accent); }

        h1, h2, h3 {
            margin: 0;
            line-height: 1.14;
            letter-spacing: -0.02em;
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
        }

        h1 { font-size: clamp(36px, 6vw, 58px); }
        h2 { font-size: clamp(30px, 4.6vw, 44px); margin-bottom: 14px; }
        h3 { font-size: 23px; margin-bottom: 9px; }

        .hero { padding: 82px 0 52px; }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 26px;
            align-items: center;
        }

        .eyebrow {
            display: inline-block;
            color: #bfe9ff;
            background: rgba(27, 89, 142, 0.3);
            border: 1px solid rgba(87, 160, 224, 0.5);
            border-radius: 999px;
            padding: 6px 11px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .hero p { font-size: 18px; color: var(--muted); max-width: 620px; margin: 14px 0 28px; }

        .cta-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }

        .btn {
            display: inline-block;
            padding: 13px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
            border: 1px solid transparent;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .btn:hover { transform: translateY(-1px); box-shadow: 0 10px 18px rgba(25, 140, 219, 0.2); }

        .btn-primary {
            background: linear-gradient(150deg, var(--accent), var(--accent-strong));
            color: #032033;
            border-color: rgba(67, 182, 255, 0.85);
        }

        .btn-secondary {
            background: rgba(11, 19, 30, 0.85);
            color: #dbeaff;
            border-color: rgba(149, 181, 216, 0.35);
        }

        .hero-media {
            background: linear-gradient(160deg, rgba(14, 25, 40, 0.86), rgba(8, 15, 24, 0.94));
            border: 1px solid var(--line);
            border-radius: var(--radius-xl);
            padding: 16px;
            box-shadow: var(--shadow);
        }

        .hero-media img {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(149, 181, 216, 0.25);
            display: block;
        }

        .hero-metric { margin-top: 12px; display: grid; gap: 10px; grid-template-columns: repeat(3, 1fr); }

        .metric {
            background: rgba(12, 23, 36, 0.86);
            border: 1px solid rgba(149, 181, 216, 0.24);
            border-radius: 12px;
            padding: 11px 10px;
            text-align: center;
        }

        .metric strong { display: block; font-size: 21px; color: #c9f3ff; }
        .metric span { font-size: 12px; color: #8fa7c2; }

        section { padding: 44px 0; }

        .section-sub { color: var(--muted); max-width: 740px; margin: 0 0 22px; font-size: 17px; }

        .image-grid { display: grid; gap: 16px; grid-template-columns: repeat(3, minmax(0, 1fr)); }

        .image-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.28);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            backdrop-filter: blur(2px);
        }

        .image-card:hover {
            transform: translateY(-3px);
            border-color: rgba(53, 182, 255, 0.5);
            box-shadow: 0 18px 34px rgba(0, 0, 0, 0.34);
        }

        .image-card img {
            width: 100%;
            aspect-ratio: 16 / 10;
            object-fit: cover;
            display: block;
        }

        .image-body { padding: 14px 14px 16px; }
        .image-body h3 { font-size: 19px; margin-bottom: 7px; }
        .image-body p { margin: 0; font-size: 14px; color: #98afc9; }

        .persona-grid { display: grid; gap: 14px; grid-template-columns: repeat(3, minmax(0, 1fr)); }

        .persona-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 18px;
            backdrop-filter: blur(2px);
            position: relative;
            overflow: hidden;
        }

        .persona-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 0.28s ease;
            filter: brightness(0.62) saturate(1.08);
            pointer-events: none;
        }

        .persona-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(8, 15, 26, 0.28), rgba(6, 11, 18, 0.78));
            opacity: 0;
            transition: opacity 0.28s ease;
            z-index: 1;
            pointer-events: none;
        }

        .persona-card.video-active .persona-video,
        .persona-card.video-active::before {
            opacity: 1;
        }

        .persona-content {
            position: relative;
            z-index: 2;
        }

        .persona-tag {
            display: inline-block;
            border: 1px solid rgba(53, 182, 255, 0.4);
            color: #bde8ff;
            background: rgba(28, 106, 171, 0.25);
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 10px;
            margin-bottom: 10px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .persona-card h3 { font-size: 20px; margin-bottom: 8px; }
        .persona-card p { margin: 0 0 10px; color: #9ab1ca; font-size: 14px; }
        .persona-card ul { margin: 0; padding-left: 18px; color: #9ab1ca; }
        .persona-card li + li { margin-top: 6px; }

        .flow-wrap { display: grid; gap: 14px; grid-template-columns: repeat(4, minmax(0, 1fr)); }

        .flow {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 16px;
            backdrop-filter: blur(2px);
        }

        .flow-badge {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(150deg, var(--accent), #168dd9);
            color: #03263a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .flow h3 { font-size: 17px; margin-bottom: 7px; }
        .flow p { margin: 0; font-size: 14px; color: #94abc6; }

        .split { display: grid; gap: 18px; grid-template-columns: 1.1fr 0.9fr; align-items: center; }

        .panel {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 22px;
            backdrop-filter: blur(2px);
        }

        .panel ul { margin: 10px 0 0; padding-left: 20px; color: #9bb1c9; }
        .panel li + li { margin-top: 7px; }

        .feature-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .feature-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 16px;
            backdrop-filter: blur(2px);
        }

        .feature-card h3 {
            font-size: 18px;
            margin-bottom: 7px;
        }

        .feature-card p {
            margin: 0;
            color: #97aec9;
            font-size: 14px;
        }

        .qr-block {
            background: linear-gradient(150deg, rgba(13, 24, 39, 0.95), rgba(9, 16, 27, 0.95));
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 20px;
            text-align: center;
            backdrop-filter: blur(2px);
        }

        .qr-block img {
            width: min(170px, 100%);
            border-radius: 12px;
            border: 1px solid rgba(149, 181, 216, 0.28);
            background: #fff;
            padding: 10px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.28);
        }

        .trust {
            padding: 16px 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 199, 111, 0.45);
            background: linear-gradient(150deg, rgba(61, 44, 16, 0.72), rgba(47, 34, 14, 0.65));
            color: #ffdca6;
            font-weight: 600;
            margin-top: 16px;
        }

        .cta {
            max-width: 980px;
            margin: 26px auto 56px;
            background: linear-gradient(160deg, rgba(12, 25, 41, 0.95), rgba(8, 16, 28, 0.95));
            border: 1px solid rgba(102, 158, 215, 0.38);
            border-radius: var(--radius-xl);
            padding: 32px 26px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .cta p { margin: 9px auto 22px; max-width: 700px; color: #9db2cd; }
        .fine { margin-top: 14px; color: #7f98b6; font-size: 13px; }

        .reassure {
            background: linear-gradient(150deg, rgba(12, 22, 35, 0.92), rgba(8, 14, 23, 0.92));
            border: 1px solid rgba(149, 181, 216, 0.22);
            border-radius: 14px;
            padding: 14px 16px;
            margin-top: 14px;
            color: #bbcee6;
            font-size: 14px;
            text-align: center;
        }

        footer {
            border-top: 1px solid rgba(149, 181, 216, 0.2);
            color: #7e97b4;
            text-align: center;
            padding: 22px 0 34px;
            font-size: 13px;
        }

        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.55s ease, transform 0.55s ease;
        }

        .reveal.visible { opacity: 1; transform: none; }

        @media (max-width: 1000px) {
            .hero-grid, .split { grid-template-columns: 1fr; }
            .image-grid { grid-template-columns: 1fr 1fr; }
            .flow-wrap { grid-template-columns: 1fr 1fr; }
            .persona-grid { grid-template-columns: 1fr 1fr; }
            .feature-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 767px) {
            .city-bg, .city-wash { display: none; }
        }

        @media (max-width: 680px) {
            .wrap { width: min(var(--max), calc(100% - 26px)); }
            nav { gap: 10px; }
            nav a { font-size: 13px; }
            .hero { padding-top: 60px; }
            .hero p { font-size: 16px; }
            .image-grid, .flow-wrap, .hero-metric { grid-template-columns: 1fr; }
            .persona-grid, .feature-grid { grid-template-columns: 1fr; }
        }

        @media (pointer: coarse) {
            .persona-video {
                opacity: 0.35;
                filter: brightness(0.7) saturate(1.05);
            }

            .persona-card.video-active .persona-video,
            .persona-card.video-active::before {
                opacity: 1;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1; transform: none; transition: none; }
            .btn, .image-card { transition: none; }
        }
    </style>
</head>
<body>
<?php
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;

if (!function_exists('mdjr_esc')) {
    function mdjr_esc($value) {
        if (function_exists('e')) {
            return e($value);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
?>

<div class="bg-gradient" aria-hidden="true"></div>
<div class="city-bg" aria-hidden="true">
    <video muted loop playsinline preload="auto">
        <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
        <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
    </video>
</div>
<div class="city-wash" aria-hidden="true"></div>
<div class="grid-overlay" aria-hidden="true"></div>

<header>
    <div class="wrap header-row">
        <a href="/" class="brand">
            <img src="/assets/logo/MYDJRequests_Logo-white.png" alt="MyDJRequests">
        </a>
        <nav>
            <?php if ($loggedIn): ?>
                <a href="/dj/dashboard.php">Dashboard</a>
                <a href="/dj/events.php">My Events</a>
                <a href="/plans.php">Pro vs Premium</a>
                <a href="/about.php">About</a>
                <a href="/dj/terms.php">Terms</a>
                <?php if ($adminUser): ?>
                    <a href="/admin/dashboard.php">Admin</a>
                <?php endif; ?>
                <a href="/dj/logout.php">Logout</a>
            <?php else: ?>
                <a href="/plans.php">Pro vs Premium</a>
                <a href="/about.php">About</a>
                <a href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">DJ Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main>
    <section class="hero city-stage" data-city="0.08">
        <div class="wrap hero-grid">
            <div class="reveal">
                <span class="eyebrow">Technology That Elevates The DJ Experience</span>
                <h1>DJs Are Becoming More Immersive With MyDJRequests</h1>
                <p>
                    Take advantage of modern technology to connect with your guests at a deeper level.
                    MyDJRequests helps you capture live crowd intent, manage requests cleanly, and stay in full control of your set.
                </p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="<?php echo mdjr_esc(mdjr_url('dj/register.php')); ?>">Start 30-Day Free Trial</a>
                    <a class="btn btn-secondary" href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">DJ Login</a>
                </div>
            </div>

            <aside class="hero-media reveal" aria-label="MyDJRequests dashboard preview">
                <img src="/assets/images/dj-dashboard-tease.png" alt="MyDJRequests DJ dashboard showing live requests">
                <div class="hero-metric">
                    <div class="metric">
                        <strong>Live</strong>
                        <span>Real-time requests</span>
                    </div>
                    <div class="metric">
                        <strong>QR</strong>
                        <span>Guest-friendly input</span>
                    </div>
                    <div class="metric">
                        <strong>100%</strong>
                        <span>DJ-controlled playback</span>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <section class="city-stage" data-city="0.14">
        <div class="wrap">
            <h2 class="reveal">How Do You Usually DJ?</h2>
            <p class="section-sub reveal">
                Whether you play mobile events, stream online, or do both, MyDJRequests keeps one clean workflow across every audience.
            </p>

            <div class="persona-grid">
                <article class="persona-card reveal has-video">
                    <video class="persona-video" muted loop playsinline preload="none">
                        <source src="/assets/video/event-dj-loop.webm" type="video/webm">
                        <source src="/assets/video/event-dj-loop.mp4" type="video/mp4">
                    </video>
                    <div class="persona-content">
                        <span class="persona-tag">Mobile Events</span>
                        <h3>Weddings, Parties, Venues</h3>
                        <p>Reduce booth crowding while still giving guests a clear way to engage.</p>
                        <ul>
                            <li>QR code requests in seconds</li>
                            <li>No shouting over your set</li>
                            <li>Popularity signals at a glance</li>
                        </ul>
                    </div>
                </article>
                <article class="persona-card reveal has-video">
                    <video class="persona-video" muted loop playsinline preload="none">
                        <source src="/assets/video/live-stream-loop.webm" type="video/webm">
                        <source src="/assets/video/live-stream-loop.mp4" type="video/mp4">
                    </video>
                    <div class="persona-content">
                        <span class="persona-tag">Live Streaming</span>
                        <h3>Twitch, Mixcloud, Broadcast</h3>
                        <p>Keep requests structured for your online audience without chat chaos.</p>
                        <ul>
                            <li>One clean request link</li>
                            <li>Guest messages in one place</li>
                            <li>Tablet and dashboard friendly</li>
                        </ul>
                    </div>
                </article>
                <article class="persona-card reveal has-video">
                    <video class="persona-video" muted loop playsinline preload="none">
                        <source src="/assets/video/both.webm" type="video/webm">
                        <source src="/assets/video/both.mp4" type="video/mp4">
                    </video>
                    <div class="persona-content">
                        <span class="persona-tag">Hybrid DJs</span>
                        <h3>One System for Everything</h3>
                        <p>Use the same setup for private gigs, nightlife, and streaming sets.</p>
                        <ul>
                            <li>No platform switching</li>
                            <li>Consistent event flow</li>
                            <li>Professional guest experience</li>
                        </ul>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="city-stage" data-city="0.2">
        <div class="wrap">
            <h2 class="reveal">What MyDJRequests Looks Like in Action</h2>
            <p class="section-sub reveal">
                Showcase your process from event setup to live curation. These touchpoints are designed to make your service look modern and organized.
            </p>

            <div class="image-grid">
                <article class="image-card reveal">
                    <img src="/assets/marketing/how-step-1-event.png" alt="Create your event and QR code in MyDJRequests" loading="lazy">
                    <div class="image-body">
                        <h3>Event Setup</h3>
                        <p>Create an event in minutes and instantly generate a unique request link and QR code for guests.</p>
                    </div>
                </article>

                <article class="image-card reveal">
                    <img src="/assets/marketing/how-step-2-patron-mobile.png" alt="Guests requesting songs on mobile" loading="lazy">
                    <div class="image-body">
                        <h3>Guest Mobile Requests</h3>
                        <p>Guests submit songs and messages directly from their phone browser without installing anything.</p>
                    </div>
                </article>

                <article class="image-card reveal">
                    <img src="/assets/marketing/how-step-3-dj-dashboard.png" alt="DJ dashboard where requests are curated" loading="lazy">
                    <div class="image-body">
                        <h3>DJ Live Curation</h3>
                        <p>Approve, skip, and prioritize tracks from one dashboard while staying in control of your set.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="city-stage" data-city="0.28">
        <div class="wrap">
            <h2 class="reveal">From Patron Request to Playlist Decision</h2>
            <p class="section-sub reveal">A clean, repeatable process your clients can trust and your guests can use instantly.</p>
            <div class="flow-wrap">
                <article class="flow reveal">
                    <div class="flow-badge">1</div>
                    <h3>Launch Event Page</h3>
                    <p>Your event page becomes the central hub for requests, messages, and contact touchpoints.</p>
                </article>
                <article class="flow reveal">
                    <div class="flow-badge">2</div>
                    <h3>Share QR or Link</h3>
                    <p>Place your QR at the booth, tables, or screens so requests arrive digitally and quietly.</p>
                </article>
                <article class="flow reveal">
                    <div class="flow-badge">3</div>
                    <h3>Review Live Demand</h3>
                    <p>See popularity trends and crowd intent without getting interrupted in the middle of your mix.</p>
                </article>
                <article class="flow reveal">
                    <div class="flow-badge">4</div>
                    <h3>Play with Precision</h3>
                    <p>Only approved tracks flow into your workflow. Nothing auto-plays. You remain fully in command.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="city-stage" data-city="0.36">
        <div class="wrap split">
            <div class="panel reveal">
                <h2>Built to Support Your DJ Brand</h2>
                <p class="section-sub" style="margin-bottom: 8px;">
                    This is more than request collection. It is a modern digital layer for your service quality and public presence.
                </p>
                <ul>
                    <li>Professional event request pages for each gig or stream</li>
                    <li>Public DJ profile and shareable identity link</li>
                    <li>Guest messages and optional tips without booth crowding</li>
                    <li>Use alongside your existing DJ software workflow and music sources</li>
                    <li>Clean closeout when events end, so requests stop on schedule</li>
                </ul>
                <div class="trust">30-day free trial, no lock-in contracts, cancel anytime.</div>
            </div>

            <aside class="qr-block reveal">
                <h3 style="margin-bottom: 8px;">Instant Guest Entry Point</h3>
                <p style="margin: 0 0 14px; color: #9bb1ca;">One scan is all guests need to start submitting requests.</p>
                <img src="/assets/qr/qr-demo.png" alt="Demo QR code for MyDJRequests">
                <p style="margin: 12px 0 0; color: #95abc5; font-size: 14px;">
                    Great for weddings, venue nights, private events, and livestream communities.
                </p>
            </aside>
        </div>
    </section>

    <section class="city-stage" data-city="0.42">
        <div class="wrap">
            <h2 class="reveal">Why DJs Love MyDJRequests</h2>
            <p class="section-sub reveal">
                Built by DJs for real events, peak-time pressure, and live crowd dynamics.
            </p>
            <div class="feature-grid">
                <article class="feature-card reveal">
                    <h3>Easy Requests</h3>
                    <p>QR and link-based requests keep the process smooth for guests and DJs.</p>
                </article>
                <article class="feature-card reveal">
                    <h3>Guest Messages</h3>
                    <p>Receive dedications and notes without interrupting your workflow.</p>
                </article>
                <article class="feature-card reveal">
                    <h3>Popularity Tracking</h3>
                    <p>See what the room wants in real time without guesswork.</p>
                </article>
                <article class="feature-card reveal">
                    <h3>Optional Tipping</h3>
                    <p>Offer a digital tipping experience that stays discreet and DJ-controlled.</p>
                </article>
                <article class="feature-card reveal">
                    <h3>Track Context</h3>
                    <p>Review useful request details before deciding what to play.</p>
                </article>
                <article class="feature-card reveal">
                    <h3>Event Analytics</h3>
                    <p>Understand request trends before, during, and after each set.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="city-stage" data-city="0.48">
        <div class="wrap">
            <div class="cta reveal">
                <h2>Ready to Try MyDJRequests?</h2>
                <p>
                    Start your 30-day free trial and run your next event with a cleaner request flow, stronger crowd insight, and full control over what gets played.
                </p>
                <div class="cta-row" style="justify-content: center;">
                    <a class="btn btn-primary" href="<?php echo mdjr_esc(mdjr_url('dj/register.php')); ?>">Start Free Trial</a>
                    <a class="btn btn-secondary" href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">Login</a>
                </div>
                <div class="fine">No credit card required during trial.</div>
                <div class="reassure">30-day free trial • No lock-in contracts • Cancel anytime</div>
            </div>
        </div>
    </section>
</main>

<footer>
    &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved.
</footer>

<script>
const revealElements = document.querySelectorAll('.reveal');
const cityStages = document.querySelectorAll('.city-stage');
const cityVideo = document.querySelector('.city-bg video');

if ('IntersectionObserver' in window) {
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('visible');
            revealObserver.unobserve(entry.target);
        });
    }, { threshold: 0.12 });

    revealElements.forEach((el) => revealObserver.observe(el));

    const cityObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const nextOpacity = entry.target.getAttribute('data-city') || '0.08';
            document.documentElement.style.setProperty('--city-opacity', nextOpacity);
        });
    }, { threshold: 0.4 });

    cityStages.forEach((stage) => cityObserver.observe(stage));
} else {
    revealElements.forEach((el) => el.classList.add('visible'));
}

if (cityVideo && window.matchMedia('(max-width: 767px)').matches === false) {
    cityVideo.play().catch(() => {});
}

const personaCards = document.querySelectorAll('.persona-card.has-video');
const isTouch = window.matchMedia('(pointer: coarse)').matches;

if (isTouch) {
    personaCards.forEach((card) => {
        const video = card.querySelector('.persona-video');
        if (!video) return;

        card.classList.add('video-active');
        video.play().catch(() => {});
    });
} else {
    personaCards.forEach((card) => {
        const video = card.querySelector('.persona-video');
        if (!video) return;

        const startVideo = () => {
            card.classList.add('video-active');
            video.currentTime = 0;
            video.play().catch(() => {});
        };

        const stopVideo = () => {
            card.classList.remove('video-active');
            video.pause();
            video.currentTime = 0;
        };

        card.addEventListener('mouseenter', startVideo);
        card.addEventListener('mouseleave', stopVideo);
        card.addEventListener('focusin', startVideo);
        card.addEventListener('focusout', stopVideo);
    });
}
</script>

</body>
</html>
