<?php

require_once __DIR__ . '/x-ui_single.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$routes = xuiModernClientRoutes();
assertSameValue('/panel/api/clients/list', $routes['clients_list'], 'uses v3.1 clients list route for capability detection');
assertSameValue('/panel/api/clients/add', $routes['clients_add'], 'uses v3.1 clients add route');
assertSameValue('/panel/api/clients/update/{email}', $routes['clients_update'], 'uses v3.1 clients update route');
assertSameValue('/panel/api/clients/del/{email}', $routes['clients_delete'], 'uses v3.1 clients delete route');
assertSameValue('/panel/api/clients/resetTraffic/{email}', $routes['clients_reset_traffic'], 'uses v3.1 reset traffic route');
assertSameValue('/panel/api/clients/subLinks/{subId}', $routes['clients_sub_links'], 'uses v3.1 subId links route');
assertSameValue('/panel/api/clients/links/{email}', $routes['clients_links'], 'uses v3.1 email links route');

$modernPanel = [
    'api_token' => 'secret-token',
    'panel_capabilities' => json_encode([
        'token_hash' => xuiCapabilityTokenHash(['api_token' => 'secret-token']),
        'modern_clients' => true,
    ]),
];
$legacyPanel = [
    'api_token' => '',
    'panel_capabilities' => '',
];
assertSameValue(true, xuiShouldUseModernClientApi($modernPanel), 'selects v3.1 path from cached modern capability detection');
assertSameValue(false, xuiShouldUseModernClientApi($legacyPanel), 'keeps legacy path when bearer token and modern capabilities are absent');

$payload = xuiBuildModernClientPayload(
    'buyer@example.com',
    2000,
    1073741824,
    'xtls-rprx-vision',
    'sub-123',
    'uuid-123',
    'test note',
    ['conecton' => ''],
    'product'
);
assertSameValue('uuid-123', $payload['id'], 'preserves generated UUID/client key');
assertSameValue('buyer@example.com', $payload['email'], 'sets client email');
assertSameValue(1073741824, $payload['totalGB'], 'sets traffic limit in bytes');
assertSameValue(2000000, $payload['expiryTime'], 'sets expiry in milliseconds for active plans');
assertSameValue(0, $payload['limitIp'], 'sets default IP limit');
assertSameValue(0, $payload['tgId'], 'normalizes empty Telegram ID for v3.1');
assertSameValue('sub-123', $payload['subId'], 'preserves subId');

$onHoldPayload = xuiBuildModernClientPayload(
    'hold@example.com',
    time() + 86400,
    0,
    '',
    'sub-hold',
    'uuid-hold',
    '',
    ['conecton' => 'onconecton'],
    'product'
);
assertTrueValue($onHoldPayload['expiryTime'] < 0, 'keeps legacy on-hold negative expiry behavior');

$patch = xuiClientPatchFromSettingsConfig([
    'settings' => json_encode([
        'clients' => [
            [
                'email' => 'buyer@example.com',
                'enable' => false,
                'tgId' => '',
                'subId' => 'sub-new',
            ],
        ],
    ]),
]);
assertSameValue(0, $patch['tgId'], 'normalizes blank tgId in update patches');
assertSameValue(false, $patch['enable'], 'preserves update enabled flag');
assertSameValue('sub-new', $patch['subId'], 'preserves update subId');

$wire = xuiModernRecordToClientWire([
    'id' => 99,
    'uuid' => 'uuid-record',
    'email' => 'buyer@example.com',
    'totalGB' => '2048',
    'expiryTime' => '3000',
    'enable' => 1,
    'subId' => 'sub-record',
]);
assertSameValue('uuid-record', $wire['id'], 'maps v3.1 record UUID to model client ID');
assertSameValue(2048, $wire['totalGB'], 'normalizes record traffic limit');
assertSameValue(true, $wire['enable'], 'normalizes record enable flag');

$legacyObj = xuiModernClientToLegacyObj([
    'client' => [
        'uuid' => 'uuid-modern',
        'email' => 'buyer@example.com',
        'totalGB' => 4096,
        'expiryTime' => 5000,
        'enable' => true,
        'subId' => 'sub-modern',
    ],
    'inboundIds' => [3, 8],
], [
    'email' => 'buyer@example.com',
    'up' => 10,
    'down' => 20,
    'lastOnline' => 123000,
]);
assertSameValue([3, 8], $legacyObj['inboundIds'], 'preserves all attached inbound IDs');
assertSameValue(3, $legacyObj['inboundId'], 'keeps first inbound ID for legacy callers');
assertSameValue('uuid-modern', $legacyObj['uuid'], 'preserves v3.1 client key in legacy-shaped output');
assertSameValue(30, $legacyObj['up'] + $legacyObj['down'], 'preserves traffic usage in legacy-shaped output');

$links = xuiLinksFromModernResponse([
    'success' => true,
    'obj' => [
        ['uri' => 'vless://a'],
        'trojan://b',
        '',
    ],
]);
assertSameValue(['vless://a', 'trojan://b'], $links, 'parses v3.1 API-generated subscription links');

$req = new CurlRequest('http://127.0.0.1/');
$req->setHeaders(['Accept: application/json']);
$req->setBearerToken('secret-token');
$prepareHeaders = new ReflectionMethod(CurlRequest::class, 'prepareHeaders');
$headers = $prepareHeaders->invoke($req);
assertTrueValue(in_array('Authorization: Bearer secret-token', $headers, true), 'uses bearer authorization header for v3.1 requests');

echo "x-ui v3.1 compatibility tests passed\n";
