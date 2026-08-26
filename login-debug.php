<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

$host    = getenv('DB_HOST')     ?: 'localhost';
$port    = getenv('DB_PORT')     ?: '3306';
$dbName  = getenv('DB_NAME')     ?: 'telemetry_db';
$user    = getenv('DB_USER')     ?: 'student_user';
$pass    = getenv('DB_PASSWORD') ?: 'Password123!';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=$charset";
$multiStmtAttr = class_exists('\Pdo\Mysql') ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS : PDO::MYSQL_ATTR_MULTI_STATEMENTS;

try {
    $db = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
        $multiStmtAttr => true
    ]);
} catch (\PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login Debugger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="p-5 bg-light">

    <div class="container" style="max-width: 600px;">
        <div class="card shadow p-4 mb-4">
            <h3>Login (Debug Mode)</h3>
            <form action="" method="post">
                <div class="mb-3">
                    <label>Email</label>
                    <input type="text" name="username" class="form-control" required value="admin@school.com" />
                </div>
                <div class="mb-3">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required value="Password123!" />
                </div>
                <button type="submit" name="login" class="btn btn-primary w-100">Submit Login</button>
            </form>
        </div>

        <?php
        if (isset($_POST['login'])) {
            $v_input_user = $_POST['username'];
            $v_input_pass = $_POST['password'];

            $query = "SELECT user_id, username, password_hash, first_name FROM users WHERE username = '$v_input_user'";

            echo "<div class='alert alert-secondary'><strong>1. Raw SQL Executed:</strong><br><code>" . htmlspecialchars($query) . "</code></div>";

            try {
                $stmt = $db->query($query);
                $user = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

                if ($user) {
                    echo "<div class='alert alert-info'><strong>2. User Found in DB:</strong> YES<br>";
                    echo "Username: " . htmlspecialchars($user['username']) . "<br>";
                    echo "Stored Hash: <code>" . htmlspecialchars($user['password_hash']) . "</code> (Length: " . strlen($user['password_hash']) . " chars)</div>";

                    $hashCheck = password_verify($v_input_pass, $user['password_hash']);

                    if ($hashCheck) {
                        echo "<div class='alert alert-success'><strong>3. password_verify():</strong> MATCH SUCCESS! User is authenticated.</div>";
                    } else {
                        echo "<div class='alert alert-danger'><strong>3. password_verify():</strong> MATCH FAILED.<br>The password string you entered does not match the hash in the database.</div>";
                    }
                } else {
                    echo "<div class='alert alert-danger'><strong>2. User Found in DB:</strong> NO.<br>No records returned for '$v_input_user'.</div>";
                }
            } catch (PDOException $e) {
                echo "<div class='alert alert-danger'><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
        ?>
    </div>

</body>

</html>
<?php ob_end_flush(); ?>