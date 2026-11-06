<?php
/**
 * GET /api/bot/cash_summary.php
 * Ringkasan kas lingkungan dan rincian pengeluaran untuk transparansi warga.
 *
 * Mendukung kustomisasi bulan & tahun:
 * - year   (int/string): tahun (contoh: 2026)
 * - month  (int/string): bulan angka 1-12 ATAU nama bulan (contoh: 10, "oktober", "october", "okt")
 * - period (string): format gabungan (contoh: "2026-10", "Oktober 2026")
 * - q      (string): kata kunci pencarian pengeluaran (contoh: "lampu", "sampah", "satpam")
 * - category (string): filter kategori pengeluaran
 * - limit  (int): batas jumlah item pengeluaran (default: 30, max: 100)
 */
require __DIR__ . '/_bootstrap.php';

$ctx = bot_boot(['GET']);
$db  = $ctx['db'];

// Saldo kumulatif kas sepanjang masa
$all = $db->query(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
       FROM cash_book'
)->fetch_assoc();

// Normalisasi bulan dan tahun yang fleksibel (bisa angka atau teks)
function bot_parse_filter_period(?string $rawMonth, ?string $rawYear, ?string $rawPeriod): array
{
    $y = (int) date('Y');
    $m = (int) date('n');

    $monthMap = [
        'januari' => 1, 'jan' => 1, 'january' => 1,
        'februari' => 2, 'feb' => 2, 'february' => 2,
        'maret' => 3, 'mar' => 3, 'march' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'jun' => 6, 'june' => 6,
        'juli' => 7, 'jul' => 7, 'july' => 7,
        'agustus' => 8, 'ags' => 8, 'agu' => 8, 'august' => 8, 'aug' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'desember' => 12, 'des' => 12, 'december' => 12, 'dec' => 12,
    ];

    if (!empty($rawPeriod)) {
        $p = strtolower(trim($rawPeriod));
        if (preg_match('/^(\d{4})[_\-\/](\d{1,2})$/', $p, $mArr)) {
            $y = (int) $mArr[1];
            $m = (int) $mArr[2];
        } elseif (preg_match('/^(\d{1,2})[_\-\/](\d{4})$/', $p, $mArr)) {
            $m = (int) $mArr[1];
            $y = (int) $mArr[2];
        } else {
            foreach ($monthMap as $name => $num) {
                if (str_contains($p, $name)) {
                    $m = $num;
                    if (preg_match('/\b(20\d{2})\b/', $p, $mArr)) {
                        $y = (int) $mArr[1];
                    }
                    break;
                }
            }
        }
    }

    if (!empty($rawYear) && is_numeric($rawYear)) {
        $candY = (int) $rawYear;
        if ($candY >= 2020 && $candY <= 2100) $y = $candY;
    }

    if (!empty($rawMonth)) {
        $cleanM = strtolower(trim($rawMonth));
        if (is_numeric($cleanM)) {
            $candM = (int) $cleanM;
            if ($candM >= 1 && $candM <= 12) $m = $candM;
        } elseif (isset($monthMap[$cleanM])) {
            $m = $monthMap[$cleanM];
        }
    }

    return [$y, $m];
}

[$y, $m] = bot_parse_filter_period(
    $_GET['month'] ?? null,
    $_GET['year'] ?? null,
    $_GET['period'] ?? null
);

$q = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 30)));

// 1. Arus kas (pemasukan & pengeluaran) pada periode yang dipilih
$st = $db->prepare(
    'SELECT COALESCE(SUM(CASE WHEN type="pemasukan"   THEN amount END),0) AS masuk,
            COALESCE(SUM(CASE WHEN type="pengeluaran" THEN amount END),0) AS keluar
       FROM cash_book WHERE YEAR(trx_date)=? AND MONTH(trx_date)=?'
);
$st->bind_param('ii', $y, $m);
$st->execute();
$monthTotals = $st->get_result()->fetch_assoc();

// 2. Rincian pengeluaran per kategori untuk periode yang dipilih
$catSql = 'SELECT category, SUM(amount) AS total, COUNT(*) AS count
             FROM cash_book
            WHERE type="pengeluaran" AND YEAR(trx_date)=? AND MONTH(trx_date)=?';
$catParams = [$y, $m];
$catTypes = 'ii';

if ($category !== '') {
    $catSql .= ' AND category = ?';
    $catParams[] = $category;
    $catTypes .= 's';
}
if ($q !== '') {
    $catSql .= ' AND (category LIKE ? OR description LIKE ?)';
    $like = "%$q%";
    $catParams[] = $like;
    $catParams[] = $like;
    $catTypes .= 'ss';
}
$catSql .= ' GROUP BY category ORDER BY total DESC';

$stCat = $db->prepare($catSql);
$stCat->bind_param($catTypes, ...$catParams);
$stCat->execute();
$resCat = $stCat->get_result();
$expensesByCategory = [];
while ($row = $resCat->fetch_assoc()) {
    $expensesByCategory[] = [
        'category' => $row['category'],
        'count'    => (int) $row['count'],
        'total'    => bot_money((float) $row['total']),
    ];
}

// 3. Daftar transaksi pengeluaran pada periode yang dipilih
$expSql = 'SELECT id, category, amount, description, trx_date
             FROM cash_book
            WHERE type="pengeluaran" AND YEAR(trx_date)=? AND MONTH(trx_date)=?';
$expParams = [$y, $m];
$expTypes = 'ii';

if ($category !== '') {
    $expSql .= ' AND category = ?';
    $expParams[] = $category;
    $expTypes .= 's';
}
if ($q !== '') {
    $expSql .= ' AND (category LIKE ? OR description LIKE ?)';
    $like = "%$q%";
    $expParams[] = $like;
    $expParams[] = $like;
    $expTypes .= 'ss';
}
$expSql .= ' ORDER BY trx_date DESC, id DESC LIMIT ?';
$expParams[] = $limit;
$expTypes .= 'i';

$stExp = $db->prepare($expSql);
$stExp->bind_param($expTypes, ...$expParams);
$stExp->execute();
$resExp = $stExp->get_result();
$periodExpenses = [];
while ($row = $resExp->fetch_assoc()) {
    $periodExpenses[] = [
        'date'        => $row['trx_date'],
        'category'    => $row['category'],
        'description' => $row['description'] ?? '',
        'amount'      => bot_money((float) $row['amount']),
    ];
}

// 4. Fallback jika periode yang dipilih belum memiliki pengeluaran (misal bulan berjalan baru mulai),
// sertakan 5 pengeluaran terakhir dari bulan sebelumnya agar bot tetap informatif
$recentFallback = [];
if (empty($periodExpenses)) {
    $stRec = $db->prepare(
        'SELECT id, category, amount, description, trx_date
           FROM cash_book
          WHERE type="pengeluaran"
          ORDER BY trx_date DESC, id DESC
          LIMIT 5'
    );
    $stRec->execute();
    $resRec = $stRec->get_result();
    while ($row = $resRec->fetch_assoc()) {
        $recentFallback[] = [
            'date'        => $row['trx_date'],
            'category'    => $row['category'],
            'description' => $row['description'] ?? '',
            'amount'      => bot_money((float) $row['amount']),
        ];
    }
}

$hasCustomFilter = isset($_GET['year']) || isset($_GET['month']) || isset($_GET['period']) || isset($_GET['q']) || isset($_GET['category']);

bot_reply(200, [
    'as_of'                      => date('Y-m-d H:i'),
    'balance'                    => bot_money((float) $all['masuk'] - (float) $all['keluar']),
    'is_custom_filter'           => $hasCustomFilter,
    'period'                     => [
        'year'  => $y,
        'month' => $m,
        'label' => period_label($y, $m),
    ],
    'month_label'                => period_label($y, $m),
    'month_income'               => bot_money((float) $monthTotals['masuk']),
    'month_expense'              => bot_money((float) $monthTotals['keluar']),
    'month_expenses_by_category' => $expensesByCategory,
    'expenses'                   => $periodExpenses,
    'expense_count'              => count($periodExpenses),
    'recent_expenses'            => !empty($periodExpenses) ? $periodExpenses : $recentFallback,
]);
