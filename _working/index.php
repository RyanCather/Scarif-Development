@ -1,417 +0,0 @@
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

$flashMessage = '';
$flashType = 'success';

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // ------------------------------------------------------------------
    // Handle Form Submission: Update or Create Device State (0 or 1)
    // ------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_state') {
        $inputDeviceId = isset($_POST['target_device_id']) ? trim($_POST['target_device_id']) : '';
        $inputState    = isset($_POST['state_value']) ? trim($_POST['state_value']) : '';

        if (empty($inputDeviceId)) {
            $flashMessage = 'Device ID cannot be empty.';
            $flashType = 'error';
        } elseif ($inputState !== '0' && $inputState !== '1') {
            $flashMessage = 'Invalid state value. Must be 0 or 1.';
            $flashType = 'error';
        } else {
            $updateStmt = $pdo->prepare("
                INSERT INTO devices (device_id, state_value)
                VALUES (:dev, :state)
                ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)
            ");
            $updateStmt->execute([
                ':dev'   => $inputDeviceId,
                ':state' => (int)$inputState
            ]);
            $flashMessage = "Successfully updated state for <code>" . htmlspecialchars($inputDeviceId) . "</code> to <strong>" . $inputState . "</strong>.";
            $flashType = 'success';
        }
    }

    // 1. Fetch current devices & states for control panel & dropdown filter
    $deviceStatesStmt = $pdo->query("SELECT device_id, state_value, updated_at FROM devices ORDER BY device_id ASC");
    $deviceStates = $deviceStatesStmt->fetchAll();

    $devicesStmt = $pdo->query("
        SELECT DISTINCT device_id FROM (
            SELECT device_id FROM sensor_readings
            UNION
            SELECT device_id FROM event_logs
            UNION
            SELECT device_id FROM devices
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
        
        /* Alert Message */
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 1.5rem; font-weight: 500; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Form & Control Card */
        .card { background: #fff; padding: 1.25rem 1.5rem; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .form-row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin-top: 0.75rem; }
        .form-row input[type="text"] { padding: 8px 12px; border-radius: 4px; border: 1px solid #ccc; font-size: 1rem; width: 220px; }
        .form-row select { padding: 8px 12px; border-radius: 4px; border: 1px solid #ccc; font-size: 1rem; }
        .btn-submit { background: #0056b3; color: white; border: none; padding: 9px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: #004085; }

        /* Filter Card */
        .filter-card { display: flex; align-items: center; gap: 1rem; }
        .filter-card label { font-weight: bold; color: #555; }
        .reset-link { color: #0056b3; text-decoration: none; font-size: 0.9rem; }
        .reset-link:hover { text-decoration: underline; }

        /* State Badges */
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 0.85rem; text-align: center; }
        .badge-on { background: #28a745; color: white; }
        .badge-off { background: #6c757d; color: white; }

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

    <?php if (!empty($flashMessage)): ?>
        <div class="alert alert-<?= $flashType ?>">
            <?= $flashMessage ?>
        </div>
    <?php endif; ?>

    <!-- Device State Controller Form -->
    <div class="card">
        <h2>Device State Controller</h2>
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="update_state">
            <div class="form-row">
                <div>
                    <label for="target_device_id" style="font-weight: bold; display: block; margin-bottom: 4px;">Device ID:</label>
                    <input type="text" name="target_device_id" id="target_device_id" placeholder="e.g. ESP32-01" required list="device-list">
                    <datalist id="device-list">
                        <?php foreach ($availableDevices as $dev): ?>
                            <option value="<?= htmlspecialchars($dev) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label for="state_value" style="font-weight: bold; display: block; margin-bottom: 4px;">State Value:</label>
                    <select name="state_value" id="state_value">
                        <option value="1">1 (ON / Active)</option>
                        <option value="0">0 (OFF / Inactive)</option>
                    </select>
                </div>
                <div style="align-self: flex-end;">
                    <button type="submit" class="btn-submit">Update State</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Registered Device States Table -->
    <div class="table-section">
        <h2>Active Device States</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 40%;">Device ID</th>
                    <th style="width: 30%;">Current State</th>
                    <th style="width: 30%;">Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deviceStates)): ?>
                <tr>
                    <td colspan="3" class="empty-row">No device states registered yet. Use the controller above to create one.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($deviceStates as $st): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($st['device_id']) ?></code></td>
                        <td>
                            <?php if ($st['state_value'] == 1): ?>
                                <span class="badge badge-on">1 (ON)</span>
                            <?php else: ?>
                                <span class="badge badge-off">0 (OFF)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($st['updated_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Filter Control -->
    <div class="card filter-card">
        <label for="deviceFilter">Filter Telemetry by Device:</label>
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