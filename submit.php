<?php
require_once __DIR__ . '/includes/functions.php';
requireSiteAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }
if (!verifyCsrf($_POST['csrf_token'] ?? '')) { header('Location: /?error=missing_fields'); exit; }

if (!inductionSatisfied()) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Location: /induction.php?lang=' . ($_SESSION['lang'] ?? 'hu'));
    exit;
}
$induction = $_SESSION['induction'] ?? null;

$name           = trim($_POST['name'] ?? '');
$company        = trim($_POST['company'] ?? '');
$position       = trim($_POST['position'] ?? '');
$contact        = trim($_POST['contact'] ?? '');
$visit_date     = trim($_POST['visit_date'] ?? date('Y-m-d'));
$signature_data = trim($_POST['signature_data'] ?? '');
$gdpr_accepted  = !empty($_POST['gdpr_consent']) ? 1 : 0;

if (empty($name) || empty($contact)) {
    header('Location: /?error=missing_fields&company=' . urlencode($company) . '&position=' . urlencode($position));
    exit;
}

if (empty($signature_data) || !str_starts_with($signature_data, 'data:image/png;base64,')) {
    header('Location: /?error=no_signature&name=' . urlencode($name) . '&company=' . urlencode($company) . '&position=' . urlencode($position) . '&contact=' . urlencode($contact));
    exit;
}

$gdprLabel = getSetting('gdpr_checkbox_label');
if ($gdprLabel && !$gdpr_accepted) {
    header('Location: /?error=no_gdpr&name=' . urlencode($name) . '&company=' . urlencode($company) . '&position=' . urlencode($position) . '&contact=' . urlencode($contact));
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) $visit_date = date('Y-m-d');

$dataHash  = computeHash($name, $company, $contact, $visit_date, $signature_data);
$expiresAt = getRetentionDate();
$ip        = getClientIp();

// Training validity is frozen at the interval configured when the
// declaration was submitted, not recomputed later — if the required
// retraining interval changes afterward, already-issued certificates keep
// reflecting what was actually true at the time they were issued.
$trainingValidUntil = null;
if (!empty($induction['visit_type_id'])) {
    $inductionType = getVisitType((int)$induction['visit_type_id']);
    if ($inductionType && !empty($inductionType['validity_days'])) {
        $trainingValidUntil = date('Y-m-d', strtotime("+{$inductionType['validity_days']} days"));
    }
}

try {
    $db = getDB();

    // Rate limiting: max 5 submissions per IP in 10 minutes
    $rateStmt = $db->prepare("SELECT COUNT(*) FROM declarations WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $rateStmt->execute([$ip]);
    if ((int)$rateStmt->fetchColumn() >= 5) {
        header('Location: /?error=rate_limit');
        exit;
    }

    $db->prepare('INSERT INTO declarations
                  (name, company, position, contact, visit_type_id, quiz_score, quiz_total, quiz_passed, training_valid_until,
                   visit_date, signature_data, gdpr_accepted, gdpr_accepted_at, data_hash, ip_address, expires_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$name, $company, $position ?: null, $contact,
                  $induction['visit_type_id'] ?? null, $induction['quiz_score'] ?? null,
                  $induction['quiz_total'] ?? null, isset($induction['quiz_passed']) ? (int)$induction['quiz_passed'] : null,
                  $trainingValidUntil,
                  $visit_date, $signature_data,
                  $gdpr_accepted, $gdpr_accepted ? date('Y-m-d H:i:s') : null,
                  $dataHash, $ip, $expiresAt]);
    $id = $db->lastInsertId();

    sendNotificationWithCc([
        'id' => $id, 'name' => $name, 'company' => $company,
        'contact' => $contact, 'visit_date' => $visit_date, 'gdpr_accepted' => $gdpr_accepted,
    ]);

    // Clear the induction state so the next visitor on this shared kiosk device
    // has to complete the video/document/quiz again rather than inheriting it.
    unset($_SESSION['induction']);

    header('Location: /?success=1');
    exit;
} catch (Exception $e) {
    header('Location: /?error=db_error');
    exit;
}
