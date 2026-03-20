<?php
declare(strict_types=1);

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
        $default = 500 * 1024 * 1024;
        $raw = function_exists('mdjr_secret') ? mdjr_secret($key, (string)$default) : (string)$default;
        $value = (int)$raw;
        return $value > 0 ? $value : $default;
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
            throw new RuntimeException('Uploaded file is missing.', 400);
        }

        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xml', 'zip'], true)) {
            throw new RuntimeException('Only .xml and .zip files are allowed.', 400);
        }

        $size = max(0, (int)@filesize($path));
        if ($size <= 0) {
            throw new RuntimeException('Uploaded file is empty.', 400);
        }

        $limit = mdjr_rekordbox_max_upload_bytes($ext);
        $effectiveBytes = max($size, (int)$declaredBytes);
        if ($effectiveBytes > $limit) {
            throw new RuntimeException('Uploaded file exceeds the allowed size limit.', 413);
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
                throw new RuntimeException('Uploaded ZIP file failed MIME validation.', 415);
            }
            $sig = substr($head, 0, 4);
            if (!in_array($sig, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
                throw new RuntimeException('Uploaded ZIP file failed signature validation.', 415);
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
            throw new RuntimeException('Uploaded XML file failed MIME validation.', 415);
        }

        $trimmed = ltrim($head, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($trimmed === '' || strpos($trimmed, '<') !== 0) {
            throw new RuntimeException('Uploaded XML file does not look like XML.', 415);
        }
    }
}

if (!function_exists('mdjr_rekordbox_validate_xml_content')) {
    function mdjr_rekordbox_validate_xml_content(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('XML file is missing.', 400);
        }

        $size = max(0, (int)@filesize($path));
        if ($size <= 0) {
            throw new RuntimeException('XML file is empty.', 400);
        }

        $head = (string)file_get_contents($path, false, null, 0, 65536);
        $normalized = ltrim($head, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($normalized === '' || stripos($normalized, '<!doctype') !== false || stripos($normalized, '<!entity') !== false) {
            throw new RuntimeException('XML DTD/ENTITY declarations are not allowed.', 400);
        }
        if (stripos($normalized, '<dj_playlists') === false) {
            throw new RuntimeException('Uploaded XML does not appear to be a Rekordbox export.', 400);
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
            throw new RuntimeException('ZIP uploads are not supported on this server.', 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploadedPath) !== true) {
            throw new RuntimeException('Failed to open ZIP archive.', 400);
        }

        $maxExtractedBytes = (int)(function_exists('mdjr_secret') ? mdjr_secret('REKORDBOX_XML_MAX_EXTRACTED_BYTES', (string)(2 * 1024 * 1024 * 1024)) : (2 * 1024 * 1024 * 1024));
        $maxRatio = (float)(function_exists('mdjr_secret') ? mdjr_secret('REKORDBOX_ZIP_MAX_RATIO', '40') : '40');
        $entry = null;

        try {
            if ($zip->numFiles !== 1) {
                throw new RuntimeException('ZIP must contain exactly one XML file.', 400);
            }

            $stat = $zip->statIndex(0);
            if (!is_array($stat)) {
                throw new RuntimeException('Failed to inspect ZIP archive.', 400);
            }

            $name = (string)($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                throw new RuntimeException('ZIP must contain exactly one XML file.', 400);
            }
            if (strpos($name, '/') !== false || strpos($name, '\\') !== false || str_contains($name, '..')) {
                throw new RuntimeException('ZIP entry path is not allowed.', 400);
            }
            if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'xml') {
                throw new RuntimeException('ZIP must contain exactly one XML file.', 400);
            }

            $entrySize = max(0, (int)($stat['size'] ?? 0));
            $compressedSize = max(0, (int)($stat['comp_size'] ?? 0));
            if ($entrySize <= 0) {
                throw new RuntimeException('ZIP XML entry is empty.', 400);
            }
            if ($entrySize > $maxExtractedBytes) {
                throw new RuntimeException('Extracted XML exceeds the allowed safety limit.', 413);
            }
            if ($compressedSize <= 0) {
                throw new RuntimeException('ZIP XML entry has invalid compressed size.', 400);
            }
            $ratio = $entrySize / max(1, $compressedSize);
            if ($ratio > $maxRatio) {
                throw new RuntimeException('ZIP archive exceeded the allowed compression ratio.', 400);
            }

            $entry = $stat;
            $xmlSafeName = mdjr_rekordbox_sanitise_library_filename((string)basename($name));
            if (strtolower((string)pathinfo($xmlSafeName, PATHINFO_EXTENSION)) !== 'xml') {
                $xmlSafeName .= '.xml';
            }
            $targetXmlPath = mdjr_rekordbox_build_target_upload_path($djId, $xmlSafeName);

            $in = $zip->getStream($name);
            if ($in === false) {
                throw new RuntimeException('Failed to read XML from ZIP archive.', 400);
            }

            $out = fopen($targetXmlPath, 'wb');
            if ($out === false) {
                fclose($in);
                throw new RuntimeException('Failed to create extracted XML file.', 500);
            }

            try {
                $copied = stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }

            if (!is_int($copied) || $copied <= 0) {
                @unlink($targetXmlPath);
                throw new RuntimeException('Failed to extract XML from ZIP archive.', 400);
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
            throw new RuntimeException('Extracted XML file is missing.', 400);
        }

        return [$targetXmlPath, $storedBytes];
    }
}

if (!function_exists('mdjr_rekordbox_file_sha256')) {
    function mdjr_rekordbox_file_sha256(string $path): string
    {
        $hash = is_file($path) ? (string)@hash_file('sha256', $path) : '';
        if ($hash === '' || strlen($hash) !== 64) {
            throw new RuntimeException('Failed to fingerprint uploaded file.', 500);
        }
        return strtolower($hash);
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
            throw new RuntimeException('Another import is already queued or processing for this DJ account.', 409);
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
            throw new RuntimeException('An identical import is already queued or processing.', 409);
        }
    }
}
