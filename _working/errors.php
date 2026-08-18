<?php
$host = getenv('DB_HOST') ?: 'scarif-production_mariadb';
$db   = getenv('DB_NAME') ?: 'telemetry_db';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'student_user';
$pass = getenv('DB_PASSWORD') ?: 'Password123!';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Pagination Configuration
$itemsPerPage = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$offset = ($page - 1) * $itemsPerPage;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 1. Get total record count for pagination math
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM error_log");
    $totalRecords = (int)$totalStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $itemsPerPage);
    if ($totalPages < 1) {
        $totalPages = 1;
    }

    // Adjust page upper bound
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $itemsPerPage;
    }

    // 2. Fetch records ordered newest first
    $stmt = $pdo->prepare("SELECT * FROM error_log ORDER BY logged_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $errors = $stmt->fetchAll();

} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <title>MQTT & Database Ingestion Error Log</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; background: #f4f4f9; color: #333; }
        h1 { color: #8b0000; }
        .stats { margin-bottom: 1rem; color: #666; font-size: 0.95rem; }
        table { border-collapse: collapse; width: 100%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #8b0000; color: white; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; font-family: monospace; font-size: 0.9em; word-break: break-all; }
        .pagination { display: flex; gap: 8px; align-items: center; justify-content: center; margin-top: 1rem; }
        .pagination a, .pagination span { padding: 8px 14px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #333; border-radius: 4px; }
        .pagination a:hover { background: #eee; }
        .pagination .active { background: #8b0000; color: white; border-color: #8b0000; font-weight: bold; }
        .pagination .disabled { color: #aaa; pointer-events: none; background: #f0f0f0; }
        .nav-link { margin-bottom: 1rem; display: inline-block; color: #0056b3; text-decoration: none; }
        .nav-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <a href="index.php" class="nav-link">&larr; Back to Telemetry Dashboard</a>
    <h1>System Ingestion Error Logs</h1>
    
    <div class="stats">
        Showing entries <?= $totalRecords > 0 ? $offset + 1 : 0 ?> to <?= min($offset + $itemsPerPage, $totalRecords) ?> of <?= $totalRecords ?> total records (Page <?= $page ?> of <?= $totalPages ?>).
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">ID</th>
                <th style="width: 20%;">Topic</th>
                <th style="width: 25%;">Raw Payload</th>
                <th style="width: 30%;">Error Details</th>
                <th style="width: 20%;">Logged At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($errors)): ?>
            <tr>
                <td colspan="5" style="text-align: center; color: #28a745; font-weight: bold; padding: 20px;">
                    No error records found. System operating normally!
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($errors as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['id']) ?></td>
                    <td><code><?= htmlspecialchars($row['topic']) ?></code></td>
                    <td><code><?= htmlspecialchars($row['raw_payload']) ?></code></td>
                    <td style="color: #b30000;"><?= htmlspecialchars($row['error_message']) ?></td>
                    <td><?= htmlspecialchars($row['logged_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <!-- Previous Button -->
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">&laquo; Prev</a>
        <?php else: ?>
            <span class="disabled">&laquo; Prev</span>
        <?php endif; ?>

        <!-- Page Numbers -->
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php elseif ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php elseif ($i == 2 || $i == $totalPages - 1): ?>
                <span>...</span>
            <?php endif; ?>
        <?php endfor; ?>

        <!-- Next Button -->
        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>">Next &raquo;</a>
        <?php else: ?>
            <span class="disabled">Next &raquo;</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</body>
</html>