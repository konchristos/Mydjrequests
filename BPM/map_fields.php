<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/parse_rekordbox_txt.php';

function canonicalHeader(string $header): string
{
    return normaliseHeader($header);
}

$file = $_GET['file'] ?? '';
$path = __DIR__ . '/uploads/' . basename($file);

if (!$file || !is_file($path)) {
    die('Invalid or missing upload');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($ext !== 'txt') {
    die('Unsupported file type');
}

$data = parseRekordboxTxt($path);

$headers = $data['headers'];
$samples = $data['samples'];
$rows    = $data['rows'];

/**
 * Logical fields we support
 */
$logicalFields = [
    'title'  => 'Title (required)',
    'artist' => 'Artist (required)',
    'bpm'    => 'BPM (required)',
    'year'   => 'Year',
    'time'   => 'Time (track length)',
    'genre'  => 'Genre',
    'key'    => 'Key'
];

/**
 * Decide whether a column is relevant
 */
function isRelevantColumn(string $header, array $samples = []): bool
{
    $h = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', '', $header)));

    if (
        str_contains($h, 'title') ||
        str_contains($h, 'artist') ||
        str_contains($h, 'bpm') ||
        str_contains($h, 'tempo') ||
        str_contains($h, 'key') ||
        str_contains($h, 'genre') ||
        str_contains($h, 'year') ||
        str_contains($h, 'time')
    ) {
        return true;
    }

    // Heuristic fallback: numeric samples (BPM / Year)
    foreach ($samples as $v) {
        if (is_numeric($v)) {
            return true;
        }
    }

    return false;
}

/**
 * Auto-select logical field based on header
 */
function autoMapField(string $header, string $logicalKey): bool
{
    $h = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', '', $header)));

    return match ($logicalKey) {
        'title'  => str_contains($h, 'title'),
        'artist' => str_contains($h, 'artist'),
        'bpm'    => str_contains($h, 'bpm') || str_contains($h, 'tempo'),
        'year'   => str_contains($h, 'year'),
        'time'   => str_contains($h, 'time'),
        'genre'  => str_contains($h, 'genre'),
        'key'    => str_contains($h, 'key'),
        default  => false,
    };
}


function shouldShowColumn(string $header, array $samples = []): bool
{
    $clean = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', '', $header)));

    // Core + optional-but-useful fields
    if (
        str_contains($clean, 'title') ||
        str_contains($clean, 'artist') ||
        str_contains($clean, 'bpm') ||
        str_contains($clean, 'tempo') ||
        str_contains($clean, 'year') ||
        str_contains($clean, 'genre') ||
        str_contains($clean, 'key') ||
        str_contains($clean, 'time')
    ) {
        return true;
    }

    return false;
}


?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>BPM Import – Column Mapping</title>
<style>
body { font-family: sans-serif; }
table { border-collapse: collapse; width: 100%; margin-top: 15px; }
th, td { border: 1px solid #ccc; padding: 6px; font-size: 14px; vertical-align: top; }
th { background: #f5f5f5; }
.sample { color: #555; font-size: 12px; }
.required { color: red; }
select { width: 100%; }
.dimmed { opacity: 0.35; }
</style>
</head>
<body>

<h2>Map Rekordbox Columns</h2>
<p>Select how each column should be used. <strong>Title, Artist, and BPM are required.</strong></p>

<form method="post" action="import.php">

<table>
<thead>
<tr>
    <th>Source Column</th>
    <th>Sample Values</th>
    <th>Map As</th>
</tr>
</thead>
<tbody>

<?php foreach ($headers as $header): ?>
    <?php if (!shouldShowColumn($header, $samples[$header] ?? [])) continue; ?>
<?php
    $relevant = isRelevantColumn($header, $samples[$header] ?? []);
?>
<tr class="<?= $relevant ? '' : 'dimmed' ?>">
    <td>
  <strong><?= htmlspecialchars($header) ?></strong>
  <div class="sample"><?= htmlspecialchars(canonicalHeader($header)) ?></div>
</td>c

    <td class="sample">
        <?= htmlspecialchars(implode(', ', array_slice($samples[$header] ?? [], 0, 3))) ?>
    </td>

    <td>
     <select name="mapping[<?= htmlspecialchars(canonicalHeader($header)) ?>]">
            <option value="">— Ignore —</option>

            <?php foreach ($logicalFields as $key => $label): ?>
                <option
                    value="<?= $key ?>"
                    <?= autoMapField($header, $key) ? 'selected' : '' ?>
                >
                    <?= $label ?>
                </option>
            <?php endforeach; ?>

        </select>
    </td>
</tr>
<?php endforeach; ?>

</tbody>
</table>


<input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">

<br>
<button type="submit">Continue to Import</button>

</form>

</body>
</html>