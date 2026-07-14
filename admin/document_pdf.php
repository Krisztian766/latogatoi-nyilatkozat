<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$id   = (int)($_GET['id'] ?? 0);
$db   = getDB();
$stmt = $db->prepare('
    SELECT s.*, d.title AS doc_title, d.content AS doc_content
    FROM document_sends s
    JOIN documents d ON d.id = s.document_id
    WHERE s.id = ?
');
$stmt->execute([$id]);
$s = $stmt->fetch();

if (!$s || $s['status'] !== 'signed') { header('Location: /admin/documents.php'); exit; }

$logoPath = __DIR__ . '/../assets/logo.png';
$logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';
$docDate  = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($s['doc_title']) ?> – <?= e($s['recipient_name']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1a1a1a; background: #e8e8e8; }

        .print-bar {
            background: #0F172A; color: #fff; padding: .8rem 1.5rem;
            display: flex; align-items: center; gap: .85rem; flex-wrap: wrap;
        }
        .print-bar a, .print-bar button {
            color: #fff; text-decoration: none; background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.25); padding: .42rem .95rem; border-radius: 5px;
            font-size: .86rem; cursor: pointer; font-family: inherit;
        }
        .print-bar button { background: #2563EB; border-color: #2563EB; font-weight: 700; }
        .print-bar span { font-size: .82rem; opacity: .6; }

        .page {
            width: 210mm; min-height: 297mm; background: #fff; margin: 1.5rem auto;
            box-shadow: 0 8px 32px rgba(0,0,0,.22); position: relative; display: flex; flex-direction: column;
        }

        .doc-header {
            background: #1E3A6E; padding: 16px 20mm; display: flex;
            align-items: center; justify-content: space-between; gap: 20px;
        }
        .doc-header .logo img { max-height: 72px; max-width: 220px; object-fit: contain; display: block; filter: brightness(0) invert(1); }
        .doc-header .doc-title { text-align: right; flex-shrink: 0; }
        .doc-header .doc-title h1 { font-size: 17pt; font-weight: 700; color: #fff; line-height: 1.2; letter-spacing: -.01em; }

        .doc-body { padding: 14mm 20mm 28mm; flex: 1; }

        .section-head {
            font-size: 7.5pt; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
            color: #1E3A6E; border-bottom: 2px solid #DBEAFE; padding-bottom: 5px; margin: 18px 0 10px;
        }
        .section-head:first-child { margin-top: 0; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .info-table td { padding: 7px 12px; font-size: 10pt; border: 1px solid #E2E8F0; vertical-align: middle; }
        .info-table td.lbl { background: #F8FAFC; font-weight: 700; color: #374151; width: 32%; white-space: nowrap; font-size: 9pt; }
        .info-table td.val { background: #fff; color: #111827; }

        .decl-box {
            background: #F0F7FF; border-left: 4px solid #1E3A6E; padding: 13px 16px;
            font-size: 9.5pt; line-height: 1.7; border-radius: 0 5px 5px 0;
        }

        .sign-row { display: flex; align-items: flex-end; gap: 24px; margin-top: 10px; }
        .sign-col { flex: 1; }
        .sign-label { font-size: 8pt; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
        .sign-line { border-bottom: 1.5px solid #9CA3AF; min-height: 50px; padding-bottom: 4px; font-size: 11pt; font-weight: 700; color: #111827; }
        .sig-img { display: block; max-width: 260px; max-height: 90px; border: 1px solid #E2E8F0; border-radius: 4px; background: #fff; padding: 3px; }

        .doc-footer {
            position: absolute; bottom: 0; left: 0; right: 0; background: #F8FAFC;
            border-top: 2px solid #1E3A6E; padding: 7px 20mm; font-size: 7.5pt; color: #9CA3AF;
        }

        @media print {
            body { background: #fff; }
            .print-bar { display: none !important; }
            .page { width: 100%; min-height: 100vh; margin: 0; box-shadow: none; }
            .doc-body { padding: 10mm 16mm 26mm; }
            .doc-header { padding: 14px 16mm; }
            .doc-footer { padding: 6px 16mm; }
            @page { size: A4 portrait; margin: 0; }
        }
    </style>
</head>
<body>

<div class="print-bar">
    <a href="/admin/document_send_view.php?id=<?= $s['id'] ?>">&larr; Vissza</a>
    <button onclick="window.print()">&#128438; PDF mentése</button>
    <span>Nyomtatásnál válassza: <strong>Mentés PDF-ként</strong> &nbsp;|&nbsp; Margók: <strong>Nincs / None</strong></span>
</div>

<div class="page">
    <div class="doc-header">
        <div class="logo">
            <?php if ($logoB64): ?><img src="<?= $logoB64 ?>" alt="Logo"><?php else: ?><div style="width:160px"></div><?php endif; ?>
        </div>
        <div class="doc-title"><h1><?= e($s['doc_title']) ?></h1></div>
    </div>

    <div class="doc-body">
        <div class="section-head">Címzett adatai</div>
        <table class="info-table">
            <tr><td class="lbl">Név</td><td class="val"><strong><?= e($s['recipient_name']) ?></strong></td></tr>
            <tr><td class="lbl">Email</td><td class="val"><?= e($s['recipient_email']) ?></td></tr>
            <tr><td class="lbl">Aláírás időpontja</td><td class="val"><?= e($s['signed_at']) ?></td></tr>
        </table>

        <div class="section-head">Dokumentum szövege</div>
        <div class="decl-box"><?= nl2br(e($s['doc_content'])) ?></div>

        <div class="section-head">Aláírás</div>
        <div class="sign-row">
            <div class="sign-col">
                <div class="sign-label">Kelt</div>
                <div class="sign-line"><?= e(substr($s['signed_at'], 0, 10)) ?></div>
            </div>
            <div class="sign-col" style="flex:2.5">
                <div class="sign-label">Aláírás</div>
                <div class="sign-line">
                    <?php if ($s['signature_data']): ?><img class="sig-img" src="<?= e($s['signature_data']) ?>" alt="Aláírás"><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-footer">
        SHA-256: <?= e($s['data_hash']) ?> &nbsp;|&nbsp; Dátum: <?= $docDate ?>
    </div>
</div>
</body>
</html>
