<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDJRequests | Premium DJ Request Experience</title>

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
            --bg: #f5f9ff;
            --bg-soft: #edf4ff;
            --ink: #0d1b2a;
            --muted: #5f7390;
            --line: #d8e5f8;
            --panel: #ffffff;
            --accent: #1f7ae0;
            --accent-soft: #e6f0ff;
            --teal: #17b4a2;
            --gold: #d89a31;
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 12px;
            --max: 1160px;
            --shadow: 0 26px 70px rgba(40, 72, 120, 0.14);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: "Manrope", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 480px at 18% -8%, rgba(31, 122, 224, 0.12), transparent 72%),
                radial-gradient(900px 420px at 88% -6%, rgba(23, 180, 162, 0.12), transparent 74%),
                var(--bg);
            line-height: 1.58;
        }

        .wrap {
            width: min(var(--max), calc(100% - 40px));
            margin: 0 auto;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(216, 229, 248, 0.75);
            background: rgba(245, 249, 255, 0.82);
            backdrop-filter: blur(8px);
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 13px 0;
        }

        .brand img {
            height: 34px;
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
            text-decoration: none;
            color: #345070;
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

        .hero {
            padding: 82px 0 52px;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 26px;
            align-items: center;
        }

        .eyebrow {
            display: inline-block;
            color: #24599d;
            background: var(--accent-soft);
            border: 1px solid #c9defb;
            border-radius: 999px;
            padding: 6px 11px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .hero p {
            font-size: 18px;
            color: var(--muted);
            max-width: 620px;
            margin: 14px 0 28px;
        }

        .cta-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

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

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(31, 122, 224, 0.18);
        }

        .btn-primary {
            background: linear-gradient(150deg, #2782e9, #1a70d5);
            color: #fff;
            border-color: #2a7ee0;
        }

        .btn-secondary {
            background: #fff;
            color: #224264;
            border-color: #cfe0f9;
        }

        .hero-media {
            background: linear-gradient(160deg, #ffffff, #f4f8ff);
            border: 1px solid #d4e4fb;
            border-radius: var(--radius-xl);
            padding: 16px;
            box-shadow: var(--shadow);
        }

        .hero-media img {
            width: 100%;
            border-radius: 14px;
            border: 1px solid #d4e2f6;
            display: block;
        }

        .hero-metric {
            margin-top: 12px;
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, 1fr);
        }

        .metric {
            background: #fff;
            border: 1px solid #d8e5f8;
            border-radius: 12px;
            padding: 11px 10px;
            text-align: center;
        }

        .metric strong {
            display: block;
            font-size: 21px;
            color: #0f3766;
        }

        .metric span {
            font-size: 12px;
            color: #5b7190;
        }

        section {
            padding: 44px 0;
        }

        .section-sub {
            color: var(--muted);
            max-width: 740px;
            margin: 0 0 22px;
            font-size: 17px;
        }

        .image-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .image-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(61, 97, 145, 0.08);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .image-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 30px rgba(61, 97, 145, 0.12);
        }

        .image-card img {
            width: 100%;
            aspect-ratio: 16 / 10;
            object-fit: cover;
            display: block;
        }

        .image-body {
            padding: 14px 14px 16px;
        }

        .image-body h3 {
            font-size: 19px;
            margin-bottom: 7px;
        }

        .image-body p {
            margin: 0;
            font-size: 14px;
            color: #5b7090;
        }

        .flow-wrap {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .flow {
            background: #fff;
            border: 1px solid #d8e5f8;
            border-radius: var(--radius-md);
            padding: 16px;
        }

        .flow-badge {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(150deg, #1f7ae0, #0f63c7);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .flow h3 {
            font-size: 17px;
            margin-bottom: 7px;
        }

        .flow p {
            margin: 0;
            font-size: 14px;
            color: #60748f;
        }

        .split {
            display: grid;
            gap: 18px;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
        }

        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 22px;
        }

        .panel ul {
            margin: 10px 0 0;
            padding-left: 20px;
            color: #596e8b;
        }

        .panel li + li {
            margin-top: 7px;
        }

        .qr-block {
            background: linear-gradient(145deg, #ffffff, #f2f7ff);
            border: 1px solid #d8e5f8;
            border-radius: var(--radius-lg);
            padding: 20px;
            text-align: center;
        }

        .qr-block img {
            width: min(170px, 100%);
            border-radius: 12px;
            border: 1px solid #d6e4f9;
            background: #fff;
            padding: 10px;
            box-shadow: 0 8px 16px rgba(43, 78, 126, 0.12);
        }

        .trust {
            padding: 16px 18px;
            border-radius: 12px;
            border: 1px solid #f2d59f;
            background: linear-gradient(150deg, #fff8eb, #fff3dc);
            color: #6b4b15;
            font-weight: 600;
            margin-top: 16px;
        }

        .cta {
            margin: 26px 0 56px;
            background: linear-gradient(160deg, #ffffff, #eef5ff);
            border: 1px solid #d4e4fb;
            border-radius: var(--radius-xl);
            padding: 32px 26px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .cta p {
            margin: 9px auto 22px;
            max-width: 700px;
            color: #5e7392;
        }

        .fine {
            margin-top: 14px;
            color: #7890ae;
            font-size: 13px;
        }

        footer {
            border-top: 1px solid #dae6f8;
            color: #7088a5;
            text-align: center;
            padding: 22px 0 34px;
            font-size: 13px;
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

        @media (max-width: 1000px) {
            .hero-grid,
            .split {
                grid-template-columns: 1fr;
            }

            .image-grid {
                grid-template-columns: 1fr 1fr;
            }

            .flow-wrap {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 680px) {
            .wrap {
                width: min(var(--max), calc(100% - 26px));
            }

            nav {
                gap: 10px;
            }

            nav a {
                font-size: 13px;
            }

            .hero {
                padding-top: 60px;
            }

            .hero p {
                font-size: 16px;
            }

            .image-grid,
            .flow-wrap,
            .hero-metric {
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
            .image-card {
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

<header>
    <div class="wrap header-row">
        <a href="/" class="brand">
            <img src="/assets/logo/MYDJRequests_Logo-blacktext.png" alt="MyDJRequests">
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
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="reveal">
                <span class="eyebrow">Premium Corporate Direction</span>
                <h1>A Refined Request Experience for Professional DJs</h1>
                <p>
                    MyDJRequests helps you collect requests, read crowd demand, and keep your workflow in control.
                    Built for premium events, venues, and creators who need polish and speed.
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

    <section>
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

    <section>
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

    <section>
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
                    <li>Spotify-compatible workflow support for Premium users in compatible DJ software</li>
                    <li>Clean closeout when events end, so requests stop on schedule</li>
                </ul>
                <div class="trust">30-day free trial, no lock-in contracts, cancel anytime.</div>
            </div>

            <aside class="qr-block reveal">
                <h3 style="margin-bottom: 8px;">Instant Guest Entry Point</h3>
                <p style="margin: 0 0 14px; color: #647a99;">One scan is all guests need to start submitting requests.</p>
                <img src="/assets/qr/qr-demo.png" alt="Demo QR code for MyDJRequests">
                <p style="margin: 12px 0 0; color: #6a7f9d; font-size: 14px;">
                    Great for weddings, venue nights, private events, and livestream communities.
                </p>
            </aside>
        </div>
    </section>

    <section class="wrap cta reveal">
        <h2>Compare This Premium-Corporate Style Against Your Other Landing Pages</h2>
        <p>
            This version is intentionally brighter, cleaner, and more executive-facing. Use it alongside your cyberpunk and dark-modern variants to test what converts best for your audience.
        </p>
        <div class="cta-row" style="justify-content: center;">
            <a class="btn btn-primary" href="<?php echo mdjr_esc(mdjr_url('dj/register.php')); ?>">Start Free Trial</a>
            <a class="btn btn-secondary" href="<?php echo mdjr_esc(mdjr_url('dj/login.php')); ?>">Login</a>
        </div>
        <div class="fine">No credit card required during trial.</div>
    </section>
</main>

<footer>
    &copy; <?php echo date('Y'); ?> MyDJRequests. All rights reserved.
</footer>

<script>
const revealElements = document.querySelectorAll('.reveal');

if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.12 });

    revealElements.forEach((el) => observer.observe(el));
} else {
    revealElements.forEach((el) => el.classList.add('visible'));
}
</script>

</body>
</html>
