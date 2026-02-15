<?php
// BPM/parse_rekordbox_txt.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function parseRekordboxTxt(string $filePath, int $maxRows = 20000): array
{
    $logFile = __DIR__ . '/_parse_debug.log';

    $log = function (string $msg) use ($logFile) {
        file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
            FILE_APPEND
        );
    };

    $log('--- parseRekordboxTxt() START ---');
    $log('File path: ' . $filePath);

    if (!is_file($filePath)) {
        $log('ERROR: File not found');
        throw new RuntimeException('TXT file not found');
    }

    // Read whole file
    $raw = file_get_contents($filePath);
    if ($raw === false) {
        $log('ERROR: Unable to read file');
        throw new RuntimeException('Unable to read file');
    }

    // Detect & convert encoding (Rekordbox often exports UTF-16)
    $encoding = mb_detect_encoding($raw, ['UTF-16LE', 'UTF-16BE', 'UTF-8'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $log('Detected encoding: ' . $encoding . ' → converting to UTF-8');
        $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    // Temp stream for CSV parsing
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw);
    rewind($fp);

    // Read first non-empty line for delimiter detection
    $firstLine = '';
    while (($line = fgets($fp)) !== false) {
        if (trim($line) !== '') {
            $firstLine = trim($line);
            break;
        }
    }
    if ($firstLine === '') {
        fclose($fp);
        throw new RuntimeException('Empty file');
    }

    $delims = [
        "\t" => substr_count($firstLine, "\t"),
        ";"  => substr_count($firstLine, ";"),
        ","  => substr_count($firstLine, ","),
    ];
    arsort($delims);
    $delimiter = array_key_first($delims);

    $log('Detected delimiter: ' . json_encode($delimiter));

    // Rewind and parse header row with fgetcsv
    rewind($fp);
    $headers = fgetcsv($fp, 0, $delimiter);
    if (!$headers || count($headers) < 2) {
        $log('ERROR: Failed to parse headers');
        $log('Header raw: ' . $firstLine);
        fclose($fp);
        throw new RuntimeException('Unable to parse header row');
    }

    // Normalise headers (strip null bytes/BOM, trim, canonical)
    $headers = array_map(
        fn($h) => normaliseHeader((string)$h),
        $headers
    );

    $log('Headers: ' . json_encode($headers));

    $rows = [];
    $samples = [];
    $sampleLimit = 5;
    $rowCount = 0;

    while (($cols = fgetcsv($fp, 0, $delimiter)) !== false) {
        if (count($cols) === 1 && trim((string)$cols[0]) === '') {
            continue;
        }

        $row = [];
        foreach ($headers as $i => $h) {
            $v = $cols[$i] ?? null;
            $row[$h] = is_string($v) ? trim(str_replace("\0", '', $v)) : $v;
        }

        if ($rowCount < $sampleLimit) {
            foreach ($row as $h => $v) {
                if ($v !== null && $v !== '') {
                    $samples[$h][] = (string)$v;
                }
            }
        }

        if ($rowCount === 0) {
            $log('First data row: ' . json_encode($row));
        }

        $rows[] = $row;
        $rowCount++;

        if ($rowCount >= $maxRows) {
            break;
        }
    }

    fclose($fp);

    $log('Rows parsed: ' . $rowCount);
    $log('--- parseRekordboxTxt() END ---');

    return [
        'headers' => $headers,
        'samples' => $samples,
        'rows'    => $rows,
        'delimiter' => $delimiter,
        'encoding'  => $encoding ?: 'unknown',
    ];
}