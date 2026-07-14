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

$total = (int)$db->prepare("SELECT COUNT(*) FROM audit_log $where")->execute($params) ? 0 : 0;
$cs = $db->prepare("SELECT COUNT(*) FROM audit_log $where"); $cs->execute($params);
$total = (int)$cs->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$stmt = $db->prepare("SELECT * FROM audit_log $where ORDER BY created_at DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actionLabels = [
    'view'           => ['&#128065;', 'Megtekintés',  '#255aa8'],
    'edit'           => ['&#9998;',   'Szerkesztés',  '#f39c12'],
    'delete'         => ['&#128465;', 'Törlés',       '#c0392b'],
    'login'          => ['&#128274;', 'Bejelentkezés','#27ae60'],
    'logout'         => ['&#128682;', 'Kijelentkezés','#7f8c8d'],
    'export'         => ['&#8595;',   'Export',       '#8e44ad'],
    'cleanup'        => ['&#128465;', 'Auto-törlés',  '#c0392b'],
    'view_audit_log' => ['&#128203;', 'Audit napló',  '#7f8c8d'],
    'create'         => ['&#43;',     'Létrehozás',   '#27ae60'],
];

function actionBadge(string $action, array $labels): string {
    $key = strtolower(explode('_', $action)[0]);
    foreach ($labels as $k => $v) {
        if (str_starts_with($action, $k)) {
            [$icon, $label, $color] = $v;
            return "<span style='background:{$color};color:#fff;padding:.15rem .5rem;border-radius:3px;font-size:.75rem;font-weight:700'>{$icon} {$label}</span>";
        }
    }
    return "<span style='background:#999;color:#fff;padding:.15rem .5rem;border-radius:3px;font-size:.75rem'>{$action}</span>";
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
        <h2>&#128203; Audit napló <span class="badge"><?= $total ?></span></h2>
        <form method="GET" class="filter-form">
            <input type="text" name="filter" value="<?= e($filter) ?>" placeholder="Keresés műveletre, felhasználóra...">
            <button type="submit" class="btn btn-secondary">Szűrés</button>
            <?php if ($filter): ?><a href="/admin/audit.php" class="btn-ghost">&#x2715;</a><?php endif; ?>
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
                <tr><td colspan="7" style="text-align:center;color:var(--gray);padding:2rem">Nincs naplóbejegyzés.</td></tr>
            <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="color:var(--gray);font-size:.8rem"><?= $log['id'] ?></td>
                    <td style="white-space:nowrap;font-size:.82rem"><?= e(substr($log['created_at'],0,16)) ?></td>
                    <td><strong><?= e($log['admin_username']) ?></strong></td>
                    <td><?= actionBadge($log['action'], $actionLabels) ?></td>
                    <td>
                        <?php if ($log['record_id']): ?>
                            <a href="/admin/view.php?id=<?= $log['record_id'] ?>" style="color:var(--blue)">#<?= $log['record_id'] ?></a>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;color:var(--gray)"><?= e($log['details'] ?? '') ?></td>
                    <td style="font-size:.8rem;color:var(--gray)"><?= e($log['ip_address']) ?></td>
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
