<?php
// Simple user management page to add users and change passwords.
session_start();
require_once __DIR__ . '/php_backend/models/User.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if ($username && $password) {
            try {
                User::create($username, $password);
                $message = 'User added.';
            } catch (Exception $e) {
                $message = 'Error adding user.';
            }
        } else {
            $message = 'Username and password required.';
        }
    } elseif ($action === 'update') {
        $password = $_POST['password'] ?? '';
        if ($password) {
            User::updatePassword((int)$_SESSION['user_id'], $password);
            $message = 'Password updated.';
        } else {
            $message = 'Password required.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
</head>
<body>
    <h1>User Management</h1>
    <p><a href="logout.php">Logout</a> | <a href="frontend/index.html">Home</a></p>
    <?php if ($message): ?>
        <p><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <h2>Add User</h2>
    <form method="post">
        <input type="hidden" name="action" value="add">
        <label>Username: <input type="text" name="username"></label><br>
        <label>Password: <input type="password" name="password"></label><br>
        <button type="submit">Add User</button>
    </form>

    <h2>Update Password</h2>
    <form method="post">
        <input type="hidden" name="action" value="update">
        <label>New Password: <input type="password" name="password"></label><br>
        <button type="submit">Update Password</button>
    </form>
</body>
</html>
