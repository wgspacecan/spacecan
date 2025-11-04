<?php
    // === Helper: Sanitize slug/uuid ===
    function sanitize_path($input) {
        return preg_replace('/[^a-z0-9_-]/i', '_', strtolower($input));
    }

    // === Helper: Delete directory and files within ===
    function deleteDirectory($dir) {
        if (!is_dir($dir)) return false;
        if (!str_ends_with($dir, '/')) $dir .= '/';

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . $item;
            is_dir($path) ? deleteDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }

    function logEvent(string $action, string $type, string $details = '', array $extra = []): void {
        $line = sprintf(
            "%s [%s] [%s] ip:%s %s %s",
            date('Y-m-d H:i:s'),
            $action,
            $type,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $details,
            !empty($extra) ? json_encode($extra, JSON_UNESCAPED_SLASHES) : ''
        );
        $line = trim($line) . PHP_EOL;
        file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }

?>