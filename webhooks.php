<?php

ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once 'config.php';
require_once 'botapi.php';
require_once 'panels.php';
require_once 'function.php';

/**
 * Webhook endpoint for panel-driven notifications (Marzban / Marzneshin).
 *
 * Whatever happens inside this handler we MUST return an HTTP 200 to the
 * caller - otherwise the panel keeps retrying the same payload, the
 * worker stays in an error storm, and the queue starves real traffic.
 *
 * The script is wrapped in a single try/catch + register_shutdown_function
 * pair so that even an uncaught Throwable or a fatal error during include
 * still produces a clean 200 OK response with no leaked internals.
 */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    // Only escalate true fatal errors.
    if (in_array($err['type'] ?? 0, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        if (!headers_sent()) {
            http_response_code(200);
        }
        error_log('webhooks.php fatal: ' . ($err['message'] ?? 'unknown'));
    }
});

try {
    if (!isset($pdo) || $pdo === null) {
        // The bootstrap in config.php already logs the failure. Acknowledge
        // the request so the upstream queue does not retry.
        http_response_code(200);
        exit;
    }

    $ManagePanel = new ManagePanel();

    // -----------------------------------------------------------------
    // 1. Webhook authentication.
    //    The caller must send a base64-encoded shared secret in the
    //    X-Webhook-Secret header. We match it against admin.password using
    //    a *bound* parameter; the previous implementation embedded the
    //    decoded value via the generic select() helper, which is fine in
    //    isolation but easy to misuse. Using db_fetch directly here gives
    //    us a clean parameterised lookup and makes the intent explicit.
    // -----------------------------------------------------------------
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    $webhookSecretEncoded = '';
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'X-Webhook-Secret') === 0) {
            $webhookSecretEncoded = is_string($value) ? trim($value) : '';
            break;
        }
    }

    if ($webhookSecretEncoded === '') {
        http_response_code(200);
        exit;
    }

    // base64_decode() with strict mode keeps us from accepting arbitrary
    // input that happens to "round-trip" through the encoder.
    $webhookSecret = base64_decode($webhookSecretEncoded, true);
    if (!is_string($webhookSecret) || $webhookSecret === '') {
        http_response_code(200);
        exit;
    }

    if (!verifyPaymentWebhookSecret($webhookSecretEncoded)) {
        http_response_code(200);
        exit;
    }

    // -----------------------------------------------------------------
    // 2. Parse the panel payload defensively.
    //    Marzban delivers events as a JSON array of objects, but a buggy
    //    panel build could send `{...}` or `null` and the previous
    //    implementation crashed with "Cannot access [0] on null".
    // -----------------------------------------------------------------
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || $rawBody === '') {
        http_response_code(200);
        exit;
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        error_log('webhooks.php: payload was not JSON');
        http_response_code(200);
        exit;
    }

    // Accept either a list-of-events (Marzban) or a single event object.
    if (isset($decoded[0]) && is_array($decoded[0])) {
        $event = $decoded[0];
    } elseif (isset($decoded['action'])) {
        $event = $decoded;
    } else {
        http_response_code(200);
        exit;
    }

    $action = isset($event['action']) ? (string) $event['action'] : '';
    if ($action === '') {
        http_response_code(200);
        exit;
    }

    // -----------------------------------------------------------------
    // 3. Resolve the invoice for this username + look up the bot owner.
    //    Bail out gracefully if any pre-condition fails.
    // -----------------------------------------------------------------
    $reportcron = select('topicid', 'idreport', 'report', 'reportcron', 'select');
    $reportcron = is_array($reportcron) ? (int) ($reportcron['idreport'] ?? 0) : 0;

    $textservice = select('textbot', 'text', 'id_text', 'text_Purchased_services', 'select');
    $textservice = is_array($textservice) ? (string) ($textservice['text'] ?? '') : '';

    $setting = select('setting', '*');
    if (!is_array($setting)) {
        $setting = [];
    }

    $username = isset($event['username']) ? (string) $event['username'] : '';
    if ($username === '') {
        http_response_code(200);
        exit;
    }

    $invoice = select('invoice', '*', 'username', $username, 'select');
    if (!is_array($invoice)) {
        http_response_code(200);
        exit;
    }
    if (($invoice['name_product'] ?? '') === 'سرویس تست') {
        http_response_code(200);
        exit;
    }

    $user = select('user', '*', 'id', $invoice['id_user'] ?? '', 'select');
    if (!is_array($user)) {
        $user = ['status_cron' => '0'];
    }

    $channelReport = (string) ($setting['Channel_Report'] ?? '');

    // -----------------------------------------------------------------
    // 4. Dispatch on action.
    // -----------------------------------------------------------------
    if ($action === 'reached_usage_percent') {
        $userPayload   = is_array($event['user'] ?? null) ? $event['user'] : [];
        $remaining     = ($userPayload['data_limit'] ?? 0) - ($userPayload['used_traffic'] ?? 0);
        $remainingHuman = formatBytes(max(0, (int) $remaining));
        $dataLimitHuman = formatBytes(max(0, (int) ($userPayload['data_limit'] ?? 0)));

        $usernameSafe = tg_html_escape($username);
        $userIdSafe   = tg_html_escape($invoice['id_user'] ?? '');
        $statusSafe   = tg_html_escape($userPayload['status'] ?? '');

        $replyMarkup = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '💊 تمدید سرویس', 'callback_data' => 'extend_' . $invoice['id_invoice']],
                ],
            ],
        ]);
        $userText = "با سلام خدمت شما کاربر گرامی 👋\n" .
            "🚨 از حجم سرویس {$usernameSafe} تنها {$remainingHuman} باقی مانده است. " .
            "لطفاً در صورت تمایل برای تمدید سرویستون از طریق بخش «{$textservice}» اقدام بفرمایین";
        if (intval($user['status_cron'] ?? 0) !== 0) {
            sendmessage($invoice['id_user'], $userText, $replyMarkup, 'HTML');
        }

        $reportText = "📌 اطلاعیه کرون حجم\n\n" .
            "نام کاربری سرویس :‌ <code>{$usernameSafe}</code>\n" .
            "آیدی عددی کاربر :‌ <code>{$userIdSafe}</code>\n" .
            "وضعیت سرویس : {$statusSafe}\n" .
            "حجم باقی مانده : {$remainingHuman}\n" .
            "حجم کل سرویس : {$dataLimitHuman}";
        if ($channelReport !== '') {
            telegram('sendmessage', [
                'chat_id'           => $channelReport,
                'message_thread_id' => $reportcron,
                'text'              => $reportText,
                'parse_mode'        => 'HTML',
            ]);
        }

        $nextStatus = ($invoice['Status'] ?? '') === 'end_of_volume' ? 'sendedwarn' : 'end_of_volume';
        update('invoice', 'Status', $nextStatus, 'username', $username);
    } elseif ($action === 'reached_days_left') {
        $userPayload = is_array($event['user'] ?? null) ? $event['user'] : [];
        $timeService = (int) ($userPayload['expire'] ?? 0) - time();
        $day = intval($timeService / 86400);
        if ($day <= 0) {
            $day = intval($timeService / 3600) . 'ساعت';
        } else {
            $day = $day . 'روز';
        }

        $usernameSafe = tg_html_escape($username);
        $userIdSafe   = tg_html_escape($invoice['id_user'] ?? '');
        $statusSafe   = tg_html_escape($userPayload['status'] ?? '');

        $replyMarkup = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '💊 تمدید سرویس', 'callback_data' => 'extend_' . $invoice['id_invoice']],
                ],
            ],
        ]);
        $userText = "با سلام خدمت شما کاربر گرامی 👋\n" .
            "📌 از مهلت زمانی استفاده از سرویس {$usernameSafe} فقط {$day} باقی مانده است. " .
            "لطفاً در صورت تمایل برای تمدید این سرویس، از طریق بخش «{$textservice}» اقدام بفرمایین. با تشکر از همراهی شما";
        if (intval($user['status_cron'] ?? 0) !== 0) {
            sendmessage($invoice['id_user'], $userText, $replyMarkup, 'HTML');
        }

        $reportText = "📌 اطلاعیه کرون زمان\n\n" .
            "نام کاربری سرویس :‌ <code>{$usernameSafe}</code>\n" .
            "آیدی عددی کاربر :‌ <code>{$userIdSafe}</code>\n" .
            "وضعیت سرویس : {$statusSafe}\n" .
            "تعداد روز باقی مانده ‌:‌{$day}";
        if ($channelReport !== '') {
            telegram('sendmessage', [
                'chat_id'           => $channelReport,
                'message_thread_id' => $reportcron,
                'text'              => $reportText,
                'parse_mode'        => 'HTML',
            ]);
        }

        $nextStatus = ($invoice['Status'] ?? '') === 'end_of_volume' ? 'sendedwarn' : 'end_of_time';
        update('invoice', 'Status', $nextStatus, 'username', $username);
    } elseif (in_array($action, ['user_expired', 'user_limited'], true)) {
        $panel = select('marzban_panel', '*', 'name_panel', $invoice['Service_location'] ?? '', 'select');
        $userPayload = is_array($event['user'] ?? null) ? $event['user'] : [];

        if (is_array($panel) && ($panel['inboundstatus'] ?? '') === 'oninbounddisable'
            && ($userPayload['data_limit_reset_strategy'] ?? '') === 'no_reset'
            && !empty($panel['inbound_deactive'])
        ) {
            $inbound = explode('*', (string) $panel['inbound_deactive']);
            if (count($inbound) >= 2) {
                update('invoice', 'uuid', json_encode($userPayload['proxies'] ?? []), 'username', $username);
                $proxies               = [];
                $inbounds              = [];
                $proxies[$inbound[0]]  = new stdClass();
                $inbounds[$inbound[0]] = [$inbound[1]];
                $configs = [
                    'proxies'  => $proxies,
                    'inbounds' => $inbounds,
                ];
                $ManagePanel->Modifyuser($username, $panel['name_panel'], $configs);
            }
        }
    }

    http_response_code(200);
    exit;
} catch (Throwable $e) {
    error_log('webhooks.php uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(200);
    }
    exit;
}
