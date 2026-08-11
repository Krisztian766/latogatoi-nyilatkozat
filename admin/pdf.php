<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$db   = getDB();
$stmt = $db->prepare('SELECT * FROM declarations WHERE id = ?');
$stmt->execute([$id]);
$d    = $stmt->fetch();

if (!$d) { header('Location: /admin/dashboard.php'); exit; }

extract(loadPdfBrandingSettings());
$visitType = !empty($d['visit_type_id']) ? getVisitType((int)$d['visit_type_id']) : null;
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nyilatkozat #<?= $id ?> – <?= e($d['name']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1a1a1a;
            background: #e8e8e8;
        }

        /* ── Print toolbar ── */
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
            transition: background .15s;
        }

        .print-bar button {
            background: #2563EB;
            border-color: #2563EB;
            font-weight: 700;
        }

        .print-bar button:hover { background: #1D4ED8; }
        .print-bar a:hover { background: rgba(255,255,255,.2); }
        .print-bar span { font-size: .82rem; opacity: .6; }

        /* ── A4 sheet ── */
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

        /* ── Page header band ── */
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

        .doc-header .doc-title {
            text-align: right;
            flex-shrink: 0;
        }

        .doc-header .doc-title h1 {
            font-size: 17pt;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
            letter-spacing: -.01em;
        }

        .doc-header .doc-title .subtitle {
            font-size: 10pt;
            color: rgba(255,255,255,.65);
            margin-top: 4px;
            font-style: italic;
        }

        /* ── Doc ID strip below header ── */
        .doc-strip {
            background: #F1F5F9;
            border-bottom: 1px solid #CBD5E1;
            padding: 6px 20mm;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .doc-strip span {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #64748B;
        }

        .doc-strip .badge {
            background: #1E3A6E;
            color: #fff;
            font-size: 7pt;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 3px;
            letter-spacing: .06em;
        }

        /* ── Main content area ── */
        .doc-body {
            padding: 14mm 20mm 28mm;
            flex: 1;
        }

        /* ── Section heading ── */
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

        /* ── Info table ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        .info-table td {
            padding: 7px 12px;
            font-size: 10pt;
            border: 1px solid #E2E8F0;
            vertical-align: middle;
        }

        .info-table td.lbl {
            background: #F8FAFC;
            font-weight: 700;
            color: #374151;
            width: 38%;
            white-space: nowrap;
            font-size: 9pt;
        }

        .info-table td.val {
            background: #fff;
            color: #111827;
        }

        .info-table tr:last-child td { border-bottom: 1px solid #E2E8F0; }

        /* ── Declaration box ── */
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

        .decl-box em {
            color: #4B5563;
            font-style: italic;
            font-size: 9pt;
        }

        /* ── Signature row ── */
        .sign-row {
            display: flex;
            align-items: flex-end;
            gap: 24px;
            margin-top: 10px;
        }

        .sign-col { flex: 1; }
        .sign-col-wide { flex: 2.5; }

        .sign-label {
            font-size: 8pt;
            font-weight: 700;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 6px;
        }

        .sign-line {
            border-bottom: 1.5px solid #9CA3AF;
            min-height: 50px;
            padding-bottom: 4px;
            font-size: 11pt;
            font-weight: 700;
            color: #111827;
        }

        .sig-img {
            display: block;
            max-width: 260px;
            max-height: 90px;
            border: 1px solid #E2E8F0;
            border-radius: 4px;
            background: #fff;
            padding: 3px;
        }

        /* ── Footer ──
           Flows normally after .doc-body instead of position:absolute — with
           .doc-body's flex:1 this still sits flush at the bottom on a single
           page, but (unlike absolute positioning) can't overlap or misplace
           itself if the content genuinely spans more than one printed page. */
        .doc-footer {
            background: #F8FAFC;
            border-top: 2px solid #1E3A6E;
            padding: 7px 20mm;
        }

        .footer-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }

        .footer-items {
            display: flex;
            flex-wrap: wrap;
            gap: 0 20px;
        }

        .footer-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 7.5pt;
            color: #374151;
        }

        .footer-item strong {
            color: #1E3A6E;
            font-weight: 700;
        }

        /* ── Print media ──
           Grayscale-safe: a solid navy fill behind white text/logo (fine on
           screen) turns invisible the moment a printer or "print background
           graphics" is off, which is a common default — so the header band
           and badges switch to dark ink on white, bordered instead of filled.
           Spacing is also tightened throughout so a normal-length declaration
           reliably fits one A4 page. */
        @media print {
            body { background: #fff; }
            .print-bar { display: none !important; }
            .page {
                width: 100%;
                min-height: 100vh;
                margin: 0;
                box-shadow: none;
            }

            .doc-body {
                padding: 6mm 14mm 10mm;
            }

            .doc-header {
                padding: 8px 16mm;
                background: #fff !important;
                border-bottom: 3px solid #1E3A6E;
            }
            .doc-header .logo img { filter: none; max-height: 52px; }
            .doc-header .logo .company-name { color: #1E3A6E !important; }
            .doc-header .doc-title h1 { color: #1E3A6E; font-size: 15pt; }
            .doc-header .doc-title .subtitle { color: #4B5563; }

            .doc-strip  { padding: 4px 16mm; }
            .doc-strip .badge {
                background: #fff !important;
                color: #1E3A6E;
                border: 1px solid #1E3A6E;
            }

            .section-head { margin: 10px 0 6px; }

            .info-table td { padding: 4px 10px; }

            .decl-box { padding: 8px 12px; line-height: 1.42; }
            .decl-box p { margin-bottom: 6px; }

            .sign-row { margin-top: 6px; }
            .sign-line { min-height: 34px; }

            .doc-footer { padding: 5px 16mm; }

            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<!-- Print toolbar -->
<div class="print-bar">
    <a href="/admin/view.php?id=<?= $id ?>"><?= icon('arrow-left') ?> Vissza</a>
    <button onclick="window.print()"><?= icon('download') ?> PDF mentése</button>
    <span>Nyomtatásnál válassza: <strong>Mentés PDF-ként</strong> &nbsp;|&nbsp; Margók: <strong>Nincs / None</strong></span>
</div>

<!-- A4 page -->
<div class="page">

    <!-- Blue header band -->
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

    <!-- Doc ID strip -->
    <?php if ($docId || $docVer): ?>
    <div class="doc-strip">
        <?php if ($docId): ?><span class="badge"><?= e($docId) ?></span><?php endif; ?>
        <?php if ($docVer): ?><span class="badge"><?= e($docVer) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Body -->
    <div class="doc-body">

        <!-- Visitor data -->
        <div class="section-head">Látogató adatai / Visitor information</div>
        <table class="info-table">
            <tr>
                <td class="lbl"><?= e($fName) ?></td>
                <td class="val"><strong><?= e($d['name']) ?></strong></td>
            </tr>
            <tr>
                <td class="lbl"><?= e($fCompany) ?></td>
                <td class="val"><?= e($d['company'] ?: '–') ?></td>
            </tr>
            <tr>
                <td class="lbl"><?= e($fContact) ?></td>
                <td class="val"><?= e($d['contact']) ?></td>
            </tr>
            <tr>
                <td class="lbl">Látogatás dátuma / Visit date</td>
                <td class="val"><?= e($d['visit_date']) ?></td>
            </tr>
        </table>

        <!-- Declaration -->
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

        <?php if ($visitType || $d['quiz_total'] !== null): ?>
        <div class="section-head">Oktatás / Induction</div>
        <table class="info-table">
            <?php if ($visitType): ?>
            <tr><td class="lbl">Típus</td><td class="val"><?= e($visitType['name_hu']) ?></td></tr>
            <?php endif; ?>
            <?php if ($d['quiz_total'] !== null): ?>
            <tr><td class="lbl">Ellenőrző teszt</td>
                <td class="val"><?= (int)$d['quiz_score'] ?>/<?= (int)$d['quiz_total'] ?> — <?= $d['quiz_passed'] ? 'sikeres' : 'sikertelen' ?></td></tr>
            <?php endif; ?>
        </table>
        <?php endif; ?>

        <!-- Signature -->
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

    <!-- Footer -->
    <div class="doc-footer">
        <div class="footer-inner">
            <div class="footer-items">
                <?php if ($docId): ?>
                    <div class="footer-item"><strong>Azonosító:</strong> <?= e($docId) ?></div>
                <?php endif; ?>
                <?php if ($docVer): ?>
                    <div class="footer-item"><strong>Verzió:</strong> <?= e($docVer) ?></div>
                <?php endif; ?>
                <div class="footer-item"><strong>Dátum:</strong> <?= $docDate ?></div>
                <?php if ($prepared): ?>
                    <div class="footer-item"><strong>Készítette:</strong> <?= e($prepared) ?></div>
                <?php endif; ?>
                <?php if ($approved): ?>
                    <div class="footer-item"><strong>Jóváhagyta:</strong> <?= e($approved) ?></div>
                <?php endif; ?>
            </div>
            <div style="font-size:7.5pt;color:#9CA3AF">
                #<?= $id ?> &nbsp;|&nbsp; <?= e($d['name']) ?>
            </div>
        </div>
    </div>

</div><!-- .page -->
</body>
</html>
