<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = "Message Statuses - MyDJRequests";

$db = db();
$stmt = $db->prepare("
    SELECT notice_type, title, body
    FROM platform_notice_templates
    WHERE notice_type IN ('pre_event','live','post_event')
");
$stmt->execute();

$templates = [
    'pre_event'  => ['title' => '', 'body' => ''],
    'live'       => ['title' => '', 'body' => ''],
    'post_event' => ['title' => '', 'body' => ''],
];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = $row['notice_type'] ?? '';
    if (!isset($templates[$type])) {
        continue;
    }
    $templates[$type] = [
        'title' => $row['title'] ?? '',
        'body'  => $row['body'] ?? '',
    ];
}

require __DIR__ . '/layout.php';
?>

<style>
    .status-wrap { max-width: 900px; margin: 0 auto; }
    .status-hero {
        background: #121218;
        border: 1px solid #252531;
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 18px;
    }
    .status-hero h1 { margin: 0 0 8px; font-size: 26px; }
    .status-hero p { margin: 0; color: #b8b8c6; }

    .status-card {
        background: #13131a;
        border: 1px solid #252531;
        border-radius: 14px;
        padding: 18px;
        margin-bottom: 16px;
    }
    .status-card h2 {
        margin: 0 0 10px;
        color: #ff2fd2;
        font-size: 18px;
    }
    .status-label {
        display: inline-block;
        font-size: 12px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 999px;
        margin-bottom: 10px;
    }
    .label-upcoming { background: rgba(0,153,255,0.15); color: #6cc6ff; }
    .label-live { background: rgba(95,219,110,0.18); color: #5fdb6e; }
    .label-ended { background: rgba(255,87,87,0.15); color: #ff8b8b; }
    .status-field {
        margin: 8px 0;
    }
    .status-field label {
        display: block;
        font-size: 12px;
        color: #9aa0aa;
        margin-bottom: 6px;
        letter-spacing: .02em;
    }
    .status-box {
        background: #0f0f14;
        border: 1px solid #2b2b36;
        border-radius: 8px;
        padding: 10px 12px;
        color: #e8e8f2;
        font-size: 14px;
        white-space: pre-wrap;
    }
    .status-note {
        margin-top: 10px;
        font-size: 13px;
        color: #9c9cab;
    }
</style>

<div class="status-wrap">
    <div class="status-hero">
        <h1>Message Statuses</h1>
        <p>These are the platform default messages shown to guests based on event status.</p>
        <p class="status-note">These defaults are read-only during Alpha.</p>
    </div>

    <?php
    $labels = [
        'pre_event'  => ['Upcoming', 'label-upcoming'],
        'live'       => ['Live', 'label-live'],
        'post_event' => ['Ended', 'label-ended'],
    ];
    ?>

    <?php foreach ($labels as $type => $meta): ?>
        <?php
            $title = $templates[$type]['title'] ?? '';
            $body = $templates[$type]['body'] ?? '';
        ?>
        <div class="status-card">
            <div class="status-label <?php echo $meta[1]; ?>"><?php echo e($meta[0]); ?></div>
            <h2><?php echo e($meta[0]); ?> Message</h2>

            <div class="status-field">
                <label>Title</label>
                <div class="status-box"><?php echo e($title !== '' ? $title : 'No default title set'); ?></div>
            </div>

            <div class="status-field">
                <label>Message</label>
                <div class="status-box"><?php echo e($body !== '' ? $body : 'No default message set'); ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="status-note">
        Placeholders supported in messages: <code>{{DJ_NAME}}</code> and <code>{{EVENT_NAME}}</code>.
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
