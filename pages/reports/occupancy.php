<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../../includes/functions.php';
require_permission('reports.view');

$db = db();
$f_block = clean($_GET['block'] ?? '');
$f_type = (int)($_GET['type'] ?? 0);
$f_status = clean($_GET['status'] ?? '');
$f_q = clean($_GET['q'] ?? '');
$f_view = in_array($_GET['view'] ?? 'grid', ['grid','table'], true) ? $_GET['view'] : 'grid';
if (!in_array($f_status, ['', 'dihuni','kosong','dijual'], true)) $f_status = '';

$where = '1=1';
$params = [];
$types = '';
if ($f_block !== '') { $where .= ' AND u.block = ?'; $params[] = $f_block; $types .= 's'; }
if ($f_type > 0) { $where .= ' AND u.unit_type_id = ?'; $params[] = $f_type; $types .= 'i'; }
if ($f_status !== '') { $where .= ' AND u.status = ?'; $params[] = $f_status; $types .= 's'; }
if ($f_q !== '') { $where .= ' AND (u.unit_number LIKE ? OR u.block LIKE ? OR r.name LIKE ?)'; $like = "%{$f_q}%"; array_push($params, $like, $like, $like); $types .= 'sss'; }

$sql = "SELECT u.block, u.unit_number, u.status AS unit_status, u.area_sqm, ut.name AS type_name, r.name AS resident_name, r.phone, r.status AS resident_status, r.is_active FROM units u JOIN unit_types ut ON ut.id = u.unit_type_id LEFT JOIN residents r ON r.unit_id = u.id AND r.is_active = 1 WHERE {$where} ORDER BY u.block, u.unit_number";
$stmt = $db->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$blocks = $db->query('SELECT DISTINCT block FROM units ORDER BY block')->fetch_all(MYSQLI_ASSOC);
$type_list = $db->query('SELECT id, name FROM unit_types ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$c = ['dihuni'=>0,'kosong'=>0,'dijual'=>0];
foreach ($rows as $r) $c[$r['unit_status']]++;
$total = count($rows);

$by_block = [];
foreach ($rows as $r) $by_block[$r['block']][] = $r;

$cls = ['dihuni'=>'success','kosong'=>'warning','dijual'=>'info'];
$lbl = ['dihuni'=>'Dihuni','kosong'=>'Kosong','dijual'=>'Dijual'];

$page_title = 'Laporan Data Unit';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
$qs = fn($v) => http_build_query(array_merge($_GET, ['view'=>$v]));
$ex = http_build_query(array_filter(['block'=>$f_block,'type'=>$f_type?:null,'status'=>$f_status,'q'=>$f_q]));
?>
<div class="content-wrapper">
  <div class="topbar d-flex align-items-center px-3 gap-2">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggler"><i class="bi bi-list fs-5"></i></button>
    <h6 class="mb-0 fw-semibold"><i class="bi bi-grid-3x3-gap me-1 text-success"></i> Laporan Data Unit</h6>
  </div>
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="main-content">
    <?= render_flash() ?>
    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="fw-bold fs-4"><?= $total ?></div><div class="text-muted small">Total Unit</div></div></div>
      <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="fw-bold fs-4 text-success"><?= $c['dihuni'] ?></div><div class="text-muted small">Dihuni</div></div></div>
      <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="fw-bold fs-4 text-warning"><?= $c['kosong'] ?></div><div class="text-muted small">Kosong</div></div></div>
      <div class="col-6 col-md-3"><div class="card text-center py-2"><div class="fw-bold fs-4 text-info"><?= $c['dijual'] ?></div><div class="text-muted small">Dijual</div></div></div>
    </div>
    <div class="card mb-3"><div class="card-body py-2">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2"><select name="block" class="form-select form-select-sm"><option value="">Semua Blok</option><?php foreach ($blocks as $b): ?><option value="<?= e($b['block']) ?>" <?= $b['block']===$f_block?'selected':'' ?>><?= e($b['block']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select name="type" class="form-select form-select-sm"><option value="0">Semua Tipe</option><?php foreach ($type_list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id']===$f_type?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Semua Status</option><?php foreach ($lbl as $k=>$v): ?><option value="<?= $k ?>" <?= $k===$f_status?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><input type="text" name="q" class="form-control form-control-sm" placeholder="Cari unit/nama warga..." value="<?= e($f_q) ?>"></div>
        <div class="col-auto d-flex gap-2">
          <button class="btn btn-sm btn-success"><i class="bi bi-search me-1"></i>Cari</button>
          <a href="occupancy.php" class="btn btn-sm btn-outline-secondary">Reset</a>
          <a href="occupancy.php?<?= $qs('grid') ?>" class="btn btn-sm <?= $f_view==='grid'?'btn-success':'btn-outline-success' ?>"><i class="bi bi-grid me-1"></i>Grid</a>
          <a href="occupancy.php?<?= $qs('table') ?>" class="btn btn-sm <?= $f_view==='table'?'btn-success':'btn-outline-success' ?>"><i class="bi bi-table me-1"></i>Tabel</a>
          <a href="occupancy_export.php?<?= e($ex) ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV</a>
          <a href="occupancy_export.php?<?= e($ex) ?>&format=xlsx" class="btn btn-sm btn-danger"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak</button>
        </div>
      </form>
    </div></div>
    <?php if ($f_view === 'grid'): ?>
      <?php if (empty($by_block)): ?><div class="alert alert-light text-center text-muted">Tidak ada unit.</div><?php endif; ?>
      <?php foreach ($by_block as $blk => $units): ?>
      <div class="card mb-3"><div class="card-header"><strong>Blok <?= e($blk) ?></strong> <span class="badge bg-secondary ms-1"><?= count($units) ?> unit</span></div>
        <div class="card-body"><div class="d-flex flex-wrap gap-2">
          <?php foreach ($units as $u): ?>
          <div class="border rounded px-2 py-1 bg-<?= $cls[$u['unit_status']] ?> bg-opacity-10 border-<?= $cls[$u['unit_status']] ?>" style="min-width:150px" title="<?= e(($u['resident_name'] ?? '-') . ' • ' . $u['type_name']) ?>">
            <div class="fw-bold"><?= e($u['block'].'-'.$u['unit_number']) ?> <span class="badge bg-<?= $cls[$u['unit_status']] ?>"><?= $lbl[$u['unit_status']] ?></span></div>
            <small class="text-muted"><?= e($u['type_name']) ?> • <?= e($u['resident_name'] ?? '—') ?></small>
          </div>
          <?php endforeach; ?>
        </div></div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="card"><div class="card-header"><i class="bi bi-table me-1 text-success"></i> Daftar Unit <span class="badge bg-secondary ms-2"><?= $total ?> unit</span></div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
        <thead><tr><th>#</th><th>Unit</th><th>Tipe</th><th>Status Unit</th><th>Warga</th><th>No. HP</th><th>Status Huni</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center text-muted py-4">Tidak ada unit.</td></tr>
        <?php else: foreach ($rows as $i => $r): ?>
          <tr><td><?= $i+1 ?></td><td><strong><?= e($r['block'].'-'.$r['unit_number']) ?></strong></td><td><?= e($r['type_name']) ?></td>
          <td><span class="badge bg-<?= $cls[$r['unit_status']] ?>"><?= $lbl[$r['unit_status']] ?></span></td>
          <td><?= e($r['resident_name'] ?? '-') ?></td><td><?= e($r['phone'] ?? '-') ?></td>
          <td><?= $r['resident_name'] ? e(($r['resident_status'] ?? '-') . ((int)($r['is_active'] ?? 1) ? '' : ' (nonaktif)')) : '-' ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<style>@media print{.sidebar,.topbar,form,.btn,.no-print{display:none!important}.content-wrapper{margin-left:0!important;width:100%!important}}</style>
