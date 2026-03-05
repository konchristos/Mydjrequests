<?php
require_once __DIR__ . '/app/bootstrap_public.php';

$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro vs Premium | MyDJRequests</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon-v2.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <style>
        :root {
            --bg: #060b12;
            --panel: rgba(12, 21, 33, 0.88);
            --panel-strong: rgba(9, 17, 28, 0.94);
            --line: rgba(149, 181, 216, 0.24);
            --text: #eef5ff;
            --muted: #9db1cb;
            --brand: #35b6ff;
            --brand-strong: #1e9fe8;
            --ok: #6de5ba;
            --off: #7f98b6;
            --header-h: 56px;
            --nav-max: 1160px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: "Manrope", system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .cyberpunk-bg {
            position: fixed;
            inset: 0;
            z-index: -2;
            overflow: hidden;
            background: #070f19;
            opacity: 1;
        }

        .cyberpunk-bg video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: saturate(1.08) contrast(1.06) brightness(.58);
        }

        .bg-vignette {
            position: fixed;
            inset: 0;
            z-index: -1;
            background:
                radial-gradient(circle at 15% -10%, rgba(53, 182, 255, 0.22), transparent 44%),
                radial-gradient(circle at 85% -12%, rgba(45, 210, 190, 0.16), transparent 46%),
                linear-gradient(180deg, rgba(7, 12, 20, 0.35) 0%, rgba(6, 10, 17, 0.9) 76%);
            pointer-events: none;
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

        .header-inner {
            width: min(var(--nav-max), calc(100% - 28px));
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            min-height: var(--header-h);
            padding: 8px 0;
        }

        nav {
            display: flex;
            gap: 16px;
            align-items: center;
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
            width: min(1100px, calc(100% - 28px));
            margin: 0 auto;
        }

        main.wrap {
            margin-top: calc(var(--header-h) + 16px);
            margin-bottom: 54px;
        }

        .hero {
            background: linear-gradient(140deg, rgba(53, 182, 255, 0.24), rgba(10, 19, 31, 0.9));
            border: 1px solid rgba(92, 150, 212, 0.4);
            border-radius: 18px;
            padding: 26px 22px;
        }

        .hero h1 {
            margin: 0 0 12px;
            font-size: 34px;
            line-height: 1.1;
            color: var(--text);
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
        }

        .hero p {
            margin: 0;
            color: #c2d4ea;
            max-width: 760px;
            line-height: 1.55;
            font-size: 16px;
        }

        .section-title {
            margin: 24px 2px 12px;
            font-size: 13px;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #b5cae3;
            font-weight: 700;
        }

        .tile-zone {
            position: relative;
            border-radius: 16px;
            border: 1px solid var(--line);
            overflow: hidden;
            padding: 18px;
            background: rgba(8, 15, 25, 0.9);
        }

        .tile-zone-video {
            position: absolute;
            inset: 0;
            z-index: 0;
            pointer-events: none;
        }

        .tile-zone-video video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(.56) saturate(1.08);
        }

        .tile-zone-shade {
            position: absolute;
            inset: 0;
            z-index: 1;
            background: linear-gradient(180deg, rgba(8, 14, 24, 0.78), rgba(7, 12, 21, 0.94));
        }

        .tile-zone-content {
            position: relative;
            z-index: 2;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: transparent;
        }

        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(149, 181, 216, 0.2);
            font-size: 14px;
            text-align: left;
            vertical-align: top;
            background: rgba(11, 19, 30, 0.9);
        }

        th {
            background: rgba(16, 28, 43, 0.96);
            color: #d4e5f8;
            font-size: 13px;
        }

        tr:last-child td { border-bottom: 0; }

        .yes { color: var(--ok); font-weight: 700; }
        .no { color: var(--off); font-weight: 700; }
        .note { color: var(--muted); line-height: 1.45; }

        .pricing {
            margin-top: 24px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .plan-card {
            border: 1px solid rgba(149, 181, 216, 0.26);
            border-radius: 14px;
            padding: 18px;
            background: var(--panel);
        }

        .plan-card.featured {
            border-color: rgba(53, 182, 255, 0.55);
            background: var(--panel-strong);
            box-shadow: 0 0 34px rgba(53, 182, 255, 0.16);
        }

        .plan-name {
            margin: 0;
            font-size: 22px;
            color: var(--text);
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
        }

        .plan-card ul {
            margin: 14px 0 0;
            padding-left: 18px;
            color: #c2d4ea;
            line-height: 1.52;
            font-size: 14px;
        }

        .cta-row {
            margin-top: 18px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid rgba(149, 181, 216, 0.32);
            border-radius: 9px;
            padding: 11px 14px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            color: #dcecff;
            background: rgba(13, 22, 35, 0.9);
            display: inline-block;
        }

        .btn.primary {
            border-color: rgba(80, 180, 240, 0.85);
            color: #03243a;
            background: linear-gradient(145deg, var(--brand), var(--brand-strong));
        }

        .btn:hover { opacity: .92; }

        .footer-note {
            margin-top: 14px;
            font-size: 12px;
            color: #9bb0ca;
            line-height: 1.45;
        }

        footer {
            border-top: 1px solid rgba(149, 181, 216, 0.22);
            background: rgba(7, 13, 22, 0.72);
            color: #9ab1cb;
        }

        .footer-inner {
            width: min(1100px, calc(100% - 28px));
            margin: 0 auto;
            text-align: center;
            padding: 22px 0 34px;
            font-size: 13px;
        }

        @media (max-width: 860px) {
            :root { --header-h: 52px; }
            .pricing { grid-template-columns: 1fr; }
            .hero h1 { font-size: 28px; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            td { border-bottom: 0; padding: 8px 12px; }
            tr {
                margin-bottom: 8px;
                border: 1px solid rgba(149, 181, 216, 0.24);
            }
            td:first-child {
                font-weight: 700;
                color: var(--text);
                padding-top: 11px;
            }
        }
    </style>
</head>
<body>
<div class="cyberpunk-bg" aria-hidden="true">
    <video muted loop playsinline autoplay preload="auto">
        <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
        <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
    </video>
</div>
<div class="bg-vignette" aria-hidden="true"></div>

<header>
    <div class="header-inner">
        <a href="/">
            <img src="/assets/logo/MYDJRequests_Logo-white.png" alt="MyDJRequests" style="height:30px;">
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
                <a href="<?php echo e(mdjr_url('dj/login.php')); ?>">DJ Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

		<main class="wrap">
		    <section class="hero">
	        <h1>Choose Your DJ Growth Plan</h1>
        <p>
            MyDJRequests gives every DJ a clean request workflow. Premium unlocks advanced growth tools for engagement, branding, and live stream presentation.
        </p>
    </section>

	    <h2 class="section-title">Plan Comparison</h2>
		    <section class="tile-zone" aria-label="Pro vs Premium comparison">
        <div class="tile-zone-video" aria-hidden="true">
            <video muted loop playsinline autoplay preload="none">
                <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
                <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
            </video>
        </div>
        <div class="tile-zone-shade" aria-hidden="true"></div>

        <div class="tile-zone-content">
            <table>
                <thead>
                <tr>
                    <th style="width:38%;">Feature</th>
                    <th style="width:10%;">Pro</th>
                    <th style="width:12%;">Premium</th>
                    <th>Why it matters</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Event request pages + QR downloads</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Run clean song requests at every event with no booth crowding.</td>
                </tr>
                <tr>
                    <td>Public DJ profile + social links</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Promote your DJ brand with one professional profile link.</td>
                </tr>
                <tr>
                    <td>A4 poster download</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Printable promotion assets for your events.</td>
                </tr>
                <tr>
                    <td>Tips + Boosts (DJ earnings)</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Allow guests to support DJs with tips and song boosts.</td>
                </tr>
                <tr>
                    <td>Live patron statistics</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Track connected patrons, engagement percentage, and top patron request ranking in real time.</td>
                </tr>
                <tr>
                    <td>Dynamic mood meter</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">See crowd mood signals update live as requests and interactions come in.</td>
                </tr>
                <tr>
                    <td>Dynamic playlist</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Keep a live, evolving playlist based on approved requests during the event.</td>
                </tr>
                <tr>
                    <td>Collaborative playlist preparation</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Collect requests before the event so guests help shape the set ahead of time.</td>
                </tr>
                <tr>
                    <td>Event-specific tipping controls</td>
                    <td><span class="yes">Yes</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Enable or disable tipping per event to match each booking style.</td>
                </tr>
                <tr>
                    <td>Export playlists to CSV (archiving)</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Export playlist/request data to CSV for records, analysis, and post-event workflows.</td>
                </tr>
                <tr>
                    <td>Poll creation + poll results</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Drive audience interaction with live polls inside message threads.</td>
                </tr>
                <tr>
                    <td>Dynamic live links + OBS live QR URL</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Set once and forget. Use one reusable link that always points to your current LIVE event, including OBS live QR output.</td>
                </tr>
                <tr>
                    <td>Global QR Style Studio + saved presets</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Custom branded QR look across event pages, posters, and live overlays.</td>
                </tr>
                <tr>
                    <td>Event-specific poster layout override</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Show/hide and reorder poster fields for each individual event.</td>
                </tr>
                <tr>
                    <td>Custom default broadcast message</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Set your own default announcement copy for every new event.</td>
                </tr>
                <tr>
                    <td>DJ image upload + crop/focus controls</td>
                    <td><span class="no">No</span></td>
                    <td><span class="yes">Yes</span></td>
                    <td class="note">Upgrade your public profile with flexible contact image controls.</td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

	    <h2 class="section-title">Alpha Access</h2>
		    <section class="tile-zone" aria-label="Subscribe options">
        <div class="tile-zone-video" aria-hidden="true">
            <video muted loop playsinline autoplay preload="none">
                <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
                <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
            </video>
        </div>
        <div class="tile-zone-shade" aria-hidden="true"></div>

        <div class="tile-zone-content">
            <div class="pricing">
                <article class="plan-card">
                    <h3 class="plan-name">Pro</h3>
                    <ul>
                        <li>Core DJ request workflow</li>
                        <li>Public profile and socials</li>
                        <li>A4 poster export</li>
                        <li>Tips and boosts enabled for DJ earnings</li>
                        <li>Best for DJs who want reliable essentials</li>
                    </ul>
                    <div class="cta-row">
                        <a class="btn" href="<?php echo e(mdjr_url('dj/register.php')); ?>">Subscribe to Pro</a>
                    </div>
                </article>

                <article class="plan-card featured">
                    <h3 class="plan-name">Premium</h3>
                    <ul>
                        <li>Everything in Pro</li>
                        <li>Tips and boosts enabled for DJ earnings</li>
                        <li>Polls and advanced audience engagement</li>
                        <li>Dynamic links + OBS live QR tools</li>
                        <li>Full QR and poster branding controls</li>
                    </ul>
                    <div class="cta-row">
                        <a class="btn primary" href="<?php echo e(mdjr_url('dj/register.php')); ?>">Subscribe to Premium</a>
                    </div>
                </article>
            </div>

            <p class="footer-note">
                Alpha testing is active. Join now to access MyDJRequests and start taking requests.
            </p>
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
