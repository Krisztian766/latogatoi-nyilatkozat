<?php
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$token = $_GET['token'] ?? '';
$db    = getDB();

$stmt = $db->prepare('
    SELECT s.*, d.title AS doc_title, d.content AS doc_content, d.file_path AS doc_file_path
    FROM document_sends s
    JOIN documents d ON d.id = s.document_id
    WHERE s.token = ?
');
$stmt->execute([$token]);
$s = $stmt->fetch();

if (!$s) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="hu">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Érvénytelen link</title><link rel="stylesheet" href="/assets/css/style.css"></head>
    <body><div class="container"><div class="form-card" style="text-align:center">
        <h2>Érvénytelen vagy lejárt link</h2>
        <p style="margin-top:1rem;color:var(--gray-500)">Ez az aláírási link nem létezik.</p>
    </div></div></body></html>
    <?php
    exit;
}

if ($s['status'] === 'sent') {
    $db->prepare("UPDATE document_sends SET status = 'viewed', viewed_at = NOW() WHERE id = ?")->execute([$s['id']]);
}

$errorMap = [
    'no_signature' => 'Kérjük, írja alá a dokumentumot beküldés előtt!',
    'csrf'         => 'A munkamenet lejárt, kérjük próbálja újra.',
];
$error = $errorMap[$_GET['error'] ?? ''] ?? '';

$csrf = generateCsrf();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($s['doc_title']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="page-top">
    <img src="/assets/logo.png" alt="Logo" class="site-logo">
</div>

<div class="container">
    <div class="form-card">
        <?php if ($s['status'] === 'signed'): ?>
            <div class="alert alert-success">
                <?= icon('check') ?> Ezt a dokumentumot már aláírta <?= e($s['signed_at']) ?>-kor. Köszönjük!
            </div>
        <?php elseif ($s['status'] === 'revoked'): ?>
            <div class="alert alert-error">
                Ez az aláírási link vissza lett vonva, már nem érvényes. Kérjük, vegye fel a kapcsolatot a küldővel.
            </div>
        <?php else: ?>

        <p class="section-label">Dokumentum</p>
        <h2 style="margin-bottom:1rem"><?= e($s['doc_title']) ?></h2>
        <?php if (!empty($s['doc_content'])): ?>
            <div class="declaration-box"><?= nl2br(e($s['doc_content'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($s['doc_file_path'])): ?>
            <p style="margin-top:1rem">
                <a href="/<?= e($s['doc_file_path']) ?>" target="_blank" class="btn btn-secondary"><?= icon('paperclip') ?> Melléklet megtekintése</a>
            </p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-top:1.5rem"><?= e($error) ?></div>
        <?php endif; ?>

        <form id="signForm" method="POST" action="/sign_submit.php">
            <input type="hidden" name="csrf_token"     value="<?= e($csrf) ?>">
            <input type="hidden" name="token"          value="<?= e($token) ?>">
            <input type="hidden" name="signature_data" id="signatureData">

            <p class="section-label" style="margin-top:2rem">Aláírás</p>
            <div class="form-group">
                <label>Aláírás <span class="required">*</span></label>
                <div class="sig-wrapper">
                    <canvas id="signaturePad"></canvas>
                    <button type="button" id="clearSig" class="btn-clear"><?= icon('x') ?> Törlés</button>
                </div>
                <p class="sig-hint">Kérjük, írja alá ujjával vagy egérrel.</p>
                <p class="alert alert-error" id="sigError" style="display:none;margin-top:.6rem">Kérjük, írja alá a dokumentumot!</p>
            </div>

            <button type="submit" class="btn btn-primary btn-full" id="submitBtn">
                <?= icon('check') ?> Aláírás és beküldés
            </button>
        </form>

        <?php endif; ?>
    </div>
</div>

<script src="/assets/js/signature_pad.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
