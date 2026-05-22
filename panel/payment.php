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
  $where[] = "(`id_user` LIKE ? OR `id_order` LIKE ?)";
  $params = ["%$search%", "%$search%"];
}
if ($status !== '') {
  $where[] = "payment_Status = ?";
  $params[] = $status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSQL = "ORDER BY time DESC";

try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM Payment_report $whereSQL", $params);
  $payments = db_fetchAll($pdo, "SELECT * FROM Payment_report $whereSQL $orderSQL LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $payments = [];
  flash('error', 'خطای پایگاه داده در خواندن تراکنش‌ها: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$totalSuccess = 0;
$todayCount = 0;
try {
  $totalSuccess = (int) db_query($pdo, "SELECT COALESCE(SUM(price),0) FROM Payment_report WHERE payment_Status ='paid'")->fetchColumn();
  $todayCount = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE time > ?", [strtotime('today')]);
} catch (Exception $e) {
}

$statusMap = [
  'paid' => ['tag-ok', 'پرداخت شده'],
  'Unpaid' => ['tag-no', 'پرداخت نشده'],
  'expire' => ['tag-plain', 'منقضی'],
  'reject' => ['tag-no', 'رد شده'],
  'waiting' => ['tag-warn', 'در انتظار'],
];
$methodMap = [
  'cart to cart' => 'کارت به کارت',
  'low balance by admin' => 'کسر موجودی ادمین',
  'add balance by admin' => 'افزایش توسط ادمین',
  'Currency Rial 1' => 'درگاه ریالی ۱',
  'Currency Rial tow' => 'درگاه ریالی ۲',
  'Currency Rial 3' => 'درگاه ریالی ۳',
  'aqayepardakht' => 'آقای پرداخت',
  'zarinpal' => 'زرین‌پال',
  'plisio' => 'Plisio',
  'arze digital offline' => 'ارز دیجیتال آفلاین',
  'Star Telegram' => 'استار تلگرام',
  'nowpayment' => 'NowPayment',
];

$pageTitle = 'تراکنش‌ها';
$pageLede = 'گزارش تمام تراکنش‌های مالی پنل.';
$activeNav = 'payment';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px">
  <div class="stat success">
    <div class="stat-label">جمع تراکنش‌های موفق</div>
    <div class="stat-num"><?= number_format($totalSuccess) ?><small>تومان</small></div>
    <div class="stat-meta">از ابتدای فعالیت</div>
  </div>
  <div class="stat">
    <div class="stat-label">تعداد کل</div>
    <div class="stat-num"><?= number_format($total) ?></div>
    <div class="stat-meta">رکورد تراکنش</div>
  </div>
  <div class="stat warn">
    <div class="stat-label">امروز</div>
    <div class="stat-num"><?= number_format($todayCount) ?></div>
    <div class="stat-meta">تراکنش جدید امروز</div>
  </div>
</div>

<div class="card">
  <div class="toolbar">
    <div class="toolbar-title">گزارش تراکنش‌ها <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" class="toolbar-end">
      <select name="status" class="select" style="width:auto" onchange="this.form.submit()">
        <option value="">همه وضعیت‌ها</option>
        <?php foreach ($statusMap as $k => [$_, $lbl]): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <div class="search-box" style="min-width:230px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="آیدی کاربر یا شماره تراکنش..."
          value="<?= htmlspecialchars($search) ?>">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search || $status): ?>
        <a href="payment.php" class="btn-link" style="font-size:.78rem">پاک</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>شناسه تراکنش</th>
          <th>مبلغ</th>
          <th>روش پرداخت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($payments)): ?>
          <tr>
            <td>
              <div class="empty">
                <div class="empty-mark">—</div>
                <p>تراکنشی یافت نشد</p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($payments as $p):
            $st = $p['payment_Status'] ?? '';
            [$cls, $lbl] = $statusMap[$st] ?? ['tag-plain', $st ?: '—'];
            $methodRaw = $p['Payment_Method'] ?? '';
            $method = $methodMap[$methodRaw] ?? ($methodRaw ?: '—');
            ?>
            <tr>
              <td style="color:var(--text-dim)"><?= $i++ ?></td>
              <td class="cell-mono"><?= htmlspecialchars($p['id_user'] ?? '—') ?></td>
              <td class="cell-mono" style="color:var(--accent)">
                <?= htmlspecialchars(trunc((string) ($p['id_order'] ?? '—'), 18)) ?>
              </td>
              <td class="cell-strong cell-num"><?= number_format((int) ($p['price'] ?? 0)) ?> <span
                  style="color:var(--text-dim);font-weight:400;font-size:.72rem">ت</span></td>
              <td style="font-size:.8rem"><?= htmlspecialchars($method) ?></td>
              <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap">
                <?= safe_date($p['time'] ?? null, 'Y/m/d H:i') ?>
              </td>
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
      <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p2 = max(1, $page - 2); $p2 <= min($totalPages, $page + 2); $p2++): ?>
        <a class="<?= $p2 === $page ? 'active' : '' ?>" href="<?= $qs($p2) ?>"><?= $p2 ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>