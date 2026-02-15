<?php
// dj/index.php
require_once __DIR__ . '/../app/bootstrap.php';

$eventUuid = $_GET['event'] ?? null;
if (!$eventUuid) {
    die("Missing event");
}

$db = db();

$stmt = $db->prepare("
  SELECT 
    id,
    uuid,
    title,
    event_date,
    location,
    is_active
  FROM events
  WHERE uuid = ?
  LIMIT 1
");
$stmt->execute([$eventUuid]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Event not found");
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

<div class="dj-app">

  <!-- LEFT -->
  <aside class="dj-requests">
    <div class="dj-header">
  <h2>🎶 Requests</h2>
  <div class="dj-controls">
    <select id="djSort">
      <option value="popularity">Popularity</option>
      <option value="last">Last Requested</option>
      <option value="title">Title</option>
    </select>
    
  </div>
 </div>

<!-- 🔍 SEARCH ROW (NEW LINE) -->
<div class="dj-search-row">
  <input
    id="djSearch"
    type="text"
    placeholder="Search tracks..."
    autocomplete="off"
  />
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
</div>
    <div class="request-list"></div>
  </aside>

  <!-- MIDDLE -->
<main class="dj-detail">
    
<!-- Event Info Tile -->
<div class="dj-event-tile">
  <h1 class="event-title">
    <?= htmlspecialchars_decode($event['title'], ENT_QUOTES) ?>
  </h1>

  <div class="event-meta">
    <span>📅 <?= date('D j M Y', strtotime($event['event_date'])) ?></span>
    <span>📍 <?= htmlspecialchars($event['location'] ?: '—') ?></span>
  </div>

  <div class="event-code">
    Event ID:
    <strong><?= substr(htmlspecialchars($event['uuid']), 0, 8) ?></strong>
<span class="event-live">LIVE</span>
  </div>
</div>

  <!-- Crowd Mood -->
  <div id="djMood" class="dj-mood clickable"></div>

  <!-- Track Panel -->
  <div id="trackPanel">
    <div class="empty-panel">
      Select a track to view details
    </div>
  </div>


</main>


<!-- RIGHT -->
<aside class="dj-messages">

  <!-- Header -->
  <div class="messages-header">
    <div class="messages-header-top">
      <h2>💬 Live Messages</h2>
      

      <div class="message-stats" id="messageStats"></div>
    </div>
    
          <div class="message-tabs">
  <button class="message-tab active" data-status="all">All</button>
  <button class="message-tab" data-status="active">Active</button>
  <button class="message-tab" data-status="muted">Muted</button>
  <button class="message-tab" data-status="blocked">Blocked</button>
</div>

  </div> <!-- ✅ CLOSE messages-header -->

  <!-- Message list -->
  <div id="messageList"></div>

</aside>

</div>


<script>
window.DJ_CONFIG = {
  eventId: <?= (int)$event['id'] ?>,
  eventUuid: "<?= htmlspecialchars($eventUuid) ?>",
  eventTitle: "<?= htmlspecialchars($event['title']) ?>",
  eventDate: "<?= htmlspecialchars($event['event_date'] ?? '') ?>",   // ✅ ADD THIS
  pollInterval: 10000
};
</script>



<div id="messageActionSheet" class="message-actions hidden">
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


<script src="/dj/dj.js?v=<?= time() ?>"></script>

</body>
</html>