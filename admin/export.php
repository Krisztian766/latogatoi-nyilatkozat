<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db     = getDB();
$search = trim($_GET['search'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR company LIKE ? OR contact LIKE ?)';
    $like    = "%{$search}%";
    $params  = array_merge($params, [$like, $like, $like]);
}
if ($from !== '') { $where[] = 'visit_date >= ?'; $params[] = $from; }
if ($to   !== '') { $where[] = 'visit_date <= ?'; $params[] = $to; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT id, name, company, contact, visit_date, created_at FROM declarations {$whereSQL} ORDER BY created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'nyilatkozatok_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

fputcsv($out, ['ID', 'Név / Name', 'Cég / Company', 'Kapcsolattartó / Contact', 'Látogatás dátuma / Visit Date', 'Beküldve / Submitted At'], ';');

foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        $r['name'],
        $r['company'],
        $r['contact'],
        $r['visit_date'],
        $r['created_at'],
    ], ';');
}

fclose($out);
exit;
