<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$db   = getDB();
$stmt = $db->prepare('
    SELECT s.*, d.title AS doc_title, d.content AS doc_content
    FROM document_sends s
    JOIN documents d ON d.id = s.document_id
    WHERE s.id = ?
');
$stmt->execute([$id]);
$s = $stmt->fetch();

if (!$s) { header('Location: /admin/documents.php'); exit; }

logAudit('document_send_view', $s['document_id'], $s['recipient_name']);

$hashOk = null;
if (!empty($s['data_hash'])) {
    $expected = computeDocHash((int)$s['document_id'], $s['recipient_email'], $s['signature_data'] ?? '');
    $hashOk   = hash_equals($expected, $s['data_hash']);
}

$statusBadge = [
    'sent'   => ['badge-gray', '&#9993; Kiküldve, még nem nyitották meg'],
    'viewed' => ['badge-warn', '&#128065; Megnyitva, még nincs aláírva'],
    'signed' => ['badge-ok',   '&#10003; Aláírva'],
];
[$cls, $label] = $statusBadge[$s['status']];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($s['doc_title']) ?> – <?= e($s['recipient_name']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <a href="/admin/document_view.php?id=<?= $s['document_id'] ?>" class="btn btn-secondary">&larr; Vissza a dokumentumhoz</a>
        <?php if ($s['status'] === 'signed'): ?>
            <a href="/admin/document_pdf.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-primary">&#128438; PDF letöltés</a>
        <?php endif; ?>
    </div>

    <div class="declaration-view">
        <h2 style="text-align:center;margin-bottom:1.5rem"><?= e($s['doc_title']) ?></h2>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.25rem">
            <span class="compliance-badge <?= $cls ?>"><?= $label ?></span>
            <?php if ($hashOk === true): ?>
                <span class="compliance-badge badge-ok">&#10003; Adat-integritás: sértetlen</span>
            <?php elseif ($hashOk === false): ?>
                <span class="compliance-badge badge-warn">&#9888; Adat-integritás: MÓDOSÍTOTT!</span>
            <?php endif; ?>
        </div>

        <table class="info-table">
            <tr><th>Címzett:</th><td><strong><?= e($s['recipient_name']) ?></strong></td></tr>
            <tr><th>Email:</th><td><?= e($s['recipient_email']) ?></td></tr>
            <tr><th>Kiküldve:</th><td><?= e($s['created_at']) ?></td></tr>
            <tr><th>Megnyitva:</th><td><?= $s['viewed_at'] ? e($s['viewed_at']) : '–' ?></td></tr>
            <tr><th>Aláírva:</th><td><?= $s['signed_at'] ? e($s['signed_at']) : '–' ?></td></tr>
            <?php if (!empty($s['ip_address'])): ?>
            <tr><th>IP cím:</th><td style="font-family:monospace;font-size:.85rem"><?= e($s['ip_address']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($s['data_hash'])): ?>
            <tr><th>SHA-256 hash:</th><td style="font-family:monospace;font-size:.75rem;word-break:break-all"><?= e($s['data_hash']) ?></td></tr>
            <?php endif; ?>
        </table>

        <p class="section-label" style="margin-top:1.5rem">Dokumentum szövege</p>
        <div class="declaration-box"><?= nl2br(e($s['doc_content'])) ?></div>

        <div class="signature-display" style="margin-top:1.5rem">
            <p><strong>Aláírás:</strong></p>
            <?php if ($s['signature_data']): ?>
                <img src="<?= e($s['signature_data']) ?>" alt="Aláírás"
                     style="border:1px solid #ccc;border-radius:4px;max-width:400px;width:100%;margin-top:.5rem">
            <?php else: ?>
                <p style="color:#999">Még nincs aláírás.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</body>
</html>
