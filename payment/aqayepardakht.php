<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

$ManagePanel = new ManagePanel();

/*
 * Aqayepardakht callback handler.
 *
 * The previous version was vulnerable to double-credit if the user
 * refreshed the callback URL (it read payment_Status before crediting
 * and then wrote it back). All credits now go through
 * confirmPaymentAtomically() so that subsequent replays are turned into
 * no-ops at the DB level (row locked + status check).
 *
 * Inputs from $_POST are validated against strict character classes;
 * htmlspecialchars() is reserved for outputting into the HTML page.
 */

$invoiceIdRaw = $_POST['invoice_id'] ?? '';
$transidRaw   = $_POST['transid'] ?? '';

$invoice_id = (is_string($invoiceIdRaw) && preg_match('/^[A-Za-z0-9_\-]+$/', $invoiceIdRaw)) ? $invoiceIdRaw : '';
$transid    = (is_string($transidRaw)   && preg_match('/^[A-Za-z0-9_\-]+$/', $transidRaw))   ? $transidRaw   : '';

$setting    = select('setting', '*');
$PaySetting = select('PaySetting', 'ValuePay', 'NamePay', 'merchant_id_aqayepardakht', 'select');
$PaySetting = is_array($PaySetting) ? (string) ($PaySetting['ValuePay'] ?? '') : '';

$priceRow = $invoice_id !== '' ? select('Payment_report', 'price', 'id_order', $invoice_id, 'select') : null;
$price    = is_array($priceRow) ? (string) ($priceRow['price'] ?? '') : '';

$payment_status     = '';
$dec_payment_status = '';

if ($invoice_id !== '' && $transid !== '' && $price !== '' && $PaySetting !== '') {
    $payload = json_encode([
        'pin'     => $PaySetting,
        'amount'  => $price,
        'transid' => $transid,
    ]);

    $ch = curl_init('https://panel.aqayepardakht.ir/api/v2/verify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ]);
    $rawResult = curl_exec($ch);
    curl_close($ch);
    $result = is_string($rawResult) ? json_decode($rawResult) : null;

    if (is_object($result) && (string) ($result->code ?? '') === '1') {
        $textbotlang = languagechange(__DIR__ . '/../text.json');

        try {
            $confirmation = confirmPaymentAtomically($invoice_id, [
                'provider' => 'aqayepardakht',
                'response' => $result,
            ], '../images.jpg');
        } catch (Throwable $e) {
            error_log('aqayepardakht.php confirmation failed: ' . $e->getMessage());
            $confirmation = ['status' => 'error', 'confirmed' => false, 'payment_report' => null];
        }

        if (($confirmation['status'] ?? '') !== 'error') {
            $payment_status     = 'پرداخت موفق';
            $dec_payment_status = 'از انجام تراکنش متشکریم!';

            if (!empty($confirmation['confirmed']) && is_array($confirmation['payment_report'] ?? null)) {
                $Payment_report = $confirmation['payment_report'];

                $pricecashback = select('PaySetting', 'ValuePay', 'NamePay', 'chashbackaqaypardokht', 'select');
                $pricecashback = is_array($pricecashback) ? (string) ($pricecashback['ValuePay'] ?? '0') : '0';
                $Balance_id    = select('user', '*', 'id', $Payment_report['id_user'] ?? '', 'select');

                if (is_array($Balance_id) && $pricecashback !== '0' && intval($pricecashback) > 0) {
                    $bonus           = ((float) $Payment_report['price']) * ((float) $pricecashback) / 100;
                    $Balance_confrim = intval($Balance_id['Balance']) + $bonus;
                    update('user', 'Balance', $Balance_confrim, 'id', $Balance_id['id']);
                    $textGift = '🎁 کاربر عزیز مبلغ ' . tg_html_escape($bonus) . ' تومان به عنوان هدیه واریز به حساب شما واریز گردید.';
                    sendmessage($Balance_id['id'], $textGift, null, 'HTML');
                }

                $paymentreports = select('topicid', 'idreport', 'report', 'paymentreport', 'select');
                $paymentreports = is_array($paymentreports) ? (int) ($paymentreports['idreport'] ?? 0) : 0;

                $text_report = "💵 پرداخت جدید

آیدی عددی کاربر : " . tg_html_escape($Payment_report['id_user'] ?? '') . "
نام کاربری کاربر : " . tg_html_escape(is_array($Balance_id) ? ($Balance_id['username'] ?? '') : '') . "
مبلغ تراکنش " . tg_html_escape($price) . "
روش پرداخت : درگاه آقای پرداخت";
                $channelReport = (string) ($setting['Channel_Report'] ?? '');
                if ($channelReport !== '') {
                    telegram('sendmessage', [
                        'chat_id'           => $channelReport,
                        'message_thread_id' => $paymentreports,
                        'text'              => $text_report,
                        'parse_mode'        => 'HTML',
                    ]);
                }
            }
        }
    } else {
        $code = is_object($result) ? (string) ($result->code ?? '') : '';
        $payment_status = [
            '0' => 'پرداخت انجام نشد',
            '2' => 'تراکنش قبلا وریفای و پرداخت شده است',
        ][$code] ?? 'پرداخت انجام نشد';
        $dec_payment_status = '';
    }
}

$paymentStatusSafe    = tg_html_escape($payment_status);
$decPaymentStatusSafe = tg_html_escape($dec_payment_status);
$invoiceIdSafe        = tg_html_escape($invoice_id);
$priceSafe            = tg_html_escape($price);
?>
<html>
<head>
    <title>فاکتور پرداخت</title>
    <style>
    @font-face {
        font-family: 'vazir';
        src: url('/Vazir.eot');
        src: local('☺'), url('../fonts/Vazir.woff') format('woff'), url('../fonts/Vazir.ttf') format('truetype');
    }
    body { font-family: vazir; background-color: #f2f2f2; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
    .confirmation-box { background-color: #ffffff; border-radius: 8px; width: 25%; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); padding: 40px; text-align: center; }
    h1 { color: #333333; margin-bottom: 20px; }
    p  { color: #666666; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="confirmation-box">
        <h1><?= $paymentStatusSafe ?></h1>
        <p>شماره تراکنش:<span><?= $invoiceIdSafe ?></span></p>
        <p>مبلغ پرداختی: <span><?= $priceSafe ?></span> تومان</p>
        <p>تاریخ: <span><?= jdate('Y/m/d') ?></span></p>
        <p><?= $decPaymentStatusSafe ?></p>
    </div>
</body>
</html>
