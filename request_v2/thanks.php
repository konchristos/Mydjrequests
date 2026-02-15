<?php
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Thanks!</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="background:#0d0d12;color:#fff;font-family:sans-serif;text-align:center;padding:40px">
    <h1>💖 Thanks for supporting the DJ!</h1>
    <p>Your tip was received successfully.</p>
    <p>You can now return to the event.</p>

    <a href="/request/?event=<?= htmlspecialchars($_GET['event'] ?? '') ?>"
       style="display:inline-block;margin-top:20px;padding:12px 20px;background:#ff2fd2;color:#fff;border-radius:10px;text-decoration:none">
        Back to Event
    </a>
</body>
</html>