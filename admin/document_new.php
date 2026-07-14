<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$error = '';

$allowedExt = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés!';
    } else {
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $hasFile = !empty($_FILES['attachment']['name'] ?? '');

        if (empty($title) || (empty($content) && !$hasFile)) {
            $error = 'A cím kötelező, és vagy a szöveget, vagy egy mellékletet meg kell adni!';
        } elseif ($hasFile && $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $error = 'A fájl feltöltése sikertelen volt!';
        } else {
            $filePath = null;

            if ($hasFile) {
                $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                $mime = mime_content_type($_FILES['attachment']['tmp_name']);

                if (!isset($allowedExt[$ext]) || $mime !== $allowedExt[$ext]) {
                    $error = 'Csak PDF, PNG vagy JPG melléklet engedélyezett!';
                } elseif ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
                    $error = 'A melléklet mérete legfeljebb 10 MB lehet!';
                } else {
                    $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
                    $destDir    = __DIR__ . '/../uploads/documents/';
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $destDir . $randomName)) {
                        $filePath = 'uploads/documents/' . $randomName;
                    } else {
                        $error = 'A melléklet mentése sikertelen volt!';
                    }
                }
            }

            if (!$error) {
                $db = getDB();
                $db->prepare('INSERT INTO documents (title, content, file_path, created_by) VALUES (?, ?, ?, ?)')
                   ->execute([$title, $content ?: null, $filePath, $_SESSION['admin_username'] ?? 'system']);
                $id = $db->lastInsertId();
                logAudit('document_create', $id, $title);
                header('Location: /admin/document_view.php?id=' . $id . '&created=1');
                exit;
            }
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
        <h2>Új dokumentum létrehozása</h2>
        <a href="/admin/documents.php" class="btn btn-secondary"><?= icon('arrow-left') ?> Vissza</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="form-card" style="max-width:680px">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <div class="form-group">
                <label>Cím <span class="required">*</span></label>
                <input type="text" name="title" required placeholder="pl. Titoktartási nyilatkozat"
                       value="<?= e($_POST['title'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Dokumentum szövege</label>
                <textarea name="content" rows="10"
                          placeholder="A dokumentum szövege. Az üres sorok új bekezdést kezdenek."><?= e($_POST['content'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Melléklet (opcionális)</label>
                <input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg">
                <small>PDF, PNG vagy JPG, legfeljebb 10 MB. A szöveg és/vagy a melléklet közül legalább az egyik kötelező.</small>
            </div>

            <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Dokumentum létrehozása</button>
                <a href="/admin/documents.php" class="btn btn-secondary">Mégse</a>
            </div>
        </form>
    </div>
</div>
</div>
</body>
</html>
