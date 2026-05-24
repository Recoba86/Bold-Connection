<?php

function fixedPlanNormalizeSalesMode($value)
{
    return $value === 'fixed_plans' ? 'fixed_plans' : 'custom_pricing';
}

function fixedPlanHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fixedPlanGetShopSetting($name, $default = null)
{
    $row = select('shopSetting', 'value', 'Namevalue', $name, 'select');
    if (!is_array($row) || !array_key_exists('value', $row)) {
        return $default;
    }

    return $row['value'];
}

function fixedPlanSetShopSetting($name, $value)
{
    global $pdo;

    $stmt = $pdo->prepare('INSERT INTO shopSetting (Namevalue, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute([
        ':name' => $name,
        ':value' => (string) $value,
    ]);
    if (function_exists('clearSelectCache')) {
        clearSelectCache('shopSetting');
    }
}

function fixedPlanSalesMode()
{
    // Deprecated: the original product table is the primary fixed-plan system.
    // Keep this helper stable for old snapshots/tests, but never route new users
    // into the duplicate service_plans flow.
    return 'custom_pricing';
}

function fixedPlanModeEnabled()
{
    return false;
}

function fixedPlanNormalizeDiscountSettings(array $settings, $now = null)
{
    $now = $now === null ? time() : intval($now);
    $enabled = !empty($settings['enabled']) && !in_array($settings['enabled'], ['0', 'off', 'false'], true);
    $percent = max(0, min(95, intval($settings['percent'] ?? 0)));
    $startAt = max(0, intval($settings['start_at'] ?? 0));
    $endAt = max(0, intval($settings['end_at'] ?? 0));
    $active = $enabled && $percent > 0;
    if ($active && $startAt > 0 && $now < $startAt) {
        $active = false;
    }
    if ($active && $endAt > 0 && $now > $endAt) {
        $active = false;
    }

    return [
        'enabled' => $enabled ? 1 : 0,
        'percent' => $percent,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'active' => $active ? 1 : 0,
    ];
}

function fixedPlanDiscountSettings($now = null)
{
    return fixedPlanNormalizeDiscountSettings([
        'enabled' => fixedPlanGetShopSetting('fixed_discount_enabled', '0'),
        'percent' => fixedPlanGetShopSetting('fixed_discount_percent', '0'),
        'start_at' => fixedPlanGetShopSetting('fixed_discount_start_at', '0'),
        'end_at' => fixedPlanGetShopSetting('fixed_discount_end_at', '0'),
    ], $now);
}

function fixedPlanCalculatePrice($price, $allowFree = false, ?array $discount = null)
{
    $original = max(0, intval($price));
    $discount = $discount === null ? fixedPlanDiscountSettings() : fixedPlanNormalizeDiscountSettings($discount);
    $percent = !empty($discount['active']) ? intval($discount['percent']) : 0;
    $final = $percent > 0 ? intval(round($original - (($original * $percent) / 100))) : $original;
    $final = max(0, $final);

    return [
        'valid' => $final > 0 || (bool) $allowFree,
        'original_price' => $original,
        'discount_percent' => $percent,
        'final_price' => $final,
        'allow_free' => (bool) $allowFree,
    ];
}

function fixedPlanMatchesPanelAndAgent(array $plan, $codePanel, $agent)
{
    $planPanel = (string) ($plan['code_panel'] ?? '/all');
    $planAgent = (string) ($plan['agent'] ?? 'all');

    return ($planPanel === '/all' || $planPanel === (string) $codePanel)
        && ($planAgent === 'all' || $planAgent === (string) $agent);
}

function fixedPlanBuildSnapshot(array $plan, ?array $discount = null)
{
    $pricing = fixedPlanCalculatePrice($plan['price'] ?? 0, !empty($plan['allow_free']), $discount);
    return [
        'valid' => $pricing['valid'],
        'plan_id' => intval($plan['id'] ?? 0),
        'plan_title' => (string) ($plan['title'] ?? ''),
        'plan_volume_gb' => intval($plan['volume_gb'] ?? 0),
        'plan_duration_days' => intval($plan['duration_days'] ?? 0),
        'plan_original_price' => $pricing['original_price'],
        'plan_discount_percent' => $pricing['discount_percent'],
        'plan_final_price' => $pricing['final_price'],
    ];
}

function fixedPlanProductFromSnapshot(array $snapshot)
{
    return [
        'code_product' => 'fixed_plan',
        'name_product' => $snapshot['plan_title'] ?? 'Fixed Plan',
        'price_product' => intval($snapshot['plan_final_price'] ?? 0),
        'Volume_constraint' => intval($snapshot['plan_volume_gb'] ?? 0),
        'Service_time' => intval($snapshot['plan_duration_days'] ?? 0),
        'data_limit_reset' => 'no_reset',
        'note' => '',
        'category' => 'Fixed Plans',
    ];
}

function fixedPlanProductFromInvoice(array $invoice)
{
    return fixedPlanProductFromSnapshot([
        'plan_title' => $invoice['plan_title'] ?? $invoice['name_product'] ?? 'Fixed Plan',
        'plan_final_price' => $invoice['plan_final_price'] ?? $invoice['price_product'] ?? 0,
        'plan_volume_gb' => $invoice['plan_volume_gb'] ?? $invoice['Volume'] ?? 0,
        'plan_duration_days' => $invoice['plan_duration_days'] ?? $invoice['Service_time'] ?? 0,
    ]);
}

function fixedPlanGetById($planId)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => intval($planId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fixedPlanListForPanel($codePanel, $agent, $activeOnly = true)
{
    global $pdo;

    $sql = "SELECT * FROM service_plans WHERE is_archived = 0 AND (code_panel = '/all' OR code_panel = :code_panel) AND (agent = 'all' OR agent = :agent)";
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':code_panel' => (string) $codePanel,
        ':agent' => (string) $agent,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fixedPlanIsPurchasable(array $plan, array $panel, $agent)
{
    if (empty($plan) || intval($plan['is_active'] ?? 0) !== 1 || intval($plan['is_archived'] ?? 0) === 1) {
        return false;
    }

    return fixedPlanMatchesPanelAndAgent($plan, $panel['code_panel'] ?? '', $agent);
}

function fixedPlanButtonText(array $plan)
{
    $snapshot = fixedPlanBuildSnapshot($plan);
    $volume = intval($snapshot['plan_volume_gb']);
    $days = intval($snapshot['plan_duration_days']);
    $title = trim((string) $snapshot['plan_title']);
    $price = number_format($snapshot['plan_final_price']);
    if ($snapshot['plan_discount_percent'] > 0) {
        return "{$title} • {$volume} GB • {$days} Days • {$price} Toman (-{$snapshot['plan_discount_percent']}%)";
    }

    return "{$title} • {$volume} GB • {$days} Days • {$price} Toman";
}

function fixedPlanInvoicePreviewText($username, array $snapshot, $balance)
{
    $original = number_format((float) $snapshot['plan_original_price'], 0);
    $final = number_format((float) $snapshot['plan_final_price'], 0);
    $balance = number_format((float) $balance, 0);
    $safeUsername = fixedPlanHtml($username);
    $safeTitle = fixedPlanHtml($snapshot['plan_title'] ?? '');
    $discountLine = intval($snapshot['plan_discount_percent']) > 0
        ? "\n💶 قیمت اصلی: <del>{$original} تومان</del>\n🎯 تخفیف: {$snapshot['plan_discount_percent']}%"
        : '';

    return "📇 پیش فاکتور شما:
👤 نام کاربری: <code>{$safeUsername}</code>
🔐 نام سرویس: {$safeTitle}
📆 مدت اعتبار: {$snapshot['plan_duration_days']} روز
👥 حجم اکانت: {$snapshot['plan_volume_gb']} گیگ{$discountLine}
💶 قیمت نهایی: {$final} تومان
💵 موجودی کیف پول شما: {$balance} تومان

💰 سفارش شما آماده پرداخت است.";
}

function fixedPlanUserKeyboard(array $panel, $agent, $backCallback = 'buyback')
{
    global $textbotlang;

    $plans = fixedPlanListForPanel($panel['code_panel'] ?? '', $agent, true);
    $keyboard = ['inline_keyboard' => []];
    foreach ($plans as $plan) {
        $snapshot = fixedPlanBuildSnapshot($plan);
        if (!$snapshot['valid']) {
            continue;
        }
        $keyboard['inline_keyboard'][] = [
            ['text' => fixedPlanButtonText($plan), 'callback_data' => 'fixedplan_' . intval($plan['id'])],
        ];
    }
    $keyboard['inline_keyboard'][] = [
        ['text' => $textbotlang['users']['backbtn'] ?? 'Back', 'callback_data' => $backCallback],
    ];

    return json_encode($keyboard, JSON_UNESCAPED_UNICODE);
}

function fixedPlanAdminSummary()
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(is_active = 1 AND is_archived = 0) AS active_count FROM service_plans');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active_count' => 0];
    $discount = fixedPlanDiscountSettings();
    $mode = fixedPlanSalesMode();

    return [
        'mode' => $mode,
        'total' => intval($row['total'] ?? 0),
        'active_count' => intval($row['active_count'] ?? 0),
        'discount' => $discount,
    ];
}

function fixedPlanAdminKeyboard()
{
    global $pdo;

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ Add fixed plan', 'callback_data' => 'fixedplan_add'],
                ['text' => '🎯 Discount settings', 'callback_data' => 'fixedplan_discount'],
            ],
        ],
    ];

    $stmt = $pdo->prepare('SELECT * FROM service_plans WHERE is_archived = 0 ORDER BY sort_order ASC, id ASC LIMIT 30');
    $stmt->execute();
    while ($plan = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = intval($plan['is_active'] ?? 0) === 1 ? '✅' : '⛔️';
        $keyboard['inline_keyboard'][] = [
            ['text' => $status . ' ' . $plan['title'], 'callback_data' => 'fixedplan_view_' . intval($plan['id'])],
        ];
    }
    $keyboard['inline_keyboard'][] = [
        ['text' => '⬅️ Back to shop', 'callback_data' => 'fixedplan_shop_back'],
    ];

    return json_encode($keyboard, JSON_UNESCAPED_UNICODE);
}

function fixedPlanAdminDashboardText()
{
    $summary = fixedPlanAdminSummary();
    $discount = $summary['discount'];
    $modeText = $summary['mode'] === 'fixed_plans' ? 'Fixed Plans' : 'Custom Pricing';
    $discountStatus = !empty($discount['enabled']) ? 'Enabled' : 'Disabled';
    $activeStatus = !empty($discount['active']) ? 'Active now' : 'Inactive now';

    return "📦 Fixed Plan Sales

Sales mode: {$modeText}
Plans: {$summary['active_count']} active / {$summary['total']} total
Discount: {$discountStatus} ({$discount['percent']}%) - {$activeStatus}

Use the buttons below to manage fixed plans.";
}

function fixedPlanAdminPlanText(array $plan)
{
    $snapshot = fixedPlanBuildSnapshot($plan);
    $status = intval($plan['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive';
    $free = intval($plan['allow_free'] ?? 0) === 1 ? 'Allowed' : 'Blocked';
    $target = fixedPlanHtml(($plan['code_panel'] ?? '/all') . ' / ' . ($plan['agent'] ?? 'all'));
    $title = fixedPlanHtml($plan['title'] ?? '');
    $description = fixedPlanHtml($plan['description'] ?? '');
    $original = number_format((float) $snapshot['plan_original_price'], 0);
    $final = number_format((float) $snapshot['plan_final_price'], 0);

    return "📦 Fixed Plan

ID: {$plan['id']}
Title: {$title}
Target: {$target}
Status: {$status}
Volume: {$plan['volume_gb']} GB
Duration: {$plan['duration_days']} days
Original price: {$original} Toman
Final price: {$final} Toman
Discount: {$snapshot['plan_discount_percent']}%
Free plan: {$free}
Sort order: {$plan['sort_order']}
Description: {$description}";
}

function fixedPlanAdminPlanKeyboard($planId)
{
    $planId = intval($planId);
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => 'Title', 'callback_data' => "fixedplan_edit_title_{$planId}"],
                ['text' => 'Price', 'callback_data' => "fixedplan_edit_price_{$planId}"],
            ],
            [
                ['text' => 'Volume', 'callback_data' => "fixedplan_edit_volume_gb_{$planId}"],
                ['text' => 'Duration', 'callback_data' => "fixedplan_edit_duration_days_{$planId}"],
            ],
            [
                ['text' => 'Target panel', 'callback_data' => "fixedplan_edit_code_panel_{$planId}"],
                ['text' => 'Agent', 'callback_data' => "fixedplan_edit_agent_{$planId}"],
            ],
            [
                ['text' => 'Description', 'callback_data' => "fixedplan_edit_description_{$planId}"],
                ['text' => 'Sort order', 'callback_data' => "fixedplan_edit_sort_order_{$planId}"],
            ],
            [
                ['text' => 'Enable/Disable', 'callback_data' => "fixedplan_toggle_active_{$planId}"],
                ['text' => 'Allow free', 'callback_data' => "fixedplan_toggle_free_{$planId}"],
            ],
            [
                ['text' => '⬆️ Up', 'callback_data' => "fixedplan_move_up_{$planId}"],
                ['text' => '⬇️ Down', 'callback_data' => "fixedplan_move_down_{$planId}"],
            ],
            [
                ['text' => 'Archive/Delete', 'callback_data' => "fixedplan_archive_{$planId}"],
                ['text' => 'Back', 'callback_data' => 'fixedplans_manage'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function fixedPlanDiscountKeyboard()
{
    $discount = fixedPlanDiscountSettings();
    $toggle = !empty($discount['enabled']) ? 'Disable discount' : 'Enable discount';
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => $toggle, 'callback_data' => 'fixeddiscount_toggle'],
                ['text' => 'Set percent', 'callback_data' => 'fixeddiscount_set_percent'],
            ],
            [
                ['text' => 'Set start', 'callback_data' => 'fixeddiscount_set_start'],
                ['text' => 'Set end', 'callback_data' => 'fixeddiscount_set_end'],
            ],
            [
                ['text' => 'Back', 'callback_data' => 'fixedplans_manage'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
}
