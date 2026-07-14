<?php
// GDPR retention purge: deletes declarations whose retention period (expires_at) has passed.
// Runnable two ways:
//   - PHP CLI (recommended, via RackHost Cron panel): php8.3 cron/purge_expired.php
//   - HTTP:  https://czeczokrisztian.hu/cron/purge_expired.php?token=CRON_SECRET
require_once __DIR__ . '/../includes/functions.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!hash_equals(CRON_SECRET, $_GET['token'] ?? '')) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

$db = getDB();

$countStmt = $db->prepare('SELECT COUNT(*) FROM declarations WHERE expires_at IS NOT NULL AND expires_at < CURDATE()');
$countStmt->execute();
$count = (int)$countStmt->fetchColumn();

if ($count > 0) {
    $db->prepare('DELETE FROM declarations WHERE expires_at IS NOT NULL AND expires_at < CURDATE()')->execute();
    logAudit('auto_purge', null, "GDPR megőrzési idő lejárta miatt törölve: {$count} nyilatkozat");
}

$msg = date('Y-m-d H:i:s') . " - purge_expired: {$count} nyilatkozat törölve\n";
echo $msg;
error_log(trim($msg));
