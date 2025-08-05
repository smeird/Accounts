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
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 font-sans p-6">
    <div class="max-w-2xl mx-auto bg-white p-6 rounded shadow">
        <img src="frontend/logo.svg" alt="Finance Manager Logo" class="w-32 mx-auto mb-4">
        <h1 class="text-2xl font-semibold mb-4">User Management</h1>
        <p class="mb-4">Add new users or update your password from this page.</p>
        <p class="mb-4"><a href="logout.php" class="text-blue-600 hover:underline">Logout</a> | <a href="frontend/index.html" class="text-blue-600 hover:underline">Home</a></p>
        <?php if ($message): ?>
            <p class="mb-4 text-green-600"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>

        <h2 class="text-xl font-semibold mt-6 mb-2">Add User</h2>
        <form method="post" class="space-y-4 mb-6">
            <input type="hidden" name="action" value="add">
            <label class="block">Username: <input type="text" name="username" class="border p-2 rounded w-full" data-help="Choose a username"></label>
            <label class="block">Password: <input type="password" name="password" class="border p-2 rounded w-full" data-help="Set a password"></label>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Add User</button>
        </form>

        <h2 class="text-xl font-semibold mt-6 mb-2">Update Password</h2>
        <form method="post" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <label class="block">New Password: <input type="password" name="password" class="border p-2 rounded w-full" data-help="Enter your new password"></label>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Update Password</button>
        </form>
    </div>
    <script src="frontend/js/input_help.js"></script>
</body>
</html>
