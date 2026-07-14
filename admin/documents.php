<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db   = getDB();
$rows = $db->query("
    SELECT d.id, d.title, d.created_at,
           COUNT(s.id) AS sent_count,
           SUM(s.status = 'signed') AS signed_count
    FROM documents d
    LEFT JOIN document_sends s ON s.document_id = d.id
    GROUP BY d.id
    ORDER BY d.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Dokumentumok</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h2>&#128196; Dokumentumok</h2>
        <a href="/admin/document_new.php" class="btn btn-primary">&#43; Új dokumentum</a>
    </div>

    <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:1.25rem">
        Tetszőleges dokumentum (szerződés, hozzájárulás, egyéb nyilatkozat) létrehozása, amelyet emailben
        kiküldött egyedi linken keresztül aláírhat a címzett. Az aláírt válasz azonnal itt jelenik meg,
        nincs szükség email-postafiók figyelésére.
    </p>

    <?php if (empty($rows)): ?>
        <div class="empty-state">Még nincs létrehozott dokumentum.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cím</th>
                    <th>Létrehozva</th>
                    <th>Kiküldve</th>
                    <th>Aláírva</th>
                    <th>Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><strong><?= e($r['title']) ?></strong></td>
                    <td><?= e(substr($r['created_at'], 0, 16)) ?></td>
                    <td><?= (int)$r['sent_count'] ?></td>
                    <td><?= (int)$r['signed_count'] ?></td>
                    <td class="actions">
                        <a href="/admin/document_view.php?id=<?= $r['id'] ?>" class="btn btn-sm">&#128065; Megnyit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>
