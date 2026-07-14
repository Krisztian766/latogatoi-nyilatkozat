<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db     = getDB();
$search = trim($_GET['search'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// Stats
$stats = [];
foreach ([
    'total'   => "SELECT COUNT(*) FROM declarations",
    'today'   => "SELECT COUNT(*) FROM declarations WHERE DATE(created_at) = CURDATE()",
    'week'    => "SELECT COUNT(*) FROM declarations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    'month'   => "SELECT COUNT(*) FROM declarations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
] as $k => $sql) {
    $stats[$k] = (int)$db->query($sql)->fetchColumn();
}

// Build WHERE
$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = '(name LIKE ? OR company LIKE ? OR contact LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($from !== '') { $where[] = 'visit_date >= ?'; $params[] = $from; }
if ($to   !== '') { $where[] = 'visit_date <= ?'; $params[] = $to; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total      = (int)$db->prepare("SELECT COUNT(*) FROM declarations {$whereSQL}")->execute($params) ? $db->prepare("SELECT COUNT(*) FROM declarations {$whereSQL}")->execute($params) : 0;
$countStmt  = $db->prepare("SELECT COUNT(*) FROM declarations {$whereSQL}");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$listStmt = $db->prepare("SELECT id, name, company, contact, visit_date, created_at FROM declarations {$whereSQL} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute($params);
$rows = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Dashboard</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-label">Összes / Total</div>
        </div>
        <div class="stat-card stat-highlight">
            <div class="stat-value"><?= $stats['today'] ?></div>
            <div class="stat-label">Ma / Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['week'] ?></div>
            <div class="stat-label">Utóbbi 7 nap</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['month'] ?></div>
            <div class="stat-label">Utóbbi 30 nap</div>
        </div>
    </div>

    <!-- Filters & actions -->
    <div class="toolbar">
        <form method="GET" class="filter-form">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Keresés névre, cégre...">
            <input type="date" name="from" value="<?= e($from) ?>" title="Dátumtól">
            <input type="date" name="to"   value="<?= e($to) ?>"   title="Dátumig">
            <button type="submit" class="btn btn-secondary">&#128269; Szűrés</button>
            <?php if ($search || $from || $to): ?>
                <a href="/admin/dashboard.php" class="btn btn-ghost">&#x2715; Törlés</a>
            <?php endif; ?>
        </form>
        <div class="toolbar-actions">
            <a href="/admin/new.php" class="btn btn-primary">&#43; Új nyilatkozat</a>
            <a href="/admin/export.php?<?= http_build_query(['search'=>$search,'from'=>$from,'to'=>$to]) ?>" class="btn btn-secondary">&#8595; CSV export</a>
        </div>
    </div>

    <!-- Table -->
    <?php if (empty($rows)): ?>
        <div class="empty-state">Nincs találat a szűrési feltételeknek megfelelően.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Név / Name</th>
                    <th>Cég / Company</th>
                    <th>Kapcsolattartó</th>
                    <th>Dátum</th>
                    <th>Beküldve</th>
                    <th>Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['company']) ?></td>
                    <td><?= e($r['contact']) ?></td>
                    <td><?= e($r['visit_date']) ?></td>
                    <td><?= e(substr($r['created_at'], 0, 16)) ?></td>
                    <td class="actions">
                        <a href="/admin/view.php?id=<?= $r['id'] ?>" class="btn btn-sm">&#128065; Nézet</a>
                        <a href="/admin/edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">&#9998; Szerkeszt</a>
                        <a href="/admin/delete.php?id=<?= $r['id'] ?>&token=<?= e(generateCsrf()) ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Biztosan törli?')">&#128465; Töröl</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>&<?= http_build_query(['search'=>$search,'from'=>$from,'to'=>$to]) ?>"
               class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <p style="margin-top:1rem;font-size:.82rem;color:var(--gray)">
        <?= $total ?> találat / result
    </p>
</div>
</div>
</body>
</html>
