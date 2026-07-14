<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$db   = getDB();
$stmt = $db->prepare('SELECT * FROM declarations WHERE id = ?');
$stmt->execute([$id]);
$d    = $stmt->fetch();

if (!$d) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés! / Invalid request!';
    } else {
        $name       = trim($_POST['name'] ?? '');
        $company    = trim($_POST['company'] ?? '');
        $contact    = trim($_POST['contact'] ?? '');
        $visit_date = trim($_POST['visit_date'] ?? '');

        if (empty($name) || empty($contact)) {
            $error = 'Név és kapcsolattartó kötelező! / Name and contact are required!';
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
                $visit_date = $d['visit_date'];
            }
            $newHash = computeHash($name, $company, $contact, $visit_date, $d['signature_data'] ?? '');
            $db->prepare('UPDATE declarations SET name=?, company=?, contact=?, visit_date=?, data_hash=?, updated_at=NOW() WHERE id=?')
               ->execute([$name, $company, $contact, $visit_date, $newHash, $id]);
            logAudit('edit', $id, $name);
            $success = 'Mentés sikeres! / Saved successfully!';
            $stmt    = $db->prepare('SELECT * FROM declarations WHERE id = ?');
            $stmt->execute([$id]);
            $d = $stmt->fetch();
        }
    }
}

$csrf = generateCsrf();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Szerkesztés #<?= $id ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/partials/nav.php'; ?>

    <div class="admin-content">
        <a href="/admin/view.php?id=<?= $id ?>" class="btn btn-secondary"><?= icon('arrow-left') ?> Vissza / Back</a>
        <h2 style="margin:1rem 0">Szerkesztés / Edit – #<?= $id ?></h2>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <div class="form-card" style="max-width:600px">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="form-group">
                    <label>Név / Name: <span class="required">*</span></label>
                    <input type="text" name="name" value="<?= e($d['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Képviselt Cég / Company:</label>
                    <input type="text" name="company" value="<?= e($d['company']) ?>">
                </div>
                <div class="form-group">
                    <label>Helyi kapcsolattartó / Visiting: <span class="required">*</span></label>
                    <input type="text" name="contact" value="<?= e($d['contact']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Dátum / Date:</label>
                    <input type="date" name="visit_date" value="<?= e($d['visit_date']) ?>">
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Mentés / Save</button>
                <a href="/admin/view.php?id=<?= $id ?>" class="btn btn-secondary">Mégse / Cancel</a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
