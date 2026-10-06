<?php
// Surat cetak PDF — bisa diprint langsung dari browser
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('letters.view');

$db = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash('error', 'ID surat tidak valid.'); redirect(APP_URL.'/pages/letters/index.php'); }

$stmt = $db->prepare(
    'SELECT l.*, r.name AS resident_name, r.id_card_number, r.phone,
            u2.unit_number, u2.block,
            u.name AS issuer_name
     FROM letters l
     LEFT JOIN residents r ON r.id=l.resident_id
     LEFT JOIN units u2 ON u2.id=r.unit_id
     LEFT JOIN users u ON u.id=l.issued_by
     WHERE l.id=?'
);
$stmt->bind_param('i', $id); $stmt->execute();
$letter = $stmt->get_result()->fetch_assoc();
if (!$letter) { flash('error', 'Surat tidak ditemukan.'); redirect(APP_URL.'/pages/letters/index.php'); }

// Replace variabel dalam body
$body = $letter['body'];
$body = strtr($body, [
    '{nama}'   => $letter['resident_name'] ?? '',
    '{alamat}' => ($letter['block'] ?? '') . '-' . ($letter['unit_number'] ?? ''),
    '{no_ktp}' => $letter['id_card_number'] ?? '',
    '{telepon}'=> $letter['phone'] ?? '',
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Surat <?= e($letter['number'] ?? '') ?></title>
  <style>
    @page { size: A4; margin: 2.5cm; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; line-height: 1.6; }
    .header { text-align: center; border-bottom: 3px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
    .header h2 { margin: 0; font-size: 16pt; text-transform: uppercase; }
    .header h3 { margin: 4px 0 0; font-size: 13pt; }
    .header p  { margin: 2px 0; font-size: 11pt; }
    .letter-number { text-align: center; margin: 16px 0 24px; font-size: 12pt; }
    .salutation { margin-bottom: 16px; }
    .body-text { text-align: justify; white-space: pre-wrap; }
    .signature { margin-top: 48px; display: flex; justify-content: flex-end; }
    .signature-block { text-align: center; min-width: 200px; }
    .signature-block .space { height: 64px; }
    @media screen {
      body { max-width: 700px; margin: 24px auto; padding: 24px; box-shadow: 0 0 10px rgba(0,0,0,.1); }
      .btn-print { position: fixed; top: 16px; right: 16px; padding: 8px 20px;
        background: #198754; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
      .btn-back  { position: fixed; top: 16px; right: 100px; padding: 8px 20px;
        background: #6c757d; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
        text-decoration: none; }
    }
    @media print { .btn-print, .btn-back { display: none; } }
  </style>
</head>
<body>
  <button class="btn-print" onclick="window.print()">🖨 Cetak / PDF</button>
  <a class="btn-back" href="index.php">← Kembali</a>

  <div class="header">
    <h2>RUKUN TETANGGA</h2>
    <h3><?= e(APP_NAME) ?></h3>
    <p>Jl. Contoh No. 123, Pamulang Barat, Tangerang Selatan 15417</p>
  </div>

  <div class="letter-number">
    <strong>SURAT <?= strtoupper(e($letter['type'])) ?></strong><br>
    Nomor: <?= e($letter['number'] ?? '—') ?>
  </div>

  <div class="salutation">Yang bertanda tangan di bawah ini, Ketua RT <?= e(APP_NAME) ?>, menerangkan bahwa:</div>

  <?php if ($letter['resident_name']): ?>
  <table style="margin-left:32px;margin-bottom:16px;line-height:1.8">
    <tr><td style="width:140px">Nama</td><td>: <strong><?= e($letter['resident_name']) ?></strong></td></tr>
    <?php if ($letter['id_card_number']): ?>
    <tr><td>No. KTP</td><td>: <?= e($letter['id_card_number']) ?></td></tr>
    <?php endif; ?>
    <?php if ($letter['unit_number']): ?>
    <tr><td>Alamat</td><td>: Blok <?= e($letter['block']) ?> No. <?= e($letter['unit_number']) ?>, <?= e(APP_NAME) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php endif; ?>

  <div class="body-text"><?= nl2br(e($body)) ?></div>

  <p style="margin-top:24px">Demikian surat ini dibuat untuk dipergunakan sebagaimana mestinya.</p>

  <div class="signature">
    <div class="signature-block">
      <p style="margin:0"><?= e(APP_NAME) ?>, <?= fmt_date($letter['issued_date'], 'd F Y') ?></p>
      <p style="margin:0">Ketua RT</p>
      <div class="space"></div>
      <p style="margin:0"><strong><u><?= e($letter['issuer_name'] ?? '________________') ?></u></strong></p>
    </div>
  </div>
</body>
</html>
