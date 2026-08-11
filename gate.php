<?php
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$return = safeReturnPath($_GET['return'] ?? '/');

if (!empty($_SESSION['site_unlocked'])) {
    header('Location: ' . $return);
    exit;
}

$error       = '';
$maxAttempts = 5;
$lockMinutes = 15;
$ip          = getClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    $error = 'Érvénytelen kérés. Próbálja újra.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();

        $attemptStmt = $db->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'gate_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL {$lockMinutes} MINUTE)"
        );
        $attemptStmt->execute([$ip]);
        $recentFailures = (int)$attemptStmt->fetchColumn();

        if ($recentFailures >= $maxAttempts) {
            $error = "Túl sok sikertelen próbálkozás. Kérjük, próbálja újra {$lockMinutes} perc múlva!";
        } else {
            $password = $_POST['password'] ?? '';
            $hash     = getSetting('site_password_hash');

            if ($hash !== '' && password_verify($password, $hash)) {
                $_SESSION['site_unlocked'] = true;
                if (!empty($_POST['remember'])) {
                    issueGateCookie();
                }
                logAudit('gate_success');
                header('Location: ' . $return);
                exit;
            } else {
                logAudit('gate_failed');
                $error = 'Hibás jelszó! / Incorrect password!';
            }
        }
    } catch (Exception $e) {
        $error = 'Adatbázis hiba! / Database error!';
    }
}

$csrf = generateCsrf();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Belépés szükséges</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="admin-login-page">
<div class="container">
    <div class="form-card login-card">
        <?php $companyName = getSetting('company_name'); if ($companyName): ?>
            <p class="login-eyebrow"><?= e($companyName) ?></p>
        <?php endif; ?>
        <h1 style="font-size:1.5rem;display:flex;align-items:center;gap:.5rem"><?= icon('lock') ?> Belépés szükséges</h1>
        <p style="font-size:.88rem;color:var(--gray-500);margin-bottom:1rem">Ez egy belső, céges rendszer. Kérjük adja meg a hozzáférési jelszót.</p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label>Jelszó / Password:</label>
                <input type="password" name="password" required autocomplete="current-password" autofocus>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
                <input type="checkbox" name="remember" id="remember" value="1" checked style="width:auto">
                <label for="remember" style="margin:0">Eszköz megjegyzése (180 napig) — kioszk eszközön hagyja bejelölve</label>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Belépés / Enter</button>
        </form>
    </div>
</div>
</body>
</html>
