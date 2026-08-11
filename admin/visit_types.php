<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db    = getDB();
$error = '';
$csrf  = generateCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés (CSRF). Próbálja újra.';
    } elseif (isset($_POST['add_type'])) {
        $name = trim($_POST['name_hu'] ?? '');
        if ($name === '') {
            $error = 'A típus neve kötelező!';
        } else {
            $db->prepare('INSERT INTO visit_types (name_hu, name_en) VALUES (?, ?)')
               ->execute([$name, '']);
            $newId = (int)$db->lastInsertId();
            logAudit('visit_type_created', $newId, $name);
            header('Location: /admin/visit_type_edit.php?id=' . $newId . '&created=1');
            exit;
        }
    } elseif (isset($_POST['toggle_active'])) {
        $id = (int)$_POST['id'];
        $db->prepare('UPDATE visit_types SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        logAudit('visit_type_toggled', $id);
    }
}

$types = $db->query('SELECT vt.*,
        (SELECT COUNT(*) FROM quiz_questions q WHERE q.visit_type_id = vt.id) AS question_count,
        (SELECT COUNT(*) FROM declarations d WHERE d.visit_type_id = vt.id) AS usage_count
    FROM visit_types vt ORDER BY sort_order, id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Oktatás típusok</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <h2 style="margin-bottom:.5rem">Belépési / oktatás típusok</h2>
    <p style="font-size:.88rem;color:var(--gray-500);margin-bottom:1.5rem">
        Minden típushoz tartozhat oktatóvideó, elolvasandó dokumentum és feleletválasztós teszt, amit a látogatónak
        a kitöltés előtt kell teljesítenie. Ha nincs egyetlen aktív típus sem, a kitöltő oldal a régi, egyszerű
        nyilatkozat-űrlapot mutatja teszt nélkül. Ha csak egy aktív típus van, a látogató nem választ, automatikusan
        azt kapja. Egynél több aktív típus esetén a látogató választhat belépéskor.
    </p>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="settings-grid">
        <div class="form-card settings-full">
            <h3>Típusok</h3>
            <?php if (empty($types)): ?>
                <p style="color:var(--gray-500);font-size:.88rem">Még nincs egyetlen típus sem létrehozva.</p>
            <?php else: ?>
            <div class="table-wrapper" style="margin-top:.75rem">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Név</th>
                            <th>Videó</th>
                            <th>Dokumentum</th>
                            <th>Kérdések</th>
                            <th>Használva</th>
                            <th>Állapot</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $t): ?>
                        <tr>
                            <td><strong><?= e($t['name_hu']) ?></strong></td>
                            <td><?= $t['video_path'] ? icon('check') : '–' ?></td>
                            <td><?= trim((string)$t['doc_content_hu']) !== '' ? icon('check') : '–' ?></td>
                            <td><?= (int)$t['question_count'] ?> db</td>
                            <td><?= (int)$t['usage_count'] ?> nyilatkozat</td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" name="toggle_active" class="btn btn-sm <?= $t['is_active'] ? 'btn-secondary' : 'btn-primary' ?>">
                                        <?= $t['is_active'] ? 'Aktív' : 'Inaktív' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="actions">
                                <a href="/admin/visit_type_edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-secondary"><?= icon('edit') ?> Szerkeszt</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-card">
            <h3>Új típus létrehozása</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="form-group">
                    <label>Típus neve (pl. Látogató, Alvállalkozó, Szállító):</label>
                    <input type="text" name="name_hu" required placeholder="pl. Alvállalkozó">
                </div>
                <button type="submit" name="add_type" class="btn btn-primary"><?= icon('plus') ?> Létrehozás és szerkesztés</button>
            </form>
        </div>
    </div>
</div>
</div>
</body>
</html>
