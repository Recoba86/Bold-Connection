<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$search = trim($_GET['q'] ?? '');

$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(id_user LIKE ? OR COALESCE(name_product,'') LIKE ? OR COALESCE(username,'') LIKE ?)";
  $params = ["%$search%", "%$search%", "%$search%"];
}
if ($status !== '') {

  $where[] = "Status = ?";
  $params[] = $status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM invoice $whereSQL", $params);
  $invoices = db_fetchAll($pdo, "SELECT * FROM invoice $whereSQL ORDER BY time_sell DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $invoices = [];
  flash('error', 'خطای پایگاه داده: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$statusMap = [
  'active' => ['tag-ok', 'فعال'],
  'end_of_time' => ['tag-warn', 'اعلان پایان زمان'],
  'end_of_volume' => ['tag-no', 'اعلان پایان حجم'],
  'sendedwarn' => ['tag-warn', 'ارسال تمامی اعلان ها'],
  'send_on_hold' => ['tag-plain', 'اعلان متصنل نشدن ارسال شده'],
  'unpaid' => ['tag-plain', 'پرداخت نشده'],
  'Unsuccessful' => ['tag-plain', 'خطا دریافت اطلاعات'],
];

$pageTitle = 'سفارشات';
$pageLede = 'فهرست کلیه سفارشات ثبت‌شده در ربات.';
$activeNav = 'invoice';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title">سفارشات <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" id="invoiceForm" class="toolbar-end">
      <select name="status" class="select" style="width:auto"
        onchange="document.getElementById('invoiceForm').submit()">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="آیدی کاربر، نام محصول..." value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search || $status): ?>
        <a href="invoice.php" class="btn-link" style="font-size:.78rem">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>محصول</th>
          <th>قیمت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr>
            <td colspan="6">
              <div class="empty">
                <svg class="ill" viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="15" width="100" height="90" rx="8" fill="var(--sf3)" />
                  <rect x="45" y="35" width="70" height="8" rx="4" fill="var(--bds)" />
                  <rect x="45" y="52" width="50" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="66" width="60" height="6" rx="3" fill="var(--bd)" />
                  <rect x="45" y="80" width="35" height="6" rx="3" fill="var(--bd)" />
                </svg>
                <p><?= $search ? 'سفارشی با این جستجو یافت نشد' : 'هنوز سفارشی ثبت نشده' ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($invoices as $inv):
            $st = $inv['Status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars($inv['id_user'] ?? '—') ?></td>
              <td class="cs"><?= htmlspecialchars(trunc($inv['name_product'] ?? '—', 28)) ?></td>
              <td class="cn cs"><?= number_format((int) ($inv['price_product'] ?? 0)) ?> <span class="cf">ت</span></td>
              <td class="cf"><?= safe_date($inv['time_sell'] ?? null, 'Y/m/d') ?></td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> رکورد · صفحه <?= $page ?> از <?= $totalPages ?></span>
    <div class="pager">
      <?php $qs = fn($p) => '?q=' . urlencode($search) . '&status=' . urlencode($status) . '&page=' . $p; ?>
      <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>