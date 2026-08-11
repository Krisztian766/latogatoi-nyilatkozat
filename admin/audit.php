<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
logAudit('view_audit_log');

$db     = getDB();
$filter = trim($_GET['filter'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;
$offset = ($page - 1) * $per;

$where  = $filter ? 'WHERE action LIKE ? OR admin_username LIKE ?' : '';
$params = $filter ? ["%$filter%", "%$filter%"] : [];

$cs = $db->prepare("SELECT COUNT(*) FROM audit_log $where"); $cs->execute($params);
$total = (int)$cs->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$stmt = $db->prepare("SELECT * FROM audit_log $where ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actionLabels = [
    'document_signed'    => ['check',   'Dokumentum aláírva',    '#1e7a4c'],
    'document_revoke'    => ['ban',     'Link visszavonva',      '#b3261e'],
    'document_resend'    => ['refresh', 'Link újraküldve',       '#255aa8'],
    'document_send_view' => ['eye',     'Aláírt válasz nézve',   '#255aa8'],
    'document_send'      => ['mail',    'Dokumentum kiküldve',   '#255aa8'],
    'document_create'    => ['plus',    'Dokumentum létrehozva', '#1e7a4c'],
    'document'           => ['folder',  'Dokumentum',            '#255aa8'],
    'email_failed'       => ['warning', 'Email küldés sikertelen','#b3261e'],
    'auto_purge'         => ['trash',   'Automatikus törlés',    '#5b6472'],
    'login_failed'       => ['ban',     'Sikertelen belépés',    '#b3261e'],
    'login_success'      => ['lock',    'Bejelentkezés',         '#1e7a4c'],
    'login'              => ['lock',    'Bejelentkezés',         '#1e7a4c'],
    'view_audit_log'     => ['list',    'Audit napló',           '#5b6472'],
    'view'               => ['eye',     'Megtekintés',           '#255aa8'],
    'edit'               => ['edit',    'Szerkesztés',           '#b8790a'],
    'delete'             => ['trash',   'Törlés',                '#b3261e'],
    'logout'             => ['log-out', 'Kijelentkezés',         '#5b6472'],
    'export'             => ['download','Export',                '#6a3fa0'],
    'cleanup'            => ['trash',   'Auto-törlés',           '#b3261e'],
    'create'             => ['plus',    'Létrehozás',            '#1e7a4c'],
];

function actionBadge(string $action, array $labels): string {
    foreach ($labels as $k => $v) {
        if (str_starts_with($action, $k)) {
            [$iconName, $label, $color] = $v;
            return "<span style='display:inline-flex;align-items:center;gap:.35rem;background:{$color};color:#fff;padding:.2rem .55rem;border-radius:3px;font-size:.75rem;font-weight:700'>" . icon($iconName) . " {$label}</span>";
        }
    }
    return "<span style='background:#5b6472;color:#fff;padding:.2rem .55rem;border-radius:3px;font-size:.75rem'>{$action}</span>";
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Audit napló</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>
<div class="admin-content">

    <div class="page-header">
        <h2>Audit napló <span class="badge"><?= $total ?></span></h2>
        <form method="GET" class="filter-form">
            <input type="text" name="filter" value="<?= e($filter) ?>" placeholder="Keresés műveletre, felhasználóra...">
            <button type="submit" class="btn btn-secondary"><?= icon('search') ?> Szűrés</button>
            <?php if ($filter): ?><a href="/admin/audit.php" class="btn-ghost"><?= icon('x') ?></a><?php endif; ?>
        </form>
    </div>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Időpont</th>
                    <th>Admin</th>
                    <th>Művelet</th>
                    <th>Rekord</th>
                    <th>Részletek</th>
                    <th>IP cím</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--gray-500);padding:2rem">Nincs naplóbejegyzés.</td></tr>
            <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="color:var(--gray-500);font-size:.8rem"><?= $log['id'] ?></td>
                    <td style="white-space:nowrap;font-size:.82rem"><?= e(substr($log['created_at'],0,16)) ?></td>
                    <td><strong><?= e($log['admin_username']) ?></strong></td>
                    <td><?= actionBadge($log['action'], $actionLabels) ?></td>
                    <td>
                        <?php if ($log['record_id']): ?>
                            <a href="/admin/view.php?id=<?= $log['record_id'] ?>" style="color:var(--blue)">#<?= $log['record_id'] ?></a>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;color:var(--gray-500)"><?= e($log['details'] ?? '') ?></td>
                    <td style="font-size:.8rem;color:var(--gray-500)"><?= e($log['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="?page=<?= $i ?>&filter=<?= urlencode($filter) ?>" class="<?= $i===$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>
