<?php
require_once __DIR__ . '/../app/bootstrap_public.php';

$djUuid = $_GET['dj'] ?? '';
$djName = 'UNKNOWN DJ';

if ($djUuid) {
    $db = db();

    $stmt = $db->prepare("
        SELECT dj_name, name
        FROM users
        WHERE uuid = ?
        LIMIT 1
    ");
    $stmt->execute([$djUuid]);
    $dj = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($dj) {
        $djName = $dj['dj_name'] ?: $dj['name'];
    }
}

$qrImg = 'https://mydjrequests.com/qr/live.php?dj=' . urlencode($djUuid);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html, body {
    margin: 0;
    padding: 0;
    background: transparent;
}

/* OUTER TILE */
.qr-tile {
    width: 300px;
    height: 420px;
    background: #0c0c11;
    border-radius: 16px;
    border: 2px solid #ff2fd2;
    box-shadow: 0 0 22px rgba(255,47,210,.45);
    display: flex;
    flex-direction: column;
    align-items: center;
    font-family: system-ui, -apple-system, sans-serif;
    overflow: hidden; /* ðŸ‘ˆ needed for full-width header */
}

/* FULL-WIDTH BRAND BAR */
.brand {
    width: 100%;
    text-align: center;
    padding: 14px 0 12px;      /* slightly taller bar */
    font-size: 22px;           /* ðŸ‘ˆ noticeably larger */
    font-weight: 900;
    color: #ffffff;
    background: linear-gradient(
        90deg,
        rgba(255,47,210,0.45),
        rgba(106,227,255,0.45)
    );
    letter-spacing: 0.10em;    /* logo-style spacing */
    text-shadow:
        0 0 8px rgba(255,47,210,0.7),
        0 0 16px rgba(255,47,210,0.35);
}

/* QR CONTAINER (NO SIDE PADDING) */
.qr {
    width: 100%;
    padding: 10px;              /* ðŸ‘ˆ only safety margin */
    box-sizing: border-box;
}

.qr-inner {
    background: #fff;
    padding: 8px;
    border-radius: 10px;
}

.qr img {
    width: 100%;
    height: auto;
    display: block;
}

/* SCAN ME â€“ BIG + PULSE */
.scan {
    margin-top: 0px;
    font-size: 28px;           /* ðŸ‘ˆ BIG CTA */
    font-weight: 900;
    color: #ff2fd2;
    letter-spacing: 0.26em;
    animation: scanPulse 1.4s infinite;
    text-shadow:
        0 0 10px rgba(255,47,210,0.8),
        0 0 18px rgba(255,47,210,0.4);
}

@keyframes scanPulse {
    0%   { transform: scale(1);   opacity: .6; }
    50%  { transform: scale(1.12); opacity: 1; }
    100% { transform: scale(1);   opacity: .6; }
}

/* DJ NAME â€“ MORE PROMINENT */
.dj {
    margin-top: 2px;
    margin-bottom: 18px;
    font-size: 22px;           /* ðŸ‘ˆ stronger nameplate */
    font-weight: 900;
    color: #ffffff;
    letter-spacing: 0.10em;
    text-shadow:
        0 0 8px rgba(255,47,210,0.9),
        0 0 18px rgba(255,47,210,0.4);
}
</style>

<script>
(function () {
    // Refresh interval (milliseconds)
    // 5 minutes is ideal for live streaming
    const REFRESH_INTERVAL = 5 * 60 * 1000;

    setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('t', Date.now());
        window.location.replace(url.toString());
    }, REFRESH_INTERVAL);
})();
</script>


<script>
(function () {

    const DJ_UUID = new URLSearchParams(window.location.search).get('dj');
    if (!DJ_UUID) return;

    let lastState = null; // stringified snapshot

    async function checkLiveStatus() {
        try {
            const res = await fetch(
                `/qr/live_status.php?dj=${encodeURIComponent(DJ_UUID)}`,
                { cache: 'no-store' }
            );

            const data = await res.json();

            // Create a stable comparison key
            const currentState = JSON.stringify({
                live: data.live,
                eventId: data.eventId
            });

            // First run → initialise only
            if (lastState === null) {
                lastState = currentState;
                return;
            }

            // Any change = hard refresh
            if (currentState !== lastState) {
                const url = new URL(window.location.href);
                url.searchParams.set('t', Date.now());
                window.location.replace(url.toString());
            }

        } catch (e) {
            // silent fail
        }
    }

    // Run immediately, then poll
    checkLiveStatus();
    setInterval(checkLiveStatus, 10000);

})();
</script>


</head>
<body>
    
<div class="qr-tile">
    <div class="brand">MyDJRequests.com</div>

    <div class="qr">
        <div class="qr-inner">
            <img src="<?= htmlspecialchars($qrImg) ?>" />
        </div>
    </div>

    <div class="scan">SCAN ME</div>
    <div class="dj"><?= htmlspecialchars($djName) ?></div>
</div>







</body>
</html>