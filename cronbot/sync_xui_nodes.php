<?php
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../xui_nodes.php';

$panels = select('marzban_panel', '*', 'type', 'x-ui_single', 'fetchAll');
if (!is_array($panels)) {
    return;
}

foreach ($panels as $panel) {
    if (($panel['panel_mode'] ?? 'single') !== 'cluster') {
        continue;
    }

    xuiSyncPanelNodes($panel);
}
