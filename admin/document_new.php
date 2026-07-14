<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés!';
    } else {
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if (empty($title) || empty($content)) {
            $error = 'A cím és a dokumentum szövege kötelező!';
        } else {
            $db = getDB();
            $db->prepare('INSERT INTO documents (title, content, created_by) VALUES (?, ?, ?)')
               ->execute([$title, $content, $_SESSION['admin_username'] ?? 'system']);
            $id = $db->lastInsertId();
            logAudit('document_create', $id, $title);
            header('Location: /admin/document_view.php?id=' . $id . '&created=1');
            exit;
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
    <title>Admin – Új dokumentum</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h2>&#43; Új dokumentum létrehozása</h2>
        <a href="/admin/documents.php" class="btn btn-secondary">&larr; Vissza</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="form-card" style="max-width:680px">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="form-group">
                <label>Cím <span class="required">*</span></label>
                <input type="text" name="title" required placeholder="pl. Titoktartási nyilatkozat"
                       value="<?= e($_POST['title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Dokumentum szövege <span class="required">*</span></label>
                <textarea name="content" rows="12" required
                          placeholder="A dokumentum teljes szövege. Az üres sorok új bekezdést kezdenek."><?= e($_POST['content'] ?? '') ?></textarea>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">&#10003; Dokumentum létrehozása</button>
                <a href="/admin/documents.php" class="btn btn-secondary">Mégse</a>
            </div>
        </form>
    </div>
</div>
</div>
</body>
</html>
