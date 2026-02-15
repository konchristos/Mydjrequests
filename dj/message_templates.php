<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$djId = (int)$_SESSION['dj_id'];
$pageTitle = "Message Templates";

$db = db();

// Load existing templates
$stmt = $db->prepare("
    SELECT notice_type, title, body
    FROM dj_notice_templates
    WHERE dj_id = ?
");
$stmt->execute([$djId]);

$templates = [
    'pre_event'  => ['title' => '', 'body' => ''],
    'live'       => ['title' => '', 'body' => ''],
    'post_event' => ['title' => '', 'body' => ''],
];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $templates[$row['notice_type']] = [
        'title' => $row['title'] ?? '',
        'body'  => $row['body'] ?? '',
    ];
}

require __DIR__ . '/layout.php';
?>

<style>
    
    
 /* Main settings container */
.settings-tile {
    max-width: 900px;
    margin: 0 auto;
}

/* Header */
.settings-title {
    margin: 0 0 6px;
    font-size: 26px;
}

.settings-subtitle {
    color: #aaa;
    margin-bottom: 26px;
    line-height: 1.5;
}

/* Message cards */
.message-template-card {
    background: #14141a;
    border: 1px solid #292933;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
}

.message-template-card h2 {
    margin: 0 0 18px;
    color: #ff2fd2;
}

/* Form fields */
.field-row {
    display: flex;
    flex-direction: column;
    margin-bottom: 16px;
}

.field-row label {
    font-size: 13px;
    font-weight: 600;
    color: #cfcfd8;
    margin-bottom: 6px;
}

.field-row label span {
    font-weight: 400;
    color: #888;
}

.field-row input,
.field-row textarea {
    background: #0f0f14;
    border: 1px solid #2e2e38;
    border-radius: 8px;
    padding: 10px 12px;
    color: #fff;
    font-size: 14px;
    font-family: inherit;
}

.field-row textarea {
    resize: vertical;
    min-height: 110px;
}

/* Live hint */
.live-tip {
    font-size: 13px;
    color: #aaa;
    margin-top: 6px;
}

/* Footer actions */
.settings-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 10px;
}

/* Button (matches your system) */
.btn-primary {
    background: #ff2fd2;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    color: #fff;
    font-weight: 800;
    cursor: pointer;
}   
    
    
</style>

<?php if (!empty($_GET['saved'])): ?>
<div class="section-card" style="border-color:#5fdb6e; max-width:900px; margin:0 auto 20px;">
    <strong style="color:#5fdb6e;">✓ Templates saved successfully</strong>
</div>
<?php endif; ?>

<form method="post" action="save_message_templates.php">

<div class="section-card settings-tile">

    <h1 class="settings-title">Event Message Templates</h1>
    <p class="settings-subtitle">
        These messages are shown automatically to guests based on your event status.
        You can override them per event if needed.
    </p>

<?php
$labels = [
    'pre_event'  => 'Pre-Event (Upcoming)',
    'live'       => 'Live Event',
    'post_event' => 'End of Event',
];

foreach ($labels as $type => $label):
    $t = $templates[$type];
?>
    <div class="message-template-card">

        <h2><?php echo e($label); ?></h2>

        <div class="field-row">
            <label>Title <span>(optional)</span></label>
            <input
                type="text"
                name="templates[<?php echo $type; ?>][title]"
                value="<?php echo e($t['title']); ?>"
                maxlength="120"
            >
        </div>

        <div class="field-row">
            <label>Message</label>
            <textarea
                name="templates[<?php echo $type; ?>][body]"
                rows="5"
                required
            ><?php echo e($t['body']); ?></textarea>
        </div>

        <?php if ($type === 'live'): ?>
            <p class="live-tip">
                💡 Tip: This is a good place to explain tipping, song requests,
                and any house rules.
            </p>
        <?php endif; ?>

    </div>
<?php endforeach; ?>

    <div class="settings-actions">
        <button type="submit" class="btn-primary">
            💾 Save Templates
        </button>
    </div>

</div>
</form>