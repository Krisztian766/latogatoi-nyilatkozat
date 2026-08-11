<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$db   = getDB();
$stmt = $db->prepare('SELECT * FROM declarations WHERE id = ?');
$stmt->execute([$id]);
$d    = $stmt->fetch();

if (!$d) { header('Location: /admin/dashboard.php'); exit; }

$visitType = !empty($d['visit_type_id']) ? getVisitType((int)$d['visit_type_id']) : null;
if (!$visitType) { header('Location: /admin/view.php?id=' . $id); exit; }

extract(loadPdfBrandingSettings());

$trainingTitle = trim($visitType['doc_title_hu']) !== '' ? $visitType['doc_title_hu'] : $visitType['name_hu'];
$expired       = $d['training_valid_until'] !== null && $d['training_valid_until'] < date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oktatási igazolás #<?= $id ?> – <?= e($d['name']) ?></title>
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

        .print-bar button { background: #2563EB; border-color: #2563EB; font-weight: 700; }
        .print-bar span { font-size: .82rem; opacity: .6; }

        .page {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            margin: 1.5rem auto;
            box-shadow: 0 8px 32px rgba(0,0,0,.22);
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

        .doc-header .logo img { max-height: 72px; max-width: 220px; object-fit: contain; display: block; filter: brightness(0) invert(1); }
        <?= pdfCompanyNameCss() ?>
        .doc-header .doc-title { text-align: right; flex-shrink: 0; }
        .doc-header .doc-title h1 { font-size: 15pt; font-weight: 700; color: #fff; line-height: 1.2; letter-spacing: -.01em; }
        .doc-header .doc-title .subtitle { font-size: 9pt; color: rgba(255,255,255,.65); margin-top: 4px; font-style: italic; }

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

        .cert-title {
            text-align: center;
            padding: 16px 20mm 4px;
        }
        .cert-title h2 {
            font-size: 16pt;
            letter-spacing: .04em;
            color: #1E3A6E;
            text-transform: uppercase;
        }
        .cert-title p { font-size: 9pt; color: #6B7280; margin-top: 3px; }

        .doc-body { padding: 6mm 20mm 10mm; flex: 1; }

        .section-head {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #1E3A6E;
            border-bottom: 2px solid #DBEAFE;
            padding-bottom: 5px;
            margin: 14px 0 8px;
        }
        .section-head:first-child { margin-top: 0; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .info-table td { padding: 6px 12px; font-size: 10pt; border: 1px solid #E2E8F0; vertical-align: middle; }
        .info-table td.lbl { background: #F8FAFC; font-weight: 700; color: #374151; width: 38%; white-space: nowrap; font-size: 9pt; }
        .info-table td.val { background: #fff; color: #111827; }
        .info-table tr:last-child td { border-bottom: 1px solid #E2E8F0; }

        .result-badge {
            display: inline-block;
            font-size: 9pt;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 3px;
            border: 1.5px solid;
        }
        .result-badge.ok      { color: #166534; border-color: #166534; }
        .result-badge.warn    { color: #B91C1C; border-color: #B91C1C; }

        .disclaimer {
            font-size: 8pt;
            color: #6B7280;
            font-style: italic;
            line-height: 1.5;
            margin-top: 8px;
        }

        .sign-row { display: flex; align-items: flex-end; gap: 24px; margin-top: 14px; }
        .sign-col { flex: 1; }
        .sign-col-wide { flex: 2.5; }
        .sign-label { font-size: 8pt; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
        .sign-line { border-bottom: 1.5px solid #9CA3AF; min-height: 44px; padding-bottom: 4px; font-size: 11pt; font-weight: 700; color: #111827; }
        .sig-img { display: block; max-width: 260px; max-height: 80px; border: 1px solid #E2E8F0; border-radius: 4px; background: #fff; padding: 3px; }

        .doc-footer { background: #F8FAFC; border-top: 2px solid #1E3A6E; padding: 7px 20mm; }
        .footer-inner { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 4px; }
        .footer-items { display: flex; flex-wrap: wrap; gap: 0 20px; }
        .footer-item { display: flex; align-items: center; gap: 4px; font-size: 7.5pt; color: #374151; }
        .footer-item strong { color: #1E3A6E; font-weight: 700; }

        @media print {
            body { background: #fff; }
            .print-bar { display: none !important; }
            .page { width: 100%; min-height: 100vh; margin: 0; box-shadow: none; }
            .doc-body { padding: 4mm 16mm 8mm; }

            .doc-header { padding: 8px 16mm; background: #fff !important; border-bottom: 3px solid #1E3A6E; }
            .doc-header .logo img { filter: none; max-height: 46px; }
            .doc-header .logo .company-name { color: #1E3A6E !important; }
            .doc-header .doc-title h1 { color: #1E3A6E; font-size: 13pt; }
            .doc-header .doc-title .subtitle { color: #4B5563; }

            .doc-strip { padding: 4px 16mm; }
            .doc-strip .badge { background: #fff !important; color: #1E3A6E; border: 1px solid #1E3A6E; }

            .cert-title { padding: 10px 16mm 2px; }
            .section-head { margin: 8px 0 5px; }
            .info-table td { padding: 4px 10px; }
            .sign-row { margin-top: 8px; }
            .sign-line { min-height: 32px; }
            .doc-footer { padding: 5px 16mm; }

            @page { size: A4 portrait; margin: 0; }
        }
    </style>
</head>
<body>

<div class="print-bar">
    <a href="/admin/view.php?id=<?= $id ?>"><?= icon('arrow-left') ?> Vissza</a>
    <button onclick="window.print()"><?= icon('download') ?> PDF mentése</button>
    <span>Nyomtatásnál válassza: <strong>Mentés PDF-ként</strong> &nbsp;|&nbsp; Margók: <strong>Nincs / None</strong></span>
</div>

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

    <div class="cert-title">
        <h2>Oktatási igazolás</h2>
        <p>Training certificate</p>
    </div>

    <div class="doc-body">

        <div class="section-head">Résztvevő / Participant</div>
        <table class="info-table">
            <tr><td class="lbl">Név / Name</td><td class="val"><strong><?= e($d['name']) ?></strong></td></tr>
            <?php if (!empty($d['position'])): ?>
            <tr><td class="lbl">Munkakör / Position</td><td class="val"><?= e($d['position']) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($d['company'])): ?>
            <tr><td class="lbl">Cég / Company</td><td class="val"><?= e($d['company']) ?></td></tr>
            <?php endif; ?>
            <tr><td class="lbl">Dátum / Date</td><td class="val"><?= e($d['visit_date']) ?></td></tr>
        </table>

        <div class="section-head">Oktatás / Training</div>
        <table class="info-table">
            <tr><td class="lbl">Megnevezés / Title</td><td class="val"><?= e($trainingTitle) ?></td></tr>
            <?php if (!empty($visitType['trainer_name'])): ?>
            <tr><td class="lbl">Oktató / Trainer</td>
                <td class="val"><?= e($visitType['trainer_name']) ?><?= !empty($visitType['trainer_qualification']) ? ' — ' . e($visitType['trainer_qualification']) : '' ?></td></tr>
            <?php endif; ?>
            <tr><td class="lbl">Eredmény / Result</td>
                <td class="val">
                    <?php if ($d['quiz_total'] !== null): ?>
                        <?= (int)$d['quiz_score'] ?>/<?= (int)$d['quiz_total'] ?>
                        <span class="result-badge <?= $d['quiz_passed'] ? 'ok' : 'warn' ?>">
                            <?= $d['quiz_passed'] ? 'MEGFELELT' : 'NEM FELELT MEG' ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#9CA3AF">nincs teszt ehhez a típushoz</span>
                    <?php endif; ?>
                </td></tr>
            <tr><td class="lbl">Érvényesség / Valid until</td>
                <td class="val">
                    <?php if ($d['training_valid_until']): ?>
                        <?= e($d['training_valid_until']) ?>
                        <?php if ($expired): ?><span class="result-badge warn">LEJÁRT</span><?php endif; ?>
                    <?php else: ?>
                        nem jár le / does not expire
                    <?php endif; ?>
                </td></tr>
        </table>

        <p class="disclaimer">
            Ez az igazolás a fent megnevezett oktató által összeállított/jóváhagyott oktatási anyag
            elektronikus úton történő elvégzését és a hozzá tartozó ellenőrző teszt eredményét
            dokumentálja. A tartalom szakmai/jogi megfelelőségéért a megnevezett oktató felel.<br>
            This certificate documents electronic completion of the training material and quiz
            result compiled/approved by the trainer named above, who is responsible for the
            professional/legal adequacy of the content.
        </p>

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

    </div><!-- .doc-body -->

    <div class="doc-footer">
        <div class="footer-inner">
            <div class="footer-items">
                <?php if ($docId): ?><div class="footer-item"><strong>Azonosító:</strong> <?= e($docId) ?></div><?php endif; ?>
                <?php if ($docVer): ?><div class="footer-item"><strong>Verzió:</strong> <?= e($docVer) ?></div><?php endif; ?>
                <div class="footer-item"><strong>Dátum:</strong> <?= $docDate ?></div>
                <?php if ($prepared): ?><div class="footer-item"><strong>Készítette:</strong> <?= e($prepared) ?></div><?php endif; ?>
                <?php if ($approved): ?><div class="footer-item"><strong>Jóváhagyta:</strong> <?= e($approved) ?></div><?php endif; ?>
            </div>
            <div style="font-size:7.5pt;color:#9CA3AF">
                #<?= $id ?> &nbsp;|&nbsp; <?= e($d['name']) ?>
            </div>
        </div>
    </div>

</div><!-- .page -->
</body>
</html>
