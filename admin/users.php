<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db    = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés (CSRF). Próbálja újra.';
    } elseif (isset($_POST['add_user'])) {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Felhasználónév és jelszó kötelező!';
        } elseif ($password !== $password2) {
            $error = 'A két jelszó nem egyezik!';
        } elseif (strlen($password) < 6) {
            $error = 'A jelszónak legalább 6 karakter hosszúnak kell lennie!';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $error = 'A felhasználónév csak betűket, számokat és _ karaktert tartalmazhat (3-50 karakter)!';
        } else {
            try {
                $db->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)')
                   ->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);
                $success = 'Felhasználó létrehozva: ' . htmlspecialchars($username);
            } catch (Exception $e) {
                $error = 'Ez a felhasználónév már foglalt!';
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        $uid      = (int)$_POST['user_id'];
        $password = $_POST['new_password'] ?? '';
        if (strlen($password) < 6) {
            $error = 'A jelszónak legalább 6 karakter hosszúnak kell lennie!';
        } else {
            $db->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')
               ->execute([password_hash($password, PASSWORD_BCRYPT), $uid]);
            $success = 'Jelszó sikeresen megváltoztatva!';
        }
    }
}

if (isset($_GET['delete'])) {
    if (!verifyCsrf($_GET['token'] ?? '')) {
        $error = 'Érvénytelen kérés (CSRF). Próbálja újra.';
    } else {
        $uid = (int)$_GET['delete'];
        $stmt = $db->prepare('SELECT username FROM admin_users WHERE id=?');
        $stmt->execute([$uid]);
        $target = $stmt->fetchColumn();
        if ($target && $target !== $_SESSION['admin_username']) {
            $db->prepare('DELETE FROM admin_users WHERE id=?')->execute([$uid]);
            $success = 'Felhasználó törölve: ' . htmlspecialchars($target);
        } else {
            $error = 'Nem törölheti saját fiókját!';
        }
    }
}

$csrf  = generateCsrf();
$users = $db->query('SELECT id, username, created_at FROM admin_users ORDER BY created_at')->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Felhasználók</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <h2 style="margin-bottom:1.5rem">Admin felhasználók</h2>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="settings-grid">

        <!-- User list -->
        <div class="form-card" style="grid-column:1/-1">
            <h3>Jelenlegi felhasználók</h3>
            <table class="data-table" style="margin-top:.75rem">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Felhasználónév</th>
                        <th>Létrehozva</th>
                        <th>Jelszó reset</th>
                        <th>Törlés</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td>
                            <strong><?= e($u['username']) ?></strong>
                            <?php if ($u['username'] === $_SESSION['admin_username']): ?>
                                <span class="badge" style="background:var(--gray-500)">Te / You</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(substr($u['created_at'], 0, 16)) ?></td>
                        <td>
                            <form method="POST" style="display:flex;gap:.4rem;align-items:center">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="password" name="new_password" placeholder="Új jelszó" minlength="6"
                                       style="padding:.3rem .6rem;font-size:.82rem;width:130px;border:1px solid var(--border);border-radius:3px">
                                <button type="submit" name="reset_password" class="btn btn-sm btn-secondary">Reset</button>
                            </form>
                        </td>
                        <td>
                            <?php if ($u['username'] !== $_SESSION['admin_username']): ?>
                                <a href="?delete=<?= $u['id'] ?>&token=<?= e($csrf) ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Biztosan törli: <?= e($u['username']) ?>?')"><?= icon('trash') ?> Töröl</a>
                            <?php else: ?>
                                <span style="color:var(--gray-500);font-size:.82rem">–</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Add user -->
        <div class="form-card">
            <h3>Új admin felhasználó</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="form-group">
                    <label>Felhasználónév:</label>
                    <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,50}"
                           placeholder="pl. kovacs.janos">
                    <small>Betűk, számok, aláhúzás. 3-50 karakter.</small>
                </div>
                <div class="form-group">
                    <label>Jelszó:</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Jelszó megerősítése:</label>
                    <input type="password" name="password2" required minlength="6">
                </div>
                <button type="submit" name="add_user" class="btn btn-primary"><?= icon('plus') ?> Felhasználó létrehozása</button>
            </form>
        </div>

    </div>
</div>
</div>
</body>
</html>
