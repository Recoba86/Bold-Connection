<?php

require_once __DIR__ . '/xui_v3.php';

function xuiNormalizeNodeRow(array $node)
{
    return [
        'remote_id' => isset($node['id']) ? intval($node['id']) : 0,
        'name' => (string) ($node['name'] ?? ('node-' . ($node['id'] ?? ''))),
        'scheme' => (string) ($node['scheme'] ?? 'https'),
        'host' => (string) ($node['host'] ?? ''),
        'port' => isset($node['port']) ? intval($node['port']) : 2053,
        'base_path' => (string) ($node['basePath'] ?? $node['base_path'] ?? ''),
        'status' => (string) ($node['status'] ?? 'unknown'),
        'cpu' => isset($node['cpu']) ? (float) $node['cpu'] : null,
        'mem' => isset($node['mem']) ? (float) $node['mem'] : null,
    ];
}

function xuiNodeIsOnline(array $node)
{
    $status = strtolower((string) ($node['status'] ?? 'unknown'));
    return in_array($status, ['online', 'connected', 'up'], true);
}

function xuiNodeSubBase(array $node)
{
    if (!empty($node['sublink_template'])) {
        return rtrim((string) $node['sublink_template'], '/');
    }

    $basePath = rtrim((string) ($node['base_path'] ?? ''), '/');
    $scheme = (string) ($node['scheme'] ?? 'https');
    $host = (string) ($node['host'] ?? '');
    $port = isset($node['port']) ? intval($node['port']) : 2053;

    return $scheme . '://' . $host . ':' . $port . $basePath;
}

function xuiConfigBelongsToNode($configLine, array $node)
{
    $configLine = (string) $configLine;
    $host = (string) ($node['host'] ?? '');
    if ($host === '') {
        return false;
    }

    if (stripos($configLine, $host) !== false) {
        return true;
    }

    $displayName = (string) ($node['display_name'] ?? $node['name'] ?? '');
    if ($displayName !== '' && stripos($configLine, $displayName) !== false) {
        return true;
    }

    return false;
}

function xuiFilterConfigsBySellableNodes(array $configLines, array $sellableNodes)
{
    $filtered = [];
    foreach ($configLines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        foreach ($sellableNodes as $node) {
            if (xuiConfigBelongsToNode($line, $node)) {
                $filtered[] = $line;
                break;
            }
        }
    }

    return array_values($filtered);
}

function xuiParseSubscriptionBody($raw)
{
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    if (function_exists('isBase64') && isBase64($raw)) {
        $raw = base64_decode($raw);
    }

    $lines = preg_split('/\R/u', trim($raw));
    if (!is_array($lines)) {
        return [];
    }

    $output = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $output[] = $line;
        }
    }

    return $output;
}

function xuiFetchSubscriptionLines($url)
{
    if (!function_exists('outputlink')) {
        return [];
    }

    $raw = outputlink($url);
    return xuiParseSubscriptionBody($raw);
}

function xuiFetchConfigsForSellableNodes(array $panel, $subId, array $sellableNodes)
{
    $subId = trim((string) $subId);
    if ($subId === '') {
        return [];
    }

    $centralBase = rtrim((string) ($panel['linksubx'] ?? ''), '/');
    if ($centralBase !== '') {
        $centralLines = xuiFetchSubscriptionLines($centralBase . '/' . $subId);
        if (!empty($centralLines) && !empty($sellableNodes)) {
            $filtered = xuiFilterConfigsBySellableNodes($centralLines, $sellableNodes);
            if (!empty($filtered)) {
                return $filtered;
            }
        }
        if (!empty($centralLines) && empty($sellableNodes)) {
            return $centralLines;
        }
    }

    $configs = [];
    foreach ($sellableNodes as $node) {
        $nodeUrl = xuiNodeSubBase($node) . '/' . $subId;
        foreach (xuiFetchSubscriptionLines($nodeUrl) as $line) {
            $configs[] = $line;
        }
    }

    return array_values(array_unique($configs));
}

function xuiGetPanelNodesFromDb($codePanel, $sellableOnly = false)
{
    global $pdo;

    $sql = 'SELECT * FROM panel_nodes WHERE code_panel = :code_panel';
    if ($sellableOnly) {
        $sql .= " AND enabled = 1 AND status IN ('online', 'connected', 'up')";
    }
    $sql .= ' ORDER BY remote_id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':code_panel' => $codePanel]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function xuiGetSellableNodes($codePanel)
{
    return xuiGetPanelNodesFromDb($codePanel, true);
}

function xuiSyncPanelNodes(array $panel)
{
    global $pdo;

    $result = xuiV3NodesList($panel);
    if (!$result['success'] || !is_array($result['obj'])) {
        return [
            'success' => false,
            'msg' => $result['msg'] ?? 'Unable to fetch nodes',
            'count' => 0,
        ];
    }

    $seenRemoteIds = [];
    $now = time();

    foreach ($result['obj'] as $rawNode) {
        if (!is_array($rawNode)) {
            continue;
        }

        $node = xuiNormalizeNodeRow($rawNode);
        if ($node['remote_id'] <= 0 || $node['host'] === '') {
            continue;
        }

        $seenRemoteIds[] = $node['remote_id'];

        $stmt = $pdo->prepare('SELECT id, enabled FROM panel_nodes WHERE code_panel = :code_panel AND remote_id = :remote_id LIMIT 1');
        $stmt->execute([
            ':code_panel' => $panel['code_panel'],
            ':remote_id' => $node['remote_id'],
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $update = $pdo->prepare('UPDATE panel_nodes SET name = :name, scheme = :scheme, host = :host, port = :port, base_path = :base_path, status = :status, cpu = :cpu, mem = :mem, last_synced_at = :last_synced_at WHERE id = :id');
            $update->execute([
                ':name' => $node['name'],
                ':scheme' => $node['scheme'],
                ':host' => $node['host'],
                ':port' => $node['port'],
                ':base_path' => $node['base_path'],
                ':status' => $node['status'],
                ':cpu' => $node['cpu'],
                ':mem' => $node['mem'],
                ':last_synced_at' => $now,
                ':id' => $row['id'],
            ]);
        } else {
            $insert = $pdo->prepare('INSERT INTO panel_nodes (code_panel, remote_id, name, scheme, host, port, base_path, status, cpu, mem, enabled, display_name, sublink_template, last_synced_at) VALUES (:code_panel, :remote_id, :name, :scheme, :host, :port, :base_path, :status, :cpu, :mem, 1, NULL, NULL, :last_synced_at)');
            $insert->execute([
                ':code_panel' => $panel['code_panel'],
                ':remote_id' => $node['remote_id'],
                ':name' => $node['name'],
                ':scheme' => $node['scheme'],
                ':host' => $node['host'],
                ':port' => $node['port'],
                ':base_path' => $node['base_path'],
                ':status' => $node['status'],
                ':cpu' => $node['cpu'],
                ':mem' => $node['mem'],
                ':last_synced_at' => $now,
            ]);
        }
    }

    if (!empty($seenRemoteIds)) {
        $placeholders = implode(',', array_fill(0, count($seenRemoteIds), '?'));
        $disable = $pdo->prepare("UPDATE panel_nodes SET enabled = 0, status = 'missing', last_synced_at = ? WHERE code_panel = ? AND remote_id NOT IN ($placeholders)");
        $disable->execute(array_merge([$now, $panel['code_panel']], $seenRemoteIds));
    }

    update('marzban_panel', 'nodes_synced_at', (string) $now, 'code_panel', $panel['code_panel']);

    return [
        'success' => true,
        'msg' => 'Nodes synced',
        'count' => count($seenRemoteIds),
    ];
}

function xuiSetPanelModeFromDetection(array $panel)
{
    $mode = xuiDetectPanelMode($panel);
    update('marzban_panel', 'panel_mode', $mode, 'code_panel', $panel['code_panel']);
    update('marzban_panel', 'node_balancer', 'all', 'code_panel', $panel['code_panel']);

    if ($mode === 'cluster') {
        return xuiSyncPanelNodes($panel);
    }

    return [
        'success' => true,
        'msg' => 'Single-node panel detected',
        'count' => 0,
    ];
}

function xuiFormatNodesSummary($codePanel)
{
    $nodes = xuiGetPanelNodesFromDb($codePanel, false);
    if (empty($nodes)) {
        return "No cluster nodes found.";
    }

    $lines = [];
    foreach ($nodes as $node) {
        $saleState = intval($node['enabled']) === 1 ? 'for sale' : 'hidden';
        $lines[] = sprintf(
            '%s | %s:%s | %s | %s',
            $node['name'],
            $node['host'],
            $node['port'],
            $node['status'],
            $saleState
        );
    }

    return implode("\n", $lines);
}

function xuiBuildNodeToggleKeyboard($codePanel)
{
    $nodes = xuiGetPanelNodesFromDb($codePanel, false);
    $keyboard = ['inline_keyboard' => []];

    foreach ($nodes as $node) {
        $state = intval($node['enabled']) === 1 ? '✅' : '⛔';
        $keyboard['inline_keyboard'][] = [[
            'text' => $state . ' ' . $node['name'] . ' (' . $node['status'] . ')',
            'callback_data' => 'xui_node_toggle_' . $node['id'],
        ]];
    }

    $keyboard['inline_keyboard'][] = [[
        'text' => '🔁 Sync nodes now',
        'callback_data' => 'xui_node_sync_' . $codePanel,
    ]];

    return json_encode($keyboard);
}

function xuiToggleNodeSaleById($nodeId)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT id, enabled, code_panel FROM panel_nodes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => intval($nodeId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $newValue = intval($row['enabled']) === 1 ? 0 : 1;
    update('panel_nodes', 'enabled', (string) $newValue, 'id', $row['id']);
    return $row['code_panel'];
}

function xuiToggleNodeSale($codePanel, $remoteId)
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT id, enabled FROM panel_nodes WHERE code_panel = :code_panel AND remote_id = :remote_id LIMIT 1');
    $stmt->execute([
        ':code_panel' => $codePanel,
        ':remote_id' => intval($remoteId),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $newValue = intval($row['enabled']) === 1 ? 0 : 1;
    update('panel_nodes', 'enabled', (string) $newValue, 'id', $row['id']);
    return true;
}

function xuiResolveBuyerConfigs(array $panel, $subId)
{
    if (($panel['panel_mode'] ?? 'single') !== 'cluster') {
        $links = xuiFetchSubscriptionLines(rtrim((string) $panel['linksubx'], '/') . '/' . $subId);
        return $links;
    }

    $sellableNodes = xuiGetSellableNodes($panel['code_panel']);
    return xuiFetchConfigsForSellableNodes($panel, $subId, $sellableNodes);
}
