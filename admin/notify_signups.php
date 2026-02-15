<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Notify Signups';
$pageBodyClass = 'admin-page';

$db = db();

// Mark this report as seen for current admin (used for dashboard "new" counter).
try {
    $seenKey = 'admin_seen_notify_signups_' . (int)($_SESSION['dj_id'] ?? 0);
    $seenStmt = $db->prepare("
        INSERT INTO app_settings (`key`, `value`)
        VALUES (?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE `value` = UTC_TIMESTAMP()
    ");
    $seenStmt->execute([$seenKey]);
} catch (Throwable $e) {
    // Non-blocking.
}

// Fetch signups
$stmt = $db->query("
    SELECT
        id,
        email,
        source,
        ip_address,
        created_at
    FROM notify_signups
    ORDER BY created_at DESC
");

$signups = $stmt->fetchAll(PDO::FETCH_ASSOC);

include APP_ROOT . '/dj/layout.php';



function country_flag(?string $countryCode): string
{
    if (!$countryCode || strlen($countryCode) !== 2) {
        return '🌐';
    }

    $countryCode = strtoupper($countryCode);

    return mb_convert_encoding(
        '&#' . (127397 + ord($countryCode[0])) . ';' .
        '&#' . (127397 + ord($countryCode[1])) . ';',
        'UTF-8',
        'HTML-ENTITIES'
    );
}


function ip_country(string $ip): ?string
{
    $url = "http://ip-api.com/json/{$ip}?fields=countryCode";

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 1
        ]
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);

    return $data['countryCode'] ?? null;
}

?>

<style>
    
 .admin-badge {
    background: rgba(255,47,210,0.18);
    color: #ff2fd2;
    border: 1px solid rgba(255,47,210,0.45);
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
}

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
    
    
    <h1>Notify Interest / Signups</h1>

    <div class="admin-report">

        <!-- Filters -->
        <div style="display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
            <input
                type="text"
                id="searchInput"
                placeholder="Search email…"
                style="padding:8px; min-width:260px;"
            >

            <select id="sourceFilter" style="padding:8px;">
                <option value="">All sources</option>
                <?php
                $sources = array_unique(array_column($signups, 'source'));
                foreach ($sources as $src):
                ?>
                    <option value="<?php echo e($src); ?>">
                        <?php echo e($src); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Source</th>
                    <th>Country</th>
                    <th>IP Address</th>
                    <th>Signed Up</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($signups as $s): ?>
                    <?php
                        $dt = new DateTime($s['created_at'], new DateTimeZone('UTC'));
                        $dt->setTimezone(new DateTimeZone('Australia/Melbourne'));
                    ?>
                    <tr
                        data-email="<?php echo strtolower($s['email']); ?>"
                        data-source="<?php echo strtolower($s['source']); ?>"
                    >
                        <td><?php echo (int)$s['id']; ?></td>
                        <td><strong><?php echo e($s['email']); ?></strong></td>
                        <td>
                            <span class="admin-badge">
                                <?php echo e($s['source']); ?>
                            </span>
                        </td>
                        
                        <?php
$countryCode = ip_country($s['ip_address']);
$flag = country_flag($countryCode);
?>

<td title="<?php echo e($countryCode ?? 'Unknown'); ?>">
    <span style="font-size:18px;"><?php echo $flag; ?></span>
    <?php if ($countryCode): ?>
        <small style="color:#888;"><?php echo e($countryCode); ?></small>
    <?php endif; ?>
</td>
                        
                        
                        <td><?php echo e($s['ip_address']); ?></td>
                        <td><?php echo e($dt->format('j M Y · H:i')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:10px; color:#777;">
            Total signups: <?php echo count($signups); ?>
        </p>

    </div>
</div>



<script>
(() => {
    const search = document.getElementById('searchInput');
    const source = document.getElementById('sourceFilter');
    const rows = document.querySelectorAll('.admin-table tbody tr');

    function applyFilters() {
        const q = search.value.toLowerCase();
        const src = source.value.toLowerCase();

        rows.forEach(row => {
            const email = row.dataset.email || '';
            const rowSource = row.dataset.source || '';

            const matchEmail = !q || email.includes(q);
            const matchSource = !src || rowSource === src;

            row.style.display = (matchEmail && matchSource) ? '' : 'none';
        });
    }

    search.addEventListener('input', applyFilters);
    source.addEventListener('change', applyFilters);
})();
</script>
