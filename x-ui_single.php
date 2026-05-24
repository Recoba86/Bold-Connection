<?php
require_once 'config.php';
require_once 'request.php';
require_once 'x-ui_auth.php';
ini_set('error_log', 'error_log');
function xuiCookiePath($code_panel)
{
    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $code_panel);
    return __DIR__ . '/cookie-' . $safeCode . '.txt';
}

function panel_login_cookie($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookiePath = xuiCookiePath($code_panel);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $panel['url_panel'] . '/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => "username={$panel['username_panel']}&password=" . urlencode($panel['password_panel']),
        CURLOPT_COOKIEJAR => $cookiePath,
    ));
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        $error = curl_error($curl);
        curl_close($curl);
        return json_encode(array(
            'success' => false,
            'msg' => $error
        ));
    }
    curl_close($curl);
    return $response;
}
function xui_apply_auth(CurlRequest $req, array $panel)
{
    if (xuiShouldUseModernClientApi($panel)) {
        $req->setBearerToken(xuiPanelApiToken($panel));
        return false;
    }

    login($panel['code_panel']);
    $req->setCookie(xuiCookiePath($panel['code_panel']));
    return true;
}

function xui_cleanup_auth($usedCookie, array $panel)
{
    if (!$usedCookie) {
        return;
    }

    $cookiePath = xuiCookiePath($panel['code_panel']);
    if (is_file($cookiePath)) {
        @unlink($cookiePath);
    }
}

function xuiLog($event, array $context = [])
{
    unset($context['api_token'], $context['password'], $context['password_panel'], $context['token']);
    error_log('xui:' . $event . ':' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function xuiDecodePanelResponse(array $response)
{
    if (!empty($response['error'])) {
        return [
            'success' => false,
            'msg' => $response['error'],
            'obj' => null,
            'http' => $response['status'] ?? null,
            'raw' => $response,
        ];
    }

    $decoded = null;
    if (isset($response['body']) && is_string($response['body']) && $response['body'] !== '') {
        $decoded = json_decode($response['body'], true);
    }

    if (is_array($decoded)) {
        return [
            'success' => !empty($decoded['success']),
            'msg' => $decoded['msg'] ?? '',
            'obj' => $decoded['obj'] ?? null,
            'http' => $response['status'] ?? null,
            'raw' => $response,
            'decoded' => $decoded,
        ];
    }

    return [
        'success' => isset($response['status']) && $response['status'] >= 200 && $response['status'] < 300,
        'msg' => 'Non-JSON response',
        'obj' => null,
        'http' => $response['status'] ?? null,
        'raw' => $response,
    ];
}

function xuiModernRequest(array $panel, $method, $path, $body = null)
{
    $token = xuiPanelApiToken($panel);
    if ($token === null) {
        return [
            'status' => null,
            'body' => null,
            'error' => 'Bearer API token is required for 3x-ui modern client API',
        ];
    }

    $url = rtrim((string) $panel['url_panel'], '/') . $path;
    $req = new CurlRequest($url);
    $req->setHeaders(array(
        'Accept: application/json',
        'Content-Type: application/json',
    ));
    $req->setBearerToken($token);

    if (strtoupper($method) === 'GET') {
        return $req->get();
    }

    $payload = $body === null ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $req->post($payload);
}

function xuiCapabilityTokenHash(array $panel)
{
    $token = xuiPanelApiToken($panel);
    return $token === null ? null : substr(hash('sha256', $token), 0, 16);
}

function xuiCachedCapabilities(array $panel)
{
    if (empty($panel['panel_capabilities']) || !is_string($panel['panel_capabilities'])) {
        return null;
    }

    $cached = json_decode($panel['panel_capabilities'], true);
    if (!is_array($cached)) {
        return null;
    }

    $currentHash = xuiCapabilityTokenHash($panel);
    if (($cached['token_hash'] ?? null) !== $currentHash) {
        return null;
    }

    return $cached;
}

function xuiSaveCapabilities(array $panel, array $capabilities)
{
    $capabilities['detected_at'] = time();
    $capabilities['token_hash'] = xuiCapabilityTokenHash($panel);
    update('marzban_panel', 'panel_capabilities', json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'code_panel', $panel['code_panel']);
    update('marzban_panel', 'detected_panel_version', $capabilities['detected_panel_version'] ?? null, 'code_panel', $panel['code_panel']);
}

function xuiStoreInvoicePanelMetadata($username, $subId = null, array $inboundIds = [], $clientKey = null)
{
    $username = trim((string) $username);
    if ($username === '') {
        return;
    }

    if ($subId !== null && $subId !== '') {
        update('invoice', 'panel_sub_id', $subId, 'username', $username);
    }
    if (!empty($inboundIds)) {
        update('invoice', 'panel_inbound_ids', implode(',', array_values(array_map('intval', $inboundIds))), 'username', $username);
    }
    if ($clientKey !== null && $clientKey !== '') {
        update('invoice', 'panel_client_key', $clientKey, 'username', $username);
    }
}

function xuiModernClientRoutes()
{
    return [
        'clients_list' => '/panel/api/clients/list',
        'clients_add' => '/panel/api/clients/add',
        'clients_get' => '/panel/api/clients/get/{email}',
        'clients_update' => '/panel/api/clients/update/{email}',
        'clients_delete' => '/panel/api/clients/del/{email}',
        'clients_reset_traffic' => '/panel/api/clients/resetTraffic/{email}',
        'clients_sub_links' => '/panel/api/clients/subLinks/{subId}',
        'clients_links' => '/panel/api/clients/links/{email}',
    ];
}

function xuiDetectCapabilities(array $panel, $force = false)
{
    if (!$force) {
        $cached = xuiCachedCapabilities($panel);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $capabilities = [
        'modern_clients' => false,
        'mode' => 'legacy',
        'detected_panel_version' => 'legacy-x-ui',
        'routes' => xuiModernClientRoutes(),
    ];

    if (!xuiUsesBearerAuth($panel)) {
        $capabilities['msg'] = 'No bearer token configured; keeping legacy x-ui API path';
        xuiLog('capability_detect', [
            'panel' => $panel['code_panel'] ?? null,
            'mode' => $capabilities['mode'],
            'msg' => $capabilities['msg'],
        ]);
        xuiSaveCapabilities($panel, $capabilities);
        return $capabilities;
    }

    $response = xuiModernRequest($panel, 'GET', '/panel/api/clients/list');
    $decoded = xuiDecodePanelResponse($response);
    if ($decoded['http'] === 200 && $decoded['success']) {
        $capabilities['modern_clients'] = true;
        $capabilities['mode'] = 'modern_clients';
        $capabilities['detected_panel_version'] = '3x-ui-modern-clients';
        $capabilities['msg'] = 'Modern 3x-ui client API detected';
    } else {
        $capabilities['http'] = $decoded['http'];
        $capabilities['msg'] = $decoded['msg'] ?: 'Modern 3x-ui client API unavailable';
    }

    xuiLog('capability_detect', [
        'panel' => $panel['code_panel'] ?? null,
        'mode' => $capabilities['mode'],
        'http' => $capabilities['http'] ?? $decoded['http'],
        'msg' => $capabilities['msg'] ?? null,
    ]);
    xuiSaveCapabilities($panel, $capabilities);
    return $capabilities;
}

function xuiShouldUseModernClientApi(array $panel)
{
    if (!xuiUsesBearerAuth($panel)) {
        return false;
    }

    $capabilities = xuiDetectCapabilities($panel);
    return !empty($capabilities['modern_clients']);
}

function xuiExpiryTimeForPanel(array $panel, $expire, $name_product)
{
    if ($name_product == "usertest") {
        $onHold = isset($panel['on_hold_test']) && $panel['on_hold_test'] == "1";
    } else {
        $onHold = isset($panel['conecton']) && $panel['conecton'] == "onconecton";
    }

    if ($onHold) {
        if ($expire == 0) {
            return 0;
        }
        $timelast = $expire - time();
        return -intval(($timelast / 86400) * 86400000);
    }

    return $expire * 1000;
}

function xuiBuildModernClientPayload($username, $expire, $total, $flow, $subId, $uuid, $note, array $panel, $name_product)
{
    return [
        'id' => $uuid,
        'flow' => (string) $flow,
        'email' => $username,
        'limitIp' => 0,
        'totalGB' => intval($total),
        'expiryTime' => xuiExpiryTimeForPanel($panel, $expire, $name_product),
        'enable' => true,
        'tgId' => 0,
        'subId' => $subId,
        'reset' => 0,
        'comment' => $note,
    ];
}

function xuiClientPatchFromSettingsConfig(array $config)
{
    if (empty($config['settings']) || !is_string($config['settings'])) {
        return [];
    }

    $settings = json_decode($config['settings'], true);
    if (!is_array($settings) || empty($settings['clients'][0]) || !is_array($settings['clients'][0])) {
        return [];
    }

    $client = $settings['clients'][0];
    if (isset($client['tgId']) && $client['tgId'] === '') {
        $client['tgId'] = 0;
    }

    return $client;
}

function xuiModernRecordToClientWire(array $record)
{
    $client = [
        'id' => $record['uuid'] ?? ($record['id'] ?? ''),
        'security' => $record['security'] ?? '',
        'password' => $record['password'] ?? '',
        'flow' => $record['flow'] ?? '',
        'auth' => $record['auth'] ?? '',
        'email' => $record['email'] ?? '',
        'limitIp' => intval($record['limitIp'] ?? 0),
        'totalGB' => intval($record['totalGB'] ?? 0),
        'expiryTime' => intval($record['expiryTime'] ?? 0),
        'enable' => array_key_exists('enable', $record) ? (bool) $record['enable'] : true,
        'tgId' => intval($record['tgId'] ?? 0),
        'subId' => $record['subId'] ?? '',
        'comment' => $record['comment'] ?? '',
        'reset' => intval($record['reset'] ?? 0),
    ];

    if (array_key_exists('reverse', $record) && $record['reverse'] !== null && $record['reverse'] !== '') {
        $client['reverse'] = $record['reverse'];
    }

    return $client;
}

function xuiNormalizeModernClient(array $client)
{
    if (isset($client['uuid']) && !isset($client['id'])) {
        $client['id'] = $client['uuid'];
    }
    if (isset($client['tgId']) && $client['tgId'] === '') {
        $client['tgId'] = 0;
    }
    if (isset($client['totalGB'])) {
        $client['totalGB'] = intval($client['totalGB']);
    }
    if (isset($client['expiryTime'])) {
        $client['expiryTime'] = intval($client['expiryTime']);
    }
    if (isset($client['limitIp'])) {
        $client['limitIp'] = intval($client['limitIp']);
    }
    if (isset($client['reset'])) {
        $client['reset'] = intval($client['reset']);
    }
    if (isset($client['enable'])) {
        $client['enable'] = (bool) $client['enable'];
    }
    return $client;
}

function xuiModernClientGet(array $panel, $username)
{
    $response = xuiModernRequest($panel, 'GET', '/panel/api/clients/get/' . rawurlencode($username));
    return xuiDecodePanelResponse($response);
}

function xuiModernClientTraffic(array $panel, $username)
{
    $response = xuiModernRequest($panel, 'GET', '/panel/api/clients/traffic/' . rawurlencode($username));
    return xuiDecodePanelResponse($response);
}

function xuiModernClientToLegacyObj(array $clientData, array $trafficData = [])
{
    $client = isset($clientData['client']) && is_array($clientData['client']) ? $clientData['client'] : $clientData;
    $traffic = isset($trafficData['email']) ? $trafficData : [];
    $inboundIds = isset($clientData['inboundIds']) && is_array($clientData['inboundIds']) ? $clientData['inboundIds'] : [];
    $up = intval($traffic['up'] ?? 0);
    $down = intval($traffic['down'] ?? 0);

    return [
        'email' => $client['email'] ?? '',
        'uuid' => $client['uuid'] ?? ($client['id'] ?? ''),
        'flow' => $client['flow'] ?? '',
        'total' => intval($client['totalGB'] ?? ($traffic['total'] ?? 0)),
        'totalGB' => intval($client['totalGB'] ?? ($traffic['total'] ?? 0)),
        'expiryTime' => intval($client['expiryTime'] ?? ($traffic['expiryTime'] ?? 0)),
        'enable' => array_key_exists('enable', $client) ? (bool) $client['enable'] : (bool) ($traffic['enable'] ?? true),
        'subId' => $client['subId'] ?? ($traffic['subId'] ?? ''),
        'up' => $up,
        'down' => $down,
        'lastOnline' => intval($traffic['lastOnline'] ?? 0),
        'inboundId' => isset($inboundIds[0]) ? intval($inboundIds[0]) : 0,
        'inboundIds' => array_values(array_map('intval', $inboundIds)),
        'comment' => $client['comment'] ?? '',
        'reset' => intval($client['reset'] ?? ($traffic['reset'] ?? 0)),
    ];
}

function xuiGetClientModern($username, array $panel)
{
    $clientResponse = xuiModernClientGet($panel, $username);
    if (!$clientResponse['success']) {
        return [
            'status' => $clientResponse['http'],
            'body' => json_encode([
                'success' => false,
                'msg' => $clientResponse['msg'],
                'obj' => null,
            ]),
            'error' => $clientResponse['http'] === 404 ? null : $clientResponse['msg'],
        ];
    }

    $trafficResponse = xuiModernClientTraffic($panel, $username);
    $traffic = $trafficResponse['success'] && is_array($trafficResponse['obj']) ? $trafficResponse['obj'] : [];
    $legacyObj = xuiModernClientToLegacyObj(is_array($clientResponse['obj']) ? $clientResponse['obj'] : [], $traffic);

    return [
        'status' => 200,
        'body' => json_encode([
            'success' => true,
            'msg' => '',
            'obj' => $legacyObj,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'xui_meta' => [
            'mode' => 'modern_clients',
            'subId' => $legacyObj['subId'],
            'inboundIds' => $legacyObj['inboundIds'],
            'clientKey' => $legacyObj['uuid'],
        ],
    ];
}

function xuiAddClientModern(array $panel, $username, $expire, $total, $flow, $subId, array $inboundIds, $name_product, $note = "")
{
    if (empty($inboundIds)) {
        return ['error' => 'No inbound IDs configured'];
    }

    $uuid = generateUUID();
    $client = xuiBuildModernClientPayload($username, $expire, $total, $flow, $subId, $uuid, $note, $panel, $name_product);
    $payload = [
        'client' => $client,
        'inboundIds' => array_values(array_map('intval', $inboundIds)),
    ];

    xuiLog('client_create_path', [
        'panel' => $panel['code_panel'] ?? null,
        'mode' => 'modern_clients',
        'inboundIds' => $payload['inboundIds'],
    ]);
    $response = xuiModernRequest($panel, 'POST', '/panel/api/clients/add', $payload);
    $decoded = xuiDecodePanelResponse($response);
    if (!$decoded['success']) {
        xuiLog('client_create_failed', [
            'panel' => $panel['code_panel'] ?? null,
            'mode' => 'modern_clients',
            'http' => $decoded['http'],
            'msg' => $decoded['msg'],
        ]);
    }

    $response['xui_meta'] = [
        'mode' => 'modern_clients',
        'subId' => $subId,
        'inboundIds' => $payload['inboundIds'],
        'clientKey' => $uuid,
    ];
    return $response;
}

function xuiUpdateClientModern($namepanel, array $config)
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $patch = xuiClientPatchFromSettingsConfig($config);
    $email = $patch['email'] ?? null;
    if (!$email) {
        return [
            'status' => 500,
            'body' => json_encode(['success' => false, 'msg' => 'Client email is required for modern update']),
        ];
    }

    $current = xuiModernClientGet($panel, $email);
    if (!$current['success'] || !is_array($current['obj'] ?? null)) {
        return [
            'status' => $current['http'],
            'body' => json_encode(['success' => false, 'msg' => $current['msg']]),
            'error' => $current['msg'],
        ];
    }

    $currentClient = $current['obj']['client'] ?? [];
    $client = xuiModernRecordToClientWire(is_array($currentClient) ? $currentClient : []);
    foreach ($patch as $key => $value) {
        $client[$key] = $value;
    }
    $client = xuiNormalizeModernClient($client);

    xuiLog('client_update_path', [
        'panel' => $panel['code_panel'] ?? null,
        'mode' => 'modern_clients',
        'email' => $email,
    ]);
    return xuiModernRequest($panel, 'POST', '/panel/api/clients/update/' . rawurlencode($email), $client);
}

function xuiRemoveClientModern(array $panel, $username)
{
    xuiLog('client_delete_path', [
        'panel' => $panel['code_panel'] ?? null,
        'mode' => 'modern_clients',
        'email' => $username,
    ]);
    return xuiModernRequest($panel, 'POST', '/panel/api/clients/del/' . rawurlencode($username), null);
}

function xuiResetClientTrafficModern(array $panel, $username)
{
    xuiLog('client_reset_traffic_path', [
        'panel' => $panel['code_panel'] ?? null,
        'mode' => 'modern_clients',
        'email' => $username,
    ]);
    return xuiModernRequest($panel, 'POST', '/panel/api/clients/resetTraffic/' . rawurlencode($username), null);
}

function xuiLinksFromModernResponse(array $decoded)
{
    if (!$decoded['success']) {
        return [];
    }

    $obj = $decoded['obj'];
    if (is_string($obj)) {
        if (function_exists('xuiParseSubscriptionBody')) {
            return xuiParseSubscriptionBody($obj);
        }
        $lines = preg_split('/\R/u', trim($obj));
        return is_array($lines) ? array_values(array_filter(array_map('trim', $lines))) : [];
    }
    if (!is_array($obj)) {
        return [];
    }

    $links = [];
    foreach ($obj as $item) {
        if (is_string($item)) {
            $links[] = trim($item);
        } elseif (is_array($item) && isset($item['uri'])) {
            $links[] = trim((string) $item['uri']);
        }
    }
    return array_values(array_filter($links, function ($line) {
        return $line !== '';
    }));
}

function xuiFetchModernSubscriptionLinks(array $panel, $subId, $email = null)
{
    if (!xuiShouldUseModernClientApi($panel)) {
        return [];
    }

    $response = xuiModernRequest($panel, 'GET', '/panel/api/clients/subLinks/' . rawurlencode($subId));
    $links = xuiLinksFromModernResponse(xuiDecodePanelResponse($response));
    if (!empty($links)) {
        return $links;
    }

    if ($email !== null && $email !== '') {
        $response = xuiModernRequest($panel, 'GET', '/panel/api/clients/links/' . rawurlencode($email));
        $links = xuiLinksFromModernResponse(xuiDecodePanelResponse($response));
        if (!empty($links)) {
            return $links;
        }
    }

    xuiLog('subscription_modern_fallback', [
        'panel' => $panel['code_panel'] ?? null,
        'mode' => 'modern_clients',
        'reason' => 'Modern links API returned no links',
    ]);
    return [];
}

function login($code_panel, $verify = true)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    if (xuiShouldUseModernClientApi($panel)) {
        $url = $panel['url_panel'] . '/panel/api/inbounds/list';
        $req = new CurlRequest($url);
        $req->setHeaders(array(
            'Accept: application/json',
            'Content-Type: application/json',
        ));
        $req->setBearerToken(xuiPanelApiToken($panel));
        $response = $req->get();
        $decoded = isset($response['body']) ? json_decode($response['body'], true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }

        return array(
            'success' => isset($response['status']) && $response['status'] >= 200 && $response['status'] < 300,
            'msg' => $response['error'] ?? ('HTTP ' . ($response['status'] ?? 'unknown')),
        );
    }

    $cookiePath = xuiCookiePath($panel['code_panel']);
    if ($panel['datelogin'] != null && $verify) {
        $date = json_decode($panel['datelogin'], true);
        if (isset($date['time'])) {
            $timecurrent = time();
            $start_date = time() - strtotime($date['time']);
            if ($start_date <= 3000) {
                file_put_contents($cookiePath, $date['access_token']);
                return array('success' => true, 'msg' => 'Using cached session');
            }
        }
    }
    $response = panel_login_cookie($panel['code_panel']);
    $time = date('Y/m/d H:i:s');
    $data = json_encode(array(
        'time' => $time,
        'access_token' => is_file($cookiePath) ? file_get_contents($cookiePath) : ''
    ));
    update("marzban_panel", "datelogin", $data, 'name_panel', $panel['name_panel']);
    if (!is_string($response))
        return array('success' => false);
    return json_decode($response, true);
}

function get_clinets($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (xuiShouldUseModernClientApi($marzban_list_get)) {
        return xuiGetClientModern($username, $marzban_list_get);
    }

    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/getClientTraffics/$username";
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->get();

    if (isset($response['body'])) {
        $decodedBody = json_decode($response['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
            if (isset($decodedBody['success']) && $decodedBody['success'] === false) {
                $response['error'] = $decodedBody['msg'] ?? 'Unknown panel error';
            }
        }
    }

    if (!empty($response['error'])) {
        error_log(json_encode($response));
    }

    xui_cleanup_auth($usedCookie, $marzban_list_get);

    return $response;
}

function xuiParseInboundIds($raw)
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw);
    }

    $ids = [];
    foreach ($parts as $part) {
        $id = intval($part);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function xuiAddClientInbounds($namepanel, $usernameac, $Expire, $Total, $Flow, $subid, $inboundIds, $name_product, $note = "")
{
    $inboundIds = xuiParseInboundIds($inboundIds);
    if (empty($inboundIds)) {
        return [
            'error' => 'No inbound IDs configured',
        ];
    }

    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (xuiShouldUseModernClientApi($panel)) {
        return xuiAddClientModern($panel, $usernameac, $Expire, $Total, $Flow, $subid, $inboundIds, $name_product, $note);
    }

    $uuid = generateUUID();
    $lastResponse = null;

    foreach ($inboundIds as $inboundId) {
        $response = addClient($namepanel, $usernameac, $Expire, $Total, $uuid, $Flow, $subid, $inboundId, $name_product, $note);
        $response['xui_meta'] = [
            'mode' => 'legacy',
            'subId' => $subid,
            'inboundIds' => $inboundIds,
            'clientKey' => $uuid,
        ];
        $lastResponse = $response;

        if (!empty($response['error'])) {
            return $response;
        }
        if (!empty($response['status']) && $response['status'] != 200) {
            return $response;
        }

        $body = json_decode($response['body'] ?? '', true);
        if (is_array($body) && array_key_exists('success', $body) && !$body['success']) {
            return $response;
        }
    }

    return $lastResponse;
}

function addClient($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid, $name_product, $note = "")
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $timeservice = xuiExpiryTimeForPanel($marzban_list_get, $Expire, $name_product);
    $config = array(
        "id" => intval($inboundid),
        'settings' => json_encode(array(
            'clients' => array(
                array(
                    "id" => $Uuid,
                    "flow" => $Flow,
                    "email" => $usernameac,
                    "totalGB" => $Total,
                    "expiryTime" => $timeservice,
                    "enable" => true,
                    "tgId" => "",
                    "subId" => $subid,
                    "reset" => 0,
                    "comment" => $note
                )
            ),
            'decryption' => 'none',
            'fallbacks' => array(),
        ))
    );
    if (!isset($usernameac))
        return array(
            'status' => 500,
            'msg' => 'username is null'
        );
    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/addClient';
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post($configpanel);
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
function updateClient($namepanel, $uuid, array $config)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (xuiShouldUseModernClientApi($marzban_list_get)) {
        return xuiUpdateClientModern($namepanel, $config);
    }

    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/panel/api/inbounds/updateClient/' . $uuid;
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post($configpanel);
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
function ResetUserDataUsagex_uisin($usernamepanel, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (xuiShouldUseModernClientApi($marzban_list_get)) {
        return xuiResetClientTrafficModern($marzban_list_get, $usernamepanel);
    }

    $data_user = get_clinets($usernamepanel, $namepanel);
    $data_user = json_decode($data_user['body'], true)['obj'];
    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$data_user['inboundId']}/resetClientTraffic/" . $usernamepanel;
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post(array());
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
function removeClient($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    if (xuiShouldUseModernClientApi($marzban_list_get)) {
        return xuiRemoveClientModern($marzban_list_get, $username);
    }

    $url = $marzban_list_get['url_panel'] . "/panel/api/inbounds/{$marzban_list_get['inboundid']}/delClientByEmail/" . $username;
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $usedCookie = xui_apply_auth($req, $marzban_list_get);
    $response = $req->post(array());
    xui_cleanup_auth($usedCookie, $marzban_list_get);
    return $response;
}
