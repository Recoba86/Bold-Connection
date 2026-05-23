<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../panels.php';
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
 * Zarinpal callback handler.
 *
 * Key changes vs the previous version:
 *   - The previous flow read Payment_report, then called DirectPayment(),
 *     then updated payment_Status. If the user refreshed the callback URL
 *     during the gap, balance was credited twice. We now go through
 *     confirmPaymentAtomically() which holds a row lock for the duration
 *     of the credit.
 *   - $_GET parameters are validated against strict character classes
 *     instead of being passed through htmlspecialchars() (which mangles
 *     legitimate ampersands and was being used as input sanitisation).
 */

$authorityRaw = $_GET['Authority'] ?? '';
$statusRaw    = $_GET['Status'] ?? '';

$Authority    = (is_string($authorityRaw) && preg_match('/^[A-Za-z0-9]+$/', $authorityRaw)) ? $authorityRaw : '';
$StatusPayment = (is_string($statusRaw)   && preg_match('/^[A-Za-z]+$/', $statusRaw))      ? $statusRaw    : '';

$setting    = select('setting', '*');
$PaySetting = select('PaySetting', 'ValuePay', 'NamePay', 'merchant_zarinpal', 'select');
$PaySetting = is_array($PaySetting) ? (string) ($PaySetting['ValuePay'] ?? '') : '';

$Payment_reports = is_array(select('Payment_report', '*', 'dec_not_confirmed', $Authority, 'select'))
    ? select('Payment_report', '*', 'dec_not_confirmed', $Authority, 'select')
    : [];
$price      = (string) ($Payment_reports['price'] ?? '');
$invoice_id = (string) ($Payment_reports['id_order'] ?? '');

// Localised UI strings (kept for the rendered HTML at the bottom of this file).
$payment_status     = '';
$dec_payment_status = '';

if ($StatusPayment === 'OK' && $Authority !== '' && $invoice_id !== '') {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://api.zarinpal.com/pg/v4/payment/verify.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'merchant_id' => $PaySetting,
            'amount'      => $price,
            'authority'   => $Authority,
            'description' => $Payment_reports['id_user'] ?? '',
        ]),
    ]);
    $raw = curl_exec($curl);
    curl_close($curl);

    $response = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($response)) {
        $response = [];
    }

    $errorMessages = [
        '-9'  => 'خطا در ارسال داده',
        '-10' => 'ای پی یا مرچنت كد پذیرنده صحیح نیست.',
        '-11' => 'مرچنت کد فعال نیست،',
        '-12' => 'تلاش بیش از دفعات مجاز در یک بازه زمانی کوتاه',
        '-15' => 'درگاه پرداخت به حالت تعلیق در آمده است',
        '-16' => 'سطح تایید پذیرنده پایین تر از سطح نقره ای است.',
        '-17' => 'محدودیت پذیرنده در سطح آبی',
        '-30' => 'پذیرنده اجازه دسترسی به سرویس تسویه اشتراکی شناور را ندارد.',
        '-31' => 'حساب بانکی تسویه را به پنل اضافه کنید.',
        '-32' => 'مبلغ وارد شده از مبلغ کل تراکنش بیشتر است.',
        '-33' => 'درصدهای وارد شده صحیح نیست.',
        '-34' => 'مبلغ وارد شده از مبلغ کل تراکنش بیشتر است.',
        '-35' => 'تعداد افراد دریافت کننده تسهیم بیش از حد مجاز است.',
        '-36' => 'حداقل مبلغ جهت تسهیم باید ۱۰۰۰۰ ریال باشد',
        '-37' => 'یک یا چند شماره شبای وارد شده برای تسهیم از سمت بانک غیر فعال است.',
        '-38' => 'خطا٬عدم تعریف صحیح شبا٬لطفا دقایقی دیگر تلاش کنید.',
        '-39' => 'خطایی رخ داده است',
        '-50' => 'مبلغ پرداخت شده با مقدار مبلغ ارسالی در متد وریفای متفاوت است.',
        '-51' => 'پرداخت ناموفق',
        '-52' => 'خطای غیر منتظره‌ای رخ داده است.',
        '-53' => 'پرداخت متعلق به این مرچنت کد نیست.',
        '-54' => 'اتوریتی نامعتبر است.',
    ];

    $errorCode = isset($response['errors']['code']) ? (string) $response['errors']['code'] : '';
    $payment_status = $errorMessages[$errorCode] ?? '';

    $responseMessage = $response['data']['message'] ?? '';
    if ($responseMessage === 'Verified' || $responseMessage === 'Paid') {
        // Make text.json available to DirectPayment() (invoked atomically).
        $textbotlang = languagechange(__DIR__ . '/../text.json');

        try {
            $confirmation = confirmPaymentAtomically($invoice_id, [
                'provider' => 'zarinpal',
                'response' => $response,
            ], '../images.jpg');
        } catch (Throwable $e) {
            error_log('zarinpal.php confirmation failed: ' . $e->getMessage());
            $confirmation = ['status' => 'error', 'confirmed' => false, 'payment_report' => null];
        }

        if (($confirmation['status'] ?? '') !== 'error') {
            $payment_status     = 'پرداخت موفق';
            $dec_payment_status = 'از انجام تراکنش متشکریم!';

            // Cashback + report are only emitted on the *first* successful
            // confirmation. Replays are detected via the atomic helper and
            // arrive here with confirmed=false / reason=already_paid.
            if (!empty($confirmation['confirmed']) && is_array($confirmation['payment_report'] ?? null)) {
                $Payment_report = $confirmation['payment_report'];
                $pricecashback  = select('PaySetting', 'ValuePay', 'NamePay', 'chashbackzarinpal', 'select');
                $pricecashback  = is_array($pricecashback) ? (string) ($pricecashback['ValuePay'] ?? '0') : '0';
                $Balance_id     = select('user', '*', 'id', $Payment_report['id_user'] ?? '', 'select');

                if (is_array($Balance_id) && $pricecashback !== '0' && intval($pricecashback) > 0) {
                    $result          = ((float) $Payment_report['price']) * ((float) $pricecashback) / 100;
                    $Balance_confrim = intval($Balance_id['Balance']) + $result;
                    update('user', 'Balance', $Balance_confrim, 'id', $Balance_id['id']);
                    $textGift = '🎁 کاربر عزیز مبلغ ' . tg_html_escape($result) . ' تومان به عنوان هدیه واریز به حساب شما واریز گردید.';
                    sendmessage($Balance_id['id'], $textGift, null, 'HTML');
                }

                $paymentreports = select('topicid', 'idreport', 'report', 'paymentreport', 'select');
                $paymentreports = is_array($paymentreports) ? (int) ($paymentreports['idreport'] ?? 0) : 0;

                $refcode  = tg_html_escape($response['data']['ref_id'] ?? '');
                $cardPan  = tg_html_escape($response['data']['card_pan'] ?? '');
                $priceFmt = number_format((float) $price);

                $text_report = "💵 پرداخت جدید

آیدی عددی کاربر : " . tg_html_escape($Payment_report['id_user'] ?? '') . "
نام کاربری کاربر : " . tg_html_escape(($Balance_id['username'] ?? '')) . "
مبلغ تراکنش {$priceFmt}
شماره تراکنش پرداخت : {$refcode}
شماره کارت کاربر : {$cardPan}
روش پرداخت : درگاه زرین پال";
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
        $payment_status = [
            '0' => 'پرداخت انجام نشد',
            '2' => 'تراکنش قبلا وریفای و پرداخت شده است',
        ][$errorCode] ?? $payment_status;
        $dec_payment_status = '';
    }
}

// All values below are echoed inside the HTML page; tg_html_escape is
// reused here to defend the rendered page against any malicious payload
// that ever lands in $payment_status / $invoice_id / $price.
$paymentStatusSafe     = tg_html_escape($payment_status);
$decPaymentStatusSafe  = tg_html_escape($dec_payment_status);
$invoiceIdSafe         = tg_html_escape($invoice_id);
$priceSafe             = tg_html_escape($price);
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

    body {
        font-family: vazir;
        background-color: #f2f2f2;
        margin: 0;
        padding: 20px;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
    }

    .confirmation-box {
        background-color: #ffffff;
        border-radius: 8px;
        width: 25%;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 40px;
        text-align: center;
    }

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
