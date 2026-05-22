<?php
require_once __DIR__ . '/icons.php';
$pageLede = $pageLede ?? '';
$activeNav = $activeNav ?? '';
$showPageHead = $showPageHead ?? true;
$currentUser = $_SESSION['admin_user'] ?? 'ادمین';
$initials = mb_strtoupper(mb_substr($currentUser, 0, 1, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <meta name="theme-color" content="#0F172A" id="mtc">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>پنل مدیریت میرزا بات</title>
  <link rel="stylesheet" href="css/style.css">
  <script>
    (function () {
      var t = localStorage.getItem('panel-theme') || 'navy';
      var bg = {
        navy: '#0F172A', purple: '#180D2E', emerald: '#0A1F1C',
        sunset: '#1A0D0D', slate: '#080808', light: '#F1F5F9',
        linen: '#FAF7F2', mint: '#F0FDF4', lavender: '#FAF5FF'
      };

      var root = document.documentElement;
      root.style.backgroundColor = bg[t] || '#0F172A';
      root.setAttribute('data-theme', t);

      root.style.colorScheme = (t === 'light' || t === 'linen' || t === 'mint' || t === 'lavender') ? 'light' : 'dark';
      var mtc = document.getElementById('mtc');
      if (mtc && bg[t]) mtc.content = bg[t];
      if (localStorage.getItem('panel-sb-collapsed') === '1' && window.innerWidth > 768)
        root.classList.add('sb-pre-collapsed');
    }());
  </script>
</head>

<body>

  <div id="load-bar"></div>
  <div id="toast-area"></div>

  <div class="confirm-veil" id="confirm-veil">
    <div class="confirm-box">
      <div class="confirm-icon"><?= icon('block', 26) ?></div>
      <h4 id="confirm-title">تأیید عملیات</h4>
      <p id="confirm-msg">آیا اطمینان دارید؟ این عملیات قابل بازگشت نیست.</p>
      <div class="confirm-btns">
        <button class="btn btn-no" id="confirm-ok">بله، ادامه</button>
        <button class="btn btn-ghost" onclick="closeConfirm()">انصراف</button>
      </div>
    </div>
  </div>

  <div class="app">
    <div class="sidebar-backdrop" id="backdrop" onclick="closeSidebar()"></div>

    <aside class="sidebar" id="sidebar">
      <div class="sidebar-brand">
        <div class="brand-mark">M</div>
        <div class="brand-name">میرزا<span> · پنل</span></div>
      </div>
      <nav class="sidebar-nav">
        <div class="nav-section">
          <div class="nav-heading">عمومی</div>
          <a href="index.php" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>" title="داشبورد">
            <span class="nav-icon"><?= icon('dashboard') ?></span><span class="nav-label">داشبورد</span>
          </a>
        </div>
        <div class="nav-section">
          <div class="nav-heading">مدیریت</div>
          <a href="users.php" class="nav-item <?= $activeNav === 'users' ? 'active' : '' ?>" title="کاربران">
            <span class="nav-icon"><?= icon('users') ?></span><span class="nav-label">کاربران</span>
          </a>
          <a href="invoice.php" class="nav-item <?= $activeNav === 'invoice' ? 'active' : '' ?>" title="سفارشات">
            <span class="nav-icon"><?= icon('invoice') ?></span><span class="nav-label">سفارشات</span>
          </a>
          <a href="service.php" class="nav-item <?= $activeNav === 'service' ? 'active' : '' ?>" title="سرویس‌ها">
            <span class="nav-icon"><?= icon('server') ?></span><span class="nav-label">سرویس‌ها</span>
          </a>
          <a href="product.php" class="nav-item <?= $activeNav === 'product' ? 'active' : '' ?>" title="محصولات">
            <span class="nav-icon"><?= icon('package') ?></span><span class="nav-label">محصولات</span>
          </a>
          <a href="payment.php" class="nav-item <?= $activeNav === 'payment' ? 'active' : '' ?>" title="تراکنش‌ها">
            <span class="nav-icon"><?= icon('card') ?></span><span class="nav-label">تراکنش‌ها</span>
          </a>
        </div>
        <div class="nav-section">
          <div class="nav-heading">پنل</div>
          <a href="settings.php" class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>" title="تنظیمات">
            <span class="nav-icon"><?= icon('settings') ?></span><span class="nav-label">تنظیمات</span>
          </a>
          <a href="logout.php" class="nav-item" title="خروج">
            <span class="nav-icon"><?= icon('logout') ?></span><span class="nav-label">خروج</span>
          </a>
        </div>
      </nav>
      <div class="sidebar-foot">
        <div class="user-pill">
          <div class="user-mono"><?= htmlspecialchars($initials) ?></div>
          <div class="user-info">
            <div class="uname"><?= htmlspecialchars($currentUser) ?></div>
            <div class="urole">مدیر پنل</div>
          </div>
        </div>
      </div>
    </aside>

    <div class="main">
      <header class="topbar">
        <div class="topbar-left">
          <button class="icon-btn menu-toggle" onclick="openSidebar()"><?= icon('menu', 18) ?></button>
          <button class="icon-btn sb-toggle" onclick="toggleSidebar()"><?= icon('menu', 17) ?></button>
          <div>
            <div class="topbar-title"><?= htmlspecialchars($pageTitle) ?></div>
            <div class="crumb"><span>میرزا</span><span
                style="opacity:.4;margin:0 3px">/</span><span><?= htmlspecialchars($pageTitle) ?></span></div>
          </div>
        </div>
        <div class="topbar-tools">
          <a href="settings.php" class="icon-btn" title="تنظیمات"><?= icon('settings', 16) ?></a>
          <a href="logout.php" class="icon-btn" title="خروج"><?= icon('logout', 16) ?></a>
        </div>
      </header>
      <main class="content">
        <?php
        $s = get_flash('success');
        $e = get_flash('error');
        $w = get_flash('warning');
        if ($s): ?>
          <div class="notice notice-ok"><?= htmlspecialchars($s) ?></div><?php endif;
        if ($e): ?>
          <div class="notice notice-no"><?= htmlspecialchars($e) ?></div><?php endif;
        if ($w): ?>
          <div class="notice notice-warn"><?= htmlspecialchars($w) ?></div><?php endif;
        if ($showPageHead): ?>
          <div class="page-head fade-up">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <?php if ($pageLede): ?>
              <p><?= htmlspecialchars($pageLede) ?></p><?php endif; ?>
          </div>
        <?php endif; ?>