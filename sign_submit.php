<?php
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }

$token = $_POST['token'] ?? '';
$db    = getDB();

$stmt = $db->prepare('SELECT * FROM document_sends WHERE token = ?');
$stmt->execute([$token]);
$s = $stmt->fetch();

if (!$s) { http_response_code(404); exit('Érvénytelen link.'); }

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    header('Location: /sign.php?token=' . urlencode($token) . '&error=csrf');
    exit;
}

if ($s['status'] === 'signed' || $s['status'] === 'revoked') {
    header('Location: /sign.php?token=' . urlencode($token));
    exit;
}

$signatureData = trim($_POST['signature_data'] ?? '');
if (empty($signatureData) || !str_starts_with($signatureData, 'data:image/png;base64,')) {
    header('Location: /sign.php?token=' . urlencode($token) . '&error=no_signature');
    exit;
}

$ip       = getClientIp();
$dataHash = computeDocHash((int)$s['document_id'], $s['recipient_email'], $signatureData);

$db->prepare("UPDATE document_sends
              SET status = 'signed', signature_data = ?, data_hash = ?, ip_address = ?, signed_at = NOW()
              WHERE id = ?")
   ->execute([$signatureData, $dataHash, $ip, $s['id']]);

logAudit('document_signed', $s['document_id'], "{$s['recipient_name']} <{$s['recipient_email']}>");

$notifyEmail = getSetting('notification_email');
if ($notifyEmail) {
    $docStmt = $db->prepare('SELECT title FROM documents WHERE id = ?');
    $docStmt->execute([$s['document_id']]);
    $docTitle = $docStmt->fetchColumn() ?: '';

    $subject = 'Dokumentum aláírva: ' . $docTitle;
    $body  = "{$s['recipient_name']} ({$s['recipient_email']}) aláírta a következő dokumentumot:\n";
    $body .= "\"{$docTitle}\"\n\n";
    $body .= "Megtekintés:\n" . SITE_URL . '/admin/document_send_view.php?id=' . $s['id'] . "\n";

    if (!sendSmtpEmail($notifyEmail, $subject, $body)) {
        logAudit('email_failed', $s['document_id'], "Aláírási értesítő nem ment ki: {$notifyEmail}");
    }
}

header('Location: /sign.php?token=' . urlencode($token));
exit;
