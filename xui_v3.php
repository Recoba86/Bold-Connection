<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/x-ui_auth.php';

function xuiV3Request(array $panel, $method, $path, $body = null)
{
    $baseUrl = rtrim((string) $panel['url_panel'], '/');
    $url = $baseUrl . $path;
    $req = new CurlRequest($url);
    $req->setHeaders([
        'Accept: application/json',
        'Content-Type: application/json',
    ]);

    $token = xuiPanelApiToken($panel);
    if ($token === null) {
        return [
            'success' => false,
            'msg' => 'API token is required for 3x-ui v3 cluster operations',
            'obj' => null,
            'http' => null,
        ];
    }

    $req->setBearerToken($token);

    if (strtoupper($method) === 'GET') {
        $response = $req->get();
    } else {
        $payload = $body === null ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $req->post($payload);
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
        ];
    }

    $http = $response['status'] ?? null;
    return [
        'success' => is_int($http) && $http >= 200 && $http < 300,
        'msg' => $response['error'] ?? ('HTTP ' . ($http ?? 'unknown')),
        'obj' => null,
        'http' => $http,
    ];
}

function xuiV3Get(array $panel, $path)
{
    return xuiV3Request($panel, 'GET', $path);
}

function xuiV3Post(array $panel, $path, array $body = [])
{
    return xuiV3Request($panel, 'POST', $path, $body);
}

function xuiV3NodesList(array $panel)
{
    return xuiV3Get($panel, '/panel/api/nodes/list');
}

function xuiV3NodeProbe(array $panel, $remoteId)
{
    return xuiV3Post($panel, '/panel/api/nodes/probe/' . intval($remoteId));
}

function xuiDetectPanelMode(array $panel)
{
    if (!xuiUsesBearerAuth($panel)) {
        return 'single';
    }

    $result = xuiV3NodesList($panel);
    if (!$result['success'] || !is_array($result['obj'])) {
        return 'single';
    }

    return count($result['obj']) > 0 ? 'cluster' : 'single';
}
