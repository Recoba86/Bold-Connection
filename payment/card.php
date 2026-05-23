<?php
ini_set('error_log', 'error.log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/*
 * Card auto-confirm webhook.
 *
 * Inbound shape: $_POST contains a single key of the form
 *   "<base64-secret>_<bank-code>" => <bank-sms-body>
 *
 * The previous implementation:
 *   1. Applied htmlspecialchars() only to the *keys* of $_POST (useless).
 *   2. Allowed two concurrent requests with the same amount to credit
 *      the same invoice twice (read-modify-write on payment_Status).
 *   3. Had a stray double-slash in one regex (`//` instead of `/`).
 *   4. Echoed nothing back to the caller — but on error the worker
 *      would still emit a PHP warning to the bank's webhook target.
 *
 * The new version:
 *   - Always returns HTTP 200 (the bank doesn't care about the body).
 *   - Validates the secret with a bound query.
 *   - Confirms the payment row inside a transaction using
 *     confirmPaymentAtomically(), with a SELECT ... FOR UPDATE on the
 *     matching unpaid invoice so two simultaneous SMS deliveries can
 *     never credit the same invoice twice.
 */

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

try {
    if (!isset($pdo) || $pdo === null) {
        exit;
    }

    $PaySetting = select('PaySetting', 'ValuePay', 'NamePay', 'statuscardautoconfirm', 'select');
    $PaySetting = is_array($PaySetting) ? (string) ($PaySetting['ValuePay'] ?? '') : '';
    if ($PaySetting !== 'onautoconfirm') {
        exit;
    }

    // The POST key carries the encoded webhook secret and the bank id.
    $rawPostKeys = array_keys($_POST);
    if ($rawPostKeys === []) {
        exit;
    }

    $firstKey = (string) $rawPostKeys[0];
    if (preg_match('/^([A-Za-z0-9+\/=]+)_([A-Za-z0-9_\-]+)$/', $firstKey, $m) !== 1) {
        error_log('card.php: malformed POST key');
        exit;
    }
    $secretEncoded = $m[1];
    $name_bank     = $m[2];

    $secretDecoded = base64_decode($secretEncoded, true);
    if (!is_string($secretDecoded) || $secretDecoded === '') {
        exit;
    }

    if (!verifyPaymentWebhookSecret($secretEncoded)) {
        exit;
    }

    $valuepost = isset($_POST[$firstKey]) && is_string($_POST[$firstKey]) ? $_POST[$firstKey] : '';
    if ($valuepost === '') {
        exit;
    }

    $setting   = select('setting', '*');
    $setting   = is_array($setting) ? $setting : [];
    $admin_ids = select('admin', 'id_admin', null, null, 'FETCH_COLUMN');
    if (!is_array($admin_ids)) {
        $admin_ids = [];
    }

    // Load text bot translations into a map without iterating thousands of rows
    // multiple times. The previous implementation did three nested foreach
    // loops over the entire textbot table.
    $datatextbot = [
        'textafterpay'        => '',
        'textaftertext'       => '',
        'textmanual'          => '',
        'textselectlocation'  => '',
        'textafterpayibsng'   => '',
    ];
    $textRows = select('textbot', '*', null, null, 'fetchAll');
    if (is_array($textRows)) {
        foreach ($textRows as $row) {
            if (is_array($row) && isset($row['id_text'], $datatextbot[$row['id_text']])) {
                $datatextbot[$row['id_text']] = (string) ($row['text'] ?? '');
            }
        }
    }

    // ----- Per-bank amount extraction ----------------------------------
    // Each pattern returns the deposit amount in toman. The previous
    // version had a regex with a stray double-slash that silently failed.
    $amountInteger = null;
    $matches       = [];
    switch ($name_bank) {
        case 'blu':
            if (preg_match('/(\d[\d,]+) ریال به حساب شما نشست\./u', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'meli':
            if (preg_match('/انتقال:(.*?)[+\-]/u', $valuepost, $matches)) {
                $amountInteger = intval(str_replace([',', '-'], '', $matches[1])) * 0.1;
            }
            break;
        case 'grdsh':
            if (preg_match('/مبلغ: ([0-9,]+)/u', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'sadhrat':
            if (preg_match('/انتقال: ([\d,]+)/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'melet':
            if (preg_match('/واریز(\d{1,3}(?:,\d{3})*)/u', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'terjart':
            if (preg_match('/واریز\s*:\s*([\d,]+)/u', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'keshavarsi':
            if (preg_match('/واريز(\d+(?:,\d+)*)/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'resalet':
            if (preg_match('/\+([\d,]+)/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'sheahr':
            // Previously written as `.../...//`, which is an invalid regex.
            if (preg_match('/مبلغ:(\d+(?:,\d+)*)ريال/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'maskan':
            if (preg_match('/انتقال اينترنت:\D*([\d,]+)/u', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'parsian':
            if (preg_match('/مبلغ:(\d{1,3}(?:,\d{3})*)\+/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'sphe':
            if (preg_match('/مبلغ:\s*([\d,]+)\s*ريال/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'paselc':
            if (preg_match('/\+([0-9,]+)/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        case 'gharz':
            if (preg_match('/(\d{1,3}(?:,\d{3})*)\+/', $valuepost, $matches)) {
                $amountInteger = intval(str_replace(',', '', $matches[1])) * 0.1;
            }
            break;
        default:
            error_log('card.php: unknown bank ' . $name_bank);
            exit;
    }

    if ($amountInteger === null || !is_numeric($amountInteger)) {
        exit;
    }
    // Filter out obviously suspect ".000" amounts (legacy heuristic).
    if (substr((string) $amountInteger, -3) === '000') {
        exit;
    }

    // ----- Atomic match-and-credit -------------------------------------
    // Wrap the lookup + DirectPayment + status update in a transaction so
    // two simultaneous SMS deliveries for the same amount cannot both
    // claim the same invoice.
    $orderId = null;
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare(
            "SELECT id_order FROM Payment_report
             WHERE price = :price
               AND (payment_Status = 'Unpaid' OR payment_Status = 'waiting')
             ORDER BY at_updated ASC
             LIMIT 1 FOR UPDATE"
        );
        $sel->execute([':price' => $amountInteger]);
        $orderId = $sel->fetchColumn();

        if ($orderId !== false && $orderId !== null) {
            // Mark as 'paid' immediately - the row stays locked until
            // commit so a concurrent transaction sees the new status.
            $upd = $pdo->prepare(
                "UPDATE Payment_report
                 SET payment_Status = 'paid'
                 WHERE id_order = :id_order
                   AND payment_Status IN ('Unpaid', 'waiting')"
            );
            $upd->execute([':id_order' => $orderId]);
            clearSelectCache('Payment_report');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('card.php atomic claim failed: ' . $e->getMessage());
        exit;
    }

    if ($orderId === false || $orderId === null) {
        exit;
    }

    // Out of the transaction (DirectPayment performs Telegram I/O which
    // we don't want to hold a row lock for): run the user-facing side
    // effects.
    $textbotlang    = languagechange(__DIR__ . '/../text.json');
    DirectPayment($orderId, '../images.jpg');

    $Payment_report = select('Payment_report', '*', 'id_order', $orderId, 'select');
    if (!is_array($Payment_report)) {
        exit;
    }
    $Balance_id = select('user', '*', 'id', $Payment_report['id_user'] ?? '', 'select');
    $balanceformatsell = number_format(
        is_array($Balance_id) ? (float) ($Balance_id['Balance'] ?? 0) : 0,
        0
    );

    $paymentreports = select('topicid', 'idreport', 'report', 'paymentreport', 'select');
    $paymentreports = is_array($paymentreports) ? (int) ($paymentreports['idreport'] ?? 0) : 0;

    $text_report = "یک رسید توسط ربات  تایید شد\n\n" .
        "اطلاعات :\n" .
        "💰 مبلغ پرداخت : " . tg_html_escape($Payment_report['price'] ?? '') . "\n" .
        "👤 آیدی عددی کاربر : " . tg_html_escape(is_array($Balance_id) ? ($Balance_id['id'] ?? '') : '') . "\n" .
        "👤 نام کاربری کاربر : @" . tg_html_escape(is_array($Balance_id) ? ($Balance_id['username'] ?? '') : '') . "\n" .
        "موجودی کاربر : {$balanceformatsell} تومان\n" .
        "کد پیگیری پرداخت : " . tg_html_escape((string) $orderId);

    $channelReport = (string) ($setting['Channel_Report'] ?? '');
    if ($channelReport !== '') {
        telegram('sendmessage', [
            'chat_id'           => $channelReport,
            'message_thread_id' => $paymentreports,
            'text'              => $text_report,
            'parse_mode'        => 'HTML',
        ]);
    }
} catch (Throwable $e) {
    error_log('card.php uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // HTTP 200 was already sent at the top of the script.
}
