<?php
require_once __DIR__ . '/app/bootstrap_public.php';

$videos = [
    [
        'title' => 'Getting Started with MyDJRequests',
        'youtube_id' => 'ysz5S6PUM-U',
        'description' => 'Quick walkthrough of setup basics and where to find key features in your dashboard.',
    ],
    [
        'title' => 'How to Manage Song Requests Live',
        'youtube_id' => 'aqz-KE-bpKQ',
        'description' => 'Learn how to review incoming requests, sort queues, and keep your set flowing smoothly.',
    ],
    [
        'title' => 'Setting Up QR Request Access',
        'youtube_id' => 'ScMzIvxBSi4',
        'description' => 'Step-by-step guide to generating and sharing your event QR code with guests.',
    ],
    [
        'title' => 'Using Premium Features Effectively',
        'youtube_id' => 'M7lc1UVf-VE',
        'description' => 'Overview of premium tools to boost guest engagement and increase support.',
    ],
    [
        'title' => 'Optimizing Your Event Profile',
        'youtube_id' => 'dQw4w9WgXcQ',
        'description' => 'Best practices for profile settings, branding, and event customization.',
    ],
    [
        'title' => 'Tips for Better Crowd Interaction',
        'youtube_id' => 'hY7m5jjJ9mM',
        'description' => 'Practical techniques to communicate with guests and keep requests organized.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDJRequests Help Videos</title>
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon-v2.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <style>
        :root {
            --bg: #090b10;
            --panel: #121722;
            --panel-border: #27324a;
            --text: #ecf0ff;
            --muted: #a7b2cc;
            --accent: #49a3ff;
            --shadow: 0 12px 26px rgba(0, 0, 0, 0.35);
            --radius: 14px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(900px 450px at 10% -12%, rgba(73, 163, 255, 0.12), transparent 70%),
                radial-gradient(900px 450px at 90% -15%, rgba(68, 114, 220, 0.09), transparent 72%),
                var(--bg);
            min-height: 100vh;
        }

        .container {
            width: min(1200px, calc(100% - 32px));
            margin: 0 auto;
            padding: 34px 0 50px;
        }

        .page-header {
            margin: 0 0 24px;
            font-size: clamp(1.7rem, 3.4vw, 2.35rem);
            line-height: 1.2;
            letter-spacing: 0.01em;
            color: var(--text);
        }

        .video-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .video-card {
            background: linear-gradient(180deg, #151b28 0%, #101521 100%);
            border: 1px solid var(--panel-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .video-frame-wrap {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            background: #0a0d14;
        }

        .video-frame-wrap iframe {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }

        .video-content {
            padding: 14px 14px 16px;
        }

        .video-title {
            margin: 0 0 8px;
            font-size: 1.04rem;
            color: #f5f8ff;
        }

        .video-description {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
            font-size: 0.95rem;
        }

        @media (min-width: 700px) {
            .video-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .video-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <main class="container">
        <h1 class="page-header">MyDJRequests Help &amp; Tutorial Videos</h1>

        <section class="video-grid" aria-label="Tutorial Videos">
            <?php foreach ($videos as $video): ?>
                <article class="video-card">
                    <div class="video-frame-wrap">
                        <iframe
                            src="https://www.youtube.com/embed/<?php echo urlencode($video['youtube_id']); ?>"
                            title="<?php echo htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>"
                            loading="lazy"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen>
                        </iframe>
                    </div>
                    <div class="video-content">
                        <h2 class="video-title"><?php echo htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p class="video-description"><?php echo htmlspecialchars($video['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
