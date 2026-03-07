<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = 'My Bug Reports';

$bugModel = new BugReport();
$bugs = $bugModel->findByUserId((int)$_SESSION['dj_id']);
$totalCount = count($bugs);
$openCount = 0;
$inProgressCount = 0;
$resolvedCount = 0;
foreach ($bugs as $bugRow) {
    $statusKey = (string)($bugRow['status'] ?? '');
    if ($statusKey === 'open') {
        $openCount++;
    } elseif ($statusKey === 'in_progress') {
        $inProgressCount++;
    } elseif ($statusKey === 'resolved' || $statusKey === 'closed') {
        $resolvedCount++;
    }
}

require __DIR__ . '/layout.php';
?>

<style>
.bugs-wrap {
    max-width: 980px;
}

.bugs-back-link {
    color: var(--brand-accent);
    text-decoration: none;
    font-weight: 600;
}

.bugs-header {
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}

.bugs-header h1 {
    margin: 0 0 8px;
}

.bugs-subtitle {
    margin: 0;
    color: #b8b9c9;
}

.btn-primary {
    background: linear-gradient(135deg, var(--brand-accent), var(--brand-accent-strong));
    color: #fff;
    border: none;
    padding: 11px 16px;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    display: inline-block;
    box-shadow: 0 8px 24px rgba(var(--brand-accent-rgb), 0.25);
}

.stats-grid {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.stat-tile {
    appearance: none;
    width: 100%;
    text-align: left;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
    padding: 12px;
    color: inherit;
    cursor: pointer;
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease;
}

.stat-tile:hover {
    border-color: rgba(255,255,255,0.22);
    transform: translateY(-1px);
}

.stat-tile.active {
    border-color: rgba(var(--brand-accent-rgb), 0.55);
    box-shadow: 0 0 0 1px rgba(var(--brand-accent-rgb), 0.3) inset;
    background: linear-gradient(180deg, rgba(var(--brand-accent-rgb), 0.14), rgba(var(--brand-accent-rgb), 0.05));
}

.stat-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9ea0bb;
    margin-bottom: 6px;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    line-height: 1;
}

.bug-list {
    margin-top: 16px;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255,255,255,0.02);
}

.bugs-toolbar {
    margin-top: 14px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.bug-search {
    flex: 1;
    min-width: 260px;
    padding: 11px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.14);
    background: rgba(255,255,255,0.03);
    color: #fff;
}

.bug-search:focus {
    outline: none;
    border-color: rgba(var(--brand-accent-rgb), 0.5);
    box-shadow: 0 0 0 3px rgba(var(--brand-accent-rgb), 0.12);
}

.search-meta {
    color: #9ea0bb;
    font-size: 13px;
}

.bug-table {
    width: 100%;
    border-collapse: collapse;
}

.bug-table thead th {
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9ea0bb;
    padding: 14px 12px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}

.bug-table td {
    padding: 14px 12px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    vertical-align: middle;
}

.bug-table tbody tr:hover {
    background: rgba(255,255,255,0.03);
}

.bug-table tbody tr:last-child td {
    border-bottom: none;
}

.bug-link {
    color: #ff55de;
    text-decoration: none;
    font-weight: 600;
}

.id-chip {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(255,255,255,0.08);
    font-weight: 600;
}

.badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}

.badge-low { background: rgba(76,175,80,0.2); color: #7be87f; }
.badge-medium { background: rgba(255,193,7,0.2); color: #ffd25f; }
.badge-high { background: rgba(244,67,54,0.2); color: #ff8c8c; }

.badge-open { background: rgba(0, 150, 255, 0.2); color: #7cc7ff; }
.badge-in_progress { background: rgba(255, 160, 0, 0.2); color: #ffcf7a; }
.badge-resolved { background: rgba(76, 175, 80, 0.2); color: #7be87f; }
.badge-closed { background: rgba(120,120,120,0.2); color: #bbb; }

.cell-label {
    display: none;
}

.empty {
    color: #aaa;
    margin-top: 12px;
    padding: 18px 16px;
}

@media (max-width: 820px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .bugs-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .bug-search {
        min-width: 0;
    }

    .bug-list {
        background: transparent;
        border: none;
    }

    .bug-table thead {
        display: none;
    }

    .bug-table,
    .bug-table tbody,
    .bug-table tr,
    .bug-table td {
        display: block;
        width: 100%;
    }

    .bug-table tr {
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 12px;
        padding: 10px;
        margin-bottom: 10px;
        background: rgba(255,255,255,0.02);
    }

    .bug-table td {
        border-bottom: none;
        padding: 6px 4px;
    }

    .cell-label {
        display: inline-block;
        color: #8f91ab;
        min-width: 78px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
}
</style>

<div class="bugs-wrap">
<p style="margin:0 0 8px;"><a class="bugs-back-link" href="/dj/dashboard.php">← Back</a></p>

<div class="bugs-header">
    <div>
        <h1>My Bug Reports</h1>
        <p class="bugs-subtitle">Track issues, follow updates, and jump back into any report quickly.</p>
    </div>
    <a class="btn-primary" href="/dj/bug_new.php">Report a Bug</a>
</div>

<div class="stats-grid">
    <button type="button" class="stat-tile active js-status-filter" data-status="all">
        <div class="stat-label">Total</div>
        <div class="stat-value"><?php echo (int)$totalCount; ?></div>
    </button>
    <button type="button" class="stat-tile js-status-filter" data-status="open">
        <div class="stat-label">Open</div>
        <div class="stat-value"><?php echo (int)$openCount; ?></div>
    </button>
    <button type="button" class="stat-tile js-status-filter" data-status="in_progress">
        <div class="stat-label">In Progress</div>
        <div class="stat-value"><?php echo (int)$inProgressCount; ?></div>
    </button>
    <button type="button" class="stat-tile js-status-filter" data-status="resolved_closed">
        <div class="stat-label">Resolved / Closed</div>
        <div class="stat-value"><?php echo (int)$resolvedCount; ?></div>
    </button>
</div>

<div class="bugs-toolbar">
    <input
        id="bugSearchInput"
        class="bug-search"
        type="search"
        placeholder="Search bugs by ID, title, priority, or status..."
        autocomplete="off"
    >
    <div id="bugSearchMeta" class="search-meta"><?php echo (int)$totalCount; ?> results</div>
</div>

<div class="bug-list">
<?php if (empty($bugs)): ?>
    <div class="empty">No bug reports yet.</div>
<?php else: ?>
    <table class="bug-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody id="bugTableBody">
        <?php foreach ($bugs as $b): ?>
            <?php
                $statusRaw = (string)($b['status'] ?? '');
                $priorityRaw = (string)($b['priority'] ?? '');
                $searchBlob = '#' . (int)$b['id'] . ' '
                    . (string)($b['title'] ?? '') . ' '
                    . $priorityRaw . ' '
                    . str_replace('_', ' ', $statusRaw);
            ?>
            <tr
                class="js-bug-row"
                data-status="<?php echo e($statusRaw); ?>"
                data-search="<?php echo e(strtolower($searchBlob)); ?>"
            >
                <td><span class="cell-label">ID</span><span class="id-chip">#<?php echo (int)$b['id']; ?></span></td>
                <td><span class="cell-label">Title</span><a href="/dj/bug_view.php?id=<?php echo (int)$b['id']; ?>" class="bug-link">
                    <?php echo e($b['title']); ?>
                </a></td>
                <td><span class="cell-label">Priority</span><span class="badge badge-<?php echo e($b['priority']); ?>"><?php echo e(ucfirst($b['priority'])); ?></span></td>
                <td><span class="cell-label">Status</span><span class="badge badge-<?php echo e($b['status']); ?>"><?php echo e(str_replace('_',' ', ucfirst($b['status']))); ?></span></td>
                <td><span class="cell-label">Updated</span><span class="js-local-time" data-utc="<?php echo e($b['updated_at']); ?>"></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div id="bugsEmptyFiltered" class="empty" hidden>No matching bug reports.</div>
<?php endif; ?>
</div>
</div>


<script>
function formatLocalDateTime(ts) {
  if (!ts) return "";
  const d = new Date(ts.replace(" ", "T") + "Z");
  const date = d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" });
  const time = d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  return `${date}, ${time}`;
}
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-local-time').forEach(el => {
    const ts = el.dataset.utc || '';
    el.textContent = formatLocalDateTime(ts);
  });

  const rows = Array.from(document.querySelectorAll('.js-bug-row'));
  const filterButtons = Array.from(document.querySelectorAll('.js-status-filter'));
  const searchInput = document.getElementById('bugSearchInput');
  const searchMeta = document.getElementById('bugSearchMeta');
  const emptyFiltered = document.getElementById('bugsEmptyFiltered');
  const bugTableBody = document.getElementById('bugTableBody');

  if (!rows.length || !searchInput || !searchMeta) return;

  let activeStatus = 'all';

  const normalize = (value) => (value || '').toString().trim().toLowerCase();

  const matchesStatus = (rowStatus, selectedStatus) => {
    if (selectedStatus === 'all') return true;
    if (selectedStatus === 'resolved_closed') {
      return rowStatus === 'resolved' || rowStatus === 'closed';
    }
    return rowStatus === selectedStatus;
  };

  const applyFilters = () => {
    const query = normalize(searchInput.value);
    let visibleCount = 0;

    rows.forEach((row) => {
      const rowStatus = normalize(row.dataset.status || '');
      const haystack = normalize(row.dataset.search || '');
      const visible = matchesStatus(rowStatus, activeStatus) && (query === '' || haystack.includes(query));
      row.style.display = visible ? '' : 'none';
      if (visible) visibleCount++;
    });

    const activeLabelBtn = filterButtons.find((btn) => btn.classList.contains('active'));
    const activeLabel = activeLabelBtn ? (activeLabelBtn.querySelector('.stat-label')?.textContent || 'Total') : 'Total';
    searchMeta.textContent = `${visibleCount} result${visibleCount === 1 ? '' : 's'} (${activeLabel})`;

    if (emptyFiltered) {
      emptyFiltered.hidden = visibleCount !== 0;
    }
    if (bugTableBody) {
      bugTableBody.hidden = visibleCount === 0;
    }
  };

  filterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      activeStatus = btn.dataset.status || 'all';
      filterButtons.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      applyFilters();
    });
  });

  searchInput.addEventListener('input', applyFilters);
  applyFilters();
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
