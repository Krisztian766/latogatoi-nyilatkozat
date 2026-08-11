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

$stmt = $db->prepare("SELECT * FROM declarations {$whereSQL} ORDER BY created_at DESC");
$stmt->execute($params);
$declarations = $stmt->fetchAll();

extract(loadPdfBrandingSettings());
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nyilatkozatok – <?= count($declarations) ?> db</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            background: #e8e8e8;
        }

        .print-bar {
            background: #0F172A;
            color: #fff;
            padding: .8rem 1.5rem;
            display: flex;
            align-items: center;
            gap: .85rem;
            flex-wrap: wrap;
        }

        .print-bar a, .print-bar button {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.25);
            padding: .42rem .95rem;
            border-radius: 5px;
            font-size: .86rem;
            cursor: pointer;
            font-family: inherit;
        }

        .print-bar button {
            background: #2563EB;
            border-color: #2563EB;
            font-weight: 700;
        }

        .print-bar span { font-size: .82rem; opacity: .6; }

        .page {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            margin: 1.5rem auto;
            box-shadow: 0 8px 32px rgba(0,0,0,.22);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .doc-header {
            background: #1E3A6E;
            padding: 16px 20mm;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .doc-header .logo img {
            max-height: 72px;
            max-width: 220px;
            object-fit: contain;
            display: block;
            filter: brightness(0) invert(1);
        }

        <?= pdfCompanyNameCss() ?>

        .doc-header .doc-title { text-align: right; flex-shrink: 0; }
        .doc-header .doc-title h1 { font-size: 17pt; font-weight: 700; color: #fff; line-height: 1.2; letter-spacing: -.01em; }
        .doc-header .doc-title .subtitle { font-size: 10pt; color: rgba(255,255,255,.65); margin-top: 4px; font-style: italic; }

        .doc-strip {
            background: #F1F5F9;
            border-bottom: 1px solid #CBD5E1;
            padding: 6px 20mm;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .doc-strip span { font-size: 7.5pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #64748B; }
        .doc-strip .badge { background: #1E3A6E; color: #fff; font-size: 7pt; font-weight: 700; padding: 2px 8px; border-radius: 3px; letter-spacing: .06em; }

        .doc-body { padding: 14mm 20mm 28mm; flex: 1; }

        .section-head {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #1E3A6E;
            border-bottom: 2px solid #DBEAFE;
            padding-bottom: 5px;
            margin: 18px 0 10px;
        }

        .section-head:first-child { margin-top: 0; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .info-table td { padding: 7px 12px; font-size: 10pt; border: 1px solid #E2E8F0; vertical-align: middle; }
        .info-table td.lbl { background: #F8FAFC; font-weight: 700; color: #374151; width: 38%; white-space: nowrap; font-size: 9pt; }
        .info-table td.val { background: #fff; color: #111827; }
        .info-table tr:last-child td { border-bottom: 1px solid #E2E8F0; }

        .decl-box {
            background: #F0F7FF;
            border-left: 4px solid #1E3A6E;
            padding: 13px 16px;
            font-size: 9.5pt;
            line-height: 1.7;
            border-radius: 0 5px 5px 0;
        }

        .decl-box p { margin-bottom: 11px; }
        .decl-box p:last-child { margin-bottom: 0; }
        .decl-box em { color: #4B5563; font-style: italic; font-size: 9pt; }

        .sign-row { display: flex; align-items: flex-end; gap: 24px; margin-top: 10px; }
        .sign-col { flex: 1; }
        .sign-col-wide { flex: 2.5; }
        .sign-label { font-size: 8pt; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
        .sign-line { border-bottom: 1.5px solid #9CA3AF; min-height: 50px; padding-bottom: 4px; font-size: 11pt; font-weight: 700; color: #111827; }
        .sig-img { display: block; max-width: 260px; max-height: 90px; border: 1px solid #E2E8F0; border-radius: 4px; background: #fff; padding: 3px; }

        .doc-footer {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            background: #F8FAFC;
            border-top: 2px solid #1E3A6E;
            padding: 7px 20mm;
        }

        .footer-inner { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 4px; }
        .footer-items { display: flex; flex-wrap: wrap; gap: 0 20px; }
        .footer-item { display: flex; align-items: center; gap: 4px; font-size: 7.5pt; color: #374151; }
        .footer-item strong { color: #1E3A6E; font-weight: 700; }

        .empty-msg { text-align: center; padding: 4rem 2rem; color: #6B7280; font-style: italic; }

        @media print {
            body { background: #fff; }
            .print-bar { display: none !important; }
            .page {
                width: 100%;
                min-height: 100vh;
                margin: 0;
                box-shadow: none;
                page-break-after: always;
            }
            .page:last-child { page-break-after: auto; }
            .doc-body { padding: 10mm 16mm 26mm; }
            .doc-header { padding: 14px 16mm; }
            .doc-strip  { padding: 5px 16mm; }
            .doc-footer { padding: 6px 16mm; }
            @page { size: A4 portrait; margin: 0; }
        }
    </style>
</head>
<body>

<div class="print-bar">
    <a href="/admin/dashboard.php"><?= icon('arrow-left') ?> Vissza</a>
    <button onclick="window.print()"><?= icon('download') ?> PDF mentése</button>
    <span><?= count($declarations) ?> nyilatkozat &nbsp;|&nbsp; Nyomtatásnál válassza: <strong>Mentés PDF-ként</strong> &nbsp;|&nbsp; Margók: <strong>Nincs / None</strong></span>
</div>

<?php if (empty($declarations)): ?>
    <div class="page"><div class="empty-msg">Nincs a szűrésnek megfelelő nyilatkozat.</div></div>
<?php endif; ?>

<?php foreach ($declarations as $d): ?>
<div class="page">
    <div class="doc-header">
        <div class="logo">
            <?php if ($logoB64): ?>
                <img src="<?= $logoB64 ?>" alt="Logo">
            <?php else: ?>
                <div style="width:160px"></div>
            <?php endif; ?>
            <?php $companyName = getSetting('company_name'); if ($companyName): ?>
                <span class="company-name"><?= e($companyName) ?></span>
            <?php endif; ?>
        </div>
        <div class="doc-title">
            <h1><?= e($titleHu) ?></h1>
            <?php if ($titleEn && $titleEn !== $titleHu): ?>
                <div class="subtitle"><?= e($titleEn) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($docId || $docVer): ?>
    <div class="doc-strip">
        <?php if ($docId): ?><span class="badge"><?= e($docId) ?></span><?php endif; ?>
        <?php if ($docVer): ?><span class="badge"><?= e($docVer) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="doc-body">
        <div class="section-head">Látogató adatai / Visitor information</div>
        <table class="info-table">
            <tr><td class="lbl"><?= e($fName) ?></td><td class="val"><strong><?= e($d['name']) ?></strong></td></tr>
            <tr><td class="lbl"><?= e($fCompany) ?></td><td class="val"><?= e($d['company'] ?: '–') ?></td></tr>
            <tr><td class="lbl"><?= e($fContact) ?></td><td class="val"><?= e($d['contact']) ?></td></tr>
            <tr><td class="lbl">Látogatás dátuma / Visit date</td><td class="val"><?= e($d['visit_date']) ?></td></tr>
        </table>

        <div class="section-head">Nyilatkozat / Declaration</div>
        <div class="decl-box">
            <?php if (!empty($paraHu)): ?>
                <?php foreach ($paraHu as $i => $p): ?>
                    <p><?= nl2br(e($p)) ?>
                    <?php if (isset($paraEn[$i]) && trim($paraEn[$i])): ?>
                        <br><em><?= nl2br(e($paraEn[$i])) ?></em>
                    <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color:#9CA3AF;font-style:italic">Nincs megadva nyilatkozat szöveg.</p>
            <?php endif; ?>
        </div>

        <div class="section-head">Aláírás / Signature</div>
        <div class="sign-row">
            <div class="sign-col">
                <div class="sign-label">Kelt / Date</div>
                <div class="sign-line"><?= e($d['visit_date']) ?></div>
            </div>
            <div class="sign-col-wide">
                <div class="sign-label">Aláírás / Signature</div>
                <div class="sign-line">
                    <?php if ($d['signature_data']): ?>
                        <img class="sig-img" src="<?= e($d['signature_data']) ?>" alt="Aláírás">
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-footer">
        <div class="footer-inner">
            <div class="footer-items">
                <?php if ($docId): ?><div class="footer-item"><strong>Azonosító:</strong> <?= e($docId) ?></div><?php endif; ?>
                <?php if ($docVer): ?><div class="footer-item"><strong>Verzió:</strong> <?= e($docVer) ?></div><?php endif; ?>
                <div class="footer-item"><strong>Dátum:</strong> <?= $docDate ?></div>
                <?php if ($prepared): ?><div class="footer-item"><strong>Készítette:</strong> <?= e($prepared) ?></div><?php endif; ?>
                <?php if ($approved): ?><div class="footer-item"><strong>Jóváhagyta:</strong> <?= e($approved) ?></div><?php endif; ?>
            </div>
            <div style="font-size:7.5pt;color:#9CA3AF">#<?= $d['id'] ?> &nbsp;|&nbsp; <?= e($d['name']) ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>

</body>
</html>
