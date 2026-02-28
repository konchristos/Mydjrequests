<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$pageTitle = 'Schema Audit';
$pageBodyClass = 'admin-page';

$db = db();

function audit_all_php_files(string $root): array
{
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($rii as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $path = (string)$f->getPathname();
        if (substr($path, -4) !== '.php') {
            continue;
        }
        // Skip obvious third-party/vendor paths.
        if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        $files[] = $path;
    }
    return $files;
}

function audit_regex_contains_word(string $haystackLower, string $word): bool
{
    $word = strtolower($word);
    return (bool)preg_match('/\b' . preg_quote($word, '/') . '\b/', $haystackLower);
}

$tablesStmt = $db->prepare("
    SELECT TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME
");
$tablesStmt->execute();
$dbTables = array_map('strval', $tablesStmt->fetchAll(PDO::FETCH_COLUMN));

$columnsStmt = $db->prepare("
    SELECT TABLE_NAME, COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME, ORDINAL_POSITION
");
$columnsStmt->execute();
$dbColumnsRaw = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$columnsByTable = [];
foreach ($dbColumnsRaw as $row) {
    $t = (string)$row['TABLE_NAME'];
    $c = (string)$row['COLUMN_NAME'];
    if (!isset($columnsByTable[$t])) {
        $columnsByTable[$t] = [];
    }
    $columnsByTable[$t][] = $c;
}

$allFiles = audit_all_php_files(APP_ROOT);
$fileText = [];
$allCode = '';
foreach ($allFiles as $path) {
    $text = @file_get_contents($path);
    if (!is_string($text)) {
        continue;
    }
    $lower = strtolower($text);
    $fileText[$path] = $lower;
    $allCode .= "\n" . $lower;
}

$referencedTables = [];
$unusedTables = [];
$tableToFiles = [];
foreach ($dbTables as $table) {
    if (audit_regex_contains_word($allCode, $table)) {
        $referencedTables[] = $table;
        $tableToFiles[$table] = [];
        foreach ($fileText as $path => $txt) {
            if (audit_regex_contains_word($txt, $table)) {
                $tableToFiles[$table][] = $path;
            }
        }
    } else {
        $unusedTables[] = $table;
    }
}

$ignoreCommonColumns = [
    'id',
    'created_at',
    'updated_at',
];

$unusedColumnsByTable = [];
$usedColumnsByTable = [];
foreach ($columnsByTable as $table => $cols) {
    $haystack = '';
    if (!empty($tableToFiles[$table])) {
        foreach ($tableToFiles[$table] as $path) {
            $haystack .= "\n" . ($fileText[$path] ?? '');
        }
    } else {
        // If the table itself is not referenced, mark all non-common columns as likely unused.
        foreach ($cols as $c) {
            if (!in_array(strtolower($c), $ignoreCommonColumns, true)) {
                $unusedColumnsByTable[$table][] = $c;
            } else {
                $usedColumnsByTable[$table][] = $c;
            }
        }
        continue;
    }

    foreach ($cols as $c) {
        if (in_array(strtolower($c), $ignoreCommonColumns, true)) {
            $usedColumnsByTable[$table][] = $c;
            continue;
        }
        if (audit_regex_contains_word($haystack, $c)) {
            $usedColumnsByTable[$table][] = $c;
        } else {
            $unusedColumnsByTable[$table][] = $c;
        }
    }
}

include APP_ROOT . '/dj/layout.php';
?>
<style>
.audit-card { background:#111116; border:1px solid #1f1f29; border-radius:12px; padding:20px; margin-bottom:16px; }
.audit-meta { color:#b8b8c8; font-size:14px; }
.audit-table { width:100%; border-collapse:collapse; margin-top:10px; }
.audit-table th, .audit-table td { text-align:left; border-bottom:1px solid #232337; padding:8px 6px; vertical-align:top; }
.audit-pill { display:inline-block; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:700; }
.audit-pill.warn { background:#3b1818; color:#ffb3b3; border:1px solid #7f2626; }
.audit-pill.ok { background:#173f1f; color:#7be87f; border:1px solid #256a33; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; }
</style>

<div class="admin-wrap">
    <p style="margin:0 0 8px;"><a href="/admin/dashboard.php" style="color:#ff2fd2; text-decoration:none;">← Back</a></p>
    <h1>DB Schema Audit</h1>
    <p class="audit-meta">
        Heuristic static scan of PHP code vs live DB schema in <code>INFORMATION_SCHEMA</code>.
        Use as a review guide before dropping anything.
    </p>

    <div class="audit-card">
        <h3 style="margin-top:0;">Summary</h3>
        <div class="audit-meta">DB tables: <strong><?php echo (int)count($dbTables); ?></strong></div>
        <div class="audit-meta">Referenced tables in code: <strong><?php echo (int)count($referencedTables); ?></strong></div>
        <div class="audit-meta">Likely unused tables: <strong><?php echo (int)count($unusedTables); ?></strong></div>
    </div>

    <div class="audit-card">
        <h3 style="margin-top:0;">Likely Unused Tables</h3>
        <?php if (empty($unusedTables)): ?>
            <span class="audit-pill ok">No likely unused tables</span>
        <?php else: ?>
            <span class="audit-pill warn">Review before drop</span>
            <table class="audit-table">
                <thead>
                <tr><th>Table</th></tr>
                </thead>
                <tbody>
                <?php foreach ($unusedTables as $t): ?>
                    <tr><td class="mono"><?php echo e($t); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="audit-card">
        <h3 style="margin-top:0;">Likely Unused Columns</h3>
        <span class="audit-pill warn">Heuristic (column-name matching)</span>
        <table class="audit-table">
            <thead>
            <tr>
                <th>Table</th>
                <th>Unused Columns</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($unusedColumnsByTable as $table => $cols): ?>
                <?php if (empty($cols)) continue; ?>
                <tr>
                    <td class="mono"><?php echo e($table); ?></td>
                    <td class="mono"><?php echo e(implode(', ', $cols)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
