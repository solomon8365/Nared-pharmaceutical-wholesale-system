<?php
/**
 * Automatic daily database backup — scheduler entry point.
 *
 * Runs the daily backup when the "auto_daily_backup" setting is enabled.
 * Registered as a Windows Task Scheduler task ("NARED_Pharma_DailyBackup")
 * that invokes this script with the PHP CLI every day at 00:00.
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\pharma project\cron_auto_backup.php
 *
 * This script is CLI-only: it refuses to run through the web server.
 *
 * Manual registration (if the app could not register the task itself):
 *   schtasks /create /tn NARED_Pharma_DailyBackup /sc DAILY /st 00:00 /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\pharma project\cron_auto_backup.php\"" /f
 * Manual removal:
 *   schtasks /delete /tn NARED_Pharma_DailyBackup /f
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only: this script is invoked by the Windows Task Scheduler.');
}

require __DIR__ . '/db.php';
require_once __DIR__ . '/includes/backup_helper.php';

$enabled = get_setting('auto_daily_backup', '0') === '1';
if (!$enabled) {
    exit;
}

$error = null;
$file = pkb_create_backup($error);
if (!$file) {
    fwrite(STDERR, 'Automatic backup failed: ' . ($error ?? 'unknown error') . PHP_EOL);
    exit(1);
}

pkb_prune(14);
echo '[' . date('Y-m-d H:i:s') . '] backup created: ' . basename($file) . PHP_EOL;
exit(0);