<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/email_notifications.php';
require_permission('payments.create');

$db      = db();
$bill_id = (int)($_GET['bill_id'] ?? 0);
$errors  = [];

// Load bill if pre-selected
$bill = null;
if ($bill_id) {
    $stmt = $db->prepare(
        'SELECT b.*, bp.label AS period, u.unit_number, u.block, r.name AS resident_name
         FROM bills b
         JOIN billing_periods bp ON bp.id=b.billing_period_id
         JOIN units u ON u.id=b.unit_id
         LEFT JOIN residents r ON r.id=b.resident_id
         WHERE b.id=? AND b.status != "sudah_bayar"'
    );
    $stmt->bind_param('i', $bill_id);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    if (!$bill) { flash('error','Tagihan tidak ditemukan atau sudah lunas.'); redirect(APP_URL.'/pages/billing/index.php'); }
}

// Load unpaid bills for dropdown if no bill_id
$unpaid_bills = [];
if (!$bill) {
    $role = auth_role();
    $uid = auth_id();
    
    // Warga hanya bisa lihat tagihan unit yang dia miliki (via residents.user_id)
    if ($role === 'warga') {
        $stmt = $db->prepare(
            'SELECT b.id, bp.label AS period, u.unit_number, u.block, b.total_amount, r.name AS resident_name
             FROM bills b
             JOIN billing_periods bp ON bp.id=b.billing_period_id
             JOIN units u ON u.id=b.unit_id
             LEFT JOIN residents r ON r.id=b.resident_id
             WHERE b.status != "sudah_bayar" AND r.user_id=?
             ORDER BY bp.period_year DESC, bp.period_month DESC'
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $unpaid_bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        // Admin/ketua/bendahara lihat semua
        $unpaid_bills = $db->query(
            'SELECT b.id, bp.label AS period, u.unit_number, u.block, b.total_amount, r.name AS resident_name
             FROM bills b
             JOIN billing_periods bp ON bp.id=b.billing_period_id
             JOIN units u ON u.id=b.unit_id
             LEFT JOIN residents r ON r.id=b.resident_id
             WHERE b.status != "sudah_bayar"
             ORDER BY bp.period_year DESC, bp.period_month DESC, u.block, u.unit_number'
        )->fetch_all(MYSQLI_ASSOC);
    }
}

$payment_methods = $db->query(
    'SELECT id, code, name, account_no, account_name, instructions
     FROM payment_methods WHERE is_active=1 ORDER BY sort_order, name'
)->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $sel_bill_id    = (int)($_POST['bill_id'] ?? $bill_id);
    $payment_date   = clean($_POST['payment_date']   ?? date('Y-m-d'));
    $amount_paid    = (float)str_replace([',','.'], ['',''], $_POST['amount_paid'] ?? 0);
    // re-parse IDR formatted number: strip dots (thousands) keep value
    $amount_paid    = (float)str_replace('.', '', preg_replace('/[^0-9.]/', '', $_POST['amount_paid'] ?? '0'));
    $pm_id          = (int)($_POST['payment_method_id'] ?? 0);
    $bank_name      = clean($_POST['bank_name']      ?? '');
    $reference_no   = clean($_POST['reference_no']   ?? '');
    $notes          = clean($_POST['notes']          ?? '');

    if (!$sel_bill_id)  $errors[] = 'Pilih tagihan terlebih dahulu.';
    if ($amount_paid <= 0) $errors[] = 'Jumlah bayar harus lebih dari 0.';
    if (!$payment_date) $errors[] = 'Tanggal pembayaran wajib diisi.';

    // Resolve metode dari master (hanya metode aktif yang boleh dipakai)
    $payment_method = null;
    if ($pm_id && empty($errors)) {
        $pms = $db->prepare('SELECT * FROM payment_methods WHERE id=? AND is_active=1');
        $pms->bind_param('i', $pm_id);
        $pms->execute();
        $payment_method = $pms->get_result()->fetch_assoc();
        if (!$payment_method) $errors[] = 'Metode pembayaran tidak valid atau nonaktif.';
    } elseif (!$pm_id) {
        $errors[] = 'Pilih metode pembayaran.';
    }
    $is_cash = $payment_method !== null && (int)($payment_method['auto_verify'] ?? 0) === 1;

    // Validate bill exists & get amount
    if ($sel_bill_id && empty($errors)) {
        $bs = $db->prepare('SELECT * FROM bills WHERE id=?');
        $bs->bind_param('i', $sel_bill_id);
        $bs->execute();
        $bill = $bs->get_result()->fetch_assoc();
        if (!$bill) $errors[] = 'Tagihan tidak valid.';
    }

    // Handle file upload
    $proof_file = null;
    if (!empty($_FILES['proof_file']['name'])) {
        try {
            $proof_file = upload_proof($_FILES['proof_file']);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        $uid = auth_id();
        $pm_name = $payment_method['name'];
        $stmt = $db->prepare(
            'INSERT INTO payments (bill_id,user_id,payment_date,amount_paid,payment_method_id,payment_method,bank_name,reference_no,proof_file,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('iisdisssss', $sel_bill_id,$uid,$payment_date,$amount_paid,$pm_id,$pm_name,
            $bank_name,$reference_no,$proof_file,$notes);
        $stmt->execute();
        $pay_id = $db->insert_id;

        // If tunai/cash, auto-verify and mark bill paid
        if ($is_cash) {
            $uid_v = auth_id();
            $upd = $db->prepare('UPDATE payments SET status="verified",verified_by=?,verified_at=NOW() WHERE id=?');
            $upd->bind_param('ii', $uid_v, $pay_id);
            $upd->execute();
            $upd2 = $db->prepare('UPDATE bills SET status="sudah_bayar",paid_date=? WHERE id=?');
            $upd2->bind_param('si', $payment_date, $sel_bill_id);
            $upd2->execute();
            // Auto-entry ke buku kas
            $s3 = $db->prepare(
                'INSERT INTO cash_book (type,category,amount,description,trx_date,ref_payment_id,created_by)
                 VALUES ("pemasukan", "IPL", ?, ?, ?, ?, ?)'
            );
            $desc = 'IPL ' . ($bill['period'] ?? '') . ' — ' . ($bill['block'] ?? '') . '-' . ($bill['unit_number'] ?? '');
            $s3->bind_param('dssii', $amount_paid, $desc, $payment_date, $pay_id, $uid_v);
            $s3->execute();
        } else {
            // Notifikasi ketua: ada pembayaran baru menunggu verifikasi
            $pay_info = [
                'id'             => $pay_id,
                'block'          => $bill['block'] ?? '',
                'unit_number'    => $bill['unit_number'] ?? '',
                'period'         => $bill['period'] ?? '',
                'amount_paid'    => $amount_paid,
                'payment_method' => $pm_name,
                'payment_date'   => $payment_date,
            ];
            notify_admin_new_payment($pay_info, $bill['resident_name'] ?? '');
        }

        log_activity('create','payments',"Payment #{$pay_id} for bill #{$sel_bill_id}");
        flash('success','Pembayaran berhasil dicatat.' . ($is_cash ? ' Status tagihan diperbarui.' : ' Menunggu verifikasi.'));
        redirect(APP_URL . '/pages/payments/index.php');
    }
}

$page_title = 'Catat Pembayaran';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-cash-coin me-1 text-success"></i> Catat Pembayaran</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
    <?php endif; ?>

    <div class="card" style="max-width:680px">
      <div class="card-header"><i class="bi bi-cash-coin me-1 text-success"></i> Form Pembayaran IPL</div>
      <div class="card-body">
        <?php if ($bill): ?>
          <div class="alert alert-info py-2 mb-3">
            <strong>Tagihan:</strong> <?= e($bill['block'].'-'.$bill['unit_number']) ?>
            — <?= e($bill['period']) ?>
            — <?= e($bill['resident_name'] ?? '-') ?>
            — <strong><?= idr((float)$bill['total_amount']) ?></strong>
          </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="row g-3">
            <?php if (!$bill): ?>
            <div class="col-12">
              <label class="form-label">Tagihan <span class="text-danger">*</span></label>
              <select name="bill_id" class="form-select" required>
                <option value="">— Pilih Tagihan —</option>
                <?php foreach ($unpaid_bills as $ub): ?>
                  <option value="<?= $ub['id'] ?>">
                    <?= e($ub['block'].'-'.$ub['unit_number']) ?> | <?= e($ub['period']) ?>
                    | <?= e($ub['resident_name'] ?? 'Kosong') ?> | <?= idr((float)$ub['total_amount']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
              <input type="hidden" name="bill_id" value="<?= $bill['id'] ?>">
            <?php endif; ?>

            <div class="col-md-6">
              <label class="form-label">Tanggal Bayar <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" class="form-control" required
                     value="<?= e($_POST['payment_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Jumlah Bayar (Rp) <span class="text-danger">*</span></label>
              <input type="text" name="amount_paid" class="form-control" required
                     value="<?= e($_POST['amount_paid'] ?? ($bill ? number_format((float)$bill['total_amount'],0,',','.') : '')) ?>"
                     placeholder="Cth: 300.000">
            </div>
            <div class="col-md-6">
              <label class="form-label">Metode Pembayaran <span class="text-danger">*</span></label>
              <select name="payment_method_id" class="form-select" id="payMethod" required>
                <option value="">— Pilih Metode —</option>
                <?php foreach ($payment_methods as $pm): ?>
                  <option value="<?= $pm['id'] ?>" data-code="<?= e($pm['code']) ?>" data-instructions="<?= e($pm['instructions'] ?? '') ?>" <?= (int)($_POST['payment_method_id'] ?? 0) === (int)$pm['id'] ? 'selected' : '' ?>><?= e($pm['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div id="paymentInstructions" class="form-text"></div>
            </div>
            <div class="col-md-6" id="bankNameField">
              <label class="form-label">Nama Bank</label>
              <input type="text" name="bank_name" class="form-control"
                     value="<?= e($_POST['bank_name'] ?? '') ?>" placeholder="Cth: BCA, Mandiri">
            </div>
            <div class="col-md-6">
              <label class="form-label">No. Referensi / Kode Bayar</label>
              <input type="text" name="reference_no" class="form-control"
                     value="<?= e($_POST['reference_no'] ?? '') ?>" placeholder="Opsional">
            </div>
            <div class="col-md-6">
              <label class="form-label">Bukti Bayar <small class="text-muted">(JPG/PNG/PDF, maks 2MB)</small></label>
              <input type="file" name="proof_file" id="proof_file" class="form-control"
                     accept="image/jpeg,image/png,image/webp,application/pdf">
              <img id="proofPreview" src="" class="mt-2 img-thumbnail d-none" style="max-height:120px">
            </div>
            <div class="col-12">
              <label class="form-label">Catatan</label>
              <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            <a href="index.php" class="btn btn-outline-secondary">Batal</a>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
const payMethod = document.getElementById('payMethod');
const bankField = document.getElementById('bankNameField');
const instrBox  = document.getElementById('paymentInstructions');
function selectedCode() {
  const opt = payMethod && payMethod.options[payMethod.selectedIndex];
  return opt ? (opt.dataset.code || '') : '';
}
function toggleBank() {
  // tampilkan nama bank hanya untuk metode non-tunai
  bankField.style.display = selectedCode() === 'tunai' ? 'none' : '';
}
function toggleInstructions() {
  const opt = payMethod && payMethod.options[payMethod.selectedIndex];
  const text = opt ? (opt.dataset.instructions || '') : '';
  if (text) { instrBox.textContent = text; instrBox.classList.remove('d-none'); }
  else { instrBox.textContent = ''; instrBox.classList.add('d-none'); }
}
payMethod && payMethod.addEventListener('change', () => { toggleBank(); toggleInstructions(); });
toggleBank();
toggleInstructions();
</script>
