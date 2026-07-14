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

// Daily submission counts for the last 30 days (for the trend chart)
$dailyRows = $db->query("
    SELECT DATE(created_at) AS d, COUNT(*) AS c
    FROM declarations
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$chartDays = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartDays[$d] = (int)($dailyRows[$d] ?? 0);
}
$chartMax = max(1, max($chartDays));

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

    <!-- Trend chart -->
    <div class="chart-card">
        <div class="chart-title">Beküldések / nap &ndash; utóbbi 30 nap</div>
        <div class="bar-chart">
            <?php foreach ($chartDays as $day => $count): ?>
                <div class="bar-col" title="<?= e($day) ?>: <?= $count ?>">
                    <div class="bar" style="height: <?= $count > 0 ? max(4, round($count / $chartMax * 100)) : 2 ?>%"></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-labels">
            <span><?= e(array_key_first($chartDays)) ?></span>
            <span><?= e(array_key_last($chartDays)) ?></span>
        </div>
    </div>

    <!-- Filters & actions -->
    <div class="toolbar">
        <form method="GET" class="filter-form">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Keresés névre, cégre...">
            <input type="date" name="from" value="<?= e($from) ?>" title="Dátumtól">
            <input type="date" name="to"   value="<?= e($to) ?>"   title="Dátumig">
            <button type="submit" class="btn btn-secondary"><?= icon('search') ?> Szűrés</button>
            <?php if ($search || $from || $to): ?>
                <a href="/admin/dashboard.php" class="btn btn-ghost"><?= icon('x') ?> Törlés</a>
            <?php endif; ?>
        </form>
        <div class="toolbar-actions">
            <a href="/admin/new.php" class="btn btn-primary"><?= icon('plus') ?> Új nyilatkozat</a>
            <a href="/admin/export.php?<?= http_build_query(['search'=>$search,'from'=>$from,'to'=>$to]) ?>" class="btn btn-secondary"><?= icon('download') ?> CSV export</a>
            <a href="/admin/pdf_bulk.php?<?= http_build_query(['search'=>$search,'from'=>$from,'to'=>$to]) ?>" class="btn btn-secondary" target="_blank"><?= icon('download') ?> PDF export (lista)</a>
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
                        <a href="/admin/view.php?id=<?= $r['id'] ?>" class="btn btn-sm"><?= icon('eye') ?> Nézet</a>
                        <a href="/admin/edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary"><?= icon('edit') ?> Szerkeszt</a>
                        <a href="/admin/delete.php?id=<?= $r['id'] ?>&token=<?= e(generateCsrf()) ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Biztosan törli?')"><?= icon('trash') ?> Töröl</a>
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

    <p style="margin-top:1rem;font-size:.82rem;color:var(--gray-500)">
        <?= $total ?> találat / result
    </p>
</div>
</div>
</body>
</html>
