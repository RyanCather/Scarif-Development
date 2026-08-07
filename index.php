<?php
$host = getenv('DB_HOST') ?: '10.0.0.100';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: 'iot_telemetry';
$user = getenv('DB_USER') ?: 'iot_user';
$pass = getenv('DB_PASSWORD') ?: 'iot_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Capture filter & pagination settings
$selectedDevice = isset($_GET['device_id']) ? trim($_GET['device_id']) : 'ALL';
$itemsPerPage   = 10;

$sensorPage = isset($_GET['sensor_page']) && is_numeric($_GET['sensor_page']) ? (int)$_GET['sensor_page'] : 1;
if ($sensorPage < 1) $sensorPage = 1;
$sensorOffset = ($sensorPage - 1) * $itemsPerPage;

$eventPage = isset($_GET['event_page']) && is_numeric($_GET['event_page']) ? (int)$_GET['event_page'] : 1;
if ($eventPage < 1) $eventPage = 1;
$eventOffset = ($eventPage - 1) * $itemsPerPage;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 1. Fetch available unique device IDs
    $devicesStmt = $pdo->query("
        SELECT DISTINCT device_id FROM (
            SELECT device_id FROM sensor_readings
            UNION
            SELECT device_id FROM event_logs
        ) AS combined_devices ORDER BY device_id ASC
    ");
    $availableDevices = $devicesStmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Query Sensor Readings + Total Count
    if ($selectedDevice !== 'ALL' && !empty($selectedDevice)) {
        $sensorCountStmt = $pdo->prepare("SELECT COUNT(*) FROM sensor_readings WHERE device_id = :dev");
        $sensorCountStmt->execute([':dev' => $selectedDevice]);
        $totalSensors = (int)$sensorCountStmt->fetchColumn();

        $sensorStmt = $pdo->prepare("SELECT * FROM sensor_readings WHERE device_id = :dev ORDER BY recorded_at DESC LIMIT :limit OFFSET :offset");
        $sensorStmt->bindValue(':dev', $selectedDevice);
        $sensorStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
        $sensorStmt->bindValue(':offset', $sensorOffset, PDO::PARAM_INT);
        $sensorStmt->execute();
    } else {
        $sensorCountStmt = $pdo->query("SELECT COUNT(*) FROM sensor_readings");
        $totalSensors = (int)$sensorCountStmt->fetchColumn();

        $sensorStmt = $pdo->prepare("SELECT * FROM sensor_readings ORDER BY recorded_at DESC LIMIT :limit OFFSET :offset");
        $sensorStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
        $sensorStmt->bindValue(':offset', $sensorOffset, PDO::PARAM_INT);
        $sensorStmt->execute();
    }
    $readings = $sensorStmt->fetchAll();
    $totalSensorPages = ceil($totalSensors / $itemsPerPage) ?: 1;

    // 3. Query Event Logs + Total Count
    if ($selectedDevice !== 'ALL' && !empty($selectedDevice)) {
        $eventCountStmt = $pdo->prepare("SELECT COUNT(*) FROM event_logs WHERE device_id = :dev");
        $eventCountStmt->execute([':dev' => $selectedDevice]);
        $totalEvents = (int)$eventCountStmt->fetchColumn();

        $eventStmt = $pdo->prepare("SELECT * FROM event_logs WHERE device_id = :dev ORDER BY logged_at DESC LIMIT :limit OFFSET :offset");
        $eventStmt->bindValue(':dev', $selectedDevice);
        $eventStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
        $eventStmt->bindValue(':offset', $eventOffset, PDO::PARAM_INT);
        $eventStmt->execute();
    } else {
        $eventCountStmt = $pdo->query("SELECT COUNT(*) FROM event_logs");
        $totalEvents = (int)$eventCountStmt->fetchColumn();

        $eventStmt = $pdo->prepare("SELECT * FROM event_logs ORDER BY logged_at DESC LIMIT :limit OFFSET :offset");
        $eventStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
        $eventStmt->bindValue(':offset', $eventOffset, PDO::PARAM_INT);
        $eventStmt->execute();
    }
    $logs = $eventStmt->fetchAll();
    $totalEventPages = ceil($totalEvents / $itemsPerPage) ?: 1;

} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Helper function to build URL with preserved query string parameters
function buildUrl($overrides = []) {
    $params = $_GET;
    foreach ($overrides as $key => $val) {
        $params[$key] = $val;
    }
    return 'index.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IoT Central System Telemetry</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 2rem; background: #f4f4f9; color: #333; }
        .header-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        h1, h2 { color: #333; margin: 0 0 0.5rem 0; }
        .nav-btn { display: inline-block; background: #8b0000; color: white; padding: 10px 16px; border-radius: 6px; text-decoration: none; font-weight: bold; }
        .nav-btn:hover { background: #a00000; }
        
        /* Filter Card */
        .filter-card { background: #fff; padding: 1rem 1.5rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 2rem; display: flex; align-items: center; gap: 1rem; }
        .filter-card label { font-weight: bold; color: #555; }
        .filter-card select { padding: 8px 12px; border-radius: 4px; border: 1px solid #ccc; font-size: 1rem; }
        .reset-link { color: #0056b3; text-decoration: none; font-size: 0.9rem; }
        .reset-link:hover { text-decoration: underline; }

        /* Tables & Layout */
        table { border-collapse: collapse; width: 100%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; overflow: hidden; }
        th, td { padding: 12px 15px; border: 1px solid #e0e0e0; text-align: left; }
        th { background: #0056b3; color: white; font-weight: 600; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        code { background: #eef2f7; padding: 3px 6px; border-radius: 4px; font-family: monospace; font-size: 0.95em; color: #0056b3; }
        .empty-row { text-align: center; color: #666; font-style: italic; padding: 20px; }

        /* Pagination Control Bar */
        .table-section { margin-bottom: 3rem; }
        .pagination { display: flex; gap: 6px; align-items: center; justify-content: flex-end; margin-top: 0.75rem; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #333; border-radius: 4px; font-size: 0.9rem; }
        .pagination a:hover { background: #eee; }
        .pagination .active { background: #0056b3; color: white; border-color: #0056b3; font-weight: bold; }
        .pagination .disabled { color: #aaa; pointer-events: none; background: #f0f0f0; }
        .page-meta { font-size: 0.85rem; color: #666; margin-right: auto; }
    </style>
</head>
<body>

    <div class="header-container">
        <div>
            <h1>Central IoT System Telemetry</h1>
            <p style="color: #666; margin: 0.25rem 0 0 0;">Live feed of student ESP32 telemetry & events</p>
        </div>
        <a href="errors.php" class="nav-btn">View System Error Logs &rarr;</a>
    </div>

    <!-- Filter Control -->
    <div class="filter-card">
        <label for="deviceFilter">Filter by Device:</label>
        <form method="GET" action="index.php" id="filterForm">
            <select name="device_id" id="deviceFilter" onchange="document.getElementById('filterForm').submit();">
                <option value="ALL" <?= $selectedDevice === 'ALL' ? 'selected' : '' ?>>-- All Devices --</option>
                <?php foreach ($availableDevices as $dev): ?>
                    <option value="<?= htmlspecialchars($dev) ?>" <?= $selectedDevice === $dev ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dev) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($selectedDevice !== 'ALL'): ?>
            <a href="index.php" class="reset-link">&times; Clear Filter</a>
        <?php endif; ?>
    </div>

    <!-- 1. Sensor Readings Section -->
    <div class="table-section">
        <h2>Recent Sensor Readings <?= $selectedDevice !== 'ALL' ? "for <code>" . htmlspecialchars($selectedDevice) . "</code>" : "" ?></h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">ID</th>
                    <th style="width: 30%;">Device ID</th>
                    <th style="width: 35%;">Sensor Value</th>
                    <th style="width: 25%;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($readings)): ?>
                <tr>
                    <td colspan="4" class="empty-row">No sensor readings found.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($readings as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td>
                            <a href="<?= buildUrl(['device_id' => $row['device_id'], 'sensor_page' => 1]) ?>" style="text-decoration: none;">
                                <code><?= htmlspecialchars($row['device_id']) ?></code>
                            </a>
                        </td>
                        <td><strong><?= htmlspecialchars($row['sensor_value']) ?></strong></td>
                        <td><?= htmlspecialchars($row['recorded_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Sensor Pagination -->
        <?php if ($totalSensorPages > 1): ?>
        <div class="pagination">
            <span class="page-meta">Showing <?= min($sensorOffset + 1, $totalSensors) ?>–<?= min($sensorOffset + $itemsPerPage, $totalSensors) ?> of <?= $totalSensors ?></span>
            
            <?php if ($sensorPage > 1): ?>
                <a href="<?= buildUrl(['sensor_page' => $sensorPage - 1]) ?>">&laquo; Prev</a>
            <?php else: ?>
                <span class="disabled">&laquo; Prev</span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalSensorPages; $i++): ?>
                <?php if ($i == $sensorPage): ?>
                    <span class="active"><?= $i ?></span>
                <?php elseif ($i == 1 || $i == $totalSensorPages || ($i >= $sensorPage - 2 && $i <= $sensorPage + 2)): ?>
                    <a href="<?= buildUrl(['sensor_page' => $i]) ?>"><?= $i ?></a>
                <?php elseif ($i == 2 || $i == $totalSensorPages - 1): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($sensorPage < $totalSensorPages): ?>
                <a href="<?= buildUrl(['sensor_page' => $sensorPage + 1]) ?>">Next &raquo;</a>
            <?php else: ?>
                <span class="disabled">Next &raquo;</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- 2. Event Logs Section -->
    <div class="table-section">
        <h2>Recent Event Logs <?= $selectedDevice !== 'ALL' ? "for <code>" . htmlspecialchars($selectedDevice) . "</code>" : "" ?></h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">ID</th>
                    <th style="width: 30%;">Device ID</th>
                    <th style="width: 35%;">Event Message</th>
                    <th style="width: 25%;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="4" class="empty-row">No event logs found.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['id']) ?></td>
                        <td>
                            <a href="<?= buildUrl(['device_id' => $log['device_id'], 'event_page' => 1]) ?>" style="text-decoration: none;">
                                <code><?= htmlspecialchars($log['device_id']) ?></code>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($log['event_message']) ?></td>
                        <td><?= htmlspecialchars($log['logged_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Event Pagination -->
        <?php if ($totalEventPages > 1): ?>
        <div class="pagination">
            <span class="page-meta">Showing <?= min($eventOffset + 1, $totalEvents) ?>–<?= min($eventOffset + $itemsPerPage, $totalEvents) ?> of <?= $totalEvents ?></span>

            <?php if ($eventPage > 1): ?>
                <a href="<?= buildUrl(['event_page' => $eventPage - 1]) ?>">&laquo; Prev</a>
            <?php else: ?>
                <span class="disabled">&laquo; Prev</span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalEventPages; $i++): ?>
                <?php if ($i == $eventPage): ?>
                    <span class="active"><?= $i ?></span>
                <?php elseif ($i == 1 || $i == $totalEventPages || ($i >= $eventPage - 2 && $i <= $eventPage + 2)): ?>
                    <a href="<?= buildUrl(['event_page' => $i]) ?>"><?= $i ?></a>
                <?php elseif ($i == 2 || $i == $totalEventPages - 1): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($eventPage < $totalEventPages): ?>
                <a href="<?= buildUrl(['event_page' => $eventPage + 1]) ?>">Next &raquo;</a>
            <?php else: ?>
                <span class="disabled">Next &raquo;</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>