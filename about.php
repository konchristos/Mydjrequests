<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About MyDJRequests</title>

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
            --max: 1100px;
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

        .wrap { width: min(var(--max), calc(100% - 28px)); margin: 0 auto; }

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
            margin-top: calc(var(--header-h) + 16px);
        }

        h1, h2, h3 {
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
            margin: 0;
            line-height: 1.15;
        }

        h1 { font-size: clamp(34px, 5vw, 56px); margin-bottom: 14px; }
        h2 { font-size: clamp(26px, 4vw, 36px); margin-bottom: 12px; }
        h3 { font-size: 20px; margin-bottom: 8px; }

        .hero { padding: 86px 0 42px; }
        .hero p { color: var(--muted); font-size: 18px; max-width: 760px; margin: 0; }

        section { padding: 28px 0; }

        .grid-2 {
            display: grid;
            gap: 16px;
            grid-template-columns: 1fr 1fr;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
        }

        .card p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .values {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .value {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
        }

        .value p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .about-cta {
            margin: 16px 0 56px;
            background: linear-gradient(160deg, rgba(12, 25, 41, 0.94), rgba(9, 17, 30, 0.94));
            border: 1px solid rgba(90, 154, 218, 0.4);
            border-radius: 20px;
            padding: 30px 24px;
            text-align: center;
        }

        .about-cta p {
            margin: 10px auto 20px;
            max-width: 700px;
            color: var(--muted);
        }

        .cta-row {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(145deg, #39b8ff, #219ee6);
            color: #03243a;
            border: 1px solid rgba(80, 180, 240, 0.85);
        }

        .btn-secondary {
            background: rgba(13, 22, 35, 0.9);
            color: #dcecff;
            border: 1px solid rgba(151, 182, 216, 0.35);
        }

        footer {
            border-top: 1px solid rgba(151, 182, 216, 0.2);
            color: #86a0bf;
            background: rgba(7, 13, 22, 0.72);
        }

        .footer-inner {
            width: min(var(--max), calc(100% - 28px));
            margin: 0 auto;
            text-align: center;
            padding: 22px 0 34px;
            font-size: 13px;
        }

        @media (max-width: 820px) {
            :root {
                --header-h: 52px;
            }

            .grid-2,
            .values {
                grid-template-columns: 1fr;
            }

            .hero p { font-size: 16px; }
        }
    </style>
</head>
<body>
<?php
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;
?>

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
    <section class="hero">
        <h1>About MyDJRequests</h1>
        <p>
            MyDJRequests exists to help DJs run cleaner events, create deeper guest engagement, and stay in full control of their set.
            We build practical tools for real-world gigs, livestreams, and high-pressure dance floors.
        </p>
    </section>

    <section>
        <div class="grid-2">
            <article class="card">
                <h2>Our Mission</h2>
                <p>
                    Modernize the song request experience so DJs can interact with guests digitally, without losing focus,
                    flow, or authority behind the decks.
                </p>
            </article>
            <article class="card">
                <h2>Why We Built It</h2>
                <p>
                    Traditional request methods are noisy and disruptive. We built MyDJRequests to replace booth crowding
                    and guesswork with a clean, mobile-first request system DJs can trust.
                </p>
            </article>
        </div>
    </section>

    <section>
        <h2>What We Believe</h2>
        <div class="values">
            <article class="value">
                <h3>DJs Stay In Control</h3>
                <p>Requests support your set. They never run it.</p>
            </article>
            <article class="value">
                <h3>Technology Should Feel Human</h3>
                <p>Better tools should create better guest connection, not extra friction.</p>
            </article>
            <article class="value">
                <h3>Simple Wins Live</h3>
                <p>Fast workflows matter when the room is full and timing is everything.</p>
            </article>
        </div>
    </section>

    <section>
        <div class="card">
            <h2>The Experience</h2>
            <p>
                From QR-based patron requests to DJ-side moderation and live event flow, MyDJRequests is designed to
                support the full arc of an event: setup, engagement, curation, and clean closeout.
            </p>
        </div>
    </section>

    <section class="about-cta">
        <h2>Ready To Experience It?</h2>
        <p>Start your 30-day free trial and run your next event with a modern request workflow.</p>
        <div class="cta-row">
            <a class="btn btn-primary" href="/dj/register.php">Start Free Trial</a>
            <a class="btn btn-secondary" href="/dj/login.php">DJ Login</a>
        </div>
    </section>
</main>

<footer>
    <div class="footer-inner">
        &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
    </div>
</footer>

</body>
</html>
