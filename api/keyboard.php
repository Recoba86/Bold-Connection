<?php

// DoS protection (Phase-2 Item #8): this endpoint is unauthenticated and
// runs three table scans (`textbot`, `setting`, json_decode of keyboardmain)
// on every hit. A trivial Layer-7 flood (`ab -n 100000 -c 200 ...`) would
// burn DB connections and starve the bot of MySQL workers. We serve a
// 60-second file-based JSON snapshot from disk to absorb burst traffic.
// Cache is intentionally short so admins editing the keyboard from the
// panel see their changes propagate within a minute without manual purge.
$cacheKey = __DIR__ . '/cache_keyboard.json';
if (is_file($cacheKey) && (time() - filemtime($cacheKey)) < 60) {
    header('Content-Type: application/json');
    echo file_get_contents($cacheKey);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Tehran');
ini_set('default_charset', 'UTF-8');
ini_set('error_log', 'error_log');


$datatextbot = array(
    'text_usertest' => '',
    'text_Purchased_services' => '',
    'text_support' => '',
    'text_help' => '',
    'accountwallet' => '',
    'text_sell' => '',
    'text_Tariff_list' => '',
    'text_affiliates' => '',
    'text_wheel_luck' => '',
    'text_extend' => ''

);
$textdatabot = select("textbot", "*", null, null, "fetchAll");
$data_text_bot = array();
foreach ($textdatabot as $row) {
    $data_text_bot[] = array(
        'id_text' => $row['id_text'],
        'text' => $row['text']
    );
}
foreach ($data_text_bot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}
$keyboardmain = json_decode(select("setting", "keyboardmain", null, null, "select")['keyboardmain'], true);

$list_keyboard = array(
    'text_sell',
    'text_extend',
    'text_usertest',
    'text_wheel_luck',
    'text_Purchased_services',
    'accountwallet',
    'text_affiliates',
    'text_Tariff_list',
    'text_support',
    'text_help',
);
if (is_array($keyboardmain) && isset($keyboardmain['keyboard']) && is_array($keyboardmain['keyboard'])) {
    foreach ($keyboardmain['keyboard'] as $keyboard) {
        if (!is_array($keyboard)) {
            continue;
        }
        foreach ($keyboard as $arrkey) {
            if (is_array($arrkey) && isset($arrkey['text']) && in_array($arrkey['text'], $list_keyboard, true)) {
                $index_number = array_search($arrkey['text'], $list_keyboard, true);
                unset($list_keyboard[$index_number]);
            }
        }
    }
}
$list_keyboard = array_values($list_keyboard);
$keyboard = [];
foreach ($list_keyboard as $key) {
    $keyboard[] = [['text' => $key]];
}

$list_data = [
    'keylist' => $keyboard,
    'userlist' => (is_array($keyboardmain) && isset($keyboardmain['keyboard'])) ? $keyboardmain['keyboard'] : [],
    'text' => $datatextbot
];
$encoded = json_encode($list_data);

// Best-effort cache write. We use LOCK_EX so concurrent hits during a cache
// miss don't trample each other, but never fail the response if the
// filesystem is read-only - the request still completes for the caller.
@file_put_contents($cacheKey, $encoded, LOCK_EX);

echo $encoded;
