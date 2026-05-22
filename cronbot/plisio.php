<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
$datatextbotget = select("textbot", "*", null, null, "fetchAll");
$datatxtbot = [];
foreach ($datatextbotget as $row) {
    $datatxtbot[] = [
        'id_text' => $row['id_text'],
        'text' => $row['text'],
    ];
}
$datatextbot = [
    'textafterpay' => '',
    'textaftertext' => '',
    'textmanual' => '',
    'textselectlocation' => '',
];
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}

function statusplisio($tx_id)
{
    $api_key = select("PaySetting", "ValuePay", "NamePay", "apinowpayment", "select")['ValuePay'];
    $url = 'https://api.plisio.net/api/v1/operations?';
    $url .= '&api_key=' . urlencode($api_key);
    $url .= '&search=' . urlencode($tx_id);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

$stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE payment_Status = :payment_status AND Payment_Method = :payment_method");
$stmt->execute([
    ':payment_status' => 'Unpaid',
    ':payment_method' => 'plisio',
]);

while ($Payment_report = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $textbotlang = languagechange('../text.json');

    if (empty($Payment_report['dec_not_confirmed'])) {
        continue;
    }

    $StatusPayment = statusplisio($Payment_report['id_order']);
    $operation = $StatusPayment['data']['operations'][0] ?? [];
    $operationStatus = $operation['status'] ?? null;

    if ($operationStatus === null || $operationStatus === "cancelled") {
        $textexpire = "❌ تراکنش زیر بدلیل عدم پرداخت منقضی شد، لطفا وجهی بابت این تراکنش پرداخت نکنید

🛒 کد سفارش: {$Payment_report['id_order']}
💰 مبلغ:  {$Payment_report['price']} تومان";
        sendmessage($Payment_report['id_user'], $textexpire, null, 'html');
        $expireStmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = :payment_status WHERE id_order = :id_order AND payment_Status != 'paid'");
        $expireStmt->execute([
            ':payment_status' => 'expire',
            ':id_order' => $Payment_report['id_order'],
        ]);
        continue;
    }

    if ($operationStatus !== "completed") {
        continue;
    }

    $confirmation = confirmPaymentAtomically($Payment_report['id_order'], [
        'provider' => 'plisio',
        'status' => $StatusPayment,
    ]);
    if (($confirmation['status'] ?? '') === 'error') {
        http_response_code(500);
        exit;
    }
    if (empty($confirmation['confirmed'])) {
        continue;
    }

    $Payment_report = $confirmation['payment_report'];
    $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackplisio", "select")['ValuePay'];
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    if ($pricecashback != "0") {
        $result = ($Payment_report['price'] * $pricecashback) / 100;
        $Balance_confrim = intval($Balance_id['Balance']) + $result;
        update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
        $pricecashback = number_format($pricecashback);
        $text_report = "🎁 کاربر عزیز مبلغ $result تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }

    $txUrl = $operation['tx_url'][0] ?? ($StatusPayment['tx_url'][0] ?? '');
    $invoiceUrl = $operation['invoice_url'] ?? ($StatusPayment['invoice_url'] ?? '');
    $invoiceTotal = $operation['invoice_total_sum'] ?? ($StatusPayment['invoice_total_sum'] ?? '');
    $text_reportpayment = "💵 پرداخت جدید
- 👤 نام کاربری کاربر : @{$Balance_id['username']}
- ‏🆔آیدی عددی کاربر : {$Balance_id['id']}
- 💸 مبلغ تراکنش {$Payment_report['price']}
- 🔗 <a href = \"{$txUrl}\">لینک پرداخت </a>
- 🔗 <a href = \"{$invoiceUrl}\">لینک پرداخت plisio </a>
- 📥 مبلغ واریز شده ترون. : {$invoiceTotal}
- 💳 روش پرداخت :  plisio";
    if (strlen($setting['Channel_Report']) > 0) {
        telegram('sendmessage', [
            'chat_id' => $setting['Channel_Report'],
            'message_thread_id' => $paymentreports,
            'text' => $text_reportpayment,
            'parse_mode' => "HTML",
        ]);
    }
}

http_response_code(200);
exit;
