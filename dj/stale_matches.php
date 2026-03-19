<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/helpers/dj_stale_matches.php';
require_dj_login();

$db = db();
if (!bpmCurrentUserHasAccess($db)) {
    http_response_code(403);
    exit('Premium feature only.');
}

$djId = (int)($_SESSION['dj_id'] ?? 0);
$staleRows = mdjrLoadStaleGlobalMatches($db, $djId);

$pageTitle = 'Stale Matches';
$pageBodyClass = 'dj-page';
include __DIR__ . '/layout.php';
?>
<style>
.stale-wrap { max-width: 980px; margin: 0 auto; }
.stale-head { margin: 0 0 8px; font-size: 34px; line-height: 1.15; }
.stale-sub { margin: 0 0 18px; color: #b7b7c8; font-size: 15px; }
.stale-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:0 0 18px; }
.stale-count { color:#fff; font-weight:700; }
.stale-back { display:inline-flex; align-items:center; gap:8px; border:1px solid #2b2b42; background:#161625; color:#fff; text-decoration:none; border-radius:10px; padding:10px 14px; font-weight:700; }
.stale-list { display:grid; gap:12px; }
.stale-card { border:1px solid #2a2a3f; border-radius:14px; background:#111116; padding:14px 16px; }
.stale-title { margin:0; color:#fff; font-size:22px; font-weight:700; }
.stale-artist { margin:4px 0 0; color:#c8c8da; font-size:16px; }
.stale-meta { margin:10px 0 0; color:#a8a8bd; font-size:13px; display:flex; flex-wrap:wrap; gap:10px; }
.stale-warning { margin:10px 0 0; color:#ffb3b3; font-size:13px; font-weight:600; }
.stale-actions { margin-top:12px; display:flex; gap:10px; }
.stale-btn { border:1px solid rgba(var(--brand-accent-rgb),0.45); background:rgba(var(--brand-accent-rgb),0.12); color:#fff; border-radius:10px; padding:9px 14px; font-weight:700; cursor:pointer; }
.stale-empty { border:1px solid #2a2a3f; border-radius:14px; background:#111116; padding:20px; color:#b7b7c8; }
.stale-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.72); display:none; align-items:center; justify-content:center; z-index:3000; }
.stale-modal-backdrop.is-open { display:flex; }
.stale-modal { width:min(960px, calc(100vw - 32px)); max-height:calc(100vh - 48px); overflow:hidden; border:1px solid #2a2a3f; border-radius:18px; background:#111116; box-shadow:0 20px 60px rgba(0,0,0,0.45); display:flex; flex-direction:column; }
.stale-modal-head { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:18px 20px; border-bottom:1px solid #232336; }
.stale-modal-title { margin:0; color:#fff; font-size:18px; font-weight:800; }
.stale-modal-close { border:none; background:transparent; color:#cfcfe3; font-size:30px; cursor:pointer; line-height:1; }
.stale-modal-body { padding:18px 20px 22px; overflow:auto; }
.stale-search-row { display:flex; gap:10px; margin:0 0 14px; }
.stale-search-input { flex:1 1 auto; border:1px solid #2b2b42; background:#0b0b13; color:#fff; border-radius:10px; padding:12px 14px; font-size:15px; }
.stale-search-btn { border:1px solid #2b2b42; background:#161625; color:#fff; border-radius:10px; padding:12px 16px; font-weight:700; cursor:pointer; }
.stale-status { min-height:22px; color:#cfcfe3; margin:0 0 12px; font-weight:600; }
.stale-status.is-error { color:#ff9b9b; }
.stale-candidates { display:grid; gap:10px; }
.manual-match-item {
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 10px;
    background: rgba(255,255,255,0.03);
    padding: 10px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
}
.manual-match-item.manual-match-item-selected {
    border-color: rgba(46, 204, 113, 0.78);
    background: rgba(46, 204, 113, 0.20);
    box-shadow: inset 0 0 0 1px rgba(46, 204, 113, 0.25);
}
.manual-match-item.manual-match-item-missing {
    border-color: rgba(255, 90, 95, 0.28);
    background: rgba(255, 90, 95, 0.06);
    box-shadow: none;
}
.manual-match-item.manual-match-item-missing.manual-match-item-selected {
    border-color: rgba(255, 90, 95, 0.44);
    background: rgba(255, 90, 95, 0.10);
    box-shadow: inset 0 0 0 1px rgba(255, 90, 95, 0.14);
}
.manual-match-item-main { min-width: 0; }
.manual-match-title {
    font-size: 14px;
    font-weight: 700;
    color: #fff;
}
.manual-match-artist,
.manual-match-meta,
.manual-match-score {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.78);
    margin-top: 2px;
}
.manual-match-genre {
    color: rgba(191, 225, 255, 0.82);
    font-size: 11px;
    letter-spacing: 0.01em;
}
.manual-match-badges {
    margin-top: 6px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.manual-match-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.01em;
}
.manual-match-badge-preferred {
    color: #f6e68b;
    background: rgba(246, 230, 139, 0.14);
    border: 1px solid rgba(246, 230, 139, 0.45);
}
.manual-match-badge-folder {
    color: #b8e7ff;
    background: rgba(77, 187, 255, 0.14);
    border: 1px solid rgba(77, 187, 255, 0.45);
}
.manual-match-badge-stars {
    color: #ffd84a;
    background: rgba(255, 216, 74, 0.14);
    border: 1px solid rgba(255, 216, 74, 0.45);
    text-shadow: 0 0 8px rgba(255, 216, 74, 0.25);
}
.manual-match-badge-local {
    color: #c4f0ff;
    background: rgba(120, 220, 255, 0.10);
    border: 1px solid rgba(120, 220, 255, 0.35);
}
.manual-match-meta.manual-match-owned {
    color: #22e07a !important;
    font-weight: 700;
    text-shadow: 0 0 8px rgba(34, 224, 122, 0.35);
}
.manual-match-meta.manual-match-missing {
    color: #ff5a5f !important;
    font-weight: 700;
}
.stale-badge { display:inline-flex; align-items:center; border-radius:999px; padding:3px 9px; font-size:12px; font-weight:700; border:1px solid #2f2f4a; background:#171727; color:#e2e2f2; }
.stale-badge.pref { border-color:#8c7a2e; background:rgba(255,214,87,0.12); color:#ffe98f; }
.stale-badge.folder { border-color:#3884b5; background:rgba(59,170,255,0.12); color:#9ed9ff; }
.stale-badge.rating { border-color:#8c7a2e; background:rgba(255,214,87,0.12); color:#ffd857; }
.stale-badge.local { border-color:#4fb6e8; background:rgba(79,182,232,0.12); color:#c5efff; }
.stale-candidate-actions { margin-top:12px; }
.stale-apply-btn { border:none; border-radius:10px; padding:10px 14px; font-weight:700; color:#fff; background:linear-gradient(90deg, var(--brand-accent) 0%, var(--brand-accent-strong) 100%); cursor:pointer; }
.manual-match-apply-btn {
    display: inline-flex;
    align-items: center;
    min-width: 92px;
    justify-content: center;
    border: none;
    border-radius: 14px;
    padding: 12px 18px;
    font-weight: 800;
    font-size: 14px;
    color: #111;
    background: linear-gradient(90deg, #49c6ff 0%, #f53ad7 100%);
    box-shadow: 0 10px 24px rgba(245, 58, 215, 0.18);
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}
.manual-match-apply-btn:hover:not([disabled]):not([aria-disabled="true"]) {
    transform: translateY(-1px);
    box-shadow: 0 12px 28px rgba(245, 58, 215, 0.24);
}
.manual-match-apply-btn[disabled],
.manual-match-apply-btn[aria-disabled="true"] {
    opacity: 0.55;
    cursor: not-allowed;
    filter: grayscale(0.15);
    box-shadow: none;
}
.stale-candidate-genre { margin:6px 0 0; color:#bfe1ff; font-size:12px; }
</style>

<div class="stale-wrap">
    <h1 class="stale-head">Stale Matched Tracks</h1>
    <p class="stale-sub">
        These saved manual matches no longer point to a currently available local DJ file. Resolve them one at a time and the updated match will be reused in future events.
    </p>

    <div class="stale-toolbar">
        <div class="stale-count"><span id="staleCountValue"><?php echo count($staleRows); ?></span> stale matches</div>
        <a class="stale-back" href="<?php echo e(url('dj/library_import.php')); ?>">← Back to Library Import</a>
    </div>

    <?php if (empty($staleRows)): ?>
        <div class="stale-empty">No stale matched tracks were found.</div>
    <?php else: ?>
        <div id="staleList" class="stale-list">
            <?php foreach ($staleRows as $row): ?>
                <div class="stale-card" data-override-key="<?php echo e((string)$row['override_key']); ?>">
                    <h2 class="stale-title"><?php echo e((string)$row['display_title']); ?></h2>
                    <p class="stale-artist"><?php echo e((string)$row['display_artist']); ?></p>
                    <div class="stale-meta">
                        <span>Previously matched BPM ID: <?php echo (int)($row['bpm_track_id'] ?? 0); ?></span>
                        <?php if (!empty($row['matched_bpm'])): ?><span><?php echo e((string)$row['matched_bpm']); ?> BPM</span><?php endif; ?>
                        <?php if (!empty($row['matched_key'])): ?><span><?php echo e((string)$row['matched_key']); ?></span><?php endif; ?>
                        <?php if (!empty($row['matched_year'])): ?><span><?php echo (int)$row['matched_year']; ?></span><?php endif; ?>
                        <?php if (!empty($row['updated_at'])): ?><span>Updated: <?php echo e((string)$row['updated_at']); ?></span><?php endif; ?>
                    </div>
                    <p class="stale-warning">Current linked file is missing from the latest library import.</p>
                    <div class="stale-actions">
                        <button
                            type="button"
                            class="stale-btn js-resolve-stale"
                            data-override-key="<?php echo e((string)$row['override_key']); ?>"
                            data-title="<?php echo e((string)$row['display_title']); ?>"
                            data-artist="<?php echo e((string)$row['display_artist']); ?>"
                        >Resolve</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="staleModalBackdrop" class="stale-modal-backdrop" aria-hidden="true">
    <div class="stale-modal" role="dialog" aria-modal="true" aria-labelledby="staleModalTitle">
        <div class="stale-modal-head">
            <h3 id="staleModalTitle" class="stale-modal-title">Metadata Match</h3>
            <button id="staleModalClose" type="button" class="stale-modal-close" aria-label="Close">×</button>
        </div>
        <div class="stale-modal-body">
            <div class="stale-search-row">
                <input id="staleSearchInput" class="stale-search-input" type="text" placeholder="Search your DJ library...">
                <button id="staleSearchBtn" class="stale-search-btn" type="button">Search</button>
            </div>
            <div id="staleStatus" class="stale-status"></div>
            <div id="staleCandidates" class="stale-candidates"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const list = document.getElementById('staleList');
    const countEl = document.getElementById('staleCountValue');
    const modal = document.getElementById('staleModalBackdrop');
    const closeBtn = document.getElementById('staleModalClose');
    const searchInput = document.getElementById('staleSearchInput');
    const searchBtn = document.getElementById('staleSearchBtn');
    const statusEl = document.getElementById('staleStatus');
    const candidatesEl = document.getElementById('staleCandidates');
    let active = null;

    function setStatus(text, isError) {
        statusEl.textContent = text || '';
        statusEl.classList.toggle('is-error', !!isError);
    }

    function escapeHtml(v) {
        return String(v || '').replace(/[&<>"']/g, function (m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'": '&#39;'})[m];
        });
    }

    function openModal(payload) {
        active = payload;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        searchInput.value = payload.artist + ' - ' + payload.title;
        candidatesEl.innerHTML = '';
        setStatus('Loading candidates from your current DJ library...', false);
        loadCandidates();
    }

    function closeModal() {
        active = null;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        candidatesEl.innerHTML = '';
        setStatus('', false);
    }

    async function loadCandidates() {
        if (!active) return;
        const params = new URLSearchParams({
            override_key: active.overrideKey,
            q: searchInput.value || ''
        });
        try {
            const resp = await fetch('/api/dj/search_stale_match_candidates.php?' + params.toString(), { cache: 'no-store' });
            const text = await resp.text();
            const payload = JSON.parse(text || '{}');
            if (!resp.ok || !payload || payload.error) {
                throw new Error((payload && payload.error) ? payload.error : 'Failed to load candidates.');
            }
            const rows = Array.isArray(payload.rows) ? payload.rows : [];
            setStatus(rows.length ? (rows.length + ' current library candidates found.') : 'No current library candidates found.', false);
            candidatesEl.innerHTML = rows.map(function (row) {
                const badges = [];
                if (Number(row.is_preferred || 0) === 1) badges.push('<span class="stale-badge pref">Preferred</span>');
                if (row.playlist_badge) badges.push('<span class="stale-badge folder">' + escapeHtml(row.playlist_badge) + '</span>');
                if (row.rating_label) badges.push('<span class="stale-badge rating">' + escapeHtml(row.rating_label) + '</span>');
                if (Number(row.local_only || 0) === 1) badges.push('<span class="stale-badge local">Owned (Local)</span>');
                const canApply = Number(row.can_apply ?? 1) === 1;
                const isSelected = Number(row.is_selected || 0) === 1;
                return '' +
                    '<div class="manual-match-item ' + (isSelected ? 'manual-match-item-selected' : '') + '">' +
                        '<div class="manual-match-item-main">' +
                        '<div class="manual-match-title">' + escapeHtml(row.title) + '</div>' +
                        '<div class="manual-match-artist">' + escapeHtml(row.artist) + '</div>' +
                        '<div class="manual-match-badges">' +
                            badges.join('') +
                        '</div>' +
                        '<div class="manual-match-meta">' + escapeHtml([row.bpm_text || '', row.key_text || '', row.year_text || ''].filter(Boolean).join(' • ')) + '</div>' +
                        (String(row.genre || '').trim() !== '' ? '<div class="manual-match-meta manual-match-genre">Genre: ' + escapeHtml(String(row.genre || '').trim()) + '</div>' : '') +
                        '<div class="manual-match-meta manual-match-owned">✓ In your library' + (Number(row.local_only || 0) === 1 ? ' (local version only)' : '') + '</div>' +
                        '<div class="manual-match-score">Score: ' + escapeHtml(row.match_score || 0) + '</div>' +
                        '</div>' +
                        '<div class="stale-candidate-actions">' +
                            '<button type="button" class="reply-btn primary manual-match-apply-btn js-stale-apply" data-bpm-track-id="' + Number(row.id || 0) + '" data-dj-track-id="' + Number(row.dj_track_id || 0) + '" data-local-only="' + (Number(row.local_only || 0) === 1 ? '1' : '0') + '"' + (canApply ? '' : ' disabled aria-disabled="true"') + '>Apply</button>' +
                        '</div>' +
                    '</div>';
            }).join('');
        } catch (err) {
            setStatus(err && err.message ? err.message : 'Failed to load candidates.', true);
            candidatesEl.innerHTML = '';
        }
    }

    async function applyCandidate(bpmTrackId, djTrackId, localOnly) {
        if (!active || (!bpmTrackId && !djTrackId)) return;
        try {
            const form = new FormData();
            form.append('override_key', active.overrideKey);
            form.append('bpm_track_id', String(bpmTrackId));
            form.append('dj_track_id', String(djTrackId || 0));
            form.append('local_only', localOnly ? '1' : '0');
            const resp = await fetch('/api/dj/apply_stale_match.php', { method: 'POST', body: form });
            const text = await resp.text();
            const payload = JSON.parse(text || '{}');
            if (!resp.ok || !payload || payload.error) {
                throw new Error((payload && payload.error) ? payload.error : 'Failed to apply stale match.');
            }
            const card = list ? list.querySelector('[data-override-key="' + CSS.escape(active.overrideKey) + '"]') : null;
            if (card) card.remove();
            if (countEl) {
                const next = Math.max(0, Number(countEl.textContent || '0') - 1);
                countEl.textContent = String(next);
            }
            if (list && !list.children.length) {
                list.outerHTML = '<div class="stale-empty">No stale matched tracks were found.</div>';
            }
            closeModal();
        } catch (err) {
            setStatus(err && err.message ? err.message : 'Failed to apply stale match.', true);
        }
    }

    document.addEventListener('click', function (event) {
        const resolveBtn = event.target.closest('.js-resolve-stale');
        if (resolveBtn) {
            openModal({
                overrideKey: String(resolveBtn.getAttribute('data-override-key') || ''),
                title: String(resolveBtn.getAttribute('data-title') || ''),
                artist: String(resolveBtn.getAttribute('data-artist') || '')
            });
            return;
        }
        const applyBtn = event.target.closest('.js-stale-apply');
        if (applyBtn) {
            applyCandidate(
                Number(applyBtn.getAttribute('data-bpm-track-id') || 0),
                Number(applyBtn.getAttribute('data-dj-track-id') || 0),
                String(applyBtn.getAttribute('data-local-only') || '') === '1'
            );
        }
    });

    searchBtn.addEventListener('click', loadCandidates);
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadCandidates();
        }
    });
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
})();
</script>
