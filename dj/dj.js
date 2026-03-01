const listEl        = document.querySelector(".request-list");
const panelEl       = document.getElementById("trackPanel");
const messageListEl = document.getElementById("messageList");
const djMessageThreadEl = document.getElementById("djMessageThread");

const DJ_CONFIG = window.DJ_CONFIG || {};
const EVENT_ID  = DJ_CONFIG.eventId || null;
const EVENT_UUID = DJ_CONFIG.eventUuid || null;
const POLL_MS   = DJ_CONFIG.pollInterval || 10000
const SPOTIFY_SYNC_MS = 30000;
const TIPS_BOOST_VISIBLE = !!DJ_CONFIG.tipsBoostVisible;
const POLLS_PREMIUM_ENABLED = !!DJ_CONFIG.pollsPremiumEnabled;
const IS_ADMIN = !!DJ_CONFIG.isAdmin;
const SPOTIFY_SYNC_ENABLED = !!DJ_CONFIG.spotifySyncEnabled;

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
let manualMatchTrack = null;


/* ===============================
   REQUEST FILTER STATE
================================ */
let requestStatusFilter = "all";


/* ===============================
   MOOD STATE
================================ */
let moodData = null;
const moodEl = document.getElementById("djMood");

let insightsCache = null;
const patronActivityCache = new Map();
let topPatronExpandedToken = null;

/* ===============================
   MESSAGE STATE
================================ */
let messageCache = [];
let activeGuestToken = null;
let filterByGuest = false;
let messageStatusFilter = "all"; // all | active | muted | blocked
let messagePrimaryView = "chats"; // chats | broadcasts | polls
let broadcastCache = [];
let pollCache = [];
let replyGuestToken = null;
const replyGuestNameOverrides = new Map();
const guestStatusOverrides = new Map(); // guest_token -> active|muted|blocked
let messageFetchSeq = 0;
let lastAppliedMessageFetchSeq = 0;
let spotifySyncInFlight = false;


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

function switchMessagePrimaryView(view) {
  messagePrimaryView = view;

  document.querySelectorAll(".message-primary-tab").forEach(tab => {
    tab.classList.toggle("active", tab.dataset.view === view);
  });

  const chatView = document.getElementById("chatView");
  const broadcastsView = document.getElementById("broadcastsView");
  const pollsView = document.getElementById("pollsView");
  const statusTabs = document.getElementById("messageStatusTabs");

  if (chatView) chatView.classList.toggle("hidden", view !== "chats");
  if (broadcastsView) broadcastsView.classList.toggle("hidden", view !== "broadcasts");
  if (pollsView) pollsView.classList.toggle("hidden", view !== "polls");
  if (statusTabs) statusTabs.classList.toggle("hidden", view !== "chats");

  if (view === "broadcasts") {
    loadBroadcasts();
  } else if (view === "polls") {
    if (POLLS_PREMIUM_ENABLED) {
      loadPolls();
    }
  } else {
    renderMessages();
  }
}

function getGuestStatus(msg) {
  return msg.guest_status || "active";
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function countryCodeToFlagEmoji(code) {
  const cc = String(code || "").trim().toUpperCase();
  if (!/^[A-Z]{2}$/.test(cc)) return "";
  const OFFSET = 127397;
  return String.fromCodePoint(cc.charCodeAt(0) + OFFSET, cc.charCodeAt(1) + OFFSET);
}

function normalizeTitle(title) {
  return (title || "")
    .toLowerCase()
    .replace(/\s*\(.*?\)/g, "")  // remove "(Extended Mix)"
    .replace(/\s*-.*$/g, "")     // remove "- Remix"
    .replace(/\s+/g, " ")
    .trim();
}

function buildDjGroupKey(row) {
  return `${normalizeTitle(row.song_title || "")}::${(row.artist || "").trim().toLowerCase()}`;
}

function groupDjRows(rows) {
  const groups = new Map();

  rows.forEach((row) => {
    const key = buildDjGroupKey(row);
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        rows: [],
        song_title: row.song_title || "",
        artist: row.artist || "",
        album_art: row.album_art || "",
        popularity: 0,
        request_count: 0,
        vote_count: 0,
        boost_count: 0,
        last_requested_at: row.last_requested_at || row.created_at || null,
      });
    }

    const group = groups.get(key);
    group.rows.push(row);
    group.popularity += Number(row.popularity || 0);
    group.request_count += Number(row.request_count || 0);
    group.vote_count += Number(row.vote_count || 0);
    group.boost_count += Number(row.boost_count || 0);

    if (!group.album_art && row.album_art) {
      group.album_art = row.album_art;
    }

    const groupTs = group.last_requested_at ? new Date(group.last_requested_at).getTime() : 0;
    const rowTs = row.last_requested_at ? new Date(row.last_requested_at).getTime() : 0;
    if (rowTs > groupTs) {
      group.last_requested_at = row.last_requested_at;
    }
  });

  const grouped = [];
  groups.forEach((group) => {
    const primary = [...group.rows].sort((a, b) => {
      const popDelta = Number(b.popularity || 0) - Number(a.popularity || 0);
      if (popDelta !== 0) return popDelta;
      return new Date(b.last_requested_at || 0) - new Date(a.last_requested_at || 0);
    })[0] || group.rows[0];

    const allPlayed = group.rows.every((r) => r.track_status === "played");
    const allSkipped = group.rows.every((r) => r.track_status === "skipped");
    const anyPlayed = group.rows.some((r) => r.track_status === "played");
    const anySkipped = group.rows.some((r) => r.track_status === "skipped");

    const merged = {
      ...primary,
      song_title: group.song_title || primary.song_title,
      artist: group.artist || primary.artist,
      album_art: group.album_art || primary.album_art,
      popularity: group.popularity,
      request_count: group.request_count,
      vote_count: group.vote_count,
      boost_count: group.boost_count,
      last_requested_at: group.last_requested_at || primary.last_requested_at,
      requesters: group.rows.flatMap((r) => Array.isArray(r.requesters) ? r.requesters : []),
      voters: group.rows.flatMap((r) => Array.isArray(r.voters) ? r.voters : []),
      boosters: group.rows.flatMap((r) => Array.isArray(r.boosters) ? r.boosters : []),
      track_status: allPlayed ? "played" : (allSkipped ? "skipped" : "active"),
      group_has_played: anyPlayed,
      group_has_skipped: anySkipped,
      __group_rows: group.rows,
    };

    grouped.push(merged);
  });

  return grouped;
}

function getGroupTrackKeys(track) {
  const groupRows = Array.isArray(track?.__group_rows) ? track.__group_rows : null;
  if (groupRows && groupRows.length) {
    return [...new Set(groupRows.map((r) => r.track_key).filter(Boolean))];
  }
  return track?.track_key ? [track.track_key] : [];
}

function findGroupedRowByTrackKey(trackKey) {
  if (!trackKey) return null;
  const grouped = groupDjRows(djRequestsCache);
  return grouped.find((g) =>
    g.track_key === trackKey ||
    (Array.isArray(g.__group_rows) && g.__group_rows.some((r) => r.track_key === trackKey))
  ) || null;
}

function formatManualMatchRow(row) {
  const bpmVal = (row?.bpm !== null && row?.bpm !== undefined && row?.bpm !== '')
    ? String(row.bpm)
    : '—';
  const keyVal = row?.key_text ? String(row.key_text).trim() : '—';
  const yearVal = row?.year ? String(row.year) : '—';
  return `${bpmVal} BPM • ${keyVal} • ${yearVal}`;
}

function closeManualMatchModal() {
  const modal = document.getElementById("manualMatchModal");
  if (modal) modal.classList.add("hidden");
  manualMatchTrack = null;
}

async function loadManualMatchCandidates(track, query = "") {
  const statusEl = document.getElementById("manualMatchStatus");
  const resultsEl = document.getElementById("manualMatchResults");
  if (!statusEl || !resultsEl) return;

  statusEl.textContent = "Searching candidates...";
  resultsEl.innerHTML = "";

  const url = new URL("/api/dj/search_bpm_candidates.php", window.location.origin);
  url.searchParams.set("event_uuid", EVENT_UUID || "");
  url.searchParams.set("track_key", track.track_key || "");
  if (query.trim() !== "") {
    url.searchParams.set("q", query.trim());
  }

  try {
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || "Search failed");
    }

    const rows = Array.isArray(data.rows) ? data.rows : [];
    if (!rows.length) {
      statusEl.textContent = "No candidates found.";
      return;
    }

    statusEl.textContent = `${rows.length} candidates found`;
    resultsEl.innerHTML = rows.map((row) => `
      <div class="manual-match-item">
        <div class="manual-match-item-main">
          <div class="manual-match-title">${escapeHtml(row.title || "Unknown title")}</div>
          <div class="manual-match-artist">${escapeHtml(row.artist || "Unknown artist")}</div>
          <div class="manual-match-meta">${escapeHtml(formatManualMatchRow(row))}</div>
          <div class="manual-match-score">Score: ${Number(row.match_score || 0).toFixed(2)}</div>
        </div>
        <button
          type="button"
          class="reply-btn primary manual-match-apply-btn"
          data-bpm-id="${Number(row.id || 0)}"
        >
          Apply
        </button>
      </div>
    `).join("");
  } catch (err) {
    statusEl.textContent = err.message || "Failed to search candidates.";
  }
}

async function applyManualMatch(bpmTrackId) {
  if (!manualMatchTrack) return;

  const statusEl = document.getElementById("manualMatchStatus");
  if (!statusEl) return;
  statusEl.textContent = "Applying metadata match...";

  try {
    const body = new URLSearchParams({
      event_uuid: EVENT_UUID || "",
      track_key: manualMatchTrack.track_key || "",
      spotify_track_id: manualMatchTrack.spotify_track_id || "",
      bpm_track_id: String(Number(bpmTrackId || 0)),
    });
    const res = await fetch("/api/dj/apply_manual_bpm_match.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body,
    });
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || "Failed to apply metadata");
    }

    const applied = data.applied || {};
    djRequestsCache = djRequestsCache.map((r) => {
      if (r.track_key !== manualMatchTrack.track_key) return r;
      return {
        ...r,
        bpm: applied.bpm ?? r.bpm,
        musical_key: applied.musical_key ?? r.musical_key,
        release_year: applied.release_year ?? r.release_year,
      };
    });

    renderDjRequests();
    const updatedTrack = djRequestsCache.find((r) => r.track_key === manualMatchTrack.track_key);
    if (updatedTrack && activeTrackKey === updatedTrack.track_key) {
      loadTrackPanel(updatedTrack);
    }

    showToast("Metadata applied", "success");
    closeManualMatchModal();
  } catch (err) {
    statusEl.textContent = err.message || "Failed to apply metadata.";
  }
}

function openManualMatchModal(track) {
  if (!IS_ADMIN) return;

  const modal = document.getElementById("manualMatchModal");
  const metaEl = document.getElementById("manualMatchTrackMeta");
  const searchEl = document.getElementById("manualMatchSearchInput");
  const statusEl = document.getElementById("manualMatchStatus");
  const resultsEl = document.getElementById("manualMatchResults");
  if (!modal || !metaEl || !searchEl || !statusEl || !resultsEl) return;

  manualMatchTrack = track;
  metaEl.innerHTML = `
    <strong>${escapeHtml(track.song_title || "Unknown title")}</strong>
    <span> · ${escapeHtml(track.artist || "Unknown artist")}</span>
  `;
  searchEl.value = "";
  statusEl.textContent = "";
  resultsEl.innerHTML = "";
  modal.classList.remove("hidden");

  loadManualMatchCandidates(track, "");
}

function formatThreadTime(ts) {
  if (!ts) return "";
  const d = new Date(ts.replace(" ", "T") + "Z");
  return d.toLocaleString([], {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: true
  });
}

{// ===============================
// REQUEST TAB COUNTS
// ===============================
function updateRequestTabCounts() {
  const counts = {
    all: groupDjRows(djRequestsCache).length,
    active: groupDjRows(djRequestsCache.filter((r) => r.track_status === "active")).length,
    played: groupDjRows(djRequestsCache.filter((r) => r.track_status === "played")).length,
    skipped: groupDjRows(djRequestsCache.filter((r) => r.track_status === "skipped")).length,
    boost: groupDjRows(djRequestsCache.filter((r) => (r.boost_count || 0) > 0)).length
  };

  document.querySelectorAll(".dj-tab").forEach(tab => {
    const status = tab.dataset.status;
    const countEl = tab.querySelector(".tab-count");
    if (!countEl) return;

    countEl.textContent =
      status === "all"
        ? `(${counts.all})`
        : `(${counts[status] || 0})`;
  });
}}



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
    .toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: true });
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
    minute: "2-digit",
    hour12: true
  });

  return `${date}, ${time}`;
}


// ===============================
// SUPPORT TIME FORMATTER
// ===============================
function formatSupportTime(ts) {
  if (!ts) return '';

  // MySQL DATETIME → local time
  const d = new Date(ts.replace(" ", "T") + "Z");

  return d.toLocaleString([], {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: true
  });
}

function formatBpmKey(row) {
  const bpmRaw = Number(row?.bpm);
  const bpm = Number.isFinite(bpmRaw) && bpmRaw > 0
    ? Math.round(bpmRaw * 10) / 10
    : null;

  const key = row?.musical_key ? String(row.musical_key).trim() : '';
  const yearRaw = Number(row?.release_year);
  const year = Number.isInteger(yearRaw) && yearRaw > 1900 && yearRaw < 3000
    ? yearRaw
    : null;

  const parts = [];
  if (bpm) parts.push(`${bpm} BPM`);
  if (key) parts.push(key);
  if (year) parts.push(String(year));
  return parts.join(' • ');
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
  const status = getGuestStatus(msg);
  const isMuted = status === "muted";
  const isBlocked = status === "blocked";

  // 🔄 Update action labels dynamically (EXISTING)
  const muteBtn  = actionSheet.querySelector('[data-action="mute"], [data-action="unmute"]');
  const blockBtn = actionSheet.querySelector('[data-action="block"], [data-action="unblock"]');
  const replyBtn = actionSheet.querySelector('[data-action="reply"], [data-action="cancel-reply"]');

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

  if (replyBtn) {
    const sameGuestReplyTarget = replyGuestToken && replyGuestToken === token;
    replyBtn.dataset.action = sameGuestReplyTarget ? "cancel-reply" : "reply";
    replyBtn.textContent = sameGuestReplyTarget ? "Cancel reply" : "Reply";
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
  actionSheet.addEventListener("click", async e => {
    const btn = e.target.closest("button");
    if (!btn) return;

    const action = btn.dataset.action;

    if (action === "cancel") {
      closeMessageActions();
      return;
    }

    if (!actionTargetMessage) return;

    const token = actionTargetMessage.guest_token;
    const label = actionTargetMessage.patron_name || "Guest";

    if (!token) {
      showToast("Guest token missing for this message", "error");
      closeMessageActions();
      return;
    }

try {
switch (action) {
  case "reply":
    replyGuestToken = token;
    activeGuestToken = token;
    filterByGuest = true;
    setReplyBoxOpen(true);
    setDjThreadOpen(true);
    updateReplyTargetLabel();
    loadDjMessageThreadForGuest(token);
    const replyInput = document.getElementById("djReplyText");
    if (replyInput) {
      replyInput.focus();
    }
    showToast(`Replying to ${label}`, "info");
    break;

  case "cancel-reply":
    replyGuestToken = null;
    activeGuestToken = null;
    filterByGuest = false;
    setReplyBoxOpen(false);
    setDjThreadOpen(false);
    updateReplyTargetLabel();
    showToast("Reply target cleared", "info");
    break;

  case "filter":
    activeGuestToken = token;
    filterByGuest = true;
    showToast(`Filtered to ${label}`);
    break;

  case "unfilter":
    filterByGuest = false;
    activeGuestToken = null;
    showToast("Filter cleared", "info");
    break;

  case "mute":
  await saveGuestStatus(token, "muted");
  updateMessageCacheGuestStatus(token, "muted");
  switchMessageTab("muted");
  showToast(`${label} muted`, "info");
  break;

case "unmute":
  await saveGuestStatus(token, "active");
  updateMessageCacheGuestStatus(token, "active");
  switchMessageTab("active");
  showToast(`${label} unmuted`, "success");
  break;

case "block":
  await saveGuestStatus(token, "blocked");
  updateMessageCacheGuestStatus(token, "blocked");
  switchMessageTab("blocked");
  showToast(`${label} blocked`, "error");
  break;

case "unblock":
  await saveGuestStatus(token, "active");
  updateMessageCacheGuestStatus(token, "active");
  switchMessageTab("active");
  showToast(`${label} unblocked`, "success");
  break;
}
} catch (err) {
  showToast(err.message || "Action failed", "error");
}

    closeMessageActions();
    renderMessages();
    updateReplyTargetLabel();
    loadDjMessages();
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
      if (messagePrimaryView !== "chats") return;
      document
        .querySelectorAll(".message-tab")
        .forEach(t => t.classList.remove("active"));

      tab.classList.add("active");
      messageStatusFilter = tab.dataset.status;
      // Switching status tabs should show the full bucket, not stay scoped to one guest.
      filterByGuest = false;
      renderMessages();
    });
  });

  document.querySelectorAll(".message-primary-tab").forEach(tab => {
    tab.addEventListener("click", () => {
      switchMessagePrimaryView(tab.dataset.view || "chats");
    });
  });

  const sendBroadcastFromTabBtn = document.getElementById("sendBroadcastFromTabBtn");
  if (sendBroadcastFromTabBtn) {
    sendBroadcastFromTabBtn.addEventListener("click", () => {
      document.getElementById("broadcastModal")?.classList.remove("hidden");
    });
  }

  const createPollBtn = document.getElementById("createPollBtn");
  if (createPollBtn) {
    createPollBtn.addEventListener("click", () => {
      if (createPollBtn.disabled) {
        showToast("Poll creation is a Premium feature.", "info");
        return;
      }
      document.getElementById("createPollModal")?.classList.remove("hidden");
    });
  }

  const pollCancelBtn = document.getElementById("pollCancelBtn");
  if (pollCancelBtn) {
    pollCancelBtn.addEventListener("click", () => {
      document.getElementById("createPollModal")?.classList.add("hidden");
      const statusEl = document.getElementById("pollCreateStatus");
      if (statusEl) statusEl.textContent = "";
    });
  }

  const pollAddOptionBtn = document.getElementById("pollAddOptionBtn");
  if (pollAddOptionBtn) {
    pollAddOptionBtn.addEventListener("click", () => {
      const wrap = document.getElementById("pollOptionsWrap");
      if (!wrap) return;
      const existing = wrap.querySelectorAll(".poll-option-input").length;
      if (existing >= 12) return;
      const input = document.createElement("input");
      input.type = "text";
      input.className = "poll-option-input";
      input.name = "poll_options[]";
      input.maxLength = 255;
      input.placeholder = `Option ${existing + 1}`;
      wrap.appendChild(input);
    });
  }

  const createPollForm = document.getElementById("createPollForm");
  if (createPollForm) {
    createPollForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      await createPoll();
    });
  }

  const pollsListEl = document.getElementById("pollsList");
  if (pollsListEl) {
    pollsListEl.addEventListener("click", (e) => {
      const card = e.target.closest(".poll-item-clickable");
      if (!card) return;
      const pollId = Number(card.dataset.pollId || 0);
      if (!pollId) return;
      openPollVoterDetails(pollId);
    });
  }

  const sendReplyBtn = document.getElementById("djReplySend");
  if (sendReplyBtn) {
    sendReplyBtn.addEventListener("click", sendDjReply);
  }

  const clearReplyBtn = document.getElementById("djReplyCancel");
  if (clearReplyBtn) {
    clearReplyBtn.addEventListener("click", closeReplyThread);
  }

  const replyCloseBtn = document.getElementById("replyCloseBtn");
  if (replyCloseBtn) {
    replyCloseBtn.addEventListener("click", closeReplyThread);
  }

  const replyInput = document.getElementById("djReplyText");
  if (replyInput) {
    replyInput.addEventListener("keydown", e => {
      if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        sendDjReply();
      }
    });
  }

  updateReplyTargetLabel();
  setReplyBoxOpen(false);
  setDjThreadOpen(false);
  switchMessagePrimaryView("chats");

  if (IS_ADMIN) {
    const manualMatchModal = document.getElementById("manualMatchModal");
    const closeManualMatchBtn = document.getElementById("closeManualMatchModal");
    const manualMatchSearchBtn = document.getElementById("manualMatchSearchBtn");
    const manualMatchSearchInput = document.getElementById("manualMatchSearchInput");
    const manualMatchResults = document.getElementById("manualMatchResults");

    closeManualMatchBtn?.addEventListener("click", closeManualMatchModal);
    manualMatchModal?.addEventListener("click", (e) => {
      if (e.target === manualMatchModal) closeManualMatchModal();
    });

    manualMatchSearchBtn?.addEventListener("click", () => {
      if (!manualMatchTrack) return;
      loadManualMatchCandidates(manualMatchTrack, manualMatchSearchInput?.value || "");
    });

    manualMatchSearchInput?.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;
      e.preventDefault();
      if (!manualMatchTrack) return;
      loadManualMatchCandidates(manualMatchTrack, manualMatchSearchInput.value || "");
    });

    manualMatchResults?.addEventListener("click", (e) => {
      const btn = e.target.closest(".manual-match-apply-btn");
      if (!btn) return;
      const bpmId = Number(btn.dataset.bpmId || 0);
      if (!bpmId) return;
      applyManualMatch(bpmId);
    });
  }

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
  if (!EVENT_UUID) return;

  try {
    const res = await fetch(`/api/dj/get_requests.php?event=${EVENT_UUID}`);
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
  let searchClearBtn = document.getElementById("djSearchClear");

  // Backward-compatible: create clear button if older HTML is deployed.
  if (searchInput && !searchClearBtn) {
    const wrap = searchInput.closest(".dj-search-wrap") || searchInput.parentElement;
    if (wrap) {
      if (!wrap.classList.contains("dj-search-wrap")) {
        wrap.classList.add("dj-search-wrap");
      }
      searchClearBtn = document.createElement("button");
      searchClearBtn.id = "djSearchClear";
      searchClearBtn.type = "button";
      searchClearBtn.className = "dj-search-clear hidden";
      searchClearBtn.setAttribute("aria-label", "Clear search");
      searchClearBtn.textContent = "✕";
      wrap.appendChild(searchClearBtn);
    }
  }

  if (searchInput) {
    const syncSearchClear = () => {
      if (!searchClearBtn) return;
      const hasText = searchInput.value.trim().length > 0;
      searchClearBtn.classList.toggle("hidden", !hasText);
    };

    searchInput.addEventListener("input", e => {
      searchQuery = e.target.value.trim().toLowerCase();
      firstLoad = false;          // 👈 don’t auto-select first row while searching
      syncSearchClear();
      renderDjRequests();
    });

    syncSearchClear();
  }

  if (searchInput && searchClearBtn) {
    searchClearBtn.addEventListener("click", () => {
      searchInput.value = "";
      searchQuery = "";
      firstLoad = false;
      searchClearBtn.classList.add("hidden");
      renderDjRequests();
      searchInput.focus();
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

  if (requestStatusFilter === "boost") {
    rows = rows.filter(r => (r.boost_count || 0) > 0);

  } else {
    rows = rows.filter(r => r.track_status === requestStatusFilter);
  }

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

  // Group same songs (ignoring version text) before sort/render.
  rows = groupDjRows(rows);

  /* SORT (existing) */

switch (currentSort) {
  case "bpm":
    rows.sort((a, b) => {
      const aBpm = Number(a?.bpm);
      const bBpm = Number(b?.bpm);
      const aHas = Number.isFinite(aBpm) && aBpm > 0;
      const bHas = Number.isFinite(bBpm) && bBpm > 0;

      if (aHas && bHas) return aBpm - bBpm;
      if (aHas) return -1;
      if (bHas) return 1;

      return (a.song_title || "").localeCompare(b.song_title || "");
    });
    break;

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
const isPlayed = row.track_status === "played" || row.group_has_played === true;
const isSkipped = row.track_status === "skipped" || row.group_has_skipped === true;
const variants = Array.isArray(row.__group_rows) ? row.__group_rows : [];
const expandable = variants.length > 1;

// ✅ BOOSTED TRACK
if ((row.boost_count || 0) > 0) {
  el.classList.add("boosted");
}
    
if (isPlayed) {
  el.classList.add("played");
}

if (isSkipped) {
  el.classList.add("skipped");
}

const trackKey = row.track_key;

    if (trackKey === activeTrackKey || (row.__group_rows || []).some((r) => r.track_key === activeTrackKey)) {
      el.classList.add("active");
    }

    el.innerHTML = `
      ${row.album_art ? `<img src="${row.album_art}" alt="">` : ``}
      <div class="req-meta">
        <div class="req-title">
  ${row.song_title}
  ${(row.boost_count || 0) > 0
    ? `<span class="boost-badge">🚀</span>`
    : ``}
</div>
        <div class="req-artist">${row.artist || ""}</div>
        ${formatBpmKey(row) ? `<div class="req-bpm">${escapeHtml(formatBpmKey(row))}</div>` : ``}
      </div>
      
      
      
<span class="req-count-wrap">
  ${isPlayed ? `<span class="request-played-pill">Played</span>` : ``}
  ${isSkipped ? `<span class="request-skipped-pill">Skipped</span>` : ``}
  <span class="req-count">${row.popularity}</span>
</span>
${expandable ? `<button type="button" class="request-expand-btn" aria-label="Show versions">+</button>` : ``}
      
      
    
    `;

    el.onclick = () => {
      document.querySelectorAll(".request-row").forEach(r => r.classList.remove("active"));
      el.classList.add("active");
      activeTrackKey = trackKey;
      loadTrackPanel(row);
    };

    if (IS_ADMIN) {
      el.addEventListener("contextmenu", (e) => {
        e.preventDefault();
        document.querySelectorAll(".request-row").forEach(r => r.classList.remove("active"));
        el.classList.add("active");
        activeTrackKey = trackKey;
        loadTrackPanel(row);
        openManualMatchModal(row);
      });
    }

    listEl.appendChild(el);

    if (expandable) {
      const variantsWrap = document.createElement("div");
      variantsWrap.className = "request-variants hidden";

      const sortedVariants = [...variants].sort((a, b) => {
        const popDelta = Number(b.popularity || 0) - Number(a.popularity || 0);
        if (popDelta !== 0) return popDelta;
        return new Date(b.last_requested_at || 0) - new Date(a.last_requested_at || 0);
      });

      variantsWrap.innerHTML = sortedVariants.map((v) => `
        <button type="button" class="request-variant-row" data-track-key="${escapeHtml(v.track_key || "")}">
          <span class="request-variant-title">${escapeHtml(v.song_title || "")}</span>
          <span class="request-variant-count">×${Number(v.popularity || 0)}</span>
        </button>
      `).join("");

      variantsWrap.addEventListener("click", (e) => {
        const btn = e.target.closest(".request-variant-row");
        if (!btn) return;
        const variantKey = btn.dataset.trackKey || "";
        const selected = variants.find((v) => (v.track_key || "") === variantKey);
        if (!selected) return;

        document.querySelectorAll(".request-row").forEach(r => r.classList.remove("active"));
        el.classList.add("active");
        activeTrackKey = selected.track_key;
        loadTrackPanel(selected);
      });

      const expandBtn = el.querySelector(".request-expand-btn");
      if (expandBtn) {
        expandBtn.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          const open = !variantsWrap.classList.contains("hidden");
          variantsWrap.classList.toggle("hidden", open);
          expandBtn.textContent = open ? "+" : "–";
          el.classList.toggle("expanded", !open);
        });
      }

      listEl.appendChild(variantsWrap);
    }

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
  if (!EVENT_UUID || !moodEl) return;

  try {
    const res  = await fetch(`/api/dj/get_mood.php?event=${EVENT_UUID}`);
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

  let barColor = "#30d158";
  if (percent < 75) barColor = "#ffd60a";
  if (percent < 50) barColor = "#ff453a";

  moodEl.innerHTML = `
    <div class="dj-mood-card">
      <div class="dj-mood-title">Crowd mood:</div>

        <div class="dj-mood-score">
          ${percent}%
        </div>

      <div class="dj-mood-votes">
        👍 ${positive} &nbsp; / &nbsp; 👎 ${negative}
      </div>

      <div class="dj-mood-bar">
        <div class="dj-mood-fill"
             style="width:${percent}%; background:${barColor}">
        </div>
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
    

  if (!panelEl) return;

  const lastTime = track.last_requested_at
    ? new Date(track.last_requested_at.replace(" ", "T") + "Z")
        .toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: true })
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
  
  
  // 🚀 Build booster list WITH TIMESTAMP
const boosters = track.boosters || [];

const boosterHtml = boosters.length
  ? boosters.map(b => `
      <div class="detail-row boosted">
        <span class="detail-name">🚀 ${b.name}</span>
        <span class="detail-time">
          ${timeAgo(b.created_at)}
          <span class="exact-time">(${formatLocalDateTime(b.created_at)})</span>
        </span>
      </div>
    `).join("")
  : ``;

panelEl.innerHTML = `
  <div class="track-panel ${track.boost_count > 0 ? 'boosted' : ''}">
  
<div class="track-header-inline">

  ${track.album_art
    ? `<img src="${track.album_art}" class="track-cover">`
    : ``
  }

  <div class="track-header-info">
    <h2 class="track-title">
  ${track.song_title}
  ${track.boost_count > 0
    ? `<span class="boost-badge">🚀</span>`
    : ``}
</h2>
    <div class="track-artist">${track.artist || ""}</div>

    <div class="track-meta">
      <span>Popularity: <strong>${track.popularity}</strong></span>
      <span class="meta-dot">•</span>
      <span>Requests: <strong>${track.request_count}</strong></span>
      <span class="meta-dot">•</span>
      <span>Votes: <strong>${track.vote_count}</strong></span>
    </div>
  </div>

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

<div class="track-separator"></div>

<div class="track-voters">
  <div class="track-requesters-label">Votes</div>
  <div class="detail-list">
    ${voterHtml}
  </div>
</div>

${track.boost_count > 0 ? `
  <div class="track-separator"></div>

  <div class="track-boosters">
    <div class="track-requesters-label electric">Boosts</div>
    <div class="detail-list">
      ${boosterHtml}
    </div>
  </div>
` : ``}



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
      const targetKeys = getGroupTrackKeys(track);
      if (!targetKeys.length) return;

      for (const key of targetKeys) {
        // 1️⃣ Mark played in DB (authoritative)
        await fetch("/api/dj/mark_played.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({
            event_id: EVENT_ID,
            track_key: key
          })
        });

        // 2️⃣ Remove from Spotify playlist
        await syncSpotifyTrackMutation(key, "remove");
      }

      // 3️⃣ Update cache
      djRequestsCache.forEach(r => {
        if (targetKeys.includes(r.track_key)) {
          r.track_status = "played";
        }
      });

      // ✅ Stay on same tab
      requestStatusFilter = currentFilter;

      updateRequestTabCounts();
      renderDjRequests();

      // Reload panel
      const updatedTrack = findGroupedRowByTrackKey(activeTrackKey || track.track_key);
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
      const targetKeys = getGroupTrackKeys(track);
      if (!targetKeys.length) return;

      for (const key of targetKeys) {
        // 1️⃣ Mark active in DB (authoritative)
        await fetch("/api/dj/mark_active.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({
            event_id: EVENT_ID,
            track_key: key
          })
        });

        // 2️⃣ Re-add to Spotify playlist (ONLY if Spotify-backed)
        await syncSpotifyTrackMutation(key, "add");
      }

      // 3️⃣ Update cache
      djRequestsCache.forEach(r => {
        if (targetKeys.includes(r.track_key)) {
          r.track_status = "active";
        }
      });

      // ✅ DO NOT TOUCH TABS
      requestStatusFilter = currentFilter;

      updateRequestTabCounts();
      renderDjRequests();

      const updatedTrack = findGroupedRowByTrackKey(activeTrackKey || track.track_key);
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
      const targetKeys = getGroupTrackKeys(track);
      if (!targetKeys.length) return;

      // 1️⃣ Mark skipped in DB (authoritative)
      for (const key of targetKeys) {
        await fetch("/api/dj/mark_skipped.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({
            event_id: EVENT_ID,
            track_key: key
          })
        });

        // 2️⃣ Remove from Spotify playlist
        await syncSpotifyTrackMutation(key, "remove");
      }

      // 3️⃣ Optimistic UI update
      djRequestsCache.forEach(r => {
        if (targetKeys.includes(r.track_key)) {
          r.track_status = "skipped";
        }
      });

      updateRequestTabCounts();
      renderDjRequests();

      const updatedTrack = findGroupedRowByTrackKey(activeTrackKey || track.track_key);

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
  if (!EVENT_UUID || !messageListEl) return;
  const seq = ++messageFetchSeq;

  try {
    const res = await fetch(`/api/dj/get_messages.php?event=${EVENT_UUID}`);
    const data = await res.json();

    if (seq < lastAppliedMessageFetchSeq) {
      return;
    }

    if (data.ok && Array.isArray(data.rows)) {
      const rows = data.rows.map(row => {
        const token = row.guest_token || "";
        if (token && guestStatusOverrides.has(token)) {
          return { ...row, guest_status: guestStatusOverrides.get(token) };
        }
        return row;
      });
      messageCache = rows;
      lastAppliedMessageFetchSeq = seq;
      renderMessages();
      updateReplyTargetLabel();
      if (replyGuestToken) {
        loadDjMessageThreadForGuest(replyGuestToken);
      }
    }
  } catch (err) {
    console.error("Message load failed", err);
  }
}

async function saveGuestStatus(guestToken, status) {
  const fd = new URLSearchParams({
    event_uuid: EVENT_UUID,
    guest_token: guestToken,
    status
  });

  const res = await fetch("/api/dj/set_guest_message_status.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: fd
  });
  const data = await res.json();
  if (!data.ok) {
    throw new Error(data.error || "Failed to update guest status");
  }
}

function updateMessageCacheGuestStatus(guestToken, status) {
  if (!guestToken) return;
  if (status === "active") {
    guestStatusOverrides.delete(guestToken);
  } else {
    guestStatusOverrides.set(guestToken, status);
  }
  messageCache = messageCache.map(row => {
    if (row.guest_token !== guestToken) return row;
    return { ...row, guest_status: status };
  });
}

function updateReplyTargetLabel() {
  const labelEl = document.getElementById("replyGuestLabel");
  if (!labelEl) return;

  if (!replyGuestToken) {
    labelEl.textContent = "Reply target: none selected";
    return;
  }

  const latest = messageCache.find(m => m.guest_token === replyGuestToken);
  const overrideName = replyGuestNameOverrides.get(replyGuestToken) || "";
  const name = latest?.patron_name || overrideName || "Guest";
  labelEl.textContent = `Reply target: ${name}`;
}

function setReplyBoxOpen(open) {
  const box = document.getElementById("messageReplyBox");
  if (!box) return;
  box.classList.toggle("hidden", !open);
}

function setDjThreadOpen(open) {
  if (messageListEl) {
    messageListEl.style.display = open ? "none" : "flex";
  }
  if (!djMessageThreadEl) return;
  djMessageThreadEl.classList.toggle("hidden", !open);
}

function closeReplyThread() {
  const input = document.getElementById("djReplyText");
  if (input) input.value = "";
  if (replyGuestToken) {
    replyGuestNameOverrides.delete(replyGuestToken);
  }
  replyGuestToken = null;
  activeGuestToken = null;
  filterByGuest = false;
  setReplyBoxOpen(false);
  setDjThreadOpen(false);
  updateReplyTargetLabel();
  showToast("Reply target cleared", "info");
  renderMessages();
}

async function startReplyToGuest(guestToken, patronName = "Guest") {
  const token = String(guestToken || "").trim();
  if (!token) return;

  replyGuestToken = token;
  activeGuestToken = token;
  filterByGuest = true;
  if (patronName && patronName !== "Guest") {
    replyGuestNameOverrides.set(token, String(patronName));
  }

  switchMessagePrimaryView("chats");
  setReplyBoxOpen(true);
  setDjThreadOpen(true);
  updateReplyTargetLabel();
  await loadDjMessageThreadForGuest(token);

  const input = document.getElementById("djReplyText");
  if (input) input.focus();

  document.getElementById('connectedPatronsModal')?.classList.add('hidden');
  document.getElementById('topPatronsModal')?.classList.add('hidden');
}

function renderDjThread(rows) {
  if (!djMessageThreadEl) return;

  if (!rows || !rows.length) {
    djMessageThreadEl.innerHTML = `<div class="dj-thread-empty">No conversation yet for this guest.</div>`;
    return;
  }

  djMessageThreadEl.innerHTML = rows.map(row => {
    if (row.sender === "poll") {
      const poll = row.poll || {};
      const options = Array.isArray(poll.options)
        ? poll.options
        : (Array.isArray(row.options) ? row.options : []);
      const selected = Number(poll.selected_option_id || 0);
      const optionsHtml = options.map(opt => {
        const isSelected = Number(opt.id) === selected;
        return `<div class="poll-response-tile${isSelected ? " selected" : ""}"><span>${isSelected ? "✅ " : ""}${escapeHtml(opt.option_text || "")}</span><span class="count">${Number(opt.vote_count || 0)} votes</span></div>`;
      }).join("");
      return `<div class="dj-thread-row poll"><div class="dj-thread-bubble poll-card"><div class="poll-question-banner">${escapeHtml(poll.question || row.body || "")}</div><div class="poll-response-list">${optionsHtml}</div><span class="dj-thread-time">${formatThreadTime(row.created_at)}</span></div></div>`;
    }

    let sender = 'guest';
    if (row.sender === 'dj') sender = 'dj';
    if (row.sender === 'broadcast') sender = 'broadcast';

    const badge = sender === 'broadcast'
      ? '<span class="dj-thread-badge">Broadcast</span>'
      : '';

    return `<div class="dj-thread-row ${sender}"><div class="dj-thread-bubble">${badge}${escapeHtml(row.body || "")}<span class="dj-thread-time">${formatThreadTime(row.created_at)}</span></div></div>`;
  }).join("");

  djMessageThreadEl.scrollTop = djMessageThreadEl.scrollHeight;
}

async function loadDjMessageThreadForGuest(guestToken) {
  if (!djMessageThreadEl || !guestToken) return;
  try {
    const res = await fetch(
      `/api/dj/get_message_thread.php?event_uuid=${encodeURIComponent(EVENT_UUID)}&guest_token=${encodeURIComponent(guestToken)}`
    );
    const data = await res.json();
    if (!data.ok) return;
    renderDjThread(data.rows || []);
  } catch (err) {
    console.warn("DJ thread load failed", err);
  }
}

async function sendDjReply() {
  const input = document.getElementById("djReplyText");
  if (!input) return;

  const body = input.value.trim();
  if (!replyGuestToken) {
    showToast("Select a guest first (right click/long press -> Reply)", "info");
    return;
  }
  if (!body) {
    showToast("Reply cannot be empty", "info");
    return;
  }

  const fd = new URLSearchParams({
    event_uuid: EVENT_UUID,
    guest_token: replyGuestToken,
    message: body
  });

  const sendBtn = document.getElementById("djReplySend");
  if (sendBtn) sendBtn.disabled = true;

  try {
    const res = await fetch("/api/dj/reply_message.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: fd
    });
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || "Failed to send reply");
    }

    input.value = "";
    showToast("Reply sent", "success");
  } catch (err) {
    showToast(err.message || "Failed to send reply", "error");
  } finally {
    if (sendBtn) sendBtn.disabled = false;
    await loadDjMessages();
    if (replyGuestToken) {
      await loadDjMessageThreadForGuest(replyGuestToken);
    }
  }
}



async function sendEventBroadcast() {
  const form = document.getElementById('broadcastForm');
  const input = document.getElementById('broadcastMessage');
  const statusEl = document.getElementById('broadcastStatus');
  const sendBtn = document.getElementById('broadcastSendBtn');

  if (!input || !form) return;

  const body = input.value.trim();
  if (!body) {
    if (statusEl) statusEl.textContent = 'Broadcast message is required.';
    return;
  }

  if (sendBtn) sendBtn.disabled = true;
  if (statusEl) statusEl.textContent = 'Sending broadcast...';

  try {
    const res = await fetch('/api/dj/send_event_broadcast.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        event_uuid: EVENT_UUID,
        message: body,
      }),
    });

    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Failed to send broadcast');
    }

    if (statusEl) statusEl.textContent = 'Broadcast sent to event patrons.';
    input.value = '';
    document.getElementById('broadcastModal')?.classList.add('hidden');
    if (statusEl) statusEl.textContent = '';
    showToast('Broadcast sent', 'success');
    await loadBroadcasts();
    await loadDjMessages();

    if (replyGuestToken) {
      await loadDjMessageThreadForGuest(replyGuestToken);
    }
  } catch (err) {
    if (statusEl) statusEl.textContent = err.message || 'Failed to send broadcast.';
    showToast(err.message || 'Failed to send broadcast', 'error');
  } finally {
    if (sendBtn) sendBtn.disabled = false;
  }
}

function renderBroadcasts() {
  const listEl = document.getElementById("broadcastsList");
  if (!listEl) return;

  if (!broadcastCache.length) {
    listEl.innerHTML = `<div class="dj-thread-empty">No broadcasts yet for this event.</div>`;
    return;
  }

  listEl.innerHTML = broadcastCache.map(item => `<div class="dj-thread-row broadcast"><div class="dj-thread-bubble"><span class="dj-thread-badge">Broadcast</span>${escapeHtml(item.message || "")}<span class="dj-thread-time">${formatThreadTime(item.created_at)}</span></div></div>`).join("");
}

async function loadBroadcasts() {
  try {
    const res = await fetch(`/api/dj/get_broadcasts.php?event_uuid=${encodeURIComponent(EVENT_UUID)}`);
    const data = await res.json();
    if (!data.ok) return;
    broadcastCache = Array.isArray(data.rows) ? data.rows : [];
    renderBroadcasts();
  } catch (e) {
    console.warn("Broadcast list load failed", e);
  }
}

function renderPolls() {
  const listEl = document.getElementById("pollsList");
  if (!listEl) return;

  if (!pollCache.length) {
    listEl.innerHTML = `<div class="dj-thread-empty">No polls yet for this event.</div>`;
    return;
  }

  listEl.innerHTML = pollCache.map(poll => {
    const options = Array.isArray(poll.options) ? poll.options : [];
    const optionsHtml = options.map(opt => `
      <div class="poll-response-tile">
        <span>${escapeHtml(opt.option_text || "")}</span>
        <span class="count">${Number(opt.vote_count || 0)} votes</span>
      </div>
    `).join("");

    return `
      <div class="poll-item poll-card poll-item-clickable" data-poll-id="${Number(poll.id || 0)}">
        <div class="poll-question-banner">${escapeHtml(poll.question || "")}</div>
        <div class="meta">${formatThreadTime(poll.created_at)} · ${Number(poll.total_votes || 0)} total votes</div>
        <div class="poll-response-list">${optionsHtml}</div>
      </div>
    `;
  }).join("");
}

function renderPollVoterDetails(poll) {
  const bodyEl = document.getElementById("pollDetailsBody");
  if (!bodyEl) return;

  const options = Array.isArray(poll?.options) ? poll.options : [];
  const optionBlocks = options.map(opt => {
    const voters = Array.isArray(opt.voters) ? opt.voters : [];
    const votersHtml = voters.length
      ? voters.map(v => `
          <div class="poll-voter-row">
            <span class="poll-voter-name">${escapeHtml(v.patron_name || "Guest")}</span>
            <span class="poll-voter-time">${formatThreadTime(v.voted_at || "")}</span>
          </div>
        `).join("")
      : `<div class="poll-voter-empty">No votes yet</div>`;

    return `
      <div class="poll-voter-option">
        <div class="poll-voter-option-head">
          <span>${escapeHtml(opt.option_text || "")}</span>
          <span>${Number(opt.vote_count || 0)} votes</span>
        </div>
        <div class="poll-voter-list">${votersHtml}</div>
      </div>
    `;
  }).join("");

  bodyEl.innerHTML = `
    <div class="poll-item poll-card">
      <div class="poll-question-banner">${escapeHtml(poll?.question || "")}</div>
      <div class="meta">${formatThreadTime(poll?.created_at || "")} · ${Number(poll?.total_votes || 0)} total votes</div>
      <div class="poll-voter-options">${optionBlocks}</div>
    </div>
  `;
}

async function openPollVoterDetails(pollId) {
  if (!pollId) return;
  const modal = document.getElementById("pollDetailsModal");
  const bodyEl = document.getElementById("pollDetailsBody");
  if (!modal || !bodyEl) return;

  modal.classList.remove("hidden");
  bodyEl.innerHTML = `<div class="dj-thread-empty">Loading poll votes...</div>`;

  try {
    const res = await fetch(`/api/dj/get_poll_voters.php?event_uuid=${encodeURIComponent(EVENT_UUID)}&poll_id=${encodeURIComponent(String(pollId))}`);
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || "Unable to load poll votes");
    }
    renderPollVoterDetails(data.poll || {});
  } catch (e) {
    bodyEl.innerHTML = `<div class="dj-thread-empty">${escapeHtml(e.message || "Unable to load poll votes")}</div>`;
  }
}

async function loadPolls() {
  try {
    const res = await fetch(`/api/dj/get_polls.php?event_uuid=${encodeURIComponent(EVENT_UUID)}`);
    const data = await res.json();
    if (!data.ok) return;
    pollCache = Array.isArray(data.rows) ? data.rows : [];
    renderPolls();
  } catch (e) {
    console.warn("Poll list load failed", e);
  }
}

async function createPoll() {
  const questionEl = document.getElementById("pollQuestion");
  const wrapEl = document.getElementById("pollOptionsWrap");
  const statusEl = document.getElementById("pollCreateStatus");
  const createBtn = document.getElementById("pollCreateBtn");
  const createPollTabBtn = document.getElementById("createPollBtn");
  if (!questionEl || !wrapEl) return;
  if (createPollTabBtn?.disabled) {
    showToast("Poll creation is a Premium feature.", "info");
    return;
  }

  const question = questionEl.value.trim();
  const optionInputs = Array.from(wrapEl.querySelectorAll(".poll-option-input"));
  const options = optionInputs.map(i => i.value.trim()).filter(Boolean);

  if (!question) {
    if (statusEl) statusEl.textContent = "Question is required.";
    return;
  }
  if (options.length < 2) {
    if (statusEl) statusEl.textContent = "Please provide at least 2 answers.";
    return;
  }

  if (createBtn) createBtn.disabled = true;
  if (statusEl) statusEl.textContent = "Creating poll...";

  try {
    const body = new URLSearchParams({ event_uuid: EVENT_UUID, question });
    options.forEach(opt => body.append("options[]", opt));

    const res = await fetch("/api/dj/create_poll.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "Failed to create poll");

    if (statusEl) statusEl.textContent = "Poll created.";
    showToast("Poll created", "success");
    questionEl.value = "";
    optionInputs.forEach((input, idx) => {
      if (idx > 1) input.remove();
      else input.value = "";
    });
    document.getElementById("createPollModal")?.classList.add("hidden");
    if (statusEl) statusEl.textContent = "";
    await loadPolls();
    if (messagePrimaryView === "chats" && replyGuestToken) {
      await loadDjMessageThreadForGuest(replyGuestToken);
    }
  } catch (e) {
    if (statusEl) statusEl.textContent = e.message || "Failed to create poll.";
    showToast(e.message || "Failed to create poll", "error");
  } finally {
    if (createBtn) createBtn.disabled = false;
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
  const status = getGuestStatus(msg);
  const isMuted = status === "muted";
  const isBlocked = status === "blocked";

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

  const status = getGuestStatus(msg);
  const isMuted = status === "muted";
  const isBlocked = status === "blocked";

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
      <div class="message-author">${escapeHtml(msg.patron_name || "Guest")}</div>
      ${badgeHtml}
    </div>

    <div class="message-text">${escapeHtml(msg.message)}</div>

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
UPCOMING/LIVE/ENDED STATE STATUS
========================================= */


function getNextState(current) {
  if (current === 'upcoming') return 'live';
  if (current === 'live') return 'ended';
  if (current === 'ended') return 'upcoming';
  return null;
}

function getConfirmMessage(next) {
  if (next === 'live') {
    return 'Go LIVE?\n\nThis will show the live message to guests.';
  }
  if (next === 'ended') {
    return 'End this event?\n\nRequests will close and the end message will be shown.';
  }
  if (next === 'upcoming') {
    return 'Reopen this event?\n\nPre-event requests will reopen.';
  }
}


/* =========================================
Render Badge + Button
========================================= */


function renderEventStateControl() {
    
  const { eventState, eventId } = window.DJ_CONFIG;

  const badge = document.getElementById('djEventStateBadge');
  const container = document.getElementById('djEventStateControl');

  if (!badge || !container) return;

  // Badge
  badge.innerHTML = '';
  badge.className = '';

  if (eventState === 'live') {
    badge.className = 'event-live';
    badge.textContent = 'LIVE';
  } else {
    badge.className = 'event-state';
    badge.textContent = eventState.toUpperCase();
  }

  // Button
  const next = getNextState(eventState);
  if (!next) return;

  let label = '';
  let cls = '';

  if (next === 'live') {
    label = 'Go Live';
    cls = 'btn btn-primary';
  } else if (next === 'ended') {
    label = 'End Event';
    cls = 'btn btn-danger';
  } else {
    label = 'Reopen Event – Set Upcoming';
    cls = 'btn btn-secondary';
  }

  container.innerHTML = `
    <button class="${cls}" id="djToggleEventState">
      ${label}
    </button>
  `;

  document
    .getElementById('djToggleEventState')
    .addEventListener('click', () => toggleEventState(eventId, eventState));
}

/* =========================================
   TOGGLE LOGIC
========================================= */
async function toggleEventState(eventId, currentState) {
  const next = getNextState(currentState);
  if (!next) return;

  if (!confirm(getConfirmMessage(next))) return;

  try {
    const res = await fetch('/dj/api/event_state_toggle.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        event_id: eventId,
        state: next
      })
    });

    const data = await res.json();
    if (!data.ok) throw new Error();

    location.reload(); // SAFE PHASE 1

  } catch {
    alert('Failed to update event state.');
  }
}

async function syncSpotifyPlaylist(manual = false) {
  if (!SPOTIFY_SYNC_ENABLED || !EVENT_ID) return;
  if (spotifySyncInFlight) return;

  spotifySyncInFlight = true;
  const syncBtn = document.getElementById("djSpotifySyncBtn");
  const originalLabel = syncBtn ? syncBtn.textContent : "";

  if (manual && syncBtn) {
    syncBtn.disabled = true;
    syncBtn.textContent = "Syncing...";
  }

  try {
    const res = await fetch("/api/dj/spotify/sync_event_playlist.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ event_id: String(EVENT_ID) })
    });
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || "Spotify sync failed");
    }

    if (manual) {
      showToast(
        `Spotify synced (added ${Number(data.added || 0)}, removed ${Number(data.removed || 0)})`,
        "success"
      );
    }
  } catch (err) {
    if (manual) {
      showToast(err?.message || "Spotify sync failed", "error");
    }
  } finally {
    spotifySyncInFlight = false;
    if (manual && syncBtn) {
      syncBtn.disabled = false;
      syncBtn.textContent = originalLabel || "🔄 Sync Spotify";
    }
  }
}

function isSpotifyTrackKey(trackKey) {
  return /^[A-Za-z0-9]{22}$/.test(String(trackKey || ""));
}

async function syncSpotifyTrackMutation(trackKey, mode) {
  if (!isSpotifyTrackKey(trackKey) || !EVENT_ID) return;

  const endpoint = mode === "add"
    ? "/api/dj/spotify/add_track.php"
    : "/api/dj/spotify/remove_track.php";

  const res = await fetch(endpoint, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      event_id: String(EVENT_ID),
      spotify_track_id: String(trackKey)
    })
  });

  let data = null;
  try {
    data = await res.json();
  } catch {
    data = null;
  }

  if (!res.ok || !data?.ok) {
    await syncSpotifyPlaylist(false);
    throw new Error(data?.error || "Spotify update failed");
  }
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



function renderConnectedPatronsList(items) {
  const listEl = document.getElementById('connectedPatronsList');
  if (!listEl) return;

  if (!items.length) {
    listEl.innerHTML = '<div class="top-patrons-empty">No connected patrons yet.</div>';
    return;
  }

  listEl.innerHTML = items.map((item, idx) => {
    const rawName = String(item.patron_name || 'Guest');
    const label = escapeHtml(rawName);
    const countryCode = String(item.country_code || '').trim().toUpperCase();
    const flag = countryCodeToFlagEmoji(countryCode);
    const flagHtml = flag
      ? `<span class="patron-flag" title="${escapeHtml(countryCode)}">${flag}</span>`
      : (countryCode
          ? `<span class="patron-flag-code" title="${escapeHtml(countryCode)}">${escapeHtml(countryCode)}</span>`
          : `<span class="patron-flag patron-flag-unknown" title="Unknown location">🌐</span>`);
    const token = String(item.guest_token || '');
    const fullToken = token ? escapeHtml(token) : '—';
    const seen = item.last_seen_at ? formatThreadTime(item.last_seen_at) : '—';

    return `
      <div class="top-patron-row">
        <div class="top-patron-rank">${idx + 1}</div>
        <div>
          <div class="top-patron-name">${flagHtml}${label}</div>
          <div class="top-patron-meta">Token: <span class="top-patron-token">${fullToken}</span></div>
        </div>
        <div class="top-patron-right">
          <button type="button" class="top-patron-message-btn" data-guest-token="${escapeHtml(token)}" data-patron-name="${escapeHtml(rawName)}" title="Send direct message">Message</button>
          <div class="top-patron-total">${seen}</div>
        </div>
      </div>
      <div class="top-patron-divider"></div>
    `;
  }).join('');
}

function renderPatronActivityPanel(data) {
  const requested = Array.isArray(data?.requested_tracks) ? data.requested_tracks : [];
  const voted = Array.isArray(data?.voted_tracks) ? data.voted_tracks : [];

  const requestRows = requested.length
    ? requested.map((row) => `
        <li>
          <span>${escapeHtml(row.song_title || 'Unknown track')}</span>
          <span class="activity-count">x${Number(row.request_count || 0)}</span>
        </li>
      `).join('')
    : '<li class="empty">No requests</li>';

  const voteRows = voted.length
    ? voted.map((row) => `
        <li>
          <span>${escapeHtml(row.song_title || 'Unknown track')}</span>
          <span class="activity-count">x${Number(row.vote_count || 0)}</span>
        </li>
      `).join('')
    : '<li class="empty">No votes</li>';

  return `
    <div class="top-patron-activity-block">
      <div class="activity-title">Requested Tracks</div>
      <ul class="activity-list">${requestRows}</ul>
    </div>
    <div class="top-patron-activity-block">
      <div class="activity-title">Voted Tracks</div>
      <ul class="activity-list">${voteRows}</ul>
    </div>
  `;
}

async function getPatronActivity(guestToken) {
  if (!guestToken) {
    return { requested_tracks: [], voted_tracks: [] };
  }

  if (patronActivityCache.has(guestToken)) {
    return patronActivityCache.get(guestToken);
  }

  const res = await fetch(
    `/api/dj/get_patron_activity.php?event_uuid=${encodeURIComponent(EVENT_UUID)}&guest_token=${encodeURIComponent(guestToken)}`
  );
  const data = await res.json();

  if (!data.ok) {
    throw new Error(data.error || 'Failed to load patron activity');
  }

  patronActivityCache.set(guestToken, data);
  return data;
}

function setupTopPatronExpand() {
  const listEl = document.getElementById('topPatronsList');
  if (!listEl) return;

  listEl.querySelectorAll('.top-patron-item').forEach((itemEl) => {
    const detailEl = itemEl.querySelector('.top-patron-activity');
    const guestToken = itemEl.dataset.guestToken || '';
    const rowBtn = itemEl.querySelector('.top-patron-row-btn');

    const open = async () => {
      if (!detailEl) return;
      detailEl.classList.add('visible');
      itemEl.classList.add('expanded');
      topPatronExpandedToken = guestToken || null;
      const icon = itemEl.querySelector('.top-patron-expand-indicator');
      if (icon) icon.textContent = '−';

      if (detailEl.dataset.loaded === '1') return;

      detailEl.innerHTML = '<div class="activity-loading">Loading activity...</div>';
      try {
        const data = await getPatronActivity(guestToken);
        detailEl.innerHTML = renderPatronActivityPanel(data);
        detailEl.dataset.loaded = '1';
      } catch (err) {
        detailEl.innerHTML = `<div class="activity-loading">${escapeHtml(err.message || 'Unable to load activity')}</div>`;
      }
    };

    const close = () => {
      if (!detailEl) return;
      detailEl.classList.remove('visible');
      itemEl.classList.remove('expanded');
      if ((topPatronExpandedToken || '') === (guestToken || '')) {
        topPatronExpandedToken = null;
      }
      const icon = itemEl.querySelector('.top-patron-expand-indicator');
      if (icon) icon.textContent = '+';
    };

    const toggle = async () => {
      if (detailEl?.classList.contains('visible')) {
        close();
      } else {
        // collapse others first
        listEl.querySelectorAll('.top-patron-item.expanded').forEach((other) => {
          if (other === itemEl) return;
          other.classList.remove('expanded');
          other.querySelector('.top-patron-activity')?.classList.remove('visible');
        });
        await open();
      }
    };

    rowBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      toggle();
    });
  });
}

function renderTopPatronsList(items) {
  const listEl = document.getElementById('topPatronsList');
  if (!listEl) return;

  const ranked = (Array.isArray(items) ? items : []).filter((item) => Number(item?.total_actions || 0) > 0);

  if (!ranked.length) {
    listEl.innerHTML = '<div class="top-patrons-empty">No patron activity yet for this event.</div>';
    return;
  }

  listEl.innerHTML = ranked.map((item, idx) => {
    const token = String(item.guest_token || '');

    return `
      <div class="top-patron-item" data-guest-token="${escapeHtml(token)}" tabindex="0">
        <button type="button" class="top-patron-row top-patron-row-btn">
          <div class="top-patron-rank">${idx + 1}</div>
          <div>
            <div class="top-patron-name">${escapeHtml(item.patron_name || 'Guest')}</div>
            <div class="top-patron-meta">${item.request_count || 0} requests • ${item.vote_count || 0} votes</div>
          </div>
          <div class="top-patron-right">
            <div class="top-patron-total">${item.total_actions || 0} total</div>
            <div class="top-patron-expand-indicator">+</div>
          </div>
        </button>
        <div class="top-patron-activity"></div>
      </div>
      <div class="top-patron-divider"></div>
    `;
  }).join('');

  setupTopPatronExpand();

  // Keep expanded patron detail open across periodic insights refreshes.
  if (topPatronExpandedToken) {
    const targetItem = Array.from(listEl.querySelectorAll('.top-patron-item'))
      .find((el) => (el.dataset.guestToken || '') === topPatronExpandedToken);
    const rowBtn = targetItem?.querySelector('.top-patron-row-btn');
    rowBtn?.click();
  }
}

function renderDjInsights(data) {
  const connectedEl = document.getElementById('djConnectedCount');
  const engagementRateEl = document.getElementById('djEngagementRate');
  const engagementMetaEl = document.getElementById('djEngagementMeta');
  const topPatronNameEl = document.getElementById('djTopPatronName');
  const topPatronMetaEl = document.getElementById('djTopPatronMeta');

  if (connectedEl) {
    connectedEl.textContent = String(data.connected_patrons ?? 0);
  }

  if (engagementRateEl) {
    const rate = Number(data.engagement_rate || 0);
    engagementRateEl.textContent = `${rate.toFixed(1).replace(/\.0$/, '')}%`;
  }

  if (engagementMetaEl) {
    engagementMetaEl.textContent = `${data.active_patrons || 0} active patrons`;
  }

  const top = Array.isArray(data.top_patrons) ? data.top_patrons[0] : null;

  if (topPatronNameEl) {
    topPatronNameEl.textContent = top ? (top.patron_name || 'Guest') : '—';
    topPatronNameEl.title = top ? (top.patron_name || 'Guest') : '';
  }

  if (topPatronMetaEl) {
    topPatronMetaEl.textContent = top
      ? `${top.request_count || 0} req • ${top.vote_count || 0} votes`
      : 'No activity yet';
  }

  renderTopPatronsList(Array.isArray(data.top_patrons) ? data.top_patrons : []);
  renderConnectedPatronsList(Array.isArray(data.connected_guests) ? data.connected_guests : []);
}

async function loadDjInsights() {
  if (!EVENT_UUID) return;

  const hasInsightsUi =
    document.getElementById('djConnectedCount') ||
    document.getElementById('djEngagementRate') ||
    document.getElementById('djTopPatronsTile');

  if (!hasInsightsUi) return;

  try {
    const res = await fetch(`/api/dj/get_event_insights.php?event_uuid=${encodeURIComponent(EVENT_UUID)}`);
    const data = await res.json();
    if (!data.ok) return;

    insightsCache = data;
    renderDjInsights(data);
  } catch (err) {
    console.warn('Insights load failed', err);
  }
}

/* =========================================
   POLLING
========================================= */
loadDjRequests();
loadDjMessages();
loadBroadcasts();
if (POLLS_PREMIUM_ENABLED) {
  loadPolls();
}
loadDjMood();
if (TIPS_BOOST_VISIBLE) {
  loadDjSupport();
} else {
  document.getElementById('djSupportTile')?.classList.add('hidden');
  document.getElementById('supportModal')?.classList.add('hidden');
}
loadDjInsights();

setInterval(loadDjRequests, POLL_MS);
setInterval(loadDjMessages, POLL_MS);
if (TIPS_BOOST_VISIBLE) {
  setInterval(loadDjSupport, POLL_MS);
}
setInterval(loadBroadcasts, POLL_MS);
if (POLLS_PREMIUM_ENABLED) {
  setInterval(loadPolls, POLL_MS);
}
setInterval(loadDjInsights, POLL_MS);
setInterval(loadDjMood, 15000);

if (SPOTIFY_SYNC_ENABLED) {
  setInterval(() => {
    if (window.DJ_CONFIG?.eventState !== "live") return;
    if (document.visibilityState !== "visible") return;
    syncSpotifyPlaylist(false);
  }, SPOTIFY_SYNC_MS);
}


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


let lastSupportTotal = null;

async function loadDjSupport() {
  if (!TIPS_BOOST_VISIBLE) return;
  const tile = document.getElementById('djSupportTile');
  if (!tile) return;

  try {
    const res = await fetch(
      `/api/dj/get_event_support.php?event_id=${DJ_CONFIG.eventId}`
    );
    const data = await res.json();

    if (!data.ok) return;

    const total = data.total_cents || 0;

    // 💡 ALWAYS show tile (even $0.00)
    tile.style.display = 'block';

    // 🔔 Pulse ONLY if value increased
    if (lastSupportTotal !== null && total > lastSupportTotal) {
      tile.classList.add('pulse');
      setTimeout(() => tile.classList.remove('pulse'), 600);
    }

    lastSupportTotal = total;

    // Update amount
    document.getElementById('djSupportAmount').textContent =
      `$${(total / 100).toFixed(2)}`;

    renderSupportList(data.items || []);

  } catch (e) {
    console.warn('Support load failed', e);
  }
}





function renderSupportList(items) {
  const wrap = document.getElementById('supportList');
  if (!wrap) return;

  wrap.innerHTML = '';

  const boosts = items.filter(i => i.type === 'boost');
  const tips   = items.filter(i => i.type !== 'boost');

  /* -------- BOOSTS -------- */
  if (boosts.length) {
    wrap.innerHTML += `<div class="support-section-title">🚀 Boosts</div>`;

    boosts.forEach(i => {
      wrap.innerHTML += `
        <div class="support-row">
          <div class="support-left">
            <div class="support-name">${i.patron_name || 'Anonymous'}</div>
            <div class="support-track">${i.track_title || 'Track Boost'}</div>
            <div class="support-time">${formatSupportTime(i.created_at)}</div>
          </div>
          <div class="support-amount">
            $${(i.amount_cents / 100).toFixed(2)}
          </div>
        </div>
        <div class="support-divider"></div>
      `;
    });
  }

  /* -------- TIPS -------- */
  if (tips.length) {
    wrap.innerHTML += `<div class="support-section-title">💜 Tips</div>`;

    tips.forEach(i => {
      wrap.innerHTML += `
        <div class="support-row">
          <div class="support-left">
            <div class="support-name">${i.patron_name || 'Anonymous'}</div>
            <div class="support-type">Tip</div>
            <div class="support-time">${formatSupportTime(i.created_at)}</div>
          </div>
          <div class="support-amount">
            $${(i.amount_cents / 100).toFixed(2)}
          </div>
        </div>
        <div class="support-divider"></div>
      `;
    });
  }
}


document.addEventListener('DOMContentLoaded', renderEventStateControl);

/* =========================================
   SUPPORT + BROADCAST MODAL INTERACTIONS
   (DELEGATED — SAFE)
========================================= */

document.addEventListener('click', (e) => {

  /* 💜 SUPPORT TILE */
  if (e.target.closest('#djSupportTile')) {
    const modal = document.getElementById('supportModal');
    if (!modal) {
      console.warn('supportModal missing');
      return;
    }
    modal.classList.remove('hidden');
    return;
  }

  /* 📢 LEGACY BROADCAST TILE */
  if (e.target.closest('#djBroadcastTile')) {
    const modal = document.getElementById('broadcastModal');
    if (modal) {
      modal.classList.remove('hidden');
    } else {
      window.location.href = '/admin/broadcasts.php';
    }
    return;
  }

  /* 👥 CONNECTED PATRONS TILE */
  if (e.target.closest('#djConnectedTile')) {
    document.getElementById('connectedPatronsModal')?.classList.remove('hidden');
    return;
  }

  /* 🏆 TOP PATRONS TILE */
  if (e.target.closest('#djTopPatronsTile')) {
    document.getElementById('topPatronsModal')?.classList.remove('hidden');
    return;
  }

  const connectedMessageBtn = e.target.closest('.top-patron-message-btn');
  if (connectedMessageBtn) {
    const token = String(connectedMessageBtn.dataset.guestToken || '');
    const name = String(connectedMessageBtn.dataset.patronName || 'Guest');
    if (!token) {
      showToast('Unable to open chat for this patron.', 'error');
      return;
    }
    startReplyToGuest(token, name);
    return;
  }

  /* ❌ CLOSE SUPPORT MODAL */
  if (e.target.id === 'closeSupportModal') {
    document.getElementById('supportModal')?.classList.add('hidden');
    return;
  }

  /* ❌ CLOSE TOP PATRONS MODAL */
  if (e.target.id === 'closeTopPatronsModal') {
    document.getElementById('topPatronsModal')?.classList.add('hidden');
    return;
  }

  /* ❌ CLOSE CONNECTED PATRONS MODAL */
  if (e.target.id === 'closeConnectedPatronsModal') {
    document.getElementById('connectedPatronsModal')?.classList.add('hidden');
    return;
  }

  /* ❌ CLOSE LEGACY BROADCAST MODAL */
  if (e.target.id === 'closeBroadcastModal' || e.target.id === 'broadcastCancelBtn') {
    document.getElementById('broadcastModal')?.classList.add('hidden');
    const statusEl = document.getElementById('broadcastStatus');
    if (statusEl) statusEl.textContent = '';
    return;
  }

  if (e.target.id === 'closeCreatePollModal') {
    document.getElementById('createPollModal')?.classList.add('hidden');
    const statusEl = document.getElementById('pollCreateStatus');
    if (statusEl) statusEl.textContent = '';
    return;
  }

  if (e.target.id === 'closePollDetailsModal') {
    document.getElementById('pollDetailsModal')?.classList.add('hidden');
    return;
  }

});

const broadcastForm = document.getElementById('broadcastForm');
if (broadcastForm) {
  broadcastForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    await sendEventBroadcast();
  });
}

/* =========================================
   COLUMN SPLITTERS (RESET ON RELOAD)
========================================= */
function initColumnSplitters() {
  const app = document.querySelector('.dj-app');
  const left = document.getElementById('splitterLeft');
  const right = document.getElementById('splitterRight');
  if (!app || !left || !right) return;

  const mobile = window.matchMedia('(max-width: 768px)');
  if (mobile.matches) return;

  const MIN_LEFT = 300;
  const MIN_MIDDLE = 460;
  const MIN_RIGHT = 300;

  let active = null;

  const onMove = (clientX) => {
    if (!active) return;
    const rect = app.getBoundingClientRect();
    const totalW = rect.width;
    const splitW = 10;

    const currentLeft = parseFloat(getComputedStyle(app).getPropertyValue('--left-col-width')) || 420;
    const currentRight = parseFloat(getComputedStyle(app).getPropertyValue('--right-col-width')) || 360;

    if (active === 'left') {
      let nextLeft = clientX - rect.left;
      const maxLeft = totalW - currentRight - MIN_MIDDLE - (splitW * 2);
      nextLeft = Math.max(MIN_LEFT, Math.min(nextLeft, maxLeft));
      app.style.setProperty('--left-col-width', `${Math.round(nextLeft)}px`);
      return;
    }

    if (active === 'right') {
      let nextRight = rect.right - clientX;
      const maxRight = totalW - currentLeft - MIN_MIDDLE - (splitW * 2);
      nextRight = Math.max(MIN_RIGHT, Math.min(nextRight, maxRight));
      app.style.setProperty('--right-col-width', `${Math.round(nextRight)}px`);
    }
  };

  const onPointerMove = (e) => onMove(e.clientX);

  const stopDrag = () => {
    active = null;
    app.classList.remove('is-resizing');
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', stopDrag);
  };

  const startDrag = (which) => {
    active = which;
    app.classList.add('is-resizing');
    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', stopDrag, { once: true });
  };

  left.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    startDrag('left');
  });

  right.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    startDrag('right');
  });
}

document.addEventListener('DOMContentLoaded', initColumnSplitters);
