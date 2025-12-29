<?php
require 'config.php';

// Must be logged in as admin
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

// === Parse log file (unified format) ===
function parseLogFile($file, $limit = 2500) {
    if (!file_exists($file)) return [];

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines); // Most recent first
    $entries = [];

    foreach (array_slice($lines, 0, $limit) as $line) {
        // Parse: 2024-12-29 08:30:00 [action] [type] ip:1.2.3.4 details {...}
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] \[(\w+)\] ip:(\S+)\s*(.*)$/', $line, $m)) {
            $details = $m[5];
            $extra = [];

            // Extract JSON if present
            if (preg_match('/^(.*?)\s*(\{.*\})$/', $details, $dm)) {
                $details = trim($dm[1]);
                $extra = json_decode($dm[2], true) ?: [];
            }

            $entries[] = [
                'timestamp' => $m[1],
                'date' => substr($m[1], 0, 10),
                'time' => substr($m[1], 11),
                'action' => strtolower($m[2]),
                'type' => strtolower($m[3]),
                'ip' => $m[4],
                'details' => $details,
                'extra' => $extra,
                'raw' => $line
            ];
        }
    }

    return $entries;
}

// === IP Geolocation with file-based cache ===
function getIpLocation($ip) {
    if ($ip === 'unknown' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    $cacheFile = __DIR__ . '/logs/ip_cache.json';
    $cache = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) ?: [] : [];

    // Check cache (24 hour expiry)
    if (isset($cache[$ip]) && $cache[$ip]['expires'] > time()) {
        return $cache[$ip]['data'];
    }

    // Query ip-api.com (free, 45 requests/min limit)
    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,countryCode,regionName,city,isp";
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['status'] === 'success') {
            $location = [
                'country' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? '',
                'city' => $data['city'] ?? '',
                'isp' => $data['isp'] ?? ''
            ];
            // Cache for 24 hours
            $cache[$ip] = ['data' => $location, 'expires' => time() + 86400];
            file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
            return $location;
        }
    }

    return null;
}

// Load logs
$allLogs = parseLogFile(LOG_FILE, 2500);
usort($allLogs, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

// Apply filters
$filterAction = $_GET['action'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterIP = $_GET['ip'] ?? '';
$filterDate = $_GET['date'] ?? '';
$filterSearch = $_GET['search'] ?? '';

$filteredLogs = array_filter($allLogs, function($entry) use ($filterAction, $filterType, $filterIP, $filterDate, $filterSearch) {
    if ($filterAction && $entry['action'] !== $filterAction) return false;
    if ($filterType && $entry['type'] !== $filterType) return false;
    if ($filterIP && strpos($entry['ip'], $filterIP) === false) return false;
    if ($filterDate && $entry['date'] !== $filterDate) return false;
    if ($filterSearch && stripos($entry['raw'], $filterSearch) === false) return false;
    return true;
});

// Pagination
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalEntries = count($filteredLogs);
$totalPages = max(1, ceil($totalEntries / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pagedLogs = array_slice($filteredLogs, $offset, $perPage);

// Statistics
$uniqueIPs = array_unique(array_column($allLogs, 'ip'));
$todayLogs = array_filter($allLogs, fn($e) => $e['date'] === date('Y-m-d'));
$uniqueIPsToday = array_unique(array_column($todayLogs, 'ip'));

$stats = [
    'total' => count($allLogs),
    'today' => count($todayLogs),
    'unique_ips' => count($uniqueIPs),
    'unique_ips_today' => count($uniqueIPsToday),
    'alerts' => count(array_filter($allLogs, fn($e) => $e['action'] === 'alert')),
    'auth' => count(array_filter($allLogs, fn($e) => $e['action'] === 'auth')),
    'downloads' => count(array_filter($allLogs, fn($e) => in_array($e['action'], ['download']) || $e['type'] === 'download')),
    'views' => count(array_filter($allLogs, fn($e) => $e['action'] === 'view')),
    'failed_logins' => count(array_filter($allLogs, fn($e) => $e['action'] === 'auth' && stripos($e['details'], 'failed') !== false)),
    'vault_access' => count(array_filter($allLogs, fn($e) => $e['type'] === 'vault')),
];

// IP activity summary (top IPs by event count)
$ipCounts = array_count_values(array_column($allLogs, 'ip'));
arsort($ipCounts);
$topIPs = array_slice($ipCounts, 0, 10, true);

// Unique values for filters
$uniqueActions = array_unique(array_column($allLogs, 'action'));
$uniqueTypes = array_unique(array_column($allLogs, 'type'));
$uniqueDates = array_unique(array_column($allLogs, 'date'));
sort($uniqueActions);
sort($uniqueTypes);
rsort($uniqueDates);

// Action colors
function getActionColor($action) {
    return match($action) {
        'alert' => '#dc3545',
        'error' => '#dc3545',
        'auth' => '#0066cc',
        'view' => '#6c757d',
        'info' => '#28a745',
        'download' => '#17a2b8',
        'login_success' => '#28a745',
        'login_failed', 'login_blocked' => '#dc3545',
        'stream' => '#6f42c1',
        default => '#6c757d'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="assets/favicon.ico">
    <title>Audit Log - SpaceCan</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .audit-container { max-width: 1400px; margin: 0 auto; padding: 1rem; }

        /* Header */
        .audit-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
        .audit-header h1 { margin: 0; }
        .audit-nav a { margin-left: 1rem; color: #0066cc; text-decoration: none; }
        .audit-nav a:hover { text-decoration: underline; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 1rem; text-align: center; }
        .stat-card.alert { border-left: 4px solid #dc3545; }
        .stat-card.info { border-left: 4px solid #17a2b8; }
        .stat-card.success { border-left: 4px solid #28a745; }
        .stat-card.warning { border-left: 4px solid #ffc107; }
        .stat-value { font-size: 2rem; font-weight: bold; color: #333; }
        .stat-label { font-size: 0.85rem; color: #666; margin-top: 0.25rem; }

        /* Filters */
        .filters { background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; }
        .filters-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.8rem; color: #666; margin-bottom: 0.25rem; }
        .filter-group select, .filter-group input { padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; min-width: 120px; }
        .filter-btn { padding: 0.5rem 1rem; background: #0066cc; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .filter-btn:hover { background: #0055aa; }
        .filter-btn.clear { background: #6c757d; }

        /* Log Table */
        .log-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .log-table th { background: #f8f9fa; padding: 0.75rem; text-align: left; font-weight: 600; border-bottom: 2px solid #ddd; font-size: 0.85rem; }
        .log-table td { padding: 0.6rem 0.75rem; border-bottom: 1px solid #eee; font-size: 0.85rem; vertical-align: top; }
        .log-table tr:hover { background: #f8f9fa; }
        .log-table .time-col { white-space: nowrap; color: #666; width: 140px; }
        .log-table .action-col { width: 100px; }
        .log-table .type-col { width: 80px; }
        .log-table .ip-col { width: 120px; font-family: monospace; font-size: 0.8rem; }
        .log-table .details-col { max-width: 400px; word-break: break-word; }

        /* Action Badge */
        .action-badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem; font-weight: 600; color: #fff; text-transform: uppercase; }

        /* Type Badge */
        .type-badge { display: inline-block; padding: 0.15rem 0.4rem; border-radius: 3px; font-size: 0.7rem; background: #e9ecef; color: #495057; }

        /* Extra Data */
        .extra-data { font-size: 0.75rem; color: #666; margin-top: 0.25rem; font-family: monospace; background: #f8f9fa; padding: 0.25rem 0.5rem; border-radius: 3px; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 0.5rem 0.75rem; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 0.9rem; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination .active { background: #0066cc; color: #fff; border-color: #0066cc; }
        .pagination .disabled { color: #aaa; cursor: not-allowed; }
        .pagination-info { color: #666; font-size: 0.85rem; }

        /* IP Summary */
        .ip-summary { background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; }
        .ip-summary h3 { margin: 0 0 0.75rem 0; font-size: 1rem; color: #333; }
        .ip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
        .ip-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 0.6rem 0.8rem; display: flex; flex-direction: column; gap: 0.25rem; }
        .ip-addr { font-family: monospace; font-size: 0.85rem; color: #0066cc; text-decoration: none; font-weight: 600; }
        .ip-addr:hover { text-decoration: underline; }
        .ip-count { font-size: 0.75rem; color: #666; }
        .ip-loc { font-size: 0.7rem; color: #888; }

        /* IP location tooltip in table */
        .ip-with-loc { position: relative; }
        .ip-loc-badge { font-size: 0.65rem; color: #666; display: block; margin-top: 2px; }

        /* IP Details Panel */
        .ip-details { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; }
        .ip-details h3 { margin: 0 0 0.75rem 0; font-size: 1rem; color: #1565c0; }
        .ip-details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; }
        .ip-detail-item { display: flex; flex-direction: column; gap: 0.2rem; }
        .ip-detail-label { font-size: 0.7rem; color: #666; text-transform: uppercase; font-weight: 600; }
        .ip-detail-value { font-size: 0.85rem; color: #333; }

        /* Responsive */
        @media (max-width: 768px) {
            .log-table { font-size: 0.8rem; }
            .log-table th, .log-table td { padding: 0.5rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .ip-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="audit-container">
    <div class="audit-header">
        <h1>Audit Log</h1>
        <nav class="audit-nav">
            <a href="admin.php">Admin</a>
            <a href="index.php">Home</a>
            <a href="?refresh=1">Refresh</a>
        </nav>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card info">
            <div class="stat-value"><?= number_format($stats['total']) ?></div>
            <div class="stat-label">Total Events</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?= number_format($stats['today']) ?></div>
            <div class="stat-label">Today</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid #6f42c1;">
            <div class="stat-value"><?= number_format($stats['unique_ips']) ?></div>
            <div class="stat-label">Unique IPs</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid #6f42c1;">
            <div class="stat-value"><?= number_format($stats['unique_ips_today']) ?></div>
            <div class="stat-label">IPs Today</div>
        </div>
        <div class="stat-card alert">
            <div class="stat-value"><?= number_format($stats['alerts']) ?></div>
            <div class="stat-label">Alerts</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value"><?= number_format($stats['failed_logins']) ?></div>
            <div class="stat-label">Failed Logins</div>
        </div>
        <div class="stat-card info">
            <div class="stat-value"><?= number_format($stats['downloads']) ?></div>
            <div class="stat-label">Downloads</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['vault_access']) ?></div>
            <div class="stat-label">Vault Access</div>
        </div>
    </div>

    <!-- Top IPs Summary -->
    <?php if (!empty($topIPs)): ?>
    <div class="ip-summary">
        <h3>Top IP Addresses</h3>
        <div class="ip-grid">
            <?php foreach ($topIPs as $ip => $count):
                $loc = getIpLocation($ip);
            ?>
            <div class="ip-card">
                <a href="?ip=<?= urlencode($ip) ?>" class="ip-addr"><?= htmlspecialchars($ip) ?></a>
                <span class="ip-count"><?= number_format($count) ?> events</span>
                <?php if ($loc): ?>
                <span class="ip-loc" title="<?= htmlspecialchars($loc['isp'] ?? '') ?>">
                    <?= htmlspecialchars($loc['city'] ? $loc['city'] . ', ' : '') ?><?= htmlspecialchars($loc['country']) ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- IP Details (when filtering by specific IP) -->
    <?php if ($filterIP):
        $ipLoc = getIpLocation($filterIP);
        $ipEvents = array_filter($allLogs, fn($e) => $e['ip'] === $filterIP);
        $ipFirstSeen = !empty($ipEvents) ? end($ipEvents)['timestamp'] : 'N/A';
        $ipLastSeen = !empty($ipEvents) ? reset($ipEvents)['timestamp'] : 'N/A';
        $ipActions = array_count_values(array_column($ipEvents, 'action'));
    ?>
    <div class="ip-details">
        <h3>IP Details: <?= htmlspecialchars($filterIP) ?></h3>
        <div class="ip-details-grid">
            <div class="ip-detail-item">
                <span class="ip-detail-label">Events</span>
                <span class="ip-detail-value"><?= number_format(count($ipEvents)) ?></span>
            </div>
            <div class="ip-detail-item">
                <span class="ip-detail-label">First Seen</span>
                <span class="ip-detail-value"><?= htmlspecialchars($ipFirstSeen) ?></span>
            </div>
            <div class="ip-detail-item">
                <span class="ip-detail-label">Last Seen</span>
                <span class="ip-detail-value"><?= htmlspecialchars($ipLastSeen) ?></span>
            </div>
            <?php if ($ipLoc): ?>
            <div class="ip-detail-item">
                <span class="ip-detail-label">Location</span>
                <span class="ip-detail-value"><?= htmlspecialchars(($ipLoc['city'] ? $ipLoc['city'] . ', ' : '') . ($ipLoc['region'] ? $ipLoc['region'] . ', ' : '') . $ipLoc['country']) ?></span>
            </div>
            <div class="ip-detail-item">
                <span class="ip-detail-label">ISP</span>
                <span class="ip-detail-value"><?= htmlspecialchars($ipLoc['isp'] ?: 'Unknown') ?></span>
            </div>
            <?php endif; ?>
            <div class="ip-detail-item" style="grid-column: span 2;">
                <span class="ip-detail-label">Activity</span>
                <span class="ip-detail-value">
                    <?php foreach ($ipActions as $action => $cnt): ?>
                    <span class="action-badge" style="background:<?= getActionColor($action) ?>;margin-right:0.3rem;"><?= htmlspecialchars($action) ?> (<?= $cnt ?>)</span>
                    <?php endforeach; ?>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <form class="filters" method="GET">
        <div class="filters-row">
            <div class="filter-group">
                <label>Action</label>
                <select name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($uniqueActions as $action): ?>
                        <option value="<?= htmlspecialchars($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($action)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Type</label>
                <select name="type">
                    <option value="">All Types</option>
                    <?php foreach ($uniqueTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Date</label>
                <select name="date">
                    <option value="">All Dates</option>
                    <?php foreach (array_slice($uniqueDates, 0, 30) as $date): ?>
                        <option value="<?= htmlspecialchars($date) ?>" <?= $filterDate === $date ? 'selected' : '' ?>>
                            <?= htmlspecialchars($date) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>IP Address</label>
                <input type="text" name="ip" value="<?= htmlspecialchars($filterIP) ?>" placeholder="Filter by IP">
            </div>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" placeholder="Search logs...">
            </div>
            <button type="submit" class="filter-btn">Filter</button>
            <a href="audit.php" class="filter-btn clear">Clear</a>
        </div>
    </form>

    <!-- Results Info -->
    <p class="pagination-info">
        Showing <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $perPage, $totalEntries)) ?>
        of <?= number_format($totalEntries) ?> entries
        <?php if ($filterAction || $filterType || $filterIP || $filterDate || $filterSearch): ?>
            (filtered)
        <?php endif; ?>
    </p>

    <!-- Log Table -->
    <table class="log-table">
        <thead>
            <tr>
                <th class="time-col">Timestamp</th>
                <th class="action-col">Action</th>
                <th class="type-col">Type</th>
                <th class="ip-col">IP Address</th>
                <th class="details-col">Details</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pagedLogs)): ?>
                <tr><td colspan="5" style="text-align:center;color:#666;padding:2rem;">No log entries found</td></tr>
            <?php else: ?>
                <?php foreach ($pagedLogs as $entry): ?>
                    <tr>
                        <td class="time-col">
                            <div><?= htmlspecialchars($entry['date']) ?></div>
                            <div style="color:#999;font-size:0.8rem;"><?= htmlspecialchars($entry['time']) ?></div>
                        </td>
                        <td class="action-col">
                            <span class="action-badge" style="background:<?= getActionColor($entry['action']) ?>">
                                <?= htmlspecialchars($entry['action']) ?>
                            </span>
                        </td>
                        <td class="type-col">
                            <span class="type-badge"><?= htmlspecialchars($entry['type']) ?></span>
                        </td>
                        <td class="ip-col">
                            <?php
                            $showLoc = $filterIP && $filterIP === $entry['ip'];
                            $entryLoc = $showLoc ? getIpLocation($entry['ip']) : null;
                            ?>
                            <a href="?ip=<?= urlencode($entry['ip']) ?>" style="color:#333;text-decoration:none;" title="Click to filter by this IP">
                                <?= htmlspecialchars($entry['ip']) ?>
                            </a>
                            <?php if ($entryLoc): ?>
                            <span class="ip-loc-badge"><?= htmlspecialchars($entryLoc['city'] ? $entryLoc['city'] . ', ' : '') ?><?= htmlspecialchars($entryLoc['country']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="details-col">
                            <?= htmlspecialchars($entry['details']) ?>
                            <?php if (!empty($entry['extra'])): ?>
                                <div class="extra-data"><?= htmlspecialchars(json_encode($entry['extra'], JSON_UNESCAPED_SLASHES)) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $queryParams = $_GET;
            unset($queryParams['page']);
            $queryString = http_build_query($queryParams);
            $baseUrl = 'audit.php' . ($queryString ? '?' . $queryString . '&' : '?');
            ?>

            <?php if ($page > 1): ?>
                <a href="<?= $baseUrl ?>page=1">&laquo; First</a>
                <a href="<?= $baseUrl ?>page=<?= $page - 1 ?>">&lsaquo; Prev</a>
            <?php else: ?>
                <span class="disabled">&laquo; First</span>
                <span class="disabled">&lsaquo; Prev</span>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= $baseUrl ?>page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= $baseUrl ?>page=<?= $page + 1 ?>">Next &rsaquo;</a>
                <a href="<?= $baseUrl ?>page=<?= $totalPages ?>">Last &raquo;</a>
            <?php else: ?>
                <span class="disabled">Next &rsaquo;</span>
                <span class="disabled">Last &raquo;</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
