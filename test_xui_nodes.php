<?php

if (!function_exists('isBase64')) {
    function isBase64($data)
    {
        if (!is_string($data) || $data === '') {
            return false;
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        return base64_encode($decoded) === $data;
    }
}

require_once __DIR__ . '/xui_nodes.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$nodeA = [
    'name' => 'Iran',
    'host' => 'iran.example.com',
    'port' => 443,
    'scheme' => 'https',
    'base_path' => '',
    'status' => 'online',
];

$nodeB = [
    'name' => 'Germany',
    'host' => 'de.example.com',
    'port' => 443,
    'scheme' => 'https',
    'base_path' => '',
    'status' => 'online',
];

$configs = [
    'vless://uuid@iran.example.com:443?type=ws#Iran',
    'vless://uuid@de.example.com:443?type=ws#Germany',
    'vless://uuid@hidden.example.com:443?type=ws#Hidden',
];

$filtered = xuiFilterConfigsBySellableNodes($configs, [$nodeA, $nodeB]);
assertSameValue(2, count($filtered), 'filters configs to enabled sellable nodes only');
assertSameValue($configs[0], $filtered[0], 'keeps Iran config');
assertSameValue($configs[1], $filtered[1], 'keeps Germany config');

assertSameValue(true, xuiConfigBelongsToNode($configs[0], $nodeA), 'matches config host to node');
assertSameValue(false, xuiConfigBelongsToNode($configs[2], $nodeA), 'does not match unrelated config');

$parsed = xuiParseSubscriptionBody("vless://a\nvless://b\n");
assertSameValue(['vless://a', 'vless://b'], $parsed, 'parses plain subscription body');

$parsedBase64 = xuiParseSubscriptionBody(base64_encode("vless://a\nvless://b\n"));
assertSameValue(['vless://a', 'vless://b'], $parsedBase64, 'parses base64 subscription body');

assertSameValue('https://iran.example.com:443', xuiNodeSubBase($nodeA), 'builds node subscription base URL');

require_once __DIR__ . '/x-ui_single.php';
assertSameValue([1], xuiParseInboundIds('1'), 'parses single inbound id');
assertSameValue([1, 2, 3], xuiParseInboundIds('1,2,3'), 'parses comma-separated inbound ids');
assertSameValue([1, 2, 3], xuiParseInboundIds("1\n2 3"), 'parses mixed separator inbound ids');

echo "x-ui nodes tests passed\n";
