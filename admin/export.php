<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db     = getDB();
$search = trim($_GET['search'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR company LIKE ? OR contact LIKE ?)';
    $like    = "%{$search}%";
    $params  = array_merge($params, [$like, $like, $like]);
}
if ($from !== '') { $where[] = 'visit_date >= ?'; $params[] = $from; }
if ($to   !== '') { $where[] = 'visit_date <= ?'; $params[] = $to; }
if ($typeFilter === 'none') {
    $where[] = 'visit_type_id IS NULL';
} elseif ($typeFilter !== '' && ctype_digit($typeFilter)) {
    $where[] = 'visit_type_id = ?';
    $params[] = (int)$typeFilter;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT d.id, d.name, d.company, d.contact, d.visit_date, d.created_at,
                              d.quiz_score, d.quiz_total, d.quiz_passed, vt.name_hu AS visit_type_name
                       FROM declarations d
                       LEFT JOIN visit_types vt ON vt.id = d.visit_type_id
                       {$whereSQL} ORDER BY d.created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'nyilatkozatok_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

// Neutralize CSV/DDE formula injection: a cell value starting with =, +, -, @, tab
// or CR would be evaluated as a formula by Excel/LibreOffice when the visitor
// controls name/company/contact freely on the public sign-in form.
$csvSafe = function ($value) {
    $value = (string)$value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
};

fputcsv($out, ['ID', 'Név / Name', 'Cég / Company', 'Kapcsolattartó / Contact', 'Típus / Type', 'Teszt eredmény / Quiz result', 'Látogatás dátuma / Visit Date', 'Beküldve / Submitted At'], ';');

foreach ($rows as $r) {
    $quizResult = $r['quiz_total'] !== null
        ? $r['quiz_score'] . '/' . $r['quiz_total'] . ' (' . ($r['quiz_passed'] ? 'sikeres' : 'sikertelen') . ')'
        : '';
    fputcsv($out, [
        $r['id'],
        $csvSafe($r['name']),
        $csvSafe($r['company']),
        $csvSafe($r['contact']),
        $csvSafe((string)$r['visit_type_name']),
        $quizResult,
        $r['visit_date'],
        $r['created_at'],
    ], ';');
}

fclose($out);
exit;
