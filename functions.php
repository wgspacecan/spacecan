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

    // === Rate limiting for login attempts ===
    define('RATE_LIMIT_FILE', __DIR__ . '/logs/rate_limits.json');
    define('MAX_LOGIN_ATTEMPTS', 5);
    define('LOCKOUT_DURATION', 900); // 15 minutes

    function getRateLimitData(): array {
        if (!file_exists(RATE_LIMIT_FILE)) return [];
        $data = json_decode(file_get_contents(RATE_LIMIT_FILE), true);
        return is_array($data) ? $data : [];
    }

    function saveRateLimitData(array $data): void {
        file_put_contents(RATE_LIMIT_FILE, json_encode($data), LOCK_EX);
    }

    function isLoginLocked(string $ip): bool {
        $data = getRateLimitData();
        if (!isset($data[$ip])) return false;

        $entry = $data[$ip];
        // Check if lockout has expired
        if (isset($entry['locked_until']) && time() < $entry['locked_until']) {
            return true;
        }
        // Check if too many recent attempts
        if (isset($entry['attempts']) && count($entry['attempts']) >= MAX_LOGIN_ATTEMPTS) {
            // Filter attempts within the lockout window
            $recent = array_filter($entry['attempts'], fn($t) => time() - $t < LOCKOUT_DURATION);
            if (count($recent) >= MAX_LOGIN_ATTEMPTS) {
                return true;
            }
        }
        return false;
    }

    function recordFailedLogin(string $ip): void {
        $data = getRateLimitData();
        if (!isset($data[$ip])) {
            $data[$ip] = ['attempts' => []];
        }

        $data[$ip]['attempts'][] = time();
        // Keep only recent attempts
        $data[$ip]['attempts'] = array_filter(
            $data[$ip]['attempts'],
            fn($t) => time() - $t < LOCKOUT_DURATION
        );

        // Set lockout if threshold reached
        if (count($data[$ip]['attempts']) >= MAX_LOGIN_ATTEMPTS) {
            $data[$ip]['locked_until'] = time() + LOCKOUT_DURATION;
        }

        saveRateLimitData($data);
    }

    function clearLoginAttempts(string $ip): void {
        $data = getRateLimitData();
        unset($data[$ip]);
        saveRateLimitData($data);
    }

    /**
     * Log an event to the access log file.
     *
     * LOGGING STANDARD:
     * - action: Event category (lowercase). Use: error, alert, auth, info, view, download
     * - type: Module/context (lowercase). Examples: admin, database, download, vault, album
     * - details: Brief description of the event
     * - extra: Optional associative array of additional data (will be JSON encoded)
     *
     * Format: YYYY-MM-DD HH:MM:SS [action] [type] ip:x.x.x.x details {"extra":"data"}
     *
     * @param string $action Event action (error|alert|auth|info|view|download)
     * @param string $type Module or context name
     * @param string $details Human-readable description
     * @param array $extra Additional structured data
     * @param string|null $logFile Override log file path (defaults to LOG_FILE constant)
     */
    function logEvent(string $action, string $type, string $details = '', array $extra = [], ?string $logFile = null): void {
        $action = strtolower($action); // Enforce lowercase
        $type = strtolower($type);

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

        $targetFile = $logFile ?? (defined('LOG_FILE') ? LOG_FILE : __DIR__ . '/logs/access.log');
        file_put_contents($targetFile, $line, FILE_APPEND | LOCK_EX);
    }

?>