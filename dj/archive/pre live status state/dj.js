const listEl        = document.querySelector(".request-list");
const panelEl       = document.getElementById("trackPanel");
const messageListEl = document.getElementById("messageList");

const mutedGuests = new Set();
const blockedGuests = new Set();

const DJ_CONFIG = window.DJ_CONFIG || {};
const EVENT_ID  = DJ_CONFIG.eventId || null;
const POLL_MS   = DJ_CONFIG.pollInterval || 10000

if (!EVENT_ID) {
  console.error("❌ EVENT_ID missing — DJ page cannot function");
}

/* ===============================
   REQUEST STATE
================================ */
let djRequestsCache = [];
let currentSort = "popularity";
let activeTrackKey = null;
let firstLoad = true;
let searchQuery = "";


/* ===============================
   REQUEST FILTER STATE
================================ */
let requestStatusFilter = "all";


/* ===============================
   MOOD STATE
================================ */
let moodData = null;
const moodEl = document.getElementById("djMood");


/* ===============================
   MESSAGE STATE
================================ */
let messageCache = [];
let activeGuestToken = null;
let filterByGuest = false;
let messageStatusFilter = "all"; // all | active | muted | blocked


// ===============================
// TOAST HELPER
// ===============================
function showToast(message, type = "info") {
  const container = document.getElementById("toastContainer");
  if (!container) {
    console.warn("Toast container missing");
    return;
  }

  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.textContent = message;

  container.appendChild(toast);

  setTimeout(() => toast.remove(), 3000);
}

// ===============================
// MESSAGE TAB SWITCH
// ===============================

function switchMessageTab(status) {
  messageStatusFilter = status;

  document.querySelectorAll(".message-tab").forEach(tab => {
    tab.classList.toggle(
      "active",
      tab.dataset.status === status
    );
  });
}

// ===============================
// REQUEST TAB COUNTS
// ===============================
function updateRequestTabCounts() {
  const counts = {
    all: djRequestsCache.length,
    active: 0,
    played: 0,
    skipped: 0
  };

  djRequestsCache.forEach(r => {
    if (r.track_status && counts[r.track_status] !== undefined) {
      counts[r.track_status]++;
    }
  });

  document.querySelectorAll(".dj-tab").forEach(tab => {
    const status = tab.dataset.status;
    const countEl = tab.querySelector(".tab-count");
    if (!countEl) return;

    countEl.textContent =
      status === "all"
        ? `(${counts.all})`
        : `(${counts[status] || 0})`;
  });
}

/* ===============================
   LIVE STATUS BADGE
================================ */

function getEventStatus(eventDateStr) {
  if (!eventDateStr) return "upcoming";

  // Start of event day local time
  const start = new Date(`${eventDateStr}T00:00:00`);

  // End = 6am next day local time
  const end = new Date(start);
  end.setDate(end.getDate() + 1);
  end.setHours(6, 0, 0, 0);

  const now = new Date();

  if (now < start) return "upcoming";
  if (now >= start && now < end) return "live";
  return "ended";
}

function renderEventBadge() {
  const badgeEl = document.querySelector(".event-live");
  if (!badgeEl) return;

  const status = getEventStatus(DJ_CONFIG.eventDate);

  badgeEl.classList.remove("live", "upcoming", "ended");

  if (status === "live") {
    badgeEl.textContent = "LIVE";
    badgeEl.classList.add("live");
  } else if (status === "ended") {
    badgeEl.textContent = "ENDED";
    badgeEl.classList.add("ended");
  } else {
    badgeEl.textContent = "UPCOMING";
    badgeEl.classList.add("upcoming");
  }
}

document.addEventListener("DOMContentLoaded", () => {
  renderEventBadge();
  setInterval(renderEventBadge, 60000);
});

// ===============================
// TIME AGO HELPER (DJ)
// ===============================
function timeAgo(ts) {
  if (!ts) return "";

  // Ensure MySQL DATETIME is treated as UTC
  const utc = new Date(ts.replace(" ", "T") + "Z");
  const diff = Math.floor((Date.now() - utc.getTime()) / 1000);

  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff / 60) + " min ago";
  if (diff < 86400) return Math.floor(diff / 3600) + " hr ago";
  if (diff < 604800) return Math.floor(diff / 86400) + " days ago";

  return utc.toLocaleDateString();
}


function formatLocalTime(ts) {
  if (!ts) return "";
  return new Date(ts.replace(" ", "T") + "Z")
    .toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}


function formatLocalDateTime(ts) {
  if (!ts) return "";

  const d = new Date(ts.replace(" ", "T") + "Z");

  const date = d.toLocaleDateString([], {
    weekday: "short",
    day: "numeric",
    month: "short"
  });

  const time = d.toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit"
  });

  return `${date}, ${time}`;
}
/* ===============================
   MESSAGE ACTION SHEET
================================ */
/* ===============================
   MESSAGE ACTION SHEET (GLOBAL)
================================ */

let actionSheet = null;
let actionTargetMessage = null;

/**
 * Open the action menu next to a message
 */
function openMessageActions(el, msg) {
  if (!actionSheet) return;

  actionTargetMessage = msg;

  const token = msg.guest_token;
  const isMuted   = mutedGuests.has(token);
  const isBlocked = blockedGuests.has(token);

  // 🔄 Update action labels dynamically (EXISTING)
  const muteBtn  = actionSheet.querySelector('[data-action="mute"], [data-action="unmute"]');
  const blockBtn = actionSheet.querySelector('[data-action="block"], [data-action="unblock"]');

  if (muteBtn) {
    muteBtn.dataset.action = isMuted ? "unmute" : "mute";
    muteBtn.textContent    = isMuted ? "🔊 Unmute guest" : "🔕 Mute guest";

    // If blocked, muting makes no sense
    muteBtn.style.display = isBlocked ? "none" : "";
  }

  if (blockBtn) {
    blockBtn.dataset.action = isBlocked ? "unblock" : "block";
    blockBtn.textContent    = isBlocked ? "🚫 Unblock guest" : "⛔ Block guest";
  }

  // 🔍 FILTER TOGGLE (NEW — SAFE ADDITION)
  const filterBtn = actionSheet.querySelector('[data-action="filter"], [data-action="unfilter"]');
  const isFilteredToThisGuest =
    filterByGuest && activeGuestToken === token;

  if (filterBtn) {
    filterBtn.dataset.action = isFilteredToThisGuest ? "unfilter" : "filter";
    filterBtn.textContent    = isFilteredToThisGuest
      ? "❌ Unfilter guest"
      : "🔍 Filter by guest";
  }

  // 📐 Existing positioning logic (UNCHANGED)
  const rect = el.getBoundingClientRect();
  const sheetWidth = 220;
  const viewportWidth = window.innerWidth;

  let left = rect.right + 10;

  // Flip left if off-screen
  if (left + sheetWidth > viewportWidth) {
    left = rect.left - sheetWidth - 10;
  }

  actionSheet.style.top  = `${rect.top + window.scrollY}px`;
  actionSheet.style.left = `${left}px`;
  actionSheet.classList.remove("hidden");
}

/**
 * Close the action menu
 */
function closeMessageActions() {
  if (!actionSheet) return;
  actionSheet.classList.add("hidden");
  actionTargetMessage = null;
}




/* ===============================
   INIT AFTER PAGE LOAD
================================ */
document.addEventListener("DOMContentLoaded", () => {

  // Grab the menu AFTER page loads
  actionSheet = document.getElementById("messageActionSheet");

  if (!actionSheet) {
    console.error("❌ messageActionSheet not found");
    return;
  }

  /* ===============================
     ACTION SHEET BUTTONS
  ============================== */
  actionSheet.addEventListener("click", e => {
    const btn = e.target.closest("button");
    if (!btn) return;

    const action = btn.dataset.action;

    if (action === "cancel") {
      closeMessageActions();
      return;
    }

    if (!actionTargetMessage) return;

    const token = actionTargetMessage.guest_token;

switch (action) {

  case "filter":
    activeGuestToken = token;
    filterByGuest = true;
    showToast(`Filtered to ${actionTargetMessage.patron_name || "Guest"}`);
    break;

  case "unfilter":
    filterByGuest = false;
    activeGuestToken = null;
    showToast("Filter cleared", "info");
    break;

  case "mute":
  mutedGuests.add(token);
  blockedGuests.delete(token); // enforce single status
  switchMessageTab("muted");
  showToast(`${actionTargetMessage.patron_name || "Guest"} muted`, "info");
  break;

case "unmute":
  mutedGuests.delete(token);
  switchMessageTab("active");
  showToast(`${actionTargetMessage.patron_name || "Guest"} unmuted`, "success");
  break;

case "block":
  blockedGuests.add(token);
  mutedGuests.delete(token); // enforce single status
  switchMessageTab("blocked");
  showToast(`${actionTargetMessage.patron_name || "Guest"} blocked`, "error");
  break;

case "unblock":
  blockedGuests.delete(token);
  switchMessageTab("active");
  showToast(`${actionTargetMessage.patron_name || "Guest"} unblocked`, "success");
  break;
}

    closeMessageActions();
    renderMessages();
  });

  // Click outside closes menu
  document.addEventListener("click", e => {
    if (!actionSheet.contains(e.target)) {
      closeMessageActions();
    }
  });

  /* ===============================
     MESSAGE STATUS TABS (STEP 4)
  ============================== */
  document.querySelectorAll(".message-tab").forEach(tab => {
    tab.addEventListener("click", () => {
      document
        .querySelectorAll(".message-tab")
        .forEach(t => t.classList.remove("active"));

      tab.classList.add("active");
      messageStatusFilter = tab.dataset.status;
      renderMessages();
    });
  });

});



/* =========================================
   EVENT DETAILS
========================================= */

(function initEventInfo() {
  const titleEl  = document.getElementById("eventTitle");
  const metaEl   = document.getElementById("eventMeta");

  if (!titleEl) return;

  titleEl.textContent = DJ_CONFIG.eventTitle || "Unknown Event";

  const shortUuid = DJ_CONFIG.eventUuid
    ? DJ_CONFIG.eventUuid.slice(-6).toUpperCase()
    : "";

  metaEl.textContent = shortUuid
    ? `Event code: ${shortUuid}`
    : "";
})();
/* =========================================
   LOAD REQUESTS
========================================= */
async function loadDjRequests() {
  if (!EVENT_ID) return;

  try {
    const res = await fetch(`/api/dj/get_requests.php?event_id=${EVENT_ID}`);
    const data = await res.json();

    if (data.ok && Array.isArray(data.rows)) {
      djRequestsCache = data.rows;
      updateRequestTabCounts();   // ✅ ADD
      renderDjRequests();
    }
  } catch (err) {
    console.error("DJ request load failed", err);
  }
}

/* =========================================
   REQUEST FILTER
========================================= */

document.addEventListener("DOMContentLoaded", () => {
  const tabEls = document.querySelectorAll(".dj-tab");

  if (!tabEls.length) return;

  tabEls.forEach(tab => {
    tab.addEventListener("click", () => {
      tabEls.forEach(t => t.classList.remove("active"));
      tab.classList.add("active");

      requestStatusFilter = tab.dataset.status || "all";
      firstLoad = true;
      renderDjRequests();
    });
  });
});

  /* ===============================
     SEARCH INPUT
  ============================== */
  const searchInput = document.getElementById("djSearch");

  if (searchInput) {
    searchInput.addEventListener("input", e => {
      searchQuery = e.target.value.trim().toLowerCase();
      firstLoad = false;          // 👈 don’t auto-select first row while searching
      renderDjRequests();
    });
  }

/* =========================================
   RENDER REQUEST LIST
========================================= */
function renderDjRequests() {
  if (!listEl) return;

  let rows = [...djRequestsCache];

  /* STATUS FILTER (existing) */
  if (requestStatusFilter !== "all") {
    rows = rows.filter(r => r.track_status === requestStatusFilter);
  }

  /* 🔍 SEARCH FILTER (NEW) */
  if (searchQuery) {
    rows = rows.filter(r => {
      const title  = (r.song_title || "").toLowerCase();
      const artist = (r.artist || "").toLowerCase();

      const requesters = (r.requesters || [])
        .map(x => x.name.toLowerCase())
        .join(" ");

      return (
        title.includes(searchQuery) ||
        artist.includes(searchQuery) ||
        requesters.includes(searchQuery)
      );
    });
  }

  /* SORT (existing) */

switch (currentSort) {
  case "last":
    rows.sort(
      (a, b) => new Date(b.last_requested_at) - new Date(a.last_requested_at)
    );
    break;

  case "title":
    rows.sort(
      (a, b) => (a.song_title || "").localeCompare(b.song_title || "")
    );
    break;

  case "popularity":
  default:
    rows.sort(
      (a, b) => (b.popularity || 0) - (a.popularity || 0)
    );
}

  listEl.innerHTML = "";

  rows.forEach((row, index) => {
    const el = document.createElement("div");
    el.className = "request-row";
    
if (row.track_status === "played") {
  el.classList.add("played");
}

if (row.track_status === "skipped") {
  el.classList.add("skipped");
}

const trackKey = row.track_key;

    if (trackKey === activeTrackKey) el.classList.add("active");

    el.innerHTML = `
      ${row.album_art ? `<img src="${row.album_art}" alt="">` : ``}
      <div class="req-meta">
        <div class="req-title">${row.song_title}</div>
        <div class="req-artist">${row.artist || ""}</div>
      </div>
      
      
      
<span class="req-count">
${row.popularity}
</span>
      
      
    
    `;

    el.onclick = () => {
      document.querySelectorAll(".request-row").forEach(r => r.classList.remove("active"));
      el.classList.add("active");
      activeTrackKey = trackKey;
      loadTrackPanel(row);
    };

    listEl.appendChild(el);

    if (firstLoad && index === 0) {
      activeTrackKey = trackKey;
      el.classList.add("active");
      loadTrackPanel(row);
    }
  });

  firstLoad = false;
}


/* =========================================
   LOAD MOOD
========================================= */
async function loadDjMood() {
  if (!EVENT_ID || !moodEl) return;

  try {
    const res  = await fetch(`/api/dj/get_mood.php?event_id=${EVENT_ID}`);
    const data = await res.json();

    if (data.ok) {
      moodData = data;
      renderMood();
    }
  } catch (err) {
    console.error("Mood load failed", err);
  }
}


/* =========================================
   RENDER MOOD
========================================= */

function renderMood() {
  if (!moodEl || !moodData) return;

  const { positive, negative, total } = moodData;
  const percent = total ? Math.round((positive / total) * 100) : 0;

  let barColor = "#2ecc71"; // green
  if (percent < 75) barColor = "#f1c40f"; // amber
  if (percent < 50) barColor = "#e74c3c"; // red

  moodEl.innerHTML = `
    <div class="dj-mood">
      <div class="dj-mood-header">
        Crowd mood: <strong>${percent}% positive</strong>
      </div>

      <div class="dj-mood-votes">
        👍 ${positive} &nbsp; / &nbsp; 👎 ${negative}
      </div>

      <div class="dj-mood-bar">
        <div class="dj-mood-fill" style="width:${percent}%; background:${barColor}"></div>
      </div>

      <div class="dj-mood-total">
        ${total} vote${total === 1 ? "" : "s"}
      </div>
    </div>
  `;
}


/* =========================================
   TRACK DETAIL PANEL
========================================= */
function loadTrackPanel(track) {
    console.log("TRACK OBJECT:", track); // 👈 ADD THIS LINE

  if (!panelEl) return;

  const lastTime = track.last_requested_at
    ? new Date(track.last_requested_at.replace(" ", "T") + "Z")
        .toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
    : "—";

const isPlayed  = track.track_status === "played";
const isSkipped = track.track_status === "skipped";

let primaryButton = "";

if (isPlayed) {
  primaryButton = `<button id="markActiveBtn" class="btn-undo-played">↩ Mark Active</button>`;
} else if (isSkipped) {
  primaryButton = `<button id="markActiveBtn" class="btn-undo-skipped">↩ Mark Active</button>`;
} else {
  primaryButton = `<button id="markPlayingBtn">▶ Mark Playing</button>`;
}


    
    
// 👥 Build requester list WITH TIMESTAMP
const requesters = track.requesters || [];

const requesterHtml = requesters.length
  ? requesters.map(r => `
      <div class="detail-row">
        <span class="detail-name">${r.name}</span>
        <span class="detail-time">
          ${timeAgo(r.created_at)}
          <span class="exact-time">(${formatLocalDateTime(r.created_at)})</span>
        </span>
      </div>
    `).join("")
  : `<div class="detail-row muted">—</div>`;

// 👍 Build voter list WITH TIMESTAMP
const voters = track.voters || [];

const voterHtml = voters.length
  ? voters.map(v => `
      <div class="detail-row">
        <span class="detail-name">👍 ${v.name}</span>
        <span class="detail-time">
          ${timeAgo(v.voted_at)}
          <span class="exact-time">(${formatLocalDateTime(v.voted_at)})</span>
        </span>
      </div>
    `).join("")
  : `<div class="detail-row muted">No votes</div>`;

panelEl.innerHTML = `
  <div class="track-panel">
    ${track.album_art ? `<img src="${track.album_art}" class="track-cover">` : ``}

    <h2 class="track-title">${track.song_title}</h2>
    <div class="track-artist">${track.artist || ""}</div>

    <div class="track-meta">
    <span>Popularity: <strong>${track.popularity}</strong></span>
    <span class="meta-dot">=</span>
    
<span>Requests: <strong>${track.request_count}</strong></span>
<span class="meta-dot">•</span>
<span>Votes: <strong>${track.vote_count}</strong></span>


    </div>

    <!-- ✅ THIS WAS MISSING -->
<div class="track-divider" 
     style="height:3px;background:red;margin:16px 0;">
</div>

<div class="track-requesters">
  <div class="track-requesters-label">Requested by</div>
  <div class="detail-list">
    ${requesterHtml}
  </div>
</div>

<div class="track-voters">
  <div class="track-requesters-label">Votes</div>
  <div class="detail-list">
    ${voterHtml}
  </div>
</div>

<div class="track-actions">
  ${primaryButton}
  ${!isPlayed ? `<button id="skipBtn">⏭ Skip</button>` : ``}
</div>
  </div>
`;
  


/* ===============================
   MARK PLAYING
============================== */
const markPlayingBtn = document.getElementById("markPlayingBtn");

if (markPlayingBtn) {
  markPlayingBtn.onclick = async () => {
    try {
      const currentFilter = requestStatusFilter; // remember tab

      // 1️⃣ Mark played in DB (authoritative)
      await fetch("/api/dj/mark_played.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          event_id: EVENT_ID,
          track_key: track.track_key
        })
      });

      // 2️⃣ Remove from Spotify playlist (fire & forget)
        const isSpotifyTrack = /^[A-Za-z0-9]{22}$/.test(track.track_key);
        
        if (isSpotifyTrack) {
          fetch("/api/dj/spotify/remove_track.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
              event_id: EVENT_ID,
              spotify_track_id: track.track_key
            })
          }).catch(() => {});
        }

      // 3️⃣ Update cache
      djRequestsCache.forEach(r => {
        if (r.track_key === track.track_key) {
          r.track_status = "played";
        }
      });

      // ✅ Stay on same tab
      requestStatusFilter = currentFilter;

      updateRequestTabCounts();
      renderDjRequests();

      // Reload panel
      const updatedTrack = djRequestsCache.find(
        r => r.track_key === track.track_key
      );
      if (updatedTrack) {
        loadTrackPanel(updatedTrack);
      }

    } catch (err) {
      console.error("Failed to mark playing", err);
    }
  };
}

/* ===============================
   MARK ACTIVE (UNDO)
============================== */
const markActiveBtn = document.getElementById("markActiveBtn");

if (markActiveBtn) {
  markActiveBtn.onclick = async () => {
    try {
      const currentFilter = requestStatusFilter; // remember tab

      // 1️⃣ Mark active in DB (authoritative)
      await fetch("/api/dj/mark_active.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          event_id: EVENT_ID,
          track_key: track.track_key
        })
      });

      // 2️⃣ Re-add to Spotify playlist (ONLY if Spotify-backed)
        const isSpotifyTrack = /^[A-Za-z0-9]{22}$/.test(track.track_key);
        
        if (isSpotifyTrack) {
          fetch("/api/dj/spotify/add_track.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
              event_id: EVENT_ID,
              spotify_track_id: track.track_key
            })
          }).catch(() => {});
        }

      // 3️⃣ Update cache
      djRequestsCache.forEach(r => {
        if (r.track_key === track.track_key) {
          r.track_status = "active";
        }
      });

      // ✅ DO NOT TOUCH TABS
      requestStatusFilter = currentFilter;

      updateRequestTabCounts();
      renderDjRequests();

      const updatedTrack = djRequestsCache.find(
        r => r.track_key === track.track_key
      );
      if (updatedTrack) {
        loadTrackPanel(updatedTrack);
      }

    } catch (err) {
      console.error("Failed to mark active", err);
    }
  };
}
  
  
/* ===============================
   SKIP TRACK
============================== */
const skipBtn = document.getElementById("skipBtn");

if (skipBtn) {
  skipBtn.onclick = async () => {
    try {
      // 1️⃣ Mark skipped in DB (authoritative)
      await fetch("/api/dj/mark_skipped.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          event_id: EVENT_ID,
          track_key: track.track_key
        })
      });

      // 2️⃣ Remove from Spotify playlist (fire & forget)
      // No await needed — skipping should never fail because Spotify did
const isSpotifyTrack = /^[A-Za-z0-9]{22}$/.test(track.track_key);

if (isSpotifyTrack) {
  fetch("/api/dj/spotify/remove_track.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      event_id: EVENT_ID,
      spotify_track_id: track.track_key
    })
  }).catch(() => {});
}

      // 3️⃣ Optimistic UI update
      djRequestsCache.forEach(r => {
        if (r.track_key === track.track_key) {
          r.track_status = "skipped";
        }
      });

      updateRequestTabCounts();
      renderDjRequests();

      const updatedTrack = djRequestsCache.find(
        r => r.track_key === track.track_key
      );

      if (updatedTrack) {
        loadTrackPanel(updatedTrack);
      }

    } catch (err) {
      console.error("Failed to skip track", err);
    }
  };
}
  
} // ✅ closes loadTrackPanel — MUST be here
 
/* =========================================
   LOAD MESSAGES
========================================= */
async function loadDjMessages() {
  if (!EVENT_ID || !messageListEl) return;

  try {
    const res = await fetch(`/api/dj/get_messages.php?event_id=${EVENT_ID}`);
    const data = await res.json();

    if (data.ok && Array.isArray(data.rows)) {
      messageCache = data.rows;
      renderMessages();
    }
  } catch (err) {
    console.error("Message load failed", err);
  }
}

/* =========================================
   RENDER MESSAGES (STEP 5)
========================================= */
function renderMessages() {
  messageListEl.innerHTML = "";

  let rows = [...messageCache];

  // Sort newest first
  rows.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

  /* ===============================
     1️⃣ STATUS TAB FILTER
  ============================== */
// Apply tab-based filtering
rows = rows.filter(msg => {
  const isMuted   = mutedGuests.has(msg.guest_token);
  const isBlocked = blockedGuests.has(msg.guest_token);

  switch (messageStatusFilter) {
    case "muted":
      return isMuted && !isBlocked;

    case "blocked":
      return isBlocked;

    case "active":
      return !isMuted && !isBlocked;

    case "all":
    default:
      return true;
  }
});

  /* ===============================
     2️⃣ OPTIONAL GUEST FILTER
  ============================== */
  if (filterByGuest && activeGuestToken) {
    rows = rows.filter(m => m.guest_token === activeGuestToken);
  }

  /* ===============================
     3️⃣ STATS
  ============================== */
  const total   = messageCache.length;
  const showing = rows.length;

  const statsEl = document.getElementById("messageStats");
  if (statsEl) {
    statsEl.textContent =
      filterByGuest && activeGuestToken
        ? `${total} total · ${showing} showing`
        : `${total} total`;
  }

  /* ===============================
     4️⃣ RENDER ROWS
  ============================== */
rows.forEach(msg => {
  const el = document.createElement("div");
  el.className = "message-item";

  const isMuted   = mutedGuests.has(msg.guest_token);
  const isBlocked = blockedGuests.has(msg.guest_token);

      // ⛔ Blocked styling (only visible in Blocked tab)
    if (isBlocked) {
      el.classList.add("blocked");
    }

  // 🎯 Active guest highlight
  if (msg.guest_token && msg.guest_token === activeGuestToken) {
    el.classList.add("active");
  }

  // 🔕 Muted styling
  if (isMuted) {
    el.classList.add("muted");
  }

  // 🏷️ Status badge
  let badgeHtml = "";
  if (isMuted) {
    badgeHtml = `<span class="msg-badge muted">🔕 Muted</span>`;
  }
  
  if (isBlocked) {
    badgeHtml = `<span class="msg-badge blocked">⛔ Blocked</span>`;
  }

  el.innerHTML = `
    <div class="message-header">
      <div class="message-author">${msg.patron_name || "Guest"}</div>
      ${badgeHtml}
    </div>

    <div class="message-text">${msg.message}</div>

<div class="message-time">
  ${timeAgo(msg.created_at)}
  <span class="exact-time">(${formatLocalDateTime(msg.created_at)})</span>
</div>
  `;

  attachMessageInteractions(el, msg);
  messageListEl.appendChild(el);
});

}

/* ===============================
   MESSAGE INTERACTIONS
================================ */
function attachMessageInteractions(el, msg) {

  // 🟦 DESKTOP: right-click
  el.addEventListener("contextmenu", e => {
    e.preventDefault();
    openMessageActions(el, msg);
  });

  // 🟨 TOUCH: long-press
  let pressTimer;

  el.addEventListener("pointerdown", e => {
    if (e.pointerType !== "touch") return;

    pressTimer = setTimeout(() => {
      openMessageActions(el, msg);
    }, 500);
  });

  el.addEventListener("pointerup", () => clearTimeout(pressTimer));
  el.addEventListener("pointerleave", () => clearTimeout(pressTimer));
  el.addEventListener("pointercancel", () => clearTimeout(pressTimer));

  // 🟢 Normal click = select guest
el.addEventListener("click", () => {
  // Select guest visually ONLY
  activeGuestToken = msg.guest_token;

  // ❗ DO NOT enable filtering here
  // Filtering is ONLY via action menu

  renderMessages();
});
}



/* =========================================
   SORT CONTROLS
========================================= */
const sortSelect = document.getElementById("djSort");
if (sortSelect) {
  sortSelect.addEventListener("change", () => {
    currentSort = sortSelect.value;
    renderDjRequests();
  });
}



/* =========================================
   POLLING
========================================= */
loadDjRequests();
loadDjMessages();
loadDjMood();

setInterval(loadDjRequests, POLL_MS);
setInterval(loadDjMessages, POLL_MS);
setInterval(loadDjMood, 15000); 


/* ===============================
   MOOD MODAL — SPLIT RENDER
================================ */

const moodModal      = document.getElementById("moodModal");
const positiveList   = document.getElementById("positiveList");
const negativeList   = document.getElementById("negativeList");
const positiveCount  = document.getElementById("positiveCount");
const negativeCount  = document.getElementById("negativeCount");
const closeMoodModal = document.getElementById("closeMoodModal");

let moodVoteCache = { positive: [], negative: [] };

// Open modal
document.getElementById("djMood")?.addEventListener("click", async () => {
  moodModal.classList.remove("hidden");
  await loadMoodVotes();
  renderMoodColumns();
});

closeMoodModal?.addEventListener("click", () => {
  moodModal.classList.add("hidden");
});

async function loadMoodVotes() {
  const res = await fetch(`/api/dj/get_mood_votes.php?event_id=${EVENT_ID}`);
  const data = await res.json();

  if (!data.ok) return;

  moodVoteCache.positive = data.positive || [];
  moodVoteCache.negative = data.negative || [];
}

function renderMoodColumns() {
  positiveList.innerHTML = "";
  negativeList.innerHTML = "";

  positiveCount.textContent = moodVoteCache.positive.length;
  negativeCount.textContent = moodVoteCache.negative.length;

  if (!moodVoteCache.positive.length) {
    positiveList.innerHTML =
      `<div style="color:#777;text-align:center;font-size:13px;">No votes</div>`;
  }

  if (!moodVoteCache.negative.length) {
    negativeList.innerHTML =
      `<div style="color:#777;text-align:center;font-size:13px;">No votes</div>`;
  }

  moodVoteCache.positive.forEach(v => {
    positiveList.appendChild(renderVote(v));
  });

  moodVoteCache.negative.forEach(v => {
    negativeList.appendChild(renderVote(v));
  });
}

function renderVote(v) {
  const el = document.createElement("div");
  el.className = "mood-vote-item";

  el.innerHTML = `
    <span>${v.patron_name || "Guest"}</span>
    <span style="opacity:.7;">${timeAgo(v.updated_at)}</span>
  `;

  return el;
}

