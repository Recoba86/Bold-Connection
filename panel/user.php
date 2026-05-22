<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: users.php');
    exit;
}

$user = db_fetch($pdo, "SELECT * FROM user WHERE id = ?", [$id]);
if (!$user) {
    flash('error', 'کاربر یافت نشد.');
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_balance') {
        $amount = (int) ($_POST['amount'] ?? 0);
        if ($amount >= 1000) {
            db_query($pdo, "UPDATE user SET Balance = Balance + ? WHERE id = ?", [$amount, $id]);
            flash('success', number_format($amount) . ' تومان به موجودی افزوده شد.');
        } else {
            flash('error', 'حداقل مبلغ ۱٬۰۰۰ تومان است.');
        }
    } elseif ($action === 'set_role') {
        $newRole = $_POST['new_role'] ?? 'f';
        if (in_array($newRole, ['f', 'n', 'n2', 'all'], true)) {
            db_query($pdo, "UPDATE user SET agent = ? WHERE id = ?", [$newRole, $id]);
            flash('success', 'گروه کاربری به «' . user_role_label($newRole) . '» تغییر کرد.');
        }
    }

    header("Location: user.php?id=$id");
    exit;
}

$invoices = [];
$payments = [];
$referrals = [];

try {
    $invoices = db_fetchAll($pdo, "SELECT * FROM invoice WHERE id_user = ? ORDER BY time_sell DESC LIMIT 30", [$id]);
} catch (Exception $e) {
}

try {
    $payments = db_fetchAll($pdo, "SELECT * FROM Payment_report WHERE id_user = ? ORDER BY time DESC LIMIT 20", [$id]);
} catch (Exception $e) {
}

try {
    $referrals = db_fetchAll($pdo, "SELECT id, username, namecustom, Balance, register, agent FROM user WHERE affiliates = ? ORDER BY register DESC LIMIT 20", [$id]);
} catch (Exception $e) {
}

$balance = (int) ($user['Balance'] ?? 0);
$totalSpent = array_sum(array_column($invoices, 'price_product'));
$activeServices = count(array_filter($invoices, fn($inv) => ($inv['Status'] ?? '') === 'active'));
$expiredServices = count(array_filter($invoices, fn($inv) => in_array($inv['Status'] ?? '', ['end_of_time', 'end_of_volume', 'expired'])));
$paidCount = count(array_filter($payments, fn($p) => in_array($p['payment_Status'] ?? '', ['paid', 'success'])));
$convRate = count($payments) > 0 ? round($paidCount / count($payments) * 100) : 0;

$agent = $user['agent'] ?? 'f';
$isBlocked = ($user['User_Status'] ?? '') === 'block';
$fullName = $user['namecustom'] ?? '';
if ($fullName === 'none')
    $fullName = '';
$username = $user['username'] ?? '';
if ($username === 'none')
    $username = '';
$initials = mb_strtoupper(mb_substr($fullName ?: ($username ?: 'U'), 0, 1, 'UTF-8'), 'UTF-8');

$pageTitle = $fullName ?: ($username ? '@' . $username : 'کاربر #' . $id);
$activeNav = 'users';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px"
    class="fade-up">
    <a href="users.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> فهرست کاربران</a>
    <?php if ($username): ?>
        <a href="https://t.me/<?= htmlspecialchars($username) ?>" target="_blank" rel="noopener"
            class="btn btn-ghost btn-sm">
            <?= icon('eye', 13) ?> تلگرام
        </a>
    <?php endif; ?>
</div>

<div class="stats u-stats fade-up" style="margin-bottom:18px">
    <div class="stat fade-up">
        <div class="stat-label">موجودی</div>
        <div class="stat-num"><?= number_format($balance) ?><small>ت</small></div>
        <div class="stat-meta">کیف پول</div>
    </div>
    <div class="stat ok fade-up d1">
        <div class="stat-label">مجموع خرید</div>
        <div class="stat-num">
            <?= $totalSpent >= 1_000_000
                ? number_format($totalSpent / 1_000_000, 1) . '<small>M ت</small>'
                : number_format($totalSpent) . '<small>ت</small>' ?>
        </div>
        <div class="stat-meta"><?= count($invoices) ?> سفارش</div>
    </div>
    <div class="stat warn fade-up d2">
        <div class="stat-label">سرویس فعال</div>
        <div class="stat-num"><?= $activeServices ?></div>
        <div class="stat-meta"><?= $expiredServices ?> منقضی</div>
    </div>
    <div class="stat fade-up d3">
        <div class="stat-label">نرخ پرداخت</div>
        <div class="stat-num"><?= $convRate ?>%</div>
        <div class="stat-meta"><?= $paidCount ?> موفق از <?= count($payments) ?></div>
    </div>
</div>

<div class="profile-grid u-profile-grid">

    <div class="u-sidebar" style="display:flex;flex-direction:column;gap:12px">

        <div class="card fade-up">
            <div class="profile-head">
                <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
                <div class="profile-name"><?= htmlspecialchars($fullName ?: 'بدون نام') ?></div>
                <?php if ($username): ?>
                    <div class="profile-handle">@<?= htmlspecialchars($username) ?></div>
                <?php endif; ?>
                <div style="margin-top:10px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
                    <span class="tag <?= $isBlocked ? 'tag-no' : 'tag-ok' ?>">
                        <?= $isBlocked ? 'مسدود' : 'فعال' ?>
                    </span>
                    <span class="tag <?= user_role_tag($agent) ?>">
                        <?= user_role_label($agent) ?>
                    </span>
                </div>
            </div>

            <div class="kv-list">
                <div class="kv">
                    <span class="kv-key">آیدی تلگرام</span>
                    <span class="kv-val cm"><?= htmlspecialchars($user['id']) ?></span>
                </div>
                <?php if ($fullName): ?>
                    <div class="kv">
                        <span class="kv-key">نام سفارشی</span>
                        <span class="kv-val"><?= htmlspecialchars($fullName) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($user['number']) && $user['number'] !== 'none'): ?>
                    <div class="kv">
                        <span class="kv-key">شماره</span>
                        <span class="kv-val cm"><?= htmlspecialchars($user['number']) ?></span>
                    </div>
                <?php endif; ?>
                <div class="kv">
                    <span class="kv-key">موجودی</span>
                    <span class="kv-val" style="color:var(--ac)"><?= number_format($balance) ?> ت</span>
                </div>
                <div class="kv">
                    <span class="kv-key">گروه کاربری</span>
                    <span class="kv-val">
                        <span class="tag <?= user_role_tag($agent) ?>"><?= user_role_label($agent) ?></span>
                        <span class="cm cf"
                            style="margin-right:6px;font-size:.72rem"><?= htmlspecialchars($agent) ?></span>
                    </span>
                </div>
                <div class="kv">
                    <span class="kv-key">ثبت‌نام</span>
                    <span class="kv-val"><?= safe_date($user['register'] ?? null) ?></span>
                </div>
                <?php if (!empty($user['affiliates']) && $user['affiliates'] !== '0'): ?>
                    <div class="kv">
                        <span class="kv-key">معرف</span>
                        <span class="kv-val cm" style="color:var(--ac)"><?= htmlspecialchars($user['affiliates']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ((int) ($user['affiliatescount'] ?? 0) > 0): ?>
                    <div class="kv">
                        <span class="kv-key">زیرمجموعه</span>
                        <span class="kv-val"><?= number_format((int) $user['affiliatescount']) ?> نفر</span>
                    </div>
                <?php endif; ?>
                <?php if ((int) ($user['score'] ?? 0) > 0): ?>
                    <div class="kv">
                        <span class="kv-key">امتیاز</span>
                        <span class="kv-val" style="color:var(--warn)">⭐ <?= number_format((int) $user['score']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($user['expire'])): ?>
                    <div class="kv">
                        <span class="kv-key">انقضای حساب</span>
                        <span class="kv-val"
                            style="<?= is_numeric($user['expire']) && (int) $user['expire'] < time() ? 'color:var(--no)' : '' ?>">
                            <?= safe_date($user['expire']) ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($user['codeInvitation'])): ?>
                    <div class="kv">
                        <span class="kv-key">کد دعوت</span>
                        <span class="kv-val cm"
                            style="color:var(--ac)"><?= htmlspecialchars($user['codeInvitation']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ((int) ($user['message_count'] ?? 0) > 0): ?>
                    <div class="kv">
                        <span class="kv-key">تعداد پیام</span>
                        <span class="kv-val cn"><?= number_format((int) $user['message_count']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card fade-up d1">
            <div class="card-head">
                <div class="card-title">عملیات</div>
            </div>
            <div style="padding:12px;display:flex;flex-direction:column;gap:6px">
                <button class="btn btn-primary btn-sm" style="justify-content:center" onclick="openModal('addModal')">
                    <?= icon('plus', 13) ?> افزایش موجودی
                </button>
                <button class="btn btn-ghost btn-sm" style="justify-content:center" onclick="openModal('roleModal')">
                    <?= icon('users', 13) ?> تغییر گروه کاربری
                </button>
                <div style="height:1px;background:var(--bd);margin:2px 0"></div>
                <?php if ($isBlocked): ?>
                    <a href="user_action.php?action=unblock&id=<?= $id ?>&_csrf=<?= csrf_token() ?>&back=user.php"
                        class="btn btn-ok btn-sm" style="justify-content:center" data-confirm="رفع مسدودیت این کاربر؟">
                        <?= icon('check', 13) ?> رفع مسدودیت
                    </a>
                <?php else: ?>
                    <a href="user_action.php?action=block&id=<?= $id ?>&_csrf=<?= csrf_token() ?>&back=user.php"
                        class="btn btn-no btn-sm" style="justify-content:center" data-confirm="مسدود کردن کاربر؟">
                        <?= icon('block', 13) ?> مسدود کردن
                    </a>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div class="u-main-col" style="display:flex;flex-direction:column;gap:16px">

        <div class="card fade-up">
            <div class="card-head">
                <div class="u-tab-bar" style="display:flex;gap:4px;background:var(--sf2);border-radius:7px;padding:3px">
                    <button class="btn btn-sm" id="tabOrders" onclick="switchTab('orders')"
                        style="background:var(--ac);color:#fff;border-radius:5px;font-size:.75rem">
                        سفارشات
                    </button>
                    <button class="btn btn-sm" id="tabPay" onclick="switchTab('pay')"
                        style="background:transparent;color:var(--mute);border-radius:5px;font-size:.75rem;border:none">
                        تراکنش‌ها
                    </button>
                    <?php if (count($referrals) > 0): ?>
                        <button class="btn btn-sm" id="tabRefs" onclick="switchTab('refs')"
                            style="background:transparent;color:var(--mute);border-radius:5px;font-size:.75rem;border:none">
                            زیرمجموعه
                            <span
                                style="background:var(--acs);color:var(--ac);padding:1px 6px;border-radius:99px;font-size:.65rem">
                                <?= count($referrals) ?>
                            </span>
                        </button>
                    <?php endif; ?>
                </div>
                <a href="invoice.php?q=<?= urlencode($id) ?>" class="btn-link" style="font-size:.75rem">همه ←</a>
            </div>

            <div id="paneOrders">
                <div class="tbl-wrap">
                    <table class="tbl-lg">
                        <thead>
                            <tr>
                                <th>محصول</th>
                                <th>قیمت</th>
                                <th>حجم</th>
                                <th>تاریخ</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty" style="padding:30px">
                                            <p>سفارشی ثبت نشده</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $statusMap = [
                                    'active' => ['tag-ok', 'فعال'],
                                    'end_of_time' => ['tag-warn', 'نزدیک به پایان زمان'],
                                    'end_of_volume' => ['tag-no', 'نزدیک به پایان حجم'],
                                    'sendedwarn' => ['tag-warn', 'اعلان همگی ارسال شده'],
                                    'send_on_hold' => ['tag-plain', 'در انتظار'],
                                    'unpiad' => ['tag-plain', 'پرداخت نشده'],
                                ];
                                foreach ($invoices as $inv):
                                    [$tagClass, $label] = $statusMap[$inv['Status'] ?? ''] ?? ['tag-plain', $inv['Status'] ?? '—'];
                                    ?>
                                    <tr>
                                        <td class="cs"
                                            style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                            <?= htmlspecialchars($inv['name_product'] ?? '—') ?>
                                        </td>
                                        <td class="cn cs" style="white-space:nowrap">
                                            <?= number_format((int) ($inv['price_product'] ?? 0)) ?> <span class="cf">ت</span>
                                        </td>
                                        <td class="cn cf"><?= htmlspecialchars($inv['Volume'] ?? '—') ?></td>
                                        <td class="cf" style="white-space:nowrap">
                                            <?= safe_date($inv['time_sell'] ?? null, 'Y/m/d') ?>
                                        </td>
                                        <td><span class="tag <?= $tagClass ?>"><?= $label ?></span></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="panePay" style="display:none">
                <div class="tbl-wrap">
                    <table class="tbl-md">
                        <thead>
                            <tr>
                                <th>مبلغ</th>
                                <th>روش</th>
                                <th>تاریخ</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="empty" style="padding:30px">
                                            <p>تراکنشی ثبت نشده</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else:
                                $methodLabels = [
                                    'cart to cart' => 'کارت→کارت',
                                    'add balance by admin' => 'افزایش ادمین',
                                    'low balance by admin' => 'کسر ادمین',
                                    'zarinpal' => 'زرین‌پال',
                                    'aqayepardakht' => 'آقای پرداخت',
                                    'plisio' => 'Plisio',
                                    'nowpayment' => 'NowPayment',
                                    'Star Telegram' => 'استار تلگرام',
                                    'Currency Rial 1' => 'ریالی ۱',
                                    'Currency Rial tow' => 'ریالی ۲',
                                    'Currency Rial 3' => 'ریالی ۳',
                                    'arze digital offline' => 'ارز دیجیتال',
                                ];
                                $payStatusMap = [
                                    'paid' => ['tag-ok', 'موفق'],
                                    'Unpaid' => ['tag-no', 'ناموفق'],
                                    'expire' => ['tag-plain', 'منقضی'],
                                    'reject' => ['tag-no', 'رد'],
                                    'waiting' => ['tag-warn', 'در انتظار'],
                                    'pending' => ['tag-warn', 'در انتظار'],
                                ];
                                foreach ($payments as $p):
                                    $payStatus = $p['payment_Status'] ?? '';
                                    [$tagClass, $label] = $payStatusMap[$payStatus] ?? ['tag-plain', $payStatus ?: '—'];
                                    $method = $methodLabels[$p['Payment_Method'] ?? ''] ?? ($p['Payment_Method'] ?? '—');
                                    ?>
                                    <tr>
                                        <td class="cn cs" style="white-space:nowrap">
                                            <?= number_format((int) ($p['price'] ?? 0)) ?> <span class="cf">ت</span>
                                        </td>
                                        <td style="font-size:.82rem"><?= htmlspecialchars($method) ?></td>
                                        <td class="cf" style="white-space:nowrap">
                                            <?= safe_date($p['time'] ?? null, 'Y/m/d H:i') ?>
                                        </td>
                                        <td><span class="tag <?= $tagClass ?>"><?= $label ?></span></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (count($referrals) > 0): ?>
                <div id="paneRefs" style="display:none">
                    <div class="tbl-wrap">
                        <table class="tbl-md">
                            <thead>
                                <tr>
                                    <th>آیدی</th>
                                    <th>نام</th>
                                    <th>موجودی</th>
                                    <th>گروه</th>
                                    <th>ثبت‌نام</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referrals as $ref):
                                    $refName = $ref['namecustom'] ?? '';
                                    if ($refName === 'none')
                                        $refName = '';
                                    $refUname = $ref['username'] ?? '';
                                    if ($refUname === 'none')
                                        $refUname = '';
                                    $refAgent = $ref['agent'] ?? 'f';
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="user.php?id=<?= (int) $ref['id'] ?>" class="cm" style="color:var(--ac)">
                                                <?= htmlspecialchars($ref['id']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($refName): ?>
                                                <span class="cs"><?= htmlspecialchars(trunc($refName, 16)) ?></span>
                                            <?php elseif ($refUname): ?>
                                                <span class="cm"
                                                    style="color:var(--ac)">@<?= htmlspecialchars(trunc($refUname, 14)) ?></span>
                                            <?php else: ?>
                                                <span class="cf">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cn" style="white-space:nowrap">
                                            <?= number_format((int) ($ref['Balance'] ?? 0)) ?> <span class="cf">ت</span>
                                        </td>
                                        <td>
                                            <span class="tag <?= user_role_tag($refAgent) ?>">
                                                <?= user_role_label($refAgent) ?>
                                            </span>
                                        </td>
                                        <td class="cf"><?= safe_date($ref['register'] ?? null, 'm/d') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>
</div>

<div class="modal-veil" id="addModal">
    <div class="modal">
        <div class="modal-head">
            <h3>افزایش موجودی</h3>
            <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_balance">
                <div class="field">
                    <label>مبلغ (تومان)</label>
                    <input type="number" name="amount" class="input" placeholder="مثلاً ۵۰۰۰۰" min="1000" required>
                    <span class="field-hint">موجودی فعلی: <strong><?= number_format($balance) ?> تومان</strong></span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> افزودن</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-veil" id="roleModal">
    <div class="modal">
        <div class="modal-head">
            <h3>تغییر گروه کاربری</h3>
            <button class="modal-x" onclick="closeModal('roleModal')"><?= icon('close', 14) ?></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_role">
                <div class="field">
                    <label>گروه</label>
                    <select name="new_role" class="select">
                        <option value="f" <?= $agent === 'f' ? 'selected' : '' ?>>کاربر عادی (f)</option>
                        <option value="n" <?= $agent === 'n' ? 'selected' : '' ?>>نماینده (n)</option>
                        <option value="n2" <?= $agent === 'n2' ? 'selected' : '' ?>>نماینده پیشرفته (n2)</option>
                    </select>
                    <span class="field-hint">
                        گروه فعلی: <strong><?= user_role_label($agent) ?></strong>
                        <span class="cm" style="color:var(--mute)">(<?= htmlspecialchars($agent) ?>)</span>
                    </span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('roleModal')">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script src="js/profile.js"></script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>