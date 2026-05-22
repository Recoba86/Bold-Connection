<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$pageTitle = 'سرویس‌ها';
$pageLede = 'سرویس‌های دستی و تراکنش‌های سرویس.';
$activeNav = 'service_other';

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(id_user LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(type,'') LIKE ?)";
  $params = ["%$search%", "%$search%", "%$search%"];
}
if ($status !== '') {
  $where[] = "status = ?";
  $params[] = $status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM service_other $whereSQL", $params);
  $services = db_fetchAll($pdo, "SELECT * FROM service_other $whereSQL ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $services = [];
  error_log('service.php error: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$typeMap = [
  'change_location' => 'تغییر لوکیشن',
  'extra_user' => 'افزایش حجم',
  'extra_time_user' => 'افزایش زمان',
  'extends_not_user' => "تمدید ",
  'extend_user' => "تمدید ",
  'transfertouser' => "انتقال سفارش به کاربر دیگر"
];

$pageTitle = 'سرویس‌ها';
$pageLede = 'تراکنش‌های سرویس دستی کاربران.';
$activeNav = 'service';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title">سرویس‌ها <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" id="srvForm" class="toolbar-end">
      <select name="status" class="select" style="width:auto" onchange="document.getElementById('srvForm').submit()">
        <option value="">همه وضعیت‌ها</option>
        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>انجام شده</option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>در انتظار</option>
        <option value="reject" <?= $status === 'reject' ? 'selected' : '' ?>>رد شده</option>
      </select>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="آیدی کاربر، یوزرنیم، نوع..." value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn">جستجو</button>
      </div>
      <?php if ($search || $status): ?>
        <a href="service.php" class="btn-link" style="font-size:.78rem">پاک</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th>کاربر</th>
          <th>یوزرنیم</th>
          <th>نوع</th>
          <th>مقدار</th>
          <th>قیمت</th>
          <th>تاریخ</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($services)): ?>
          <tr>
            <td colspan="8">
              <div class="empty">
                <svg class="ill" viewBox="0 0 180 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="30" width="120" height="80" rx="10" fill="var(--sf3)" />
                  <rect x="50" y="50" width="40" height="40" rx="6" fill="var(--bds)" />
                  <rect x="100" y="55" width="35" height="8" rx="4" fill="var(--bd)" />
                  <rect x="100" y="70" width="25" height="8" rx="4" fill="var(--bd)" />
                  <rect x="100" y="85" width="30" height="8" rx="4" fill="var(--bd)" />
                  <path d="M60 65 l10 10 l20-20" stroke="var(--ac)" stroke-width="3" stroke-linecap="round" fill="none" />
                </svg>
                <p><?= $search ? 'سرویسی یافت نشد' : 'هنوز سرویس دستی ثبت نشده' ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($services as $s):
            $stMap = [
              'done' => ['tag-ok', 'انجام شده'],
              'pending' => ['tag-warn', 'در انتظار'],
              'reject' => ['tag-no', 'رد شده'],
            ];
            [$cls, $lbl] = $stMap[$s['status'] ?? ''] ?? ['tag-plain', $s['status'] ?? '—'];
            $typeLabel = $typeMap[$s['type'] ?? ''] ?? ($s['type'] ?? '—');
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars($s['id_user'] ?? '—') ?></td>
              <td>
                <?= !empty($s['username']) ? '<span class="cm" style="color:var(--ac)">@' . htmlspecialchars(trunc($s['username'], 18)) . '</span>' : '<span class="cf">—</span>' ?>
              </td>
              <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($typeLabel) ?></td>
              <td class="cn" style="font-size:.82rem"><?= htmlspecialchars(trunc($s['value'] ?? '—', 20)) ?></td>
              <td class="cn cs"><?= number_format((int) ($s['price'] ?? 0)) ?> <span class="cf">ت</span></td>
              <td class="cf"><?= safe_date($s['time'] ?? null, 'Y/m/d') ?></td>
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