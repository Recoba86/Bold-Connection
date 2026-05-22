<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';
$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
$datatextbotget = select("textbot", "*", null, null, "fetchAll");
$datatxtbot = array();
foreach ($datatextbotget as $row) {
    $datatxtbot[] = array(
        'id_text' => $row['id_text'],
        'text' => $row['text']
    );
}
$datatextbot = array(
    'textafterpay' => '',
    'textaftertext' => '',
    'textmanual' => '',
    'textselectlocation' => ''
);
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}
$textbotlang = languagechange('../text.json');
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['payment_status']) || $data['payment_status'] !== "finished" || empty($data['payment_id'])) {
    http_response_code(200);
    exit;
}

if (isset($data['payment_status']) && $data['payment_status'] == "finished") {
    $pay = StatusPayment($data['payment_id']);
    if (($pay['payment_status'] ?? null) != "finished") {
        http_response_code(200);
        exit;
    }
    $Payment_report = select("Payment_report", "*", "dec_not_confirmed", $pay['invoice_id'], "select");
    if ($Payment_report) {
        $confirmation = confirmPaymentAtomically($Payment_report['id_order'], [
            'provider' => 'nowpayments',
            'webhook' => $data,
            'payment' => $pay,
        ]);
        if (($confirmation['status'] ?? '') === 'error') {
            http_response_code(500);
            exit;
        }
        if (empty($confirmation['confirmed'])) {
            http_response_code(200);
            exit;
        }
        $Payment_report = $confirmation['payment_report'];
        $pricecashback = select("PaySetting", "ValuePay", "NamePay", "cashbacknowpayment", "select")['ValuePay'];
        $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
        if ($pricecashback != "0") {
            $result = ($Payment_report['price'] * $pricecashback) / 100;
            $Balance_confrim = intval($Balance_id['Balance']) + $result;
            update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
            $pricecashback = number_format($pricecashback);
            $text_report = "🎁 کاربر عزیز مبلغ $result تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
            sendmessage($Balance_id['id'], $text_report, null, 'HTML');
        }
        $text_reportpayment = "💵 پرداخت جدید
- 👤 نام کاربری کاربر : @{$Balance_id['username']}
- ‏🆔آیدی عددی کاربر : {$Balance_id['id']}
- 💸 مبلغ تراکنش {$Payment_report['price']}
- 📥 مبلغ واریز شده ترون. : {$pay['actually_paid']}
- 💳 روش پرداخت :  nowpayment";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $text_reportpayment,
                'parse_mode' => "HTML"
            ]);
        }
    }
}
http_response_code(200);
exit;
