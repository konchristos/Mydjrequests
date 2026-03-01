<?php
// dj/index.php
require_once __DIR__ . '/../app/bootstrap.php';

$eventUuid = $_GET['event'] ?? null;
if (!$eventUuid) {
    die("Missing event");
}

$db = db();

function djHasColumn(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$hasTipsBoostOverride = djHasColumn($db, 'events', 'tips_boost_enabled');
$eventSelect = "
  SELECT
    id,
    user_id,
    uuid,
    title,
    event_date,
    location,
    is_active,
    event_state" . ($hasTipsBoostOverride ? ", tips_boost_enabled" : "") . "
  FROM events
  WHERE uuid = ?
  LIMIT 1
";
$stmt = $db->prepare($eventSelect);
$stmt->execute([$eventUuid]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Event not found");
}

// Effective tips/boost visibility for DJ support tile:
// global prod toggle AND (event override if set, else DJ default).
$platformTipsBoostEnabled = false;
try {
    $settingsStmt = $db->prepare("
        SELECT `value`
        FROM app_settings
        WHERE `key` IN ('patron_payments_enabled_prod', 'patron_payments_enabled')
        ORDER BY FIELD(`key`, 'patron_payments_enabled_prod', 'patron_payments_enabled')
        LIMIT 1
    ");
    $settingsStmt->execute();
    $platformTipsBoostEnabled = ((string)$settingsStmt->fetchColumn() === '1');
} catch (Throwable $e) {
    $platformTipsBoostEnabled = false;
}

$djDefaultTipsBoostEnabled = false;
try {
    $userSettingStmt = $db->prepare("
        SELECT default_tips_boost_enabled
        FROM user_settings
        WHERE user_id = ?
        LIMIT 1
    ");
    $userSettingStmt->execute([(int)($event['user_id'] ?? 0)]);
    $djDefaultTipsBoostEnabled = ((string)$userSettingStmt->fetchColumn() === '1');
} catch (Throwable $e) {
    $djDefaultTipsBoostEnabled = false;
}

$eventOverrideRaw = $event['tips_boost_enabled'] ?? null;
$eventTipsBoostEnabled = ($eventOverrideRaw === null || $eventOverrideRaw === '')
    ? $djDefaultTipsBoostEnabled
    : ((int)$eventOverrideRaw === 1);

$tipsBoostVisible = $platformTipsBoostEnabled && $eventTipsBoostEnabled;

$pollsPremiumEnabled = mdjr_get_user_plan($db, (int)($event['user_id'] ?? 0)) === 'premium';

$spotifySyncEnabled = false;
$spotifyHasPlaylist = false;
try {
    $spotifyAllowedStmt = $db->prepare("
        SELECT spotify_access_enabled
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $spotifyAllowedStmt->execute([(int)$event['user_id']]);
    $spotifyAllowed = ((int)$spotifyAllowedStmt->fetchColumn() === 1);

    $spotifyTokenStmt = $db->prepare("
        SELECT COUNT(*)
        FROM dj_spotify_accounts
        WHERE dj_id = ?
          AND expires_at > NOW()
    ");
    $spotifyTokenStmt->execute([(int)$event['user_id']]);
    $spotifyConnected = ((int)$spotifyTokenStmt->fetchColumn() > 0);

    $playlistStmt = $db->prepare("
        SELECT COUNT(*)
        FROM event_spotify_playlists
        WHERE event_id = ?
    ");
    $playlistStmt->execute([(int)$event['id']]);
    $spotifyHasPlaylist = ((int)$playlistStmt->fetchColumn() > 0);

    $spotifySyncEnabled = ($spotifyAllowed && $spotifyConnected && $spotifyHasPlaylist);
} catch (Throwable $e) {
    $spotifySyncEnabled = false;
    $spotifyHasPlaylist = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DJ Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/dj/dj.css?v=<?= time() ?>">
</head>

<body class="dj-body">
<div class="container">
    <div style="
    position: fixed;
    bottom: 12px;
    right: 12px;
    background: #4b2d7f;
    color: #fff;
    font-size: 11px;
    padding: 6px 10px;
    border-radius: 999px;
    opacity: 0.75;
    z-index: 9999;
">
    DJ Page · V1.4</div>    

<div class="dj-app">

  <!-- LEFT -->
  <aside class="dj-requests">
    <div class="dj-header">
  <h2>🎶 Requests</h2>
  <div class="dj-controls">
    <select id="djSort">
      <option value="popularity">Popularity</option>
      <?php if (is_admin()): ?>
        <option value="bpm">BPM (Low to High)</option>
      <?php endif; ?>
      <option value="last">Last Requested</option>
      <option value="title">Title</option>
    </select>
    
  </div>
 </div>

<!-- 🔍 SEARCH ROW (NEW LINE) -->
<div class="dj-search-row">
  <div class="dj-search-wrap">
    <input
      id="djSearch"
      type="text"
      placeholder="Search tracks..."
      autocomplete="off"
    />
    <button id="djSearchClear" class="dj-search-clear hidden" type="button" aria-label="Clear search">✕</button>
  </div>
</div>


<!-- ✅ NEW: Status Tabs -->
<div class="dj-tabs">
<button class="dj-tab active" data-status="all">
  All <span class="tab-count"></span>
</button>

<button class="dj-tab" data-status="active">
  Active <span class="tab-count"></span>
</button>

<button class="dj-tab" data-status="played">
  Played <span class="tab-count"></span>
</button>

<button class="dj-tab" data-status="skipped">
  Skipped <span class="tab-count"></span>
</button>

<button class="dj-tab" data-status="boost">
  Boosted <span class="tab-count"></span>
</button>

</div>
    <div class="request-list"></div>
  </aside>

  <div class="column-splitter" id="splitterLeft" role="separator" aria-label="Resize left panel" tabindex="0"></div>

  <!-- MIDDLE -->
<main class="dj-detail">
    
<!-- Event Info Tile -->
<div class="dj-event-tile">
<div class="event-header-row">
  <h1 class="event-title">
    <?= htmlspecialchars_decode($event['title'], ENT_QUOTES) ?>
  </h1>

  
</div>

<div class="event-meta">
  <span>📅 <?= date('D j M Y', strtotime($event['event_date'])) ?></span>
  <span>📍 <?= htmlspecialchars($event['location'] ?: '—') ?></span>
  
</div>
  
  
    <div class="dj-event-actions">
      <div class="dj-event-state" id="djEventStateControl"></div>
      <span id="djEventStateBadge"></span>
    </div>

</div>

<!-- TOP WIDGETS ROW -->
<div class="dj-top-widgets">

  <div class="dj-left-stack">
    <button id="djTopPatronsTile" class="insight-tile" type="button">
      <div class="insight-label">🏆 Top Patron</div>
      <div id="djTopPatronName" class="insight-value small">—</div>
      <div id="djTopPatronMeta" class="insight-sub">No activity yet</div>
    </button>

    <div id="djSupportTile" class="dj-support-tile">
      <div class="dj-tile-inner">
        <div class="support-label">💜 Support</div>
        <div id="djSupportAmount" class="support-amount">$0.00</div>
        <div class="support-sub">Tips & boosts</div>
      </div>
    </div>
  </div>

  <div id="djMood" class="dj-mood clickable"></div>

  <div class="dj-insights-grid">
    <button id="djConnectedTile" class="insight-tile" type="button">
      <div class="insight-label">👥 Connected</div>
      <div id="djConnectedCount" class="insight-value">0</div>
      <div class="insight-sub">Patrons joined</div>
    </button>

    <div class="insight-tile">
      <div class="insight-label">📈 Engagement</div>
      <div id="djEngagementRate" class="insight-value">0%</div>
      <div id="djEngagementMeta" class="insight-sub">0 active</div>
    </div>
  </div>

</div>
  

  <!-- Track Panel -->
  <div id="trackPanel">
    <div class="empty-panel">
      Select a track to view details
    </div>
  </div>


</main>

  <div class="column-splitter" id="splitterRight" role="separator" aria-label="Resize right panel" tabindex="0"></div>

<!-- RIGHT -->
<aside class="dj-messages">

  <!-- Header -->
  <div class="messages-header">
    <div class="messages-header-top">
      <h2>💬 Live Messages</h2>
      

      <div class="message-stats" id="messageStats"></div>
    </div>

    <div class="message-primary-tabs">
      <button class="message-primary-tab active" data-view="chats">Chats</button>
      <button class="message-primary-tab" data-view="broadcasts">Broadcasts</button>
      <button class="message-primary-tab" data-view="polls">Polls</button>
    </div>

    <div class="message-tabs" id="messageStatusTabs">
      <button class="message-tab active" data-status="all">All</button>
      <button class="message-tab" data-status="active">Active</button>
      <button class="message-tab" data-status="muted">Muted</button>
      <button class="message-tab" data-status="blocked">Blocked</button>
    </div>

  </div> <!-- ✅ CLOSE messages-header -->

  <!-- Chats view -->
  <div id="chatView">
    <div id="messageList"></div>
    <div id="djMessageThread" class="dj-message-thread hidden"></div>

    <div id="messageReplyBox" class="message-reply-box hidden">
      <div class="reply-header">
        <div id="replyGuestLabel" class="reply-guest-label">Reply target: none selected</div>
        <button id="replyCloseBtn" type="button" class="reply-close-btn" aria-label="Close reply">✕</button>
      </div>
      <textarea id="djReplyText" rows="2" placeholder="Type a private reply..."></textarea>
      <div class="reply-actions">
        <button id="djReplyCancel" type="button" class="reply-btn secondary">Cancel Reply</button>
        <button id="djReplySend" type="button" class="reply-btn primary">Send Reply</button>
      </div>
    </div>
  </div>

  <!-- Broadcasts view -->
  <div id="broadcastsView" class="message-view hidden">
    <div class="message-view-actions">
      <button id="sendBroadcastFromTabBtn" type="button" class="reply-btn primary">Send Broadcast</button>
    </div>
    <div id="broadcastsList" class="message-view-list"></div>
  </div>

  <!-- Polls view -->
  <div id="pollsView" class="message-view hidden">
    <div class="message-view-actions">
      <?php if ($pollsPremiumEnabled): ?>
        <button id="createPollBtn" type="button" class="reply-btn primary">Create Poll</button>
      <?php else: ?>
        <button id="createPollBtn" type="button" class="reply-btn primary" disabled title="Premium feature. Upgrade to unlock Poll creation.">Create Poll <span class="premium-badge">PRO</span></button>
      <?php endif; ?>
    </div>
    <?php if (!$pollsPremiumEnabled): ?>
      <div class="premium-lock-note" role="note">
        <span class="premium-badge">PRO</span>
        <span>Polls are a Premium feature. Upgrade your plan to unlock poll creation and results.</span>
      </div>
    <?php endif; ?>
    <div id="pollsList" class="message-view-list<?= $pollsPremiumEnabled ? '' : ' hidden' ?>"></div>
  </div>

</aside>

</div>


<script>
window.DJ_CONFIG = {
  eventId: <?= (int)$event['id'] ?>,
  eventUuid: "<?= htmlspecialchars($eventUuid) ?>",
  eventTitle: "<?= htmlspecialchars($event['title']) ?>",
  eventDate: "<?= htmlspecialchars($event['event_date'] ?? '') ?>",
  eventState: "<?= htmlspecialchars($event['event_state']) ?>",
  isAdmin: <?= is_admin() ? 'true' : 'false' ?>,
  tipsBoostVisible: <?= $tipsBoostVisible ? 'true' : 'false' ?>,
  pollsPremiumEnabled: <?= $pollsPremiumEnabled ? 'true' : 'false' ?>,
  spotifySyncEnabled: <?= $spotifySyncEnabled ? 'true' : 'false' ?>,
  spotifyHasPlaylist: <?= $spotifyHasPlaylist ? 'true' : 'false' ?>,
  pollInterval: 10000
};
</script>



<div id="messageActionSheet" class="message-actions hidden">
  <button data-action="reply">Reply</button>
  <button data-action="filter">🔍 Filter by guest</button>
  <button data-action="mute">🔕 Mute guest</button>
  <button data-action="block">⛔ Block guest</button>
  <button data-action="cancel" class="cancel">Cancel</button>
</div>


<div id="toastContainer" class="toast-container"></div>


<!-- =========================
     MOOD BREAKDOWN MODAL
========================= -->
<div id="moodModal" class="mood-modal hidden">
    <div class="mood-modal-content">
        <div class="mood-modal-header">
          <h3>🎭 Crowd Mood Breakdown</h3>
          <button id="closeMoodModal">✕</button>
        </div>

        <div class="mood-modal-tabs">
    
        <div class="mood-columns">
        
          <!-- POSITIVE -->
          <div class="mood-column positive">
            <div class="mood-column-header">
              👍 Positive <span id="positiveCount" class="mood-count">0</span>
            </div>
            <div id="positiveList" class="mood-vote-list"></div>
          </div>
    
          <!-- NEGATIVE -->
          <div class="mood-column negative">
            <div class="mood-column-header">
              👎 Negative <span id="negativeCount" class="mood-count">0</span>
            </div>
            <div id="negativeList" class="mood-vote-list"></div>
          </div>
    
            </div>
    
    
    
        </div>
    </div>
</div>

<div id="connectedPatronsModal" class="support-modal hidden">
  <div class="support-modal-content insights-modal-content connected-patrons-modal-content">
    <div class="mood-modal-header">
      <h3>👥 Connected Patrons</h3>
      <button id="closeConnectedPatronsModal" type="button">✕</button>
    </div>

    <div id="connectedPatronsList" class="top-patrons-list"></div>
  </div>
</div>

<div id="topPatronsModal" class="support-modal hidden">
  <div class="support-modal-content insights-modal-content">
    <div class="mood-modal-header">
      <h3>🏆 Top Patrons</h3>
      <button id="closeTopPatronsModal" type="button">✕</button>
    </div>

    <div id="topPatronsList" class="top-patrons-list"></div>
  </div>
</div>

<div id="broadcastModal" class="support-modal hidden">
  <div class="support-modal-content insights-modal-content">
    <div class="mood-modal-header">
      <h3>📢 Event Broadcast</h3>
      <button id="closeBroadcastModal" type="button">✕</button>
    </div>

    <form id="broadcastForm" class="broadcast-form">
      <p class="broadcast-help">Send to all patrons connected to this event. New patrons will also see this in their message history.</p>
      <textarea id="broadcastMessage" name="message" rows="5" maxlength="1000" placeholder="Type your broadcast message..." required></textarea>
      <div class="broadcast-form-actions">
        <button type="button" class="reply-btn secondary" id="broadcastCancelBtn">Cancel</button>
        <button type="submit" class="reply-btn primary" id="broadcastSendBtn">Send Broadcast</button>
      </div>
      <div id="broadcastStatus" class="broadcast-status"></div>
    </form>
  </div>
</div>

<div id="createPollModal" class="support-modal hidden">
  <div class="support-modal-content insights-modal-content">
    <div class="mood-modal-header">
      <h3>🗳️ Create Poll</h3>
      <button id="closeCreatePollModal" type="button">✕</button>
    </div>

    <form id="createPollForm" class="broadcast-form">
      <label for="pollQuestion" style="font-size:12px;color:#b9bfd0;">Question</label>
      <textarea id="pollQuestion" name="question" rows="3" maxlength="500" placeholder="Ask your crowd a question..." required></textarea>

      <div id="pollOptionsWrap" class="poll-options-wrap">
        <label style="font-size:12px;color:#b9bfd0;">Answers</label>
        <input type="text" class="poll-option-input" name="poll_options[]" maxlength="255" placeholder="Option 1" required>
        <input type="text" class="poll-option-input" name="poll_options[]" maxlength="255" placeholder="Option 2" required>
      </div>

      <div class="broadcast-form-actions">
        <button type="button" class="reply-btn secondary" id="pollAddOptionBtn">Add Response</button>
        <button type="button" class="reply-btn secondary" id="pollCancelBtn">Cancel</button>
        <button type="submit" class="reply-btn primary" id="pollCreateBtn">Create Poll</button>
      </div>
      <div id="pollCreateStatus" class="broadcast-status"></div>
    </form>
  </div>
</div>

<div id="pollDetailsModal" class="support-modal hidden">
  <div class="support-modal-content insights-modal-content">
    <div class="mood-modal-header">
      <h3>🗳️ Poll Votes</h3>
      <button id="closePollDetailsModal" type="button">✕</button>
    </div>
    <div id="pollDetailsBody" style="padding:14px; overflow-y:auto; max-height:70vh;"></div>
  </div>
</div>

<div id="manualMatchModal" class="support-modal hidden">
  <div class="support-modal-content manual-match-modal-content">
    <div class="mood-modal-header">
      <h3>🎯 Admin Metadata Match</h3>
      <button id="closeManualMatchModal" type="button">✕</button>
    </div>
    <div class="manual-match-body">
      <div id="manualMatchTrackMeta" class="manual-match-track-meta"></div>
      <div class="manual-match-search-row">
        <input
          id="manualMatchSearchInput"
          type="text"
          maxlength="180"
          placeholder="Refine search title/artist..."
        >
        <button id="manualMatchSearchBtn" type="button" class="reply-btn secondary">Search</button>
      </div>
      <div id="manualMatchStatus" class="broadcast-status"></div>
      <div id="manualMatchResults" class="manual-match-results"></div>
    </div>
  </div>
</div>

<!-- TIP MODAL -->
<div id="supportModal" class="support-modal hidden">
  <div class="support-modal-content">
    <div class="mood-modal-header">
      <h3>💜 Event Support</h3>
      <button id="closeSupportModal">✕</button>
    </div>

    <div id="supportList" style="padding:14px; overflow-y:auto;"></div>
  </div>
</div>

<script src="/dj/dj.js?v=<?= time() ?>"></script>

</body>
</html>
