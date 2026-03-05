<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDJRequests | Modern DJ Request Platform</title>

    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon-v2.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #060c12;
            --bg-soft: #0d1622;
            --surface: rgba(15, 24, 36, 0.72);
            --surface-strong: #121e2e;
            --text: #eff6ff;
            --muted: #a8bbd2;
            --line: rgba(160, 192, 224, 0.24);
            --aqua: #42d9c8;
            --aqua-strong: #1ecab7;
            --amber: #ffb454;
            --shadow: 0 26px 54px rgba(0, 0, 0, 0.45);
            --radius-lg: 22px;
            --radius-md: 14px;
            --max: 1120px;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            color: var(--text);
            background: var(--bg);
            font-family: "Space Grotesk", "Segoe UI", sans-serif;
            line-height: 1.5;
            overflow-x: hidden;
        }

        .page-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -2;
            background:
                radial-gradient(1200px 600px at -10% -20%, rgba(66, 217, 200, 0.18), transparent 70%),
                radial-gradient(1000px 500px at 110% -10%, rgba(255, 180, 84, 0.15), transparent 68%),
                linear-gradient(180deg, #07101a 0%, #05090f 100%);
        }

        .page-grid {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            opacity: 0.26;
            background-image:
                linear-gradient(rgba(151, 181, 211, 0.16) 1px, transparent 1px),
                linear-gradient(90deg, rgba(151, 181, 211, 0.14) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, 0.65), transparent 82%);
        }

        .wrap {
            max-width: var(--max);
            margin: 0 auto;
            padding: 0 22px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(8px);
            background: rgba(5, 10, 16, 0.78);
            border-bottom: 1px solid rgba(160, 192, 224, 0.18);
        }

        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            gap: 16px;
        }

        .brand img {
            height: 32px;
            display: block;
        }

        nav {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        nav a {
            color: #d8e5f5;
            text-decoration: none;
            font-size: 14px;
            opacity: 0.9;
            transition: opacity 0.2s ease, color 0.2s ease;
        }

        nav a:hover {
            opacity: 1;
            color: var(--aqua);
        }

        .hero {
            padding: 92px 0 68px;
        }

        .hero-grid {
            display: grid;
            gap: 28px;
            grid-template-columns: 1.08fr 0.92fr;
            align-items: center;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(66, 217, 200, 0.42);
            border-radius: 999px;
            padding: 6px 12px;
            color: #bff9f1;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
            background: rgba(35, 136, 127, 0.18);
        }

        h1, h2, h3 {
            font-family: "Sora", "Space Grotesk", sans-serif;
            margin: 0;
            line-height: 1.14;
            letter-spacing: -0.01em;
        }

        h1 {
            font-size: clamp(36px, 6vw, 56px);
            margin-bottom: 16px;
        }

        .hero p {
            color: var(--muted);
            max-width: 620px;
            font-size: 18px;
            margin: 0 0 30px;
        }

        .cta-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            text-decoration: none;
            border-radius: 12px;
            padding: 13px 18px;
            font-weight: 600;
            font-size: 15px;
            border: 1px solid transparent;
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(140deg, var(--aqua), var(--aqua-strong));
            color: #03241f;
            border-color: rgba(66, 217, 200, 0.8);
        }

        .btn-secondary {
            background: rgba(16, 28, 42, 0.85);
            border-color: rgba(160, 192, 224, 0.35);
            color: #e9f3ff;
        }

        .hero-card {
            background: linear-gradient(165deg, rgba(18, 31, 47, 0.86), rgba(9, 17, 27, 0.92));
            border: 1px solid rgba(160, 192, 224, 0.24);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .hero-card::before {
            content: "";
            position: absolute;
            inset: -30% -25% auto auto;
            width: 240px;
            height: 240px;
            background: radial-gradient(circle, rgba(66, 217, 200, 0.42), transparent 68%);
            transform: rotate(8deg);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(66, 217, 200, 0.42);
            background: rgba(14, 44, 56, 0.42);
            border-radius: 999px;
            font-size: 12px;
            padding: 6px 10px;
            margin-bottom: 16px;
            color: #baf6ef;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #40eac8;
            box-shadow: 0 0 0 5px rgba(64, 234, 200, 0.2);
        }

        .mini-list {
            margin: 12px 0 0;
            padding: 0;
            list-style: none;
        }

        .mini-list li {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(160, 192, 224, 0.15);
            color: #d7e5f6;
            font-size: 14px;
        }

        .mini-list li:last-child {
            border-bottom: 0;
        }

        .tag {
            color: #ffd59a;
            font-size: 12px;
            border: 1px solid rgba(255, 180, 84, 0.38);
            border-radius: 999px;
            padding: 2px 8px;
            white-space: nowrap;
            align-self: center;
        }

        .section {
            padding: 42px 0 26px;
        }

        .section h2 {
            font-size: clamp(28px, 4.4vw, 40px);
            margin-bottom: 14px;
        }

        .section-sub {
            color: var(--muted);
            max-width: 730px;
            margin: 0 0 22px;
        }

        .cards {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 18px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .card:hover {
            transform: translateY(-3px);
            border-color: rgba(66, 217, 200, 0.58);
        }

        .card h3 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        .card p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .timeline {
            margin-top: 8px;
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .step {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 18px;
        }

        .step-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-weight: 700;
            color: #053a32;
            background: linear-gradient(140deg, #7af2e3, #4fd8c7);
        }

        .step h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        .step p {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
        }

        .band {
            margin: 44px 0 10px;
            padding: 18px 20px;
            border-radius: 14px;
            border: 1px solid rgba(255, 180, 84, 0.38);
            background: linear-gradient(150deg, rgba(43, 29, 11, 0.8), rgba(27, 22, 14, 0.75));
            color: #ffdfaf;
            font-size: 15px;
        }

        .stats {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 12px;
        }

        .stat {
            background: var(--surface-strong);
            border: 1px solid rgba(160, 192, 224, 0.26);
            border-radius: 14px;
            padding: 18px;
        }

        .stat strong {
            display: block;
            font-family: "Sora", sans-serif;
            font-size: 28px;
            margin-bottom: 6px;
            color: #d2f9f3;
        }

        .stat span {
            font-size: 14px;
            color: var(--muted);
        }

        .closing {
            margin: 52px 0 54px;
            background: linear-gradient(160deg, rgba(10, 33, 41, 0.92), rgba(12, 19, 35, 0.9));
            border: 1px solid rgba(66, 217, 200, 0.34);
            border-radius: var(--radius-lg);
            padding: 30px 24px;
            text-align: center;
        }

        .closing h2 {
            margin-bottom: 8px;
        }

        .closing p {
            color: #c3d6ec;
            max-width: 640px;
            margin: 0 auto 22px;
        }

        .fine {
            color: #97afcc;
            font-size: 13px;
            margin-top: 14px;
        }

        footer {
            border-top: 1px solid rgba(160, 192, 224, 0.16);
            color: #8ea5bf;
            text-align: center;
            font-size: 13px;
            padding: 24px 0 36px;
        }

        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.55s ease, transform 0.55s ease;
        }

        .reveal.visible {
            opacity: 1;
            transform: none;
        }

        @media (max-width: 980px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }

            .cards {
                grid-template-columns: 1fr 1fr;
            }

            .timeline {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 680px) {
            .header-inner {
                align-items: flex-start;
            }

            nav {
                gap: 12px;
            }

            nav a {
                font-size: 13px;
            }

            .hero {
                padding-top: 62px;
            }

            .hero p {
                font-size: 16px;
            }

            .cards,
            .timeline,
            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal {
                opacity: 1;
                transform: none;
                transition: none;
            }

            .btn,
            .card {
                transition: none;
            }
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

<div class="page-bg" aria-hidden="true"></div>
<div class="page-grid" aria-hidden="true"></div>

<header>
    <div class="wrap header-inner">
        <a class="brand" href="/">
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
                <a href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">DJ Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main>
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="reveal">
                <span class="eyebrow">Modern DJ Workflow</span>
                <h1>Take Requests Without Losing Control of the Room</h1>
                <p>
                    MyDJRequests gives DJs a clean request pipeline: guests scan, submit, and you decide what plays.
                    Built for mobile events, nightlife venues, and live streams.
                </p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="<?php echo mdjr_esc(mdjr_url('dj/register.php')); ?>">Start 30-Day Free Trial</a>
                    <a class="btn btn-secondary" href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">Existing DJ Login</a>
                </div>
            </div>

            <aside class="hero-card reveal" aria-label="Live dashboard preview">
                <div class="status-pill"><span class="status-dot"></span> Event Live: Saturday Rooftop</div>
                <h3 style="margin-bottom: 8px;">Requests Ready in Real Time</h3>
                <p style="margin: 0; color: var(--muted); font-size: 14px;">
                    Approve requests as they come in. Keep the set clean, legal, and aligned to your crowd.
                </p>
                <ul class="mini-list">
                    <li><span>Calvin Harris - Feel So Close</span><span class="tag">Top Pick</span></li>
                    <li><span>Disclosure - Latch</span><span class="tag">Approved</span></li>
                    <li><span>Dua Lipa - Levitating</span><span class="tag">Queued</span></li>
                </ul>
            </aside>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <h2 class="reveal">Built for the DJs You Actually Are</h2>
            <p class="section-sub reveal">
                Weddings. Clubs. Corporate gigs. Livestreams. One platform, same workflow, no awkward handoffs.
            </p>
            <div class="cards">
                <article class="card reveal">
                    <h3>Mobile Event DJs</h3>
                    <p>QR requests reduce booth crowding and keep request volume organized during peak moments.</p>
                </article>
                <article class="card reveal">
                    <h3>Venue & Residency DJs</h3>
                    <p>Collect smarter crowd signals and keep your set direction intentional, not random.</p>
                </article>
                <article class="card reveal">
                    <h3>Livestream DJs</h3>
                    <p>Share one clean request link in chat, avoid spam, and keep audience interaction structured.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <h2 class="reveal">How It Flows</h2>
            <p class="section-sub reveal">Simple for guests, precise for DJs.</p>
            <div class="timeline">
                <article class="step reveal">
                    <div class="step-num">1</div>
                    <h3>Create Event</h3>
                    <p>Launch an event and instantly get a shareable request link and QR code.</p>
                </article>
                <article class="step reveal">
                    <div class="step-num">2</div>
                    <h3>Collect Requests</h3>
                    <p>Guests submit songs and optional messages from phone browser, no app install needed.</p>
                </article>
                <article class="step reveal">
                    <div class="step-num">3</div>
                    <h3>Curate Live</h3>
                    <p>Approve, skip, and prioritize tracks from your dashboard. Nothing auto-plays.</p>
                </article>
                <article class="step reveal">
                    <div class="step-num">4</div>
                    <h3>Close Cleanly</h3>
                    <p>Events end on schedule so requests stop when the gig is done.</p>
                </article>
            </div>

            <div class="band reveal">
                Spotify-ready playlist workflow available for Premium users in compatible DJ software.
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <h2 class="reveal">Professional Presence Included</h2>
            <p class="section-sub reveal">
                Every subscription includes your event request page, contact capture flow, and public DJ profile link.
            </p>
            <div class="stats">
                <div class="stat reveal">
                    <strong>1 Link</strong>
                    <span>Use one clean URL for events, socials, and guest requests.</span>
                </div>
                <div class="stat reveal">
                    <strong>0 Apps</strong>
                    <span>Guests join from browser instantly with no account friction.</span>
                </div>
                <div class="stat reveal">
                    <strong>Full Control</strong>
                    <span>You choose what plays and when, while still reading the room.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="wrap closing reveal">
        <h2>Try the Modern MyDJRequests Experience</h2>
        <p>
            Start your 30-day free trial and run your next gig with a cleaner request system, better crowd intelligence,
            and less booth chaos.
        </p>
        <div class="cta-row" style="justify-content:center;">
            <a class="btn btn-primary" href="<?php echo mdjr_esc(mdjr_url('dj/register.php')); ?>">Start Free Trial</a>
            <a class="btn btn-secondary" href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">Login</a>
        </div>
        <p class="fine">No credit card required. Cancel anytime.</p>
    </section>
</main>

<footer>
    &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved. <a href="/privacy.php" style="color:inherit; text-decoration:underline;">Privacy</a>
</footer>

<script>
const revealNodes = document.querySelectorAll('.reveal');

if ('IntersectionObserver' in window) {
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('visible');
            revealObserver.unobserve(entry.target);
        });
    }, { threshold: 0.12 });

    revealNodes.forEach((node) => revealObserver.observe(node));
} else {
    revealNodes.forEach((node) => node.classList.add('visible'));
}
</script>

</body>
</html>
