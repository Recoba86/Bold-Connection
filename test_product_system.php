<?php

require_once __DIR__ . '/fixed_plans.php';

function assertContainsText($needle, $haystack, $message)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Missing: ' . $needle . PHP_EOL);
        exit(1);
    }
}

function assertNotContainsText($needle, $haystack, $message)
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Unexpected: ' . $needle . PHP_EOL);
        exit(1);
    }
}

function readProjectFile($path)
{
    $contents = file_get_contents(__DIR__ . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read ' . $path . PHP_EOL);
        exit(1);
    }
    return $contents;
}

$table = readProjectFile('table.php');
$admin = readProjectFile('admin.php');
$index = readProjectFile('index.php');
$keyboard = readProjectFile('keyboard.php');
$panels = readProjectFile('panels.php');

assertContainsText('CREATE TABLE product', $table, 'original product table must exist');
assertContainsText('price_product', $table, 'original product table stores fixed price');
assertContainsText('Volume_constraint', $table, 'original product table stores fixed GB volume');
assertContainsText('Service_time', $table, 'original product table stores fixed duration');
assertContainsText('CREATE TABLE category', $table, 'original product categories must exist');

assertContainsText('INSERT IGNORE INTO product', $admin, 'admin Telegram flow must create original products');
assertContainsText('🛍 مدیریت محصولات', $keyboard, 'shop keyboard must expose original Persian product manager');
assertNotContainsText('📦 مدیریت پلن ثابت', $keyboard, 'shop keyboard must not expose duplicate service_plans manager');

assertContainsText('KeyboardProduct', $index, 'user purchase flow must render original product buttons');
assertContainsText('INSERT IGNORE INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time', $index, 'invoice creation must snapshot original product fields');
assertContainsText('SELECT * FROM product WHERE (Location = :name_panel OR Location = \'/all\')  AND code_product = :code_product', $panels, 'provisioning must resolve original products by code');
assertContainsText('xuiAddClientInbounds', $panels, 'original products must still provision through x-ui adapter');

if (fixedPlanModeEnabled() !== false) {
    fwrite(STDERR, 'duplicate service_plans user flow must remain disabled' . PHP_EOL);
    exit(1);
}

echo "original product system tests passed\n";
