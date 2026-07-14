<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés!';
    } else {
        $name           = trim($_POST['name'] ?? '');
        $company        = trim($_POST['company'] ?? '');
        $contact        = trim($_POST['contact'] ?? '');
        $visit_date     = trim($_POST['visit_date'] ?? date('Y-m-d'));
        $signature_data = trim($_POST['signature_data'] ?? '');

        if (empty($name) || empty($contact)) {
            $error = 'Név és kapcsolattartó kötelező!';
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
                $visit_date = date('Y-m-d');
            }
            $dataHash  = computeHash($name, $company, $contact, $visit_date, $signature_data);
            $expiresAt = getRetentionDate();
            $ip        = getClientIp();

            $db = getDB();
            $db->prepare('INSERT INTO declarations (name, company, contact, visit_date, signature_data, gdpr_accepted, data_hash, ip_address, expires_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)')
               ->execute([$name, $company, $contact, $visit_date, $signature_data ?: null, $dataHash, $ip, $expiresAt]);
            $id = $db->lastInsertId();
            logAudit('create', $id, $name);

            if (!empty($_POST['send_notification'])) {
                sendNotification(['id' => $id, 'name' => $name, 'company' => $company, 'contact' => $contact, 'visit_date' => $visit_date, 'gdpr_accepted' => 0]);
            }

            header('Location: /admin/view.php?id=' . $id . '&created=1');
            exit;
        }
    }
}

$today    = date('Y-m-d');
$today_hu = date('Y. m. d.');
$csrf     = generateCsrf();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Új nyilatkozat</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h2>Új nyilatkozat létrehozása</h2>
        <a href="/admin/dashboard.php" class="btn btn-secondary"><?= icon('arrow-left') ?> Vissza</a>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="form-card" style="max-width:680px">
        <form id="newForm" method="POST">
            <input type="hidden" name="csrf_token"     value="<?= e($csrf) ?>">
            <input type="hidden" name="signature_data" id="signatureData">

            <p class="section-label">Személyes adatok</p>

            <div class="form-row">
                <div class="form-group">
                    <label>Név / Name <span class="required">*</span></label>
                    <input type="text" name="name" required placeholder="Teljes név" value="<?= e($_POST['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Képviselt Cég / Company</label>
                    <input type="text" name="company" placeholder="Cég neve" value="<?= e($_POST['company'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Helyi kapcsolattartó <span class="required">*</span></label>
                    <input type="text" name="contact" required placeholder="Kapcsolattartó neve" value="<?= e($_POST['contact'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Látogatás dátuma / Date</label>
                    <input type="date" name="visit_date" value="<?= e($_POST['visit_date'] ?? $today) ?>">
                </div>
            </div>

            <p class="section-label">Aláírás / Signature <span style="font-weight:400;text-transform:none;font-size:.8rem">(opcionális / optional)</span></p>

            <div class="sig-wrapper" style="margin-bottom:.5rem">
                <canvas id="signaturePad"></canvas>
                <button type="button" id="clearSig" class="btn-clear"><?= icon('x') ?> Törlés</button>
            </div>
            <p class="sig-hint">Rajzoljon aláírást, vagy hagyja üresen.</p>

            <p class="section-label">Opciók</p>

            <label class="checkbox-label">
                <input type="checkbox" name="send_notification" value="1">
                Email értesítő küldése / Send email notification
            </label>

            <div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Nyilatkozat létrehozása</button>
                <a href="/admin/dashboard.php" class="btn btn-secondary">Mégse</a>
            </div>
        </form>
    </div>
</div>
</div>
<script src="/assets/js/signature_pad.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
