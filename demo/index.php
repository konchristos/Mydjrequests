<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Song Request — MyDJRequests (Demo)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
body {
    margin: 0;
    font-family: Inter, sans-serif;
    background: #0d0d0f;
    color: #fff;
}

.demo-wrap {
    max-width: 420px;
    margin: 0 auto;
    padding: 28px 18px;
}

.demo-header {
    text-align: center;
    margin-bottom: 24px;
}

.demo-header h1 {
    margin: 0;
    font-size: 24px;
    color: #ff2fd2;
}

.demo-header p {
    margin-top: 6px;
    font-size: 14px;
    color: #aaa;
}

.live-badge {
    display: inline-block;
    margin-top: 10px;
    padding: 4px 10px;
    border-radius: 999px;
    background: linear-gradient(135deg,#ff2fd2,#2fd8ff);
    color: #050510;
    font-size: 11px;
    font-weight: 700;
}

.demo-card {
    background: #1a1a1f;
    border: 1px solid #292933;
    border-radius: 14px;
    padding: 18px;
}

.demo-card label {
    font-size: 13px;
    color: #ccc;
}

.demo-card input,
.demo-card textarea {
    width: 100%;
    margin-top: 6px;
    margin-bottom: 14px;
    padding: 10px;
    border-radius: 8px;
    border: none;
    background: #111;
    color: #aaa;
    font-size: 14px;
}

.demo-card button {
    width: 100%;
    padding: 12px;
    border-radius: 10px;
    border: none;
    background: #ff2fd2;
    color: #fff;
    font-weight: 700;
    font-size: 15px;
    opacity: 0.6;
    cursor: not-allowed;
}

.demo-note {
    margin-top: 14px;
    font-size: 13px;
    color: #888;
    text-align: center;
}

.demo-footer {
    margin-top: 28px;
    text-align: center;
    font-size: 13px;
    color: #777;
}

.demo-footer a {
    display: inline-block;
    margin-top: 8px;
    color: #ff2fd2;
    text-decoration: none;
    font-weight: 600;
}
</style>
</head>

<body>

<div class="demo-wrap">

    <div class="demo-header">
        <h1>Send Your Song Request</h1>
        <p>Disco / Hi-NRG Night</p>
        <span class="live-badge">LIVE</span>
    </div>

    <div class="demo-card">
        <label>Song title</label>
        <input type="text" placeholder="e.g. Born To Be Alive" disabled>

        <label>Artist (optional)</label>
        <input type="text" placeholder="Patrick Hernandez" disabled>

        <label>Your name (optional)</label>
        <input type="text" placeholder="Alex" disabled>

        <label>Message to DJ (optional)</label>
        <textarea rows="3" placeholder="Play this for the birthday crew 🎉" disabled></textarea>

        <button disabled>Send Request</button>

        <div class="demo-note">
            Demo mode — requests are disabled
        </div>
    </div>

    <div class="demo-footer">
        No apps • No accounts • Just scan & request<br>
        <a href="/dj/register.php">DJs: Start your free trial</a>
    </div>

</div>

</body>
</html>