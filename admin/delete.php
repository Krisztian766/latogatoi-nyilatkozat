<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

if (!verifyCsrf($_GET['token'] ?? '')) {
    header('Location: /admin/dashboard.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $db   = getDB();
    $stmt = $db->prepare('SELECT name FROM declarations WHERE id = ?');
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    $db->prepare('DELETE FROM declarations WHERE id = ?')->execute([$id]);
    logAudit('delete', $id, $row['name'] ?? '');
}

header('Location: /admin/dashboard.php');
exit;
