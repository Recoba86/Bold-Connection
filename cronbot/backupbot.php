<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';

/*
 * Backup cron.
 *
 * The previous implementation interpolated $folderName (which contains the
 * agent bot's Telegram username) directly into a shell command, and
 * inlined the database password into a `mysqldump -p'$pwd' ...` call.
 *
 * Both of these are now built with escapeshellarg() and an explicit
 * argv-style sprintf, so a username like `me'; rm -rf /; #` or a password
 * containing a quote can no longer break out of its argument.
 */

$reportbackup = select('topicid', 'idreport', 'report', 'backupfile', 'select');
$reportbackup = is_array($reportbackup) ? (int) ($reportbackup['idreport'] ?? 0) : 0;

$destination = getcwd();
$setting     = select('setting', '*');
$setting     = is_array($setting) ? $setting : [];
$channelReport = (string) ($setting['Channel_Report'] ?? '');

$sourcefir = dirname($destination);
$botlist   = select('botsaz', '*', null, null, 'fetchAll');

if (is_array($botlist)) {
    foreach ($botlist as $bot) {
        if (!is_array($bot)) {
            continue;
        }

        // Only allow conservative characters in the on-disk folder name.
        $rawFolder  = (string) ($bot['id_user'] ?? '') . (string) ($bot['username'] ?? '');
        $folderName = preg_replace('/[^A-Za-z0-9_\-]/', '', $rawFolder);
        if ($folderName === '' || strlen($folderName) > 200) {
            error_log('backupbot: skipped bot with invalid folder name');
            continue;
        }

        $baseDir  = $sourcefir . '/vpnbot/' . $folderName;
        $zipPath  = $destination . '/file.zip';

        $command = sprintf(
            'zip -r %s %s %s %s',
            escapeshellarg($zipPath),
            escapeshellarg($baseDir . '/data'),
            escapeshellarg($baseDir . '/product.json'),
            escapeshellarg($baseDir . '/product_name.json')
        );

        if (!isShellExecAvailable()) {
            error_log('backupbot: shell_exec unavailable; skipping agent bot ' . $folderName);
            continue;
        }

        shell_exec($command);

        if (file_exists('file.zip') && $channelReport !== '') {
            telegram('sendDocument', [
                'chat_id'           => $channelReport,
                'message_thread_id' => $reportbackup,
                'document'          => new CURLFile('file.zip'),
                'caption'           => '@' . ($bot['username'] ?? '') . ' | ' . ($bot['id_user'] ?? ''),
            ]);
            @unlink('file.zip');
        }
    }
}

// ----- Main database backup ------------------------------------------------
$backup_file_name = 'backup_' . date('Y-m-d') . '.sql';
$zip_file_name    = 'backup_' . date('Y-m-d') . '.zip';
$dbhostLocal      = empty($dbhost) ? 'localhost' : $dbhost;

// Build mysqldump invocation safely. Each argument is escapeshellarg'd so
// passwords containing quotes, dollar signs or backticks cannot break out
// of the command.
$command = sprintf(
    'mysqldump -h %s -u %s -p%s --no-tablespaces --ssl-mode=DISABLED %s > %s 2>/dev/null',
    escapeshellarg($dbhostLocal),
    escapeshellarg((string) $usernamedb),
    escapeshellarg((string) $passworddb),
    escapeshellarg((string) $dbname),
    escapeshellarg($backup_file_name)
);

$output     = [];
$return_var = 0;
exec($command, $output, $return_var);

if ($return_var !== 0) {
    if ($channelReport !== '') {
        telegram('sendmessage', [
            'chat_id'           => $channelReport,
            'message_thread_id' => $reportbackup,
            'text'              => '❌❌❌❌❌❌ خطا در بکاپ گیری ',
        ]);
    }
    return;
}

$zip = new ZipArchive();
if ($zip->open($zip_file_name, ZipArchive::CREATE) === true) {
    $zip->addFile($backup_file_name, basename($backup_file_name));
    $zip->setEncryptionName(basename($backup_file_name), ZipArchive::EM_AES_256, 'mirzapro2026#$');
    $zip->close();

    if ($channelReport !== '') {
        telegram('sendDocument', [
            'chat_id'           => $channelReport,
            'message_thread_id' => $reportbackup,
            'document'          => new CURLFile($zip_file_name),
            'caption'           => "📌 خروجی دیتابیس ربات اصلی \nتوضیحات : https://t.me/mirzapanel/915",
        ]);
    }
    @unlink($zip_file_name);
    @unlink($backup_file_name);
}
