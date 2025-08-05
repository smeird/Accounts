<?php
// Simple login page for user authentication.
session_start();
require_once __DIR__ . '/php_backend/models/User.php';
require_once __DIR__ . '/php_backend/models/Log.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $reason = '';
    $userId = User::verify($username, $password, $reason);
    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
        header('Location: frontend/index.html');
        exit;
    } else {
        $error = 'Invalid credentials';
        Log::write("Login failed for '$username': $reason", 'ERROR');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
</head>
<body>
    <h1>Login</h1>
    <?php if ($error): ?>
        <p style="color:red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>Username: <input type="text" name="username"></label><br>
        <label>Password: <input type="password" name="password"></label><br>
        <button type="submit">Login</button>
    </form>
</body>
</html>
