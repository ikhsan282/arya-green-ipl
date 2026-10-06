<?php
// Standalone print page — no header.php/sidebar.php so @media print works correctly
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

// Ganti variabel dalam body surat
$body = $letter['body'];
$body = strtr($body, [
    '{nama}'    => $letter['resident_name'] ?? '',
    '{alamat}'  => trim(($letter['block'] ?? '') . '-' . ($letter['unit_number'] ?? ''), '-'),
    '{no_ktp}'  => $letter['id_card_number'] ?? '',
    '{telepon}' => $letter['phone'] ?? '',
]);

$type_labels = ['pengantar'=>'PENGANTAR','keterangan'=>'KETERANGAN','domisili'=>'DOMISILI','lainnya'=>'KETERANGAN'];
$type_label  = $type_labels[$letter['type']] ?? strtoupper($letter['type']);
$auto_print  = isset($_GET['print']) && $_GET['print'] === '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Surat <?= e($letter['number'] ?? '') ?> — <?= e(APP_NAME) ?></title>
  <style>
    @page { size: A4 portrait; margin: 2.5cm 2cm; }
    * { box-sizing: border-box; }
    body {
      font-family: 'Times New Roman', Times, serif;
      font-size: 12pt;
      color: #000;
      line-height: 1.6;
      margin: 0;
    }
    .kop {
      display: flex;
      align-items: center;
      border-bottom: 4px double #000;
      padding-bottom: 10px;
      margin-bottom: 20px;
      gap: 16px;
    }
    .kop-logo {
      width: 72px;
      height: 72px;
      border: 2px solid #000;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28pt;
      flex-shrink: 0;
    }
    .kop-text { flex: 1; text-align: center; }
    .kop-text h2 { margin: 0; font-size: 14pt; text-transform: uppercase; letter-spacing: 1px; }
    .kop-text h3 { margin: 2px 0 0; font-size: 13pt; text-transform: uppercase; }
    .kop-text p  { margin: 2px 0; font-size: 10pt; }
    .letter-title {
      text-align: center;
      margin: 20px 0 6px;
      font-size: 13pt;
      font-weight: bold;
      text-transform: uppercase;
      text-decoration: underline;
    }
    .letter-number {
      text-align: center;
      margin-bottom: 24px;
      font-size: 11pt;
    }
    .salutation { margin-bottom: 12px; }
    .identity-table {
      margin: 0 0 16px 40px;
      border-collapse: collapse;
      line-height: 1.9;
      font-size: 12pt;
    }
    .identity-table td:first-child { width: 150px; }
    .body-text {
      text-align: justify;
      white-space: pre-wrap;
      margin-bottom: 16px;
    }
    .closing { margin-bottom: 48px; }
    .signature-row {
      display: flex;
      justify-content: flex-end;
    }
    .signature-block {
      text-align: center;
      min-width: 200px;
    }
    .signature-space { height: 70px; }

    /* Screen-only styles */
    @media screen {
      body {
        background: #e9ecef;
        padding: 20px;
      }
      .letter-paper {
        background: #fff;
        max-width: 740px;
        margin: 0 auto 40px;
        padding: 2.5cm 2cm;
        box-shadow: 0 2px 16px rgba(0,0,0,.15);
        min-height: 29.7cm;
      }
      .no-print {
        max-width: 740px;
        margin: 0 auto 16px;
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
      }
      .btn-cetak {
        padding: 8px 24px;
        background: #198754;
        color: #fff;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-family: Arial, sans-serif;
      }
      .btn-back {
        padding: 8px 20px;
        background: #6c757d;
        color: #fff;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-family: Arial, sans-serif;
        text-decoration: none;
        display: inline-block;
      }
      .meta-badge {
        font-family: Arial, sans-serif;
        font-size: 12px;
        color: #6c757d;
        margin-left: auto;
      }
    }

    @media print {
      body { background: none; padding: 0; }
      .letter-paper { box-shadow: none; padding: 0; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>

  <div class="no-print">
    <a class="btn-back" href="index.php">← Kembali</a>
    <button class="btn-cetak" onclick="window.print()">🖨&nbsp; Cetak / Simpan PDF</button>
    <span class="meta-badge">
      Nomor: <strong><?= e($letter['number'] ?? '—') ?></strong>
      &nbsp;|&nbsp;
      <?= e($type_labels[$letter['type']] ?? $letter['type']) ?>
      &nbsp;|&nbsp;
      <?= fmt_date($letter['issued_date']) ?>
    </span>
  </div>

  <div class="letter-paper">

    <!-- Kop surat -->
    <div class="kop">
      <div class="kop-logo">🌿</div>
      <div class="kop-text">
        <h2>Rukun Tetangga</h2>
        <h3><?= e(APP_NAME) ?></h3>
        <p>Pamulang Barat, Pamulang, Tangerang Selatan 15417</p>
        <p>Telepon: —&nbsp;&nbsp;&nbsp; Email: —</p>
      </div>
    </div>

    <!-- Judul & Nomor -->
    <div class="letter-title">Surat <?= $type_label ?></div>
    <div class="letter-number">Nomor: <?= e($letter['number'] ?? '—') ?></div>

    <!-- Pembuka -->
    <div class="salutation">
      Yang bertanda tangan di bawah ini, Ketua RT <?= e(APP_NAME) ?>,
      dengan ini menerangkan bahwa:
    </div>

    <!-- Data warga -->
    <?php if ($letter['resident_name']): ?>
    <table class="identity-table">
      <tr>
        <td>Nama</td>
        <td>: <strong><?= e($letter['resident_name']) ?></strong></td>
      </tr>
      <?php if ($letter['id_card_number']): ?>
      <tr>
        <td>No. KTP / NIK</td>
        <td>: <?= e($letter['id_card_number']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($letter['unit_number']): ?>
      <tr>
        <td>Alamat</td>
        <td>: Blok <?= e($letter['block']) ?> No. <?= e($letter['unit_number']) ?>,<br>
            &nbsp;&nbsp;&nbsp;<?= e(APP_NAME) ?>, Pamulang Barat, Tangerang Selatan</td>
      </tr>
      <?php endif; ?>
      <?php if ($letter['phone']): ?>
      <tr>
        <td>No. Telepon</td>
        <td>: <?= e($letter['phone']) ?></td>
      </tr>
      <?php endif; ?>
    </table>
    <?php endif; ?>

    <!-- Isi surat -->
    <div class="body-text"><?= nl2br(e($body)) ?></div>

    <!-- Penutup -->
    <div class="closing">
      Demikian surat keterangan ini dibuat dengan sebenarnya untuk dipergunakan
      sebagaimana mestinya.
    </div>

    <!-- Tanda tangan -->
    <div class="signature-row">
      <div class="signature-block">
        <p style="margin:0">
          <?= e(APP_NAME) ?>, <?= fmt_date($letter['issued_date'], 'd F Y') ?>
        </p>
        <p style="margin:0">Ketua RT</p>
        <div class="signature-space"></div>
        <p style="margin:0">
          <strong><u><?= e($letter['issuer_name'] ?? '________________________________') ?></u></strong>
        </p>
      </div>
    </div>

  </div><!-- /.letter-paper -->

  <?php if ($auto_print): ?>
  <script>window.addEventListener('load', function() { window.print(); });</script>
  <?php endif; ?>

</body>
</html>
