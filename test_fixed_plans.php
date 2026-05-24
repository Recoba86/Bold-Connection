<?php

require_once __DIR__ . '/fixed_plans.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSameValue('custom_pricing', fixedPlanNormalizeSalesMode('bad'), 'invalid sales mode falls back to custom pricing');
assertSameValue('fixed_plans', fixedPlanNormalizeSalesMode('fixed_plans'), 'fixed plan sales mode is accepted');
assertSameValue('custom_pricing', fixedPlanSalesMode(), 'duplicate service_plans mode is deprecated in favor of original products');
assertSameValue(false, fixedPlanModeEnabled(), 'service_plans user flow is disabled');
assertSameValue('&lt;b&gt;Plan&lt;/b&gt;', fixedPlanHtml('<b>Plan</b>'), 'fixed plan HTML output is escaped');

$discount = fixedPlanNormalizeDiscountSettings([
    'enabled' => '1',
    'percent' => '125',
    'start_at' => '0',
    'end_at' => '0',
], 1000);
assertSameValue(95, $discount['percent'], 'discount percent is capped at 95');
assertSameValue(1, $discount['active'], 'enabled discount with no dates is active');

$futureDiscount = fixedPlanNormalizeDiscountSettings([
    'enabled' => '1',
    'percent' => '10',
    'start_at' => '2000',
    'end_at' => '0',
], 1000);
assertSameValue(0, $futureDiscount['active'], 'future discount is inactive until start time');

$pricing = fixedPlanCalculatePrice(100000, false, [
    'enabled' => '1',
    'percent' => '25',
    'start_at' => '0',
    'end_at' => '0',
    'active' => '1',
]);
assertSameValue(100000, $pricing['original_price'], 'original price is preserved');
assertSameValue(25, $pricing['discount_percent'], 'active discount percent is applied');
assertSameValue(75000, $pricing['final_price'], 'final price is discounted correctly');
assertSameValue(true, $pricing['valid'], 'positive final price is valid');

$freeBlocked = fixedPlanCalculatePrice(0, false, ['enabled' => '0', 'percent' => '0']);
assertSameValue(false, $freeBlocked['valid'], 'free plans require explicit allow_free');
$freeAllowed = fixedPlanCalculatePrice(0, true, ['enabled' => '0', 'percent' => '0']);
assertSameValue(true, $freeAllowed['valid'], 'explicit free plans are valid');

$plan = [
    'id' => 7,
    'title' => '3 GB Weekly',
    'volume_gb' => 3,
    'duration_days' => 7,
    'price' => 120000,
    'code_panel' => '7e15',
    'agent' => 'f',
    'allow_free' => 0,
];
assertSameValue(true, fixedPlanMatchesPanelAndAgent($plan, '7e15', 'f'), 'plan matches its panel and agent');
assertSameValue(false, fixedPlanMatchesPanelAndAgent($plan, '7e16', 'f'), 'plan does not match another panel');
assertSameValue(false, fixedPlanMatchesPanelAndAgent($plan, '7e15', 'n'), 'plan does not match another agent');

$snapshot = fixedPlanBuildSnapshot($plan, [
    'enabled' => '1',
    'percent' => '10',
    'start_at' => '0',
    'end_at' => '0',
    'active' => '1',
]);
assertSameValue(7, $snapshot['plan_id'], 'snapshot stores plan id');
assertSameValue('3 GB Weekly', $snapshot['plan_title'], 'snapshot stores plan title');
assertSameValue(3, $snapshot['plan_volume_gb'], 'snapshot stores plan volume');
assertSameValue(7, $snapshot['plan_duration_days'], 'snapshot stores plan duration');
assertSameValue(120000, $snapshot['plan_original_price'], 'snapshot stores original price');
assertSameValue(10, $snapshot['plan_discount_percent'], 'snapshot stores discount percent');
assertSameValue(108000, $snapshot['plan_final_price'], 'snapshot stores final price');

$product = fixedPlanProductFromSnapshot($snapshot);
assertSameValue('fixed_plan', $product['code_product'], 'fixed plan product config uses fixed_plan code');
assertSameValue(3, $product['Volume_constraint'], 'product config maps snapshot volume');
assertSameValue(7, $product['Service_time'], 'product config maps snapshot duration');
assertSameValue(108000, $product['price_product'], 'product config maps final price');

$preview = fixedPlanInvoicePreviewText('user<&>', [
    'plan_title' => '<b>Unsafe</b>',
    'plan_volume_gb' => 1,
    'plan_duration_days' => 1,
    'plan_original_price' => 1000,
    'plan_discount_percent' => 0,
    'plan_final_price' => 1000,
], 0);
assertSameValue(false, strpos($preview, '<b>Unsafe</b>') !== false, 'invoice preview does not render raw plan HTML');
assertSameValue(true, strpos($preview, '&lt;b&gt;Unsafe&lt;/b&gt;') !== false, 'invoice preview includes escaped plan title');

echo "fixed plan tests passed\n";
