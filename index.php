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
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-50 font-sans">
    <div class="w-full max-w-sm bg-white p-6 rounded shadow">
        <h1 class="text-2xl font-semibold mb-4 text-center">Login</h1>
        <?php if ($error): ?>
            <p class="mb-4 text-red-500 text-center"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post" class="space-y-4">
            <label class="block">Username:
                <input type="text" name="username" class="mt-1 w-full border p-2 rounded">
            </label>
            <label class="block">Password:
                <input type="password" name="password" class="mt-1 w-full border p-2 rounded">
            </label>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Login</button>
        </form>
    </div>
</body>
</html>
