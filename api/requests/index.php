<?php
require_once __DIR__ . '/../app/bootstrap.php';

// event UUID from /request/{uuid}
$eventUuid = $_GET['event'] ?? '';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Song Request - MyDJRequests</title>
</head>
<body>
<?php if (!$eventUuid): ?>
    <h1>Event not found.</h1>
    <p>Please check the link or ask the DJ for a new QR code.</p>
<?php else: ?>
    <h1>Send Your Song Request</h1>
    <form id="requestForm" method="post" action="<?php echo e(url('api/requests/submit.php')); ?>">
        <input type="hidden" name="event_uuid" value="<?php echo e($eventUuid); ?>">
        <label>Song title:<br><input type="text" name="song_title" required></label><br><br>
        <label>Artist (optional):<br><input type="text" name="artist"></label><br><br>
        <label>Your name (optional):<br><input type="text" name="requester_name"></label><br><br>
        <label>Message to DJ (optional):<br>
            <textarea name="message" rows="3"></textarea>
        </label><br><br>
        <button type="submit">Send Request</button>
    </form>
    <p id="status"></p>

    <script>
        const form = document.getElementById('requestForm');
        const statusEl = document.getElementById('status');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            statusEl.textContent = 'Sending...';

            const formData = new FormData(form);

            const res = await fetch(form.action, {
                method: 'POST',
                body: formData
            });

            const data = await res.json();

            if (data.success) {
                statusEl.textContent = 'Request sent! Thank you 🙌';
                form.reset();
            } else {
                statusEl.textContent = data.message || 'Something went wrong.';
            }
        });
    </script>
<?php endif; ?>
</body>
</html>