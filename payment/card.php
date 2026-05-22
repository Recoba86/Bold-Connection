<?php
ini_set('error_log', 'error.log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
$ManagePanel = new ManagePanel();
require __DIR__ . '/../vendor/autoload.php';
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

$PaySetting = select("PaySetting", "ValuePay", "NamePay", "statuscardautoconfirm", "select")['ValuePay'];
if($PaySetting == "onautoconfirm"){
$name_post = array_keys($_POST);
$name_post = array_map('htmlspecialchars', $name_post);
$name_post = preg_split("/_+/", $name_post[0], -1);
$secret_key = select("admin", "*", "password", base64_decode($name_post[0]), "count");
if($secret_key == 0)return;
$name_bank = $name_post[1];
$valuepost = $_POST["{$name_post[0]}_$name_bank"];
$setting = select("setting", "*");
$admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
 $datatextbotget = select("textbot", "*",null ,null ,"fetchAll");
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
    'textselectlocation' => '',
    'textafterpayibsng' => ''
);
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}
if($name_bank == 'blu'){
$pattern = "/(\d[\d,]+) ریال به حساب شما نشست\./u";
preg_match($pattern, $valuepost, $matches);
if (isset($matches[1])) {
    $amountString = str_replace(',', '', $matches[1]);
    $amount = intval($amountString);
    $amountInteger = intval($amount) * 0.1;
}}
elseif($name_bank == "meli"){
$pattern = '/انتقال:(.*?)[+\-]/u';
preg_match($pattern, $valuepost, $matches);
if (isset($matches[1])) {
    $amount = str_replace([',', '-'], '', $matches[1]);
    $amountInteger = intval($amount) * 0.1;
}}
elseif($name_bank == "grdsh"){
preg_match('/مبلغ: ([0-9,]+)/u',$valuepost, $matches);
if (isset($matches[1])) {
    $amountInteger = str_replace(',', '', $matches[1]) * 0.1;
}}
elseif($name_bank == "sadhrat"){
preg_match('/انتقال: ([\d,]+)/', $valuepost, $matches);
if (isset($matches[1])) {
    $amountInteger = str_replace(',', '', $matches[1]) * 0.1;
}}
elseif($name_bank == "melet"){
preg_match('/واریز(\d{1,3}(?:,\d{3})*)/u', $valuepost, $matches);
if (isset($matches[1])) {
    $amountInteger = str_replace(',', '', $matches[1])* 0.1;
}}
elseif($name_bank  == "terjart"){
if(preg_match('/واریز\s*:\s*([\d,]+)/u', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1]) * 0.1;
}}
elseif($name_bank  == "keshavarsi"){
if(preg_match('/واريز(\d+(?:,\d+)*)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}
elseif($name_bank  == "resalet"){
if(preg_match('/\+([\d,]+)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}
elseif($name_bank  == "sheahr"){
if(preg_match('/مبلغ:(\d+(?:,\d+)*)ريال//', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}
elseif($name_bank  == "maskan"){
if(preg_match('/انتقال اينترنت:\D*([\d,]+)/u', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "parsian"){
if(preg_match('/مبلغ:(\d{1,3}(?:,\d{3})*)\+/', $valuepost, $matches)) {
    file_put_contents('ss',json_encode($matches));
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "sphe"){
if(preg_match('/مبلغ:\s*([\d,]+)\s*ريال/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "paselc"){
if(preg_match('/\+([0-9,]+)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}elseif($name_bank  == "gharz"){
if(preg_match('/(\d{1,3}(?:,\d{3})*\+)/', $valuepost, $matches)) {
    $amountInteger = str_replace(',', '', $matches[1])*0.1;
}}


if (is_numeric($amountInteger) && substr($amountInteger, -3) === '000')return;
if(isset($amountInteger) && $amountInteger !== NULL){
    $stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE price = :price AND (payment_Status = 'Unpaid' OR payment_Status = 'waiting') LIMIT 1");
    $stmt->execute([':price' => $amountInteger]);
    $datauser = $stmt->fetch(PDO::FETCH_ASSOC);
    $order_id = $datauser['id_order'];
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    if(!isset($Payment_report['price']) || $Payment_report['price'] == null)return;
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    $textbotlang = languagechange('../text.json');

    if ($Payment_report['payment_Status'] == "paid" || $Payment_report['payment_Status'] == "reject") {
        telegram('answerCallbackQuery', array(
            'callback_query_id' => $callback_query_id,
            'text' => $textbotlang['Admin']['Payment']['reviewedpayment'],
            'show_alert' => true,
            'cache_time' => 5,
        ));
        return;}
        DirectPayment($order_id,"../images.jpg");
        update("Payment_report","payment_Status","paid",'id_order',$order_id);
    $balanceformatsell = number_format(select("user", "Balance", "id", $Payment_report['id_user'], "select")['Balance'], 0);
    $paymentreports = select("topicid","idreport","report","paymentreport","select")['idreport'];
    $text_report = "یک رسید توسط ربات  تایید شد

اطلاعات :
💰 مبلغ پرداخت : {$Payment_report['price']}
👤  آیدی عددی کاربر : {$Balance_id['id']} 
👤 نام کاربری کاربر : @{$Balance_id['username']} 
موجودی کاربر : $balanceformatsell تومان
کد پیگیری پرداخت : $order_id";
    if (strlen($setting['Channel_Report']) > 0) {
        telegram('sendmessage',[
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentreports,
        'text' => $text_report,
        'parse_mode' => "HTML"
        ]);
    }
}
}
