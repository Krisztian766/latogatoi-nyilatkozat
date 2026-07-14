<?php
require_once __DIR__ . '/../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username']  = $username;
            header('Location: /admin/dashboard.php');
            exit;
        } else {
            $error = 'Hibás felhasználónév vagy jelszó! / Invalid username or password!';
        }
    } catch (Exception $e) {
        $error = 'Adatbázis hiba! / Database error!';
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Bejelentkezés</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="admin-login-page">
<div class="container">
    <div class="form-card login-card">
        <h1 style="font-size:1.5rem">&#128274; Admin Bejelentkezés</h1>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Felhasználónév / Username:</label>
                <input type="text" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label>Jelszó / Password:</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary btn-full">Bejelentkezés / Login</button>
        </form>
        <p style="text-align:center;margin-top:1rem"><a href="/">&larr; Vissza a nyilatkozathoz</a></p>
    </div>
</div>
</body>
</html>
