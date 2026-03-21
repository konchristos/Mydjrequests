<?php
declare(strict_types=1);

if (!function_exists('mdjr_rekordbox_secret_int')) {
    function mdjr_rekordbox_secret_int(string $key, int $default): int
    {
        $raw = function_exists('mdjr_secret') ? mdjr_secret($key, (string)$default) : (string)$default;
        $value = (int)$raw;
        return $value > 0 ? $value : $default;
    }
}

if (!function_exists('mdjr_rekordbox_secret_float')) {
    function mdjr_rekordbox_secret_float(string $key, float $default): float
    {
        $raw = function_exists('mdjr_secret') ? mdjr_secret($key, (string)$default) : (string)$default;
        $value = (float)$raw;
        return $value > 0 ? $value : $default;
    }
}

if (!function_exists('mdjr_rekordbox_upload_root')) {
    function mdjr_rekordbox_upload_root(): string
    {
        $configured = '';
        if (function_exists('mdjr_secret')) {
            $configured = trim((string)mdjr_secret('DJ_LIBRARY_UPLOAD_DIR', ''));
        }

        $base = $configured !== ''
            ? $configured
            : (dirname(APP_ROOT) . '/storage/dj_libraries');

        mdjr_rekordbox_ensure_directory($base);
        return rtrim($base, '/');
    }
}

if (!function_exists('mdjr_rekordbox_log_path')) {
    function mdjr_rekordbox_log_path(string $name): string
    {
        return mdjr_rekordbox_upload_root() . '/' . ltrim($name, '/');
    }
}

if (!function_exists('mdjr_rekordbox_log_event')) {
    function mdjr_rekordbox_log_event(string $category, string $message, array $context = []): void
    {
        $payload = [
            'ts' => gmdate('c'),
            'category' => $category,
            'message' => $message,
            'context' => $context,
        ];
        @file_put_contents(
            mdjr_rekordbox_log_path('security.log'),
            json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }
}

if (!function_exists('mdjr_rekordbox_throw')) {
    function mdjr_rekordbox_throw(string $message, int $code, array $context = []): void
    {
        mdjr_rekordbox_log_event('upload_rejected', $message, $context + ['code' => $code]);
        throw new RuntimeException($message, $code);
    }
}

if (!function_exists('mdjr_rekordbox_chunk_root_dir')) {
    function mdjr_rekordbox_chunk_root_dir(): string
    {
        $dir = mdjr_rekordbox_upload_root() . '/chunks';
        mdjr_rekordbox_ensure_directory($dir);
        return $dir;
    }
}

if (!function_exists('mdjr_rekordbox_chunk_session_dir')) {
    function mdjr_rekordbox_chunk_session_dir(int $djId, string $uploadId): string
    {
        return mdjr_rekordbox_chunk_root_dir() . '/' . $djId . '_' . $uploadId;
    }
}

if (!function_exists('mdjr_rekordbox_chunk_part_path')) {
    function mdjr_rekordbox_chunk_part_path(int $djId, string $uploadId, int $chunkIndex): string
    {
        return mdjr_rekordbox_chunk_session_dir($djId, $uploadId) . '/part_' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . '.bin';
    }
}

if (!function_exists('mdjr_rekordbox_load_chunk_meta')) {
    function mdjr_rekordbox_load_chunk_meta(int $djId, string $uploadId): ?array
    {
        $metaPath = mdjr_rekordbox_chunk_session_dir($djId, $uploadId) . '/meta.json';
        if (!is_file($metaPath)) {
            return null;
        }

        $json = file_get_contents($metaPath);
        if ($json === false || $json === '') {
            return null;
        }

        $meta = json_decode($json, true);
        if (!is_array($meta)) {
            return null;
        }

        if ((int)($meta['dj_id'] ?? 0) !== $djId) {
            return null;
        }

        return $meta;
    }
}

if (!function_exists('mdjr_rekordbox_cleanup_chunk_session')) {
    function mdjr_rekordbox_cleanup_chunk_session(int $djId, string $uploadId): void
    {
        $dir = mdjr_rekordbox_chunk_session_dir($djId, $uploadId);
        if (!is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $path) {
            $p = $path->getPathname();
            if ($path->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }

        @rmdir($dir);
    }
}

if (!function_exists('mdjr_rekordbox_ensure_directory')) {
    function mdjr_rekordbox_ensure_directory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path, 500);
        }
    }
}

if (!function_exists('mdjr_rekordbox_sanitise_library_filename')) {
    function mdjr_rekordbox_sanitise_library_filename(string $fileName): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($fileName));
        if ($safe === '' || $safe === '.' || $safe === '..') {
            $safe = 'rekordbox_library.xml';
        }
        $ext = strtolower((string)pathinfo($safe, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xml', 'zip'], true)) {
            $safe .= '.xml';
        }
        return $safe;
    }
}

if (!function_exists('mdjr_rekordbox_is_allowed_library_upload_name')) {
    function mdjr_rekordbox_is_allowed_library_upload_name(string $fileName): bool
    {
        $ext = strtolower((string)pathinfo(trim($fileName), PATHINFO_EXTENSION));
        return in_array($ext, ['xml', 'zip'], true);
    }
}

if (!function_exists('mdjr_rekordbox_build_target_upload_path')) {
    function mdjr_rekordbox_build_target_upload_path(int $djId, string $safeName): string
    {
        return mdjr_rekordbox_upload_root() . '/' . $djId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
    }
}

if (!function_exists('mdjr_rekordbox_max_upload_bytes')) {
    function mdjr_rekordbox_max_upload_bytes(string $ext): int
    {
        $key = strtolower($ext) === 'zip' ? 'REKORDBOX_ZIP_MAX_UPLOAD_BYTES' : 'REKORDBOX_XML_MAX_UPLOAD_BYTES';
        return mdjr_rekordbox_secret_int($key, 500 * 1024 * 1024);
    }
}

if (!function_exists('mdjr_rekordbox_detect_mime')) {
    function mdjr_rekordbox_detect_mime(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)@finfo_file($finfo, $path);
                @finfo_close($finfo);
                if ($mime !== '') {
                    return strtolower(trim($mime));
                }
            }
        }
        return '';
    }
}

if (!function_exists('mdjr_rekordbox_validate_uploaded_blob')) {
    function mdjr_rekordbox_validate_uploaded_blob(string $path, string $originalName, ?int $declaredBytes = null): void
    {
        if (!is_file($path) || !is_readable($path)) {
            mdjr_rekordbox_throw('Uploaded file is missing.', 400, ['original_name' => $originalName]);
        }

        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xml', 'zip'], true)) {
            mdjr_rekordbox_throw('Only .xml and .zip files are allowed.', 400, ['original_name' => $originalName]);
        }

        $size = max(0, (int)@filesize($path));
        if ($size <= 0) {
            mdjr_rekordbox_throw('Uploaded file is empty.', 400, ['original_name' => $originalName]);
        }

        $limit = mdjr_rekordbox_max_upload_bytes($ext);
        $effectiveBytes = max($size, (int)$declaredBytes);
        if ($effectiveBytes > $limit) {
            mdjr_rekordbox_throw('Uploaded file exceeds the allowed size limit.', 413, [
                'original_name' => $originalName,
                'effective_bytes' => $effectiveBytes,
                'limit' => $limit,
            ]);
        }

        $mime = mdjr_rekordbox_detect_mime($path);
        $head = (string)file_get_contents($path, false, null, 0, 4096);

        if ($ext === 'zip') {
            $allowedZipMimes = [
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
                'multipart/x-zip',
                'application/octet-stream',
            ];
            if ($mime !== '' && !in_array($mime, $allowedZipMimes, true)) {
                mdjr_rekordbox_throw('Uploaded ZIP file failed MIME validation.', 415, ['original_name' => $originalName, 'mime' => $mime]);
            }
            $sig = substr($head, 0, 4);
            if (!in_array($sig, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
                mdjr_rekordbox_throw('Uploaded ZIP file failed signature validation.', 415, ['original_name' => $originalName]);
            }
            return;
        }

        $allowedXmlMimes = [
            'application/xml',
            'text/xml',
            'text/plain',
            'application/octet-stream',
        ];
        if ($mime !== '' && !in_array($mime, $allowedXmlMimes, true)) {
            mdjr_rekordbox_throw('Uploaded XML file failed MIME validation.', 415, ['original_name' => $originalName, 'mime' => $mime]);
        }

        $trimmed = ltrim($head, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($trimmed === '' || strpos($trimmed, '<') !== 0) {
            mdjr_rekordbox_throw('Uploaded XML file does not look like XML.', 415, ['original_name' => $originalName]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_validate_xml_content')) {
    function mdjr_rekordbox_validate_xml_content(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            mdjr_rekordbox_throw('XML file is missing.', 400, ['path' => $path]);
        }

        $size = max(0, (int)@filesize($path));
        if ($size <= 0) {
            mdjr_rekordbox_throw('XML file is empty.', 400, ['path' => $path]);
        }

        $head = (string)file_get_contents($path, false, null, 0, 65536);
        $normalized = ltrim($head, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($normalized === '' || stripos($normalized, '<!doctype') !== false || stripos($normalized, '<!entity') !== false) {
            mdjr_rekordbox_throw('XML DTD/ENTITY declarations are not allowed.', 400, ['path' => $path]);
        }
        mdjr_rekordbox_validate_xml_structure($path);
    }
}

if (!function_exists('mdjr_rekordbox_validate_xml_structure')) {
    function mdjr_rekordbox_validate_xml_structure(string $path): void
    {
        $reader = new XMLReader();
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE;

        if (!$reader->open($path, null, $flags)) {
            mdjr_rekordbox_throw('Uploaded XML could not be opened for validation.', 400, ['path' => $path]);
        }

        $rootSeen = false;
        $collectionSeen = false;
        $trackSeen = false;
        $playlistsSeen = false;
        $nodesChecked = 0;

        try {
            while ($reader->read()) {
                $nodesChecked++;
                if ($nodesChecked > 50000) {
                    break;
                }

                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    mdjr_rekordbox_throw('XML DTD declarations are not allowed.', 400, ['path' => $path]);
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if (!$rootSeen) {
                    $rootSeen = true;
                    if ($reader->name !== 'DJ_PLAYLISTS') {
                        mdjr_rekordbox_throw('Uploaded XML does not appear to be a Rekordbox export.', 400, [
                            'path' => $path,
                            'root' => $reader->name,
                        ]);
                    }
                }

                if ($reader->name === 'COLLECTION') {
                    $collectionSeen = true;
                } elseif ($reader->name === 'TRACK') {
                    $trackSeen = true;
                } elseif ($reader->name === 'PLAYLISTS') {
                    $playlistsSeen = true;
                }

                if ($rootSeen && $collectionSeen && $trackSeen && $playlistsSeen) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        if (!$rootSeen || !$collectionSeen || !$trackSeen || !$playlistsSeen) {
            mdjr_rekordbox_throw('Uploaded XML is missing required Rekordbox sections.', 400, [
                'path' => $path,
                'root_seen' => $rootSeen,
                'collection_seen' => $collectionSeen,
                'track_seen' => $trackSeen,
                'playlists_seen' => $playlistsSeen,
            ]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_is_safe_zip_entry_name')) {
    function mdjr_rekordbox_is_safe_zip_entry_name(string $name): bool
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return false;
        }

        $normalized = str_replace('\\', '/', $name);
        if (strpos($normalized, '/') !== false || str_contains($normalized, '..')) {
            return false;
        }

        return (bool)preg_match('/^[A-Za-z0-9 ._()\\-]+$/', basename($normalized));
    }
}

if (!function_exists('mdjr_rekordbox_directory_size')) {
    function mdjr_rekordbox_directory_size(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += max(0, (int)$item->getSize());
            }
        }
        return $size;
    }
}

if (!function_exists('mdjr_rekordbox_dj_storage_bytes')) {
    function mdjr_rekordbox_dj_storage_bytes(int $djId): int
    {
        $root = mdjr_rekordbox_upload_root();
        $bytes = 0;

        foreach (glob($root . '/' . $djId . '_*') ?: [] as $path) {
            if (is_file($path)) {
                $bytes += max(0, (int)@filesize($path));
            }
        }

        $chunkRoot = mdjr_rekordbox_chunk_root_dir();
        foreach (glob($chunkRoot . '/' . $djId . '_*') ?: [] as $path) {
            $bytes += is_dir($path) ? mdjr_rekordbox_directory_size($path) : max(0, (int)@filesize($path));
        }

        return $bytes;
    }
}

if (!function_exists('mdjr_rekordbox_assert_storage_quota')) {
    function mdjr_rekordbox_assert_storage_quota(int $djId, int $incomingBytes): void
    {
        $quota = mdjr_rekordbox_secret_int('REKORDBOX_IMPORT_MAX_STORAGE_BYTES_PER_DJ', 5 * 1024 * 1024 * 1024);
        $incomingBytes = max(0, $incomingBytes);
        $currentBytes = mdjr_rekordbox_dj_storage_bytes($djId);
        if (($currentBytes + $incomingBytes) > $quota) {
            mdjr_rekordbox_throw('Upload exceeds the temporary storage quota for this DJ account.', 429, [
                'dj_id' => $djId,
                'current_bytes' => $currentBytes,
                'incoming_bytes' => $incomingBytes,
                'quota_bytes' => $quota,
            ]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_prepare_uploaded_library_source')) {
    function mdjr_rekordbox_prepare_uploaded_library_source(int $djId, string $uploadedPath, string $originalSafeName): array
    {
        $ext = strtolower((string)pathinfo($originalSafeName, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            mdjr_rekordbox_validate_xml_content($uploadedPath);
            $storedBytes = is_file($uploadedPath) ? max(0, (int)@filesize($uploadedPath)) : 0;
            return [$uploadedPath, $storedBytes];
        }

        if (!class_exists('ZipArchive')) {
            mdjr_rekordbox_throw('ZIP uploads are not supported on this server.', 500, ['uploaded_path' => $uploadedPath]);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploadedPath) !== true) {
            mdjr_rekordbox_throw('Failed to open ZIP archive.', 400, ['uploaded_path' => $uploadedPath]);
        }

        $maxExtractedBytes = mdjr_rekordbox_secret_int('REKORDBOX_XML_MAX_EXTRACTED_BYTES', 2 * 1024 * 1024 * 1024);
        $maxRatio = mdjr_rekordbox_secret_float('REKORDBOX_ZIP_MAX_RATIO', 30.0);
        $entry = null;
        $candidateEntries = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }

                $name = (string)($stat['name'] ?? '');
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $baseName = (string)basename($name);
                $normalizedName = str_replace('\\', '/', $name);

                // Ignore common macOS/Finder metadata entries.
                if (
                    str_starts_with($normalizedName, '__MACOSX/')
                    || $baseName === '.DS_Store'
                    || str_starts_with($baseName, '._')
                ) {
                    continue;
                }

                if (!mdjr_rekordbox_is_safe_zip_entry_name($name)) {
                    mdjr_rekordbox_throw('ZIP entry path is not allowed.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
                }

                $extName = strtolower((string)pathinfo($baseName, PATHINFO_EXTENSION));
                if (in_array($extName, ['zip', 'gz', 'rar', '7z', 'tar'], true)) {
                    mdjr_rekordbox_throw('Nested archives are not allowed inside ZIP uploads.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
                }

                if ($extName !== 'xml') {
                    mdjr_rekordbox_throw('ZIP may only contain one XML file and optional macOS metadata.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
                }

                $candidateEntries[] = $stat;
            }

            if (count($candidateEntries) !== 1) {
                mdjr_rekordbox_throw('ZIP must contain exactly one XML file.', 400, [
                    'uploaded_path' => $uploadedPath,
                    'candidate_xml_files' => count($candidateEntries),
                    'num_files' => $zip->numFiles,
                ]);
            }

            $stat = $candidateEntries[0];
            $name = (string)($stat['name'] ?? '');

            $entrySize = max(0, (int)($stat['size'] ?? 0));
            $compressedSize = max(0, (int)($stat['comp_size'] ?? 0));
            if ($entrySize <= 0) {
                mdjr_rekordbox_throw('ZIP XML entry is empty.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
            }
            if ($entrySize > $maxExtractedBytes) {
                mdjr_rekordbox_throw('Extracted XML exceeds the allowed safety limit.', 413, [
                    'uploaded_path' => $uploadedPath,
                    'entry_name' => $name,
                    'entry_size' => $entrySize,
                    'limit' => $maxExtractedBytes,
                ]);
            }
            if ($compressedSize <= 0) {
                mdjr_rekordbox_throw('ZIP XML entry has invalid compressed size.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
            }
            $ratio = $entrySize / max(1, $compressedSize);
            if ($ratio > $maxRatio) {
                mdjr_rekordbox_throw('ZIP archive exceeded the allowed compression ratio.', 400, [
                    'uploaded_path' => $uploadedPath,
                    'entry_name' => $name,
                    'entry_size' => $entrySize,
                    'compressed_size' => $compressedSize,
                    'ratio' => $ratio,
                    'limit' => $maxRatio,
                ]);
            }

            $entry = $stat;
            $xmlSafeName = mdjr_rekordbox_sanitise_library_filename((string)basename($name));
            if (strtolower((string)pathinfo($xmlSafeName, PATHINFO_EXTENSION)) !== 'xml') {
                $xmlSafeName .= '.xml';
            }
            $targetXmlPath = mdjr_rekordbox_build_target_upload_path($djId, $xmlSafeName);

            $in = $zip->getStream($name);
            if ($in === false) {
                mdjr_rekordbox_throw('Failed to read XML from ZIP archive.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
            }

            $out = fopen($targetXmlPath, 'wb');
            if ($out === false) {
                fclose($in);
                mdjr_rekordbox_throw('Failed to create extracted XML file.', 500, ['target_xml_path' => $targetXmlPath]);
            }

            $copied = 0;
            $bufferSize = 1024 * 1024;
            try {
                while (!feof($in)) {
                    $chunk = fread($in, $bufferSize);
                    if ($chunk === false) {
                        break;
                    }
                    $copied += strlen($chunk);
                    if ($copied > $entrySize || $copied > $maxExtractedBytes) {
                        @unlink($targetXmlPath);
                        mdjr_rekordbox_throw('ZIP stream exceeded the declared extracted size.', 400, [
                            'uploaded_path' => $uploadedPath,
                            'entry_name' => $name,
                            'streamed_bytes' => $copied,
                            'declared_bytes' => $entrySize,
                        ]);
                    }
                    fwrite($out, $chunk);
                }
            } finally {
                fclose($in);
                fclose($out);
            }

            if (!is_int($copied) || $copied <= 0) {
                @unlink($targetXmlPath);
                mdjr_rekordbox_throw('Failed to extract XML from ZIP archive.', 400, ['uploaded_path' => $uploadedPath, 'entry_name' => $name]);
            }

            mdjr_rekordbox_validate_xml_content($targetXmlPath);
        } finally {
            $zip->close();
            if (is_file($uploadedPath)) {
                @unlink($uploadedPath);
            }
        }

        $storedBytes = is_file($targetXmlPath) ? max(0, (int)@filesize($targetXmlPath)) : 0;
        if ($storedBytes <= 0) {
            mdjr_rekordbox_throw('Extracted XML file is missing.', 400, ['target_xml_path' => $targetXmlPath]);
        }

        return [$targetXmlPath, $storedBytes];
    }
}

if (!function_exists('mdjr_rekordbox_file_sha256')) {
    function mdjr_rekordbox_file_sha256(string $path): string
    {
        $hash = is_file($path) ? (string)@hash_file('sha256', $path) : '';
        if ($hash === '' || strlen($hash) !== 64) {
            mdjr_rekordbox_throw('Failed to fingerprint uploaded file.', 500, ['path' => $path]);
        }
        return strtolower($hash);
    }
}

if (!function_exists('mdjr_rekordbox_count_recent_jobs')) {
    function mdjr_rekordbox_count_recent_jobs(PDO $db, int $djId, int $windowMinutes): int
    {
        $windowMinutes = max(1, $windowMinutes);
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM dj_library_import_jobs
            WHERE dj_id = :dj_id
              AND created_at >= (NOW() - INTERVAL {$windowMinutes} MINUTE)
        ");
        $stmt->execute([':dj_id' => $djId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('mdjr_rekordbox_assert_rate_limit')) {
    function mdjr_rekordbox_assert_rate_limit(PDO $db, int $djId): void
    {
        $windowMinutes = mdjr_rekordbox_secret_int('REKORDBOX_IMPORT_RATE_WINDOW_MINUTES', 60);
        $maxAttempts = mdjr_rekordbox_secret_int('REKORDBOX_IMPORT_MAX_ATTEMPTS_PER_WINDOW', 6);
        $count = mdjr_rekordbox_count_recent_jobs($db, $djId, $windowMinutes);
        if ($count >= $maxAttempts) {
            mdjr_rekordbox_throw('Upload rate limit reached. Please wait before starting another import.', 429, [
                'dj_id' => $djId,
                'window_minutes' => $windowMinutes,
                'max_attempts' => $maxAttempts,
                'count' => $count,
            ]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_count_queued_jobs')) {
    function mdjr_rekordbox_count_queued_jobs(PDO $db, int $djId): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM dj_library_import_jobs
            WHERE dj_id = :dj_id
              AND status = 'queued'
        ");
        $stmt->execute([':dj_id' => $djId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('mdjr_rekordbox_assert_queue_capacity')) {
    function mdjr_rekordbox_assert_queue_capacity(PDO $db, int $djId): void
    {
        $maxQueued = mdjr_rekordbox_secret_int('REKORDBOX_IMPORT_MAX_QUEUED_PER_DJ', 1);
        $queued = mdjr_rekordbox_count_queued_jobs($db, $djId);
        if ($queued >= $maxQueued) {
            mdjr_rekordbox_throw('Too many queued imports for this DJ account.', 429, [
                'dj_id' => $djId,
                'queued' => $queued,
                'max_queued' => $maxQueued,
            ]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_has_active_import')) {
    function mdjr_rekordbox_has_active_import(PDO $db, int $djId): bool
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM dj_library_import_jobs
            WHERE dj_id = :dj_id
              AND status IN ('queued', 'processing')
        ");
        $stmt->execute([':dj_id' => $djId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('mdjr_rekordbox_assert_no_active_import')) {
    function mdjr_rekordbox_assert_no_active_import(PDO $db, int $djId): void
    {
        if (mdjr_rekordbox_has_active_import($db, $djId)) {
            mdjr_rekordbox_throw('Another import is already queued or processing for this DJ account.', 409, ['dj_id' => $djId]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_assert_no_duplicate_active_hash')) {
    function mdjr_rekordbox_assert_no_duplicate_active_hash(PDO $db, int $djId, string $sha256): void
    {
        $stmt = $db->prepare("
            SELECT id
            FROM dj_library_import_jobs
            WHERE dj_id = :dj_id
              AND status IN ('queued', 'processing')
              AND source_sha256 = :source_sha256
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':dj_id' => $djId,
            ':source_sha256' => $sha256,
        ]);
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId > 0) {
            mdjr_rekordbox_throw('An identical import is already queued or processing.', 409, [
                'dj_id' => $djId,
                'source_sha256' => $sha256,
                'existing_job_id' => $existingId,
            ]);
        }
    }
}

if (!function_exists('mdjr_rekordbox_count_processing_jobs')) {
    function mdjr_rekordbox_count_processing_jobs(PDO $db): int
    {
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM dj_library_import_jobs
            WHERE status = 'processing'
        ");
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('mdjr_rekordbox_global_processing_limit')) {
    function mdjr_rekordbox_global_processing_limit(): int
    {
        return mdjr_rekordbox_secret_int('REKORDBOX_IMPORT_MAX_CONCURRENT_JOBS', 2);
    }
}

if (!function_exists('mdjr_rekordbox_can_dispatch_worker')) {
    function mdjr_rekordbox_can_dispatch_worker(PDO $db): bool
    {
        return mdjr_rekordbox_count_processing_jobs($db) < mdjr_rekordbox_global_processing_limit();
    }
}

if (!function_exists('mdjr_rekordbox_log_entries')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function mdjr_rekordbox_log_entries(int $limit = 200): array
    {
        $path = mdjr_rekordbox_log_path('security.log');
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $slice = array_slice($lines, -max(1, $limit));
        $rows = [];
        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode((string)$line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }
}

if (!function_exists('mdjr_rekordbox_log_summary')) {
    /**
     * @return array{total:int,by_category:array<string,int>,recent_errors:int}
     */
    function mdjr_rekordbox_log_summary(int $withinHours = 24, int $limit = 500): array
    {
        $entries = mdjr_rekordbox_log_entries($limit);
        $summary = [
            'total' => 0,
            'by_category' => [],
            'recent_errors' => 0,
        ];
        $cutoff = time() - (max(1, $withinHours) * 3600);

        foreach ($entries as $entry) {
            $summary['total']++;
            $category = trim((string)($entry['category'] ?? 'unknown'));
            if ($category === '') {
                $category = 'unknown';
            }
            $summary['by_category'][$category] = (int)($summary['by_category'][$category] ?? 0) + 1;

            $ts = strtotime((string)($entry['ts'] ?? ''));
            if ($ts !== false && $ts >= $cutoff) {
                $summary['recent_errors']++;
            }
        }

        arsort($summary['by_category']);
        return $summary;
    }
}
