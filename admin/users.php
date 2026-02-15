<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$userModel = new User();
$users = $userModel->getAllUsers(); // adjust if needed (order by created_at desc)

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


?>



<?php include APP_ROOT . '/dj/layout.php'; ?>

<?php $me = current_user(); ?>


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
    
</style>




<div class="admin-wrap">

    <!-- TOP BAR -->
    <div class="admin-top-bar">
        <a href="/admin/dashboard.php" class="admin-back-btn">
            ← Back to Dashboard
        </a>
    </div>

    <h1>All Users</h1>


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
            <th data-sort="date">Trial Ends</th>
            <th data-sort="string">Status</th>
            <th data-sort="date">Registered</th>
            <th data-sort="lastactive">Last Active</th>
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
    $dt = new DateTime($u['session_updated_at'], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Australia/Melbourne'));
    echo e($dt->format('Y-m-d H:i:s'));
?>">
    <?php echo e(admin_time_ago($u['session_updated_at'])); ?>
</td>


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