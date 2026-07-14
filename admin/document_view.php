<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$db   = getDB();
$stmt = $db->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$id]);
$doc  = $stmt->fetch();

if (!$doc) { header('Location: /admin/documents.php'); exit; }

$error   = '';
$success = '';

function sendSigningEmail(string $title, string $recipientName, string $recipientEmail, string $token): bool {
    $signUrl = SITE_URL . '/sign.php?token=' . $token;
    $subject = 'Aláírásra váró dokumentum: ' . $title;
    $body  = "Kedves {$recipientName}!\n\n";
    $body .= "Az alábbi dokumentum aláírásra vár:\n\"{$title}\"\n\n";
    $body .= "Az aláíráshoz kattintson az alábbi linkre:\n{$signUrl}\n\n";
    $body .= "Üdvözlettel,\n" . getSetting('company_name', 'Látogatói Rendszer');
    return sendSmtpEmail($recipientEmail, $subject, $body);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_document'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés!';
    } else {
        $recipientName  = trim($_POST['recipient_name'] ?? '');
        $recipientEmail = trim($_POST['recipient_email'] ?? '');

        if (empty($recipientName) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Érvényes név és email cím megadása kötelező!';
        } else {
            $token = bin2hex(random_bytes(32));
            $db->prepare('INSERT INTO document_sends (document_id, recipient_name, recipient_email, token) VALUES (?, ?, ?, ?)')
               ->execute([$id, $recipientName, $recipientEmail, $token]);

            $sent = sendSigningEmail($doc['title'], $recipientName, $recipientEmail, $token);
            logAudit('document_send', $id, "{$recipientName} <{$recipientEmail}>" . ($sent ? '' : ' — EMAIL KÜLDÉS SIKERTELEN'));

            $success = $sent
                ? "Dokumentum kiküldve: {$recipientEmail}"
                : "A címzett rögzítve, de az email küldése sikertelen volt! Ellenőrizze az email beállításokat.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_id'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés!';
    } else {
        $resendId = (int)$_POST['resend_id'];
        $sendStmt = $db->prepare('SELECT * FROM document_sends WHERE id = ? AND document_id = ?');
        $sendStmt->execute([$resendId, $id]);
        $send = $sendStmt->fetch();

        if ($send && $send['status'] !== 'signed' && $send['status'] !== 'revoked') {
            $sent = sendSigningEmail($doc['title'], $send['recipient_name'], $send['recipient_email'], $send['token']);
            logAudit('document_resend', $id, "{$send['recipient_name']} <{$send['recipient_email']}>" . ($sent ? '' : ' — EMAIL KÜLDÉS SIKERTELEN'));
            $success = $sent ? "Link újraküldve: {$send['recipient_email']}" : 'Az email küldése sikertelen volt!';
        } else {
            $error = 'Ez a küldés már nem küldhető újra (aláírva vagy visszavonva).';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_id'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Érvénytelen kérés!';
    } else {
        $revokeId = (int)$_POST['revoke_id'];
        $upd = $db->prepare("UPDATE document_sends SET status = 'revoked' WHERE id = ? AND document_id = ? AND status != 'signed'");
        $upd->execute([$revokeId, $id]);
        if ($upd->rowCount() > 0) {
            logAudit('document_revoke', $id, "send #{$revokeId}");
            $success = 'A link visszavonva, a címzett a továbbiakban nem tudja aláírni.';
        } else {
            $error = 'Ez a küldés nem vonható vissza (már aláírták, vagy nem található).';
        }
    }
}

$sends = $db->prepare('SELECT * FROM document_sends WHERE document_id = ? ORDER BY created_at DESC');
$sends->execute([$id]);
$sends = $sends->fetchAll();

$csrf = generateCsrf();

$statusBadge = [
    'sent'    => ['badge-gray', 'mail',  'Kiküldve'],
    'viewed'  => ['badge-warn', 'eye',   'Megnyitva'],
    'signed'  => ['badge-ok',   'check', 'Aláírva'],
    'revoked' => ['badge-warn', 'ban',   'Visszavonva'],
];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumentum – <?= e($doc['title']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <div class="page-header">
        <h2><?= e($doc['title']) ?></h2>
        <a href="/admin/documents.php" class="btn btn-secondary"><?= icon('arrow-left') ?> Vissza</a>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success"><?= icon('check') ?> Dokumentum sikeresen létrehozva!</div>
    <?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="form-card" style="margin-bottom:1.5rem">
        <?php if (!empty($doc['content'])): ?>
            <p class="section-label">Dokumentum szövege</p>
            <div class="declaration-box"><?= nl2br(e($doc['content'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($doc['file_path'])): ?>
            <p class="section-label" style="<?= empty($doc['content']) ? '' : 'margin-top:1.25rem' ?>">Melléklet</p>
            <a href="/<?= e($doc['file_path']) ?>" target="_blank" class="btn btn-secondary"><?= icon('paperclip') ?> Melléklet megnyitása</a>
        <?php endif; ?>
    </div>

    <div class="form-card" style="max-width:520px;margin-bottom:1.75rem">
        <p class="section-label">Kiküldés aláírásra</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label>Címzett neve <span class="required">*</span></label>
                <input type="text" name="recipient_name" required placeholder="Teljes név">
            </div>
            <div class="form-group">
                <label>Címzett email címe <span class="required">*</span></label>
                <input type="email" name="recipient_email" required placeholder="cimzett@example.com">
            </div>
            <button type="submit" name="send_document" class="btn btn-primary"><?= icon('mail') ?> Kiküldés emailben</button>
        </form>
    </div>

    <p class="section-label">Kiküldött példányok</p>
    <?php if (empty($sends)): ?>
        <div class="empty-state">Ez a dokumentum még senkinek nem lett kiküldve.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Címzett</th>
                    <th>Email</th>
                    <th>Állapot</th>
                    <th>Kiküldve</th>
                    <th>Aláírva</th>
                    <th>Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sends as $s): [$cls, $iconName, $label] = $statusBadge[$s['status']]; ?>
                <tr>
                    <td><strong><?= e($s['recipient_name']) ?></strong></td>
                    <td><?= e($s['recipient_email']) ?></td>
                    <td><span class="compliance-badge <?= $cls ?>"><?= icon($iconName) ?> <?= $label ?></span></td>
                    <td><?= e(substr($s['created_at'], 0, 16)) ?></td>
                    <td><?= $s['signed_at'] ? e(substr($s['signed_at'], 0, 16)) : '–' ?></td>
                    <td class="actions">
                        <a href="/admin/document_send_view.php?id=<?= $s['id'] ?>" class="btn btn-sm"><?= icon('eye') ?> Megnyit</a>
                        <?php if (!in_array($s['status'], ['signed', 'revoked'], true)): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="resend_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary"><?= icon('refresh') ?> Újraküldés</button>
                            </form>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('Biztosan visszavonja ezt a linket? A címzett a továbbiakban nem tudja aláírni.')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="revoke_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><?= icon('ban') ?> Visszavonás</button>
                            </form>
                        <?php endif; ?>
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
