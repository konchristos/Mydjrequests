<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$db = db();
$me = current_user();
$enableUserDelete = false; // Toggle to true when you need temporary delete access.

$pageTitle = 'All Users';
$pageBodyClass = 'admin-page';   // ðŸ‘ˆ EXPLICITLY opt into admin layout

function admin_time_ago(string $utcDatetime, string $tz = 'Australia/Melbourne'): string
{
    try {
        $now = new DateTime('now', new DateTimeZone($tz));
        $then = new DateTime($utcDatetime, new DateTimeZone('UTC'));
        $then->setTimezone(new DateTimeZone($tz));

        $diff = $now->getTimestamp() - $then->getTimestamp();

        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
        return floor($diff / 86400) . ' days ago';
    } catch (Exception $e) {
        return 'â€”';
    }
}

function admin_table_exists(PDO $db, string $tableName): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);
    return ((int)$stmt->fetchColumn() > 0);
}

function admin_column_exists(PDO $db, string $tableName, string $columnName): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return ((int)$stmt->fetchColumn() > 0);
}

function admin_software_label(string $software, ?string $other): string
{
    $software = strtolower(trim($software));
    $labels = [
        'rekordbox' => 'Rekordbox',
        'serato' => 'Serato',
        'traktor' => 'Traktor',
        'virtualdj' => 'VirtualDJ',
        'djay' => 'djay / djay Pro',
        'other' => 'Other',
    ];
    if ($software === '') {
        return '-';
    }
    if ($software === 'other') {
        $other = trim((string)$other);
        return $other !== '' ? $other : 'Other';
    }
    return $labels[$software] ?? ucfirst($software);
}

function admin_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function admin_delete_rows_for_user_column(PDO $db, string $columnName, int $userId, array $excludeTables = []): void
{
    $stmt = $db->prepare("
        SELECT DISTINCT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$columnName]);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($tables as $tableName) {
        if ($tableName === 'users' || in_array($tableName, $excludeTables, true)) {
            continue;
        }
        $sql = 'DELETE FROM ' . admin_ident((string)$tableName) . ' WHERE ' . admin_ident($columnName) . ' = ?';
        $del = $db->prepare($sql);
        $del->execute([$userId]);
    }
}

function admin_delete_rows_for_event_column(PDO $db, string $columnName, array $eventValues, array $excludeTables = []): void
{
    if (empty($eventValues)) {
        return;
    }
    $safeValues = [];
    foreach ($eventValues as $value) {
        if (is_int($value)) {
            $safeValues[] = (string)$value;
        } else {
            $safeValues[] = "'" . str_replace("'", "''", (string)$value) . "'";
        }
    }
    if (empty($safeValues)) {
        return;
    }
    $inList = implode(',', $safeValues);

    $stmt = $db->prepare("
        SELECT DISTINCT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$columnName]);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($tables as $tableName) {
        if (in_array($tableName, $excludeTables, true)) {
            continue;
        }
        $sql = 'DELETE FROM ' . admin_ident((string)$tableName)
            . ' WHERE ' . admin_ident($columnName) . ' IN (' . $inList . ')';
        $db->exec($sql);
    }
}

function admin_delete_user_with_related_data(PDO $db, int $userId): void
{
    $evtStmt = $db->prepare("SELECT id, uuid FROM events WHERE user_id = ?");
    $evtStmt->execute([$userId]);
    $events = $evtStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $eventIds = [];
    $eventUuids = [];
    foreach ($events as $event) {
        $eid = (int)($event['id'] ?? 0);
        if ($eid > 0) {
            $eventIds[] = $eid;
        }
        $uuid = trim((string)($event['uuid'] ?? ''));
        if ($uuid !== '') {
            $eventUuids[] = $uuid;
        }
    }

    admin_delete_rows_for_event_column($db, 'event_uuid', $eventUuids, []);
    admin_delete_rows_for_event_column($db, 'event_id', $eventIds, ['events']);

    admin_delete_rows_for_user_column($db, 'dj_id', $userId, []);
    admin_delete_rows_for_user_column($db, 'user_id', $userId, []);
    admin_delete_rows_for_user_column($db, 'recipient_user_id', $userId, []);

    $db->prepare("DELETE FROM users WHERE id = ? LIMIT 1")->execute([$userId]);
}

$flashMsg = '';
$flashOk = false;

if (
    $enableUserDelete
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'delete_user'
) {
    if (!verify_csrf_token()) {
        $flashMsg = 'Security check failed. Please refresh and try again.';
    } else {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        if ($targetUserId <= 0) {
            $flashMsg = 'Invalid user selection.';
        } elseif ($targetUserId === (int)($me['id'] ?? 0)) {
            $flashMsg = 'You cannot delete your own admin account.';
        } else {
            $targetStmt = $db->prepare("SELECT id, email, is_admin FROM users WHERE id = ? LIMIT 1");
            $targetStmt->execute([$targetUserId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$target) {
                $flashMsg = 'User not found (they may already be deleted).';
            } elseif (!empty($target['is_admin'])) {
                $flashMsg = 'Admin users are protected and cannot be deleted here.';
            } else {
                try {
                    $db->beginTransaction();
                    admin_delete_user_with_related_data($db, $targetUserId);
                    $db->commit();
                    $flashOk = true;
                    $flashMsg = 'User deleted: ' . (string)$target['email'];
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $flashMsg = 'Delete failed: ' . $e->getMessage();
                }
            }
        }
    }
}

$userModel = new User();
$users = $userModel->getAllUsers(); // adjust if needed (order by created_at desc)

$currencyByUserId = [];
if (admin_table_exists($db, 'user_settings') && admin_column_exists($db, 'user_settings', 'tip_boost_currency')) {
    $stmt = $db->query("SELECT user_id, tip_boost_currency FROM user_settings");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid > 0) {
            $cur = strtoupper(trim((string)($row['tip_boost_currency'] ?? '')));
            $currencyByUserId[$uid] = in_array($cur, ['AUD', 'USD', 'NZD'], true) ? $cur : '-';
        }
    }
}

$softwareByUserId = [];
if (
    admin_table_exists($db, 'user_onboarding_profiles')
    && admin_column_exists($db, 'user_onboarding_profiles', 'dj_software')
) {
    $hasOther = admin_column_exists($db, 'user_onboarding_profiles', 'dj_software_other');
    $sql = $hasOther
        ? "SELECT user_id, dj_software, dj_software_other FROM user_onboarding_profiles"
        : "SELECT user_id, dj_software, NULL AS dj_software_other FROM user_onboarding_profiles";
    $stmt = $db->query($sql);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid > 0) {
            $softwareByUserId[$uid] = admin_software_label(
                (string)($row['dj_software'] ?? ''),
                $row['dj_software_other'] ?? null
            );
        }
    }
}


?>



<?php include APP_ROOT . '/dj/layout.php'; ?>


<style>
    
  .admin-top-bar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 14px;
}

.admin-back-btn {
    background: #292933;
    border: 1px solid #3a3a46;
    padding: 8px 14px;
    border-radius: 8px;
    color: #cfcfd8;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.admin-back-btn:hover {
    background: #323241;
    border-color: #4a4a5a;
}  

<?php if ($enableUserDelete): ?>
.admin-delete-btn {
    background:#40161e;
    border:1px solid #8f2b3d;
    color:#ffb8c3;
    padding:6px 10px;
    border-radius:8px;
    font-size:12px;
    font-weight:600;
    cursor:pointer;
}
.admin-delete-btn:hover {
    background:#531b26;
}
.admin-protected-pill {
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    border:1px solid #3f3f4e;
    color:#9ea0af;
    font-size:11px;
}
.admin-alert {
    margin: 10px 0 14px;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 13px;
    border: 1px solid #2c2c39;
    background:#12121a;
    color:#d8d9e2;
}
.admin-alert.ok {
    border-color:#1f6c38;
    color:#9be8b4;
}
.admin-alert.err {
    border-color:#8f2b3d;
    color:#ffb8c3;
}
<?php endif; ?>
    
</style>




<div class="admin-wrap">

    <!-- TOP BAR -->
    <div class="admin-top-bar">
        <a href="/admin/dashboard.php" class="admin-back-btn">
            ← Back to Dashboard
        </a>
    </div>

    <h1>All Users</h1>
    <?php if ($enableUserDelete && $flashMsg !== ''): ?>
        <div class="admin-alert <?php echo $flashOk ? 'ok' : 'err'; ?>">
            <?php echo e($flashMsg); ?>
        </div>
    <?php endif; ?>


<div class="admin-report">
    <div class="admin-filters" style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
        <input
            type="text"
            id="userSearch"
            placeholder="Search email, city, country"
            style="padding: 8px; min-width: 260px;"
        >

        <select id="statusFilter" style="padding: 8px;">
            <option value="">All statuses</option>
            <option value="trial">Trial</option>
            <option value="active">Active</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <table class="admin-table">
        <thead>
        <tr>
            <th data-sort="number">ID</th>
            <th data-sort="string">UUID</th>
            <th data-sort="string">Email</th>
            <th data-sort="string">Country</th>
            <th data-sort="string">City</th>
            <th data-sort="string">Currency</th>
            <th data-sort="string">DJ Software</th>
            <th data-sort="date">Trial Ends</th>
            <th data-sort="string">Status</th>
            <th data-sort="date">Registered</th>
            <th data-sort="lastactive">Last Active</th>
            <?php if ($enableUserDelete): ?><th>Actions</th><?php endif; ?>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($users as $u): ?>

            <?php
            // Trial highlight logic
            $trialClass = '';

            if (!empty($u['trial_ends_at']) && ($u['subscription_status'] ?? '') === 'trial') {
                $daysLeft = floor((strtotime($u['trial_ends_at']) - time()) / 86400);

                if ($daysLeft < 0) {
                    $trialClass = 'admin-trial-expired';
                } elseif ($daysLeft <= 7) {
                    $trialClass = 'admin-trial-soon';
                }
            }

            $rowClasses = trim(
                $trialClass . ' ' . (($u['id'] === $me['id']) ? 'admin-me' : '')
            );
            ?>

            <tr
              class="<?php echo $rowClasses; ?>"
              data-email="<?php echo strtolower($u['email']); ?>"
              data-country="<?php echo strtolower($u['country_code'] ?? ''); ?>"
              data-city="<?php echo strtolower($u['city'] ?? ''); ?>"
              data-status="<?php echo strtolower($u['subscription_status'] ?? ''); ?>"
              data-lastactive="<?php echo !empty($u['session_updated_at']) ? strtotime($u['session_updated_at']) : 0; ?>"
            >
                <td><?php echo (int)$u['id']; ?></td>
                
<td
    style="font-family:monospace; font-size:12px; cursor:pointer;"
    title="Click to copy"
    onclick="navigator.clipboard.writeText('<?php echo e($u['uuid']); ?>')"
>
    <?php echo e($u['uuid']); ?>
</td>
                
                <td><?php echo e($u['email']); ?></td>
                
                <td><?php echo e($u['country_code'] ?? '-'); ?></td>
                <td><?php echo e($u['city'] ?? '-'); ?></td>
                <td><?php echo e($currencyByUserId[(int)$u['id']] ?? '-'); ?></td>
                <td><?php echo e($softwareByUserId[(int)$u['id']] ?? '-'); ?></td>

                <td>
                    <?php if (!empty($u['trial_ends_at'])): ?>
                        <?php
                        echo e(date('Y-m-d', strtotime($u['trial_ends_at'])));
                        if (($u['subscription_status'] ?? '') === 'trial') {
                            echo $daysLeft >= 0
                                ? " <small>({$daysLeft} days left)</small>"
                                : " <small>(expired)</small>";
                        }
                        ?>
<?php else: ?>
    -
<?php endif; ?>
                </td>

                <td class="admin-status-<?php echo e($u['subscription_status'] ?? 'none'); ?>">
                    <?php echo e(ucfirst($u['subscription_status'] ?? 'none')); ?>
                </td>

                <td><?php echo e($u['created_at']); ?></td>

                
<td title="<?php
    if (!empty($u['session_updated_at'])) {
        $dt = new DateTime($u['session_updated_at'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Australia/Melbourne'));
        echo e($dt->format('Y-m-d H:i:s'));
    }
?>">
    <?php echo !empty($u['session_updated_at']) ? e(admin_time_ago($u['session_updated_at'])) : '-'; ?>
</td>

                <?php if ($enableUserDelete): ?>
                    <td>
                        <?php if ((int)$u['id'] === (int)($me['id'] ?? 0)): ?>
                            <span class="admin-protected-pill">Protected</span>
                        <?php else: ?>
                            <form method="post" class="delete-user-form" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="target_user_id" value="<?php echo (int)$u['id']; ?>">
                                <button
                                    type="submit"
                                    class="admin-delete-btn"
                                    data-user-id="<?php echo (int)$u['id']; ?>"
                                    data-user-email="<?php echo e($u['email']); ?>"
                                >
                                    Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>

            </tr>

        <?php endforeach; ?>
        </tbody>
    </table>



<script>
(() => {
    const searchInput = document.getElementById('userSearch');
    const statusFilter = document.getElementById('statusFilter');
    const rows = document.querySelectorAll('.admin-table tbody tr');

    function applyFilters() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;

        rows.forEach(row => {
            const email = row.dataset.email || '';
            const country = row.dataset.country || '';
            const city = row.dataset.city || '';
            const rowStatus = row.dataset.status || '';

            const matchesSearch =
                !search ||
                email.includes(search) ||
                country.includes(search) ||
                city.includes(search);

            const matchesStatus =
                !status || rowStatus === status;

            row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
        });
    }

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
})();
</script>

<?php if ($enableUserDelete): ?>
<script>
(() => {
    const forms = document.querySelectorAll('.delete-user-form');
    forms.forEach(form => {
        form.addEventListener('submit', (event) => {
            const btn = form.querySelector('.admin-delete-btn');
            const uid = btn ? btn.dataset.userId : '';
            const email = btn ? (btn.dataset.userEmail || '') : '';
            const label = email !== '' ? `${email} (ID ${uid})` : `ID ${uid}`;
            const ok = window.confirm(
                `Delete user ${label} and their related data?\n\nThis action cannot be undone.`
            );
            if (!ok) {
                event.preventDefault();
            }
        });
    });
})();
</script>
<?php endif; ?>

<script>
(function () {
    const table = document.querySelector('.admin-table');
    if (!table) {
        console.warn('admin-table not found');
        return;
    }

    const headers = table.querySelectorAll('th[data-sort]');
    const tbody = table.querySelector('tbody');

    let current = { index: null, dir: 'asc' };

    headers.forEach((th, index) => {
        th.style.cursor = 'pointer';
        const label = th.innerText.trim();
        th.dataset.label = label;

        th.addEventListener('click', () => {
            const type = th.dataset.sort;
            const dir =
                current.index === index && current.dir === 'asc'
                    ? 'desc'
                    : 'asc';

            current = { index, dir };

            // reset headers
            headers.forEach(h => {
                h.innerText = h.dataset.label;
            });

            // set arrow
            th.innerText = `${label} ${dir === 'asc' ? 'â–²' : 'â–¼'}`;

            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                let aText = a.children[index]?.innerText.trim() ?? '';
                let bText = b.children[index]?.innerText.trim() ?? '';

                if (type === 'number') {
                    return dir === 'asc'
                        ? (parseFloat(aText) || 0) - (parseFloat(bText) || 0)
                        : (parseFloat(bText) || 0) - (parseFloat(aText) || 0);
                }

if (type === 'date') {
    return dir === 'asc'
        ? Date.parse(aText) - Date.parse(bText)
        : Date.parse(bText) - Date.parse(aText);
}

if (type === 'lastactive') {
    const aVal = parseInt(a.dataset.lastactive || '0', 10);
    const bVal = parseInt(b.dataset.lastactive || '0', 10);
    return dir === 'asc' ? aVal - bVal : bVal - aVal;
}

                return dir === 'asc'
                    ? aText.localeCompare(bText)
                    : bText.localeCompare(aText);
            });

            rows.forEach(row => tbody.appendChild(row));
        });
    });
})();
</script>


</div>
</div>
