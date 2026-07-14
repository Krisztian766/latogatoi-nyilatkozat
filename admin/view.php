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

logAudit('view', $id, $d['name']);

$hashOk   = !empty($d['data_hash']) ? verifyHash($d) : null;
$fName    = getSetting('field_name_label_hu',    'Név / Name');
$fCompany = getSetting('field_company_label_hu', 'Képviselt Cég / Company');
$fContact = getSetting('field_contact_label_hu', 'Helyi kapcsolattartó');

$paraHu = [];
$paraEn = [];
for ($i = 1; $i <= 4; $i++) {
    $ph = getSetting("decl_para_{$i}_hu");
    $pe = getSetting("decl_para_{$i}_en");
    if (trim($ph)) $paraHu[] = $ph;
    if (trim($pe)) $paraEn[] = $pe;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nyilatkozat #<?= $id ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        @media print {
            .admin-nav, .no-print { display: none !important; }
            .admin-content { padding: 0; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/partials/nav.php'; ?>

    <div class="admin-content">
        <?php if (isset($_GET['created'])): ?>
            <div class="alert alert-success" style="margin-bottom:1rem">&#10003; Nyilatkozat sikeresen létrehozva!</div>
        <?php endif; ?>

        <div class="no-print" style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <a href="/admin/dashboard.php" class="btn btn-secondary">&larr; Vissza</a>
            <a href="/admin/edit.php?id=<?= $id ?>" class="btn btn-secondary">&#9998; Szerkeszt</a>
            <a href="/admin/pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-primary">&#128438; PDF letöltés</a>
            <a href="/admin/delete.php?id=<?= $id ?>&token=<?= e(generateCsrf()) ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Biztosan törli ezt a nyilatkozatot?')"
               style="margin-left:auto">&#128465; Töröl</a>
        </div>

        <div class="declaration-view">
            <h2 style="text-align:center;margin-bottom:1.5rem">
                Látogatói nyilatkozat / Visitor declaration
            </h2>

            <!-- Integrity + GDPR badges -->
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.25rem">
                <?php if ($hashOk === true): ?>
                    <span class="compliance-badge badge-ok">&#10003; Adat-integritás: sértetlen</span>
                <?php elseif ($hashOk === false): ?>
                    <span class="compliance-badge badge-warn">&#9888; Adat-integritás: MÓDOSÍTOTT!</span>
                <?php else: ?>
                    <span class="compliance-badge badge-gray">&#8212; Hash: nincs (régi rekord)</span>
                <?php endif; ?>

                <?php if (!empty($d['gdpr_accepted'])): ?>
                    <span class="compliance-badge badge-ok">&#10003; GDPR hozzájárulás: megadva</span>
                <?php else: ?>
                    <span class="compliance-badge badge-gray">&#8212; GDPR: nem rögzített</span>
                <?php endif; ?>

                <?php if (!empty($d['expires_at'])): ?>
                    <?php $expired = $d['expires_at'] < date('Y-m-d'); ?>
                    <span class="compliance-badge <?= $expired ? 'badge-warn' : 'badge-gray' ?>">
                        <?= $expired ? '&#9888; Lejárt' : '&#128197; Lejár' ?>: <?= e($d['expires_at']) ?>
                    </span>
                <?php endif; ?>
            </div>

            <table class="info-table">
                <tr><th><?= e($fName) ?>:</th><td><strong><?= e($d['name']) ?></strong></td></tr>
                <tr><th><?= e($fCompany) ?>:</th><td><?= e($d['company'] ?: '–') ?></td></tr>
                <tr><th><?= e($fContact) ?>:</th><td><?= e($d['contact']) ?></td></tr>
                <tr><th>Dátum / Date:</th><td><?= e($d['visit_date']) ?></td></tr>
                <tr class="no-print"><th>Beküldve:</th><td><?= e($d['created_at']) ?></td></tr>
                <?php if (!empty($d['ip_address'])): ?>
                <tr class="no-print"><th>IP cím:</th><td style="font-family:monospace;font-size:.85rem"><?= e($d['ip_address']) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($d['data_hash'])): ?>
                <tr class="no-print"><th>SHA-256 hash:</th><td style="font-family:monospace;font-size:.75rem;word-break:break-all"><?= e($d['data_hash']) ?></td></tr>
                <?php endif; ?>
            </table>

            <div class="declaration-box" style="margin:1.5rem 0">
                <?php if (!empty($paraHu)): ?>
                    <?php foreach ($paraHu as $i => $p): ?>
                        <p><?= nl2br(e($p)) ?>
                        <?php if (isset($paraEn[$i]) && trim($paraEn[$i])): ?>
                            <br><em style="color:#555"><?= nl2br(e($paraEn[$i])) ?></em>
                        <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:#888;font-style:italic">Nincs megadva nyilatkozat szöveg. Beállítható az admin &rarr; Beállítások menüpontban.</p>
                <?php endif; ?>
            </div>

            <div class="signature-display">
                <p><strong>Kelt. / Date:</strong> <?= e($d['visit_date']) ?></p>
                <p style="margin-top:1rem"><strong>Aláírás / Signature:</strong></p>
                <?php if ($d['signature_data']): ?>
                    <img src="<?= e($d['signature_data']) ?>" alt="Aláírás"
                         style="border:1px solid #ccc;border-radius:4px;max-width:400px;width:100%;margin-top:.5rem">
                <?php else: ?>
                    <p style="color:#999">Nincs aláírás</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
