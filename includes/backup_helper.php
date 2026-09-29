<?php
/**
 * Database backup/restore + Windows Task Scheduler helpers.
 * Shared by pages/backup.php, pages/settings.php and cron_auto_backup.php.
 */
require_once __DIR__ . '/../db.php';

if (!function_exists('pkb_backup_dir')) {

function pkb_backup_dir(): string
{
    $dir = __DIR__ . '/../backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return rtrim($dir, '/\\');
}

function pkb_bin_dir(): string
{
    foreach ([getenv('MYSQL_BIN'), getenv('MYSQL_HOME')] as $cand) {
        if ($cand && is_dir($cand)) {
            return rtrim($cand, '/\\');
        }
    }
    return 'C:\xampp\mysql\bin';
}

function pkb_tool(string $name): ?string
{
    $base = rtrim(pkb_bin_dir(), '/\\');
    foreach ([$base . '\\' . $name . '.exe', $base . '\\' . $name, 'C:\xampp\mysql\bin\\' . $name . '.exe'] as $cand) {
        if (is_file($cand)) return $cand;
    }
    return null;
}

function pkb_build_env(): array
{
    $env = getenv();
    if (!is_array($env)) $env = [];
    foreach (['PATH' => 'C:\xampp\php;C:\xampp\mysql\bin;C:\Windows\System32', 'SystemRoot' => 'C:\Windows'] as $k => $default) {
        if (empty($env[$k])) $env[$k] = $default;
    }
    $env['MYSQL_PWD'] = (string) DB_PASS;
    return $env;
}

function pkb_run(array $args, ?string &$stderr = null): array
{
    $cmdline = implode(' ', array_map('escapeshellarg', $args));
    return pkb_run_raw($cmdline, $stderr);
}

function pkb_run_raw(string $cmdline, ?string &$stderr = null): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmdline, $spec, $pipes, null, pkb_build_env());
    if (!is_resource($proc)) {
        $stderr = 'unable to launch: ' . $cmdline;
        return ['code' => -1, 'stdout' => ''];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'stdout' => (string) $stdout];
}

/** Pure-PHP fallback dump (used when mysqldump.exe is not available). */
function pkb_php_dump(string $ts): string
{
    $pdo = getPDO();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $sql = "-- NARED PHARMACON backup created on $ts\n-- Database: " . DB_NAME . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `$table`;\n{$create[1]};\n\n";
        $rows = $pdo->query("SELECT * FROM `" . str_replace('`', '``', $table) . "`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(function ($c) { return "`" . str_replace('`', '``', $c) . "`"; }, array_keys($row));
            $vals = array_map(function ($v) use ($pdo) {
                if ($v === null) return 'NULL';
                return "'" . str_replace("'", "''", $v) . "'";
            }, array_values($row));
            $sql .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/** Create a real SQL backup in backups/. Returns file path or null (+$error). */
function pkb_create_backup(?string &$error = null): ?string
{
    $ts = date('Y-m-d_H-i-s');
    $file = pkb_backup_dir() . DIRECTORY_SEPARATOR . 'backup_' . DB_NAME . '_' . $ts . '.sql';

    $dump = pkb_tool('mysqldump');
    if ($dump) {
        $args = [$dump, '-h', DB_HOST, '-u', DB_USER,
            '--add-drop-table', '--create-options', '--disable-keys',
            '--routines', '--triggers', '--single-transaction', '--quick', DB_NAME];
        $out = pkb_run($args, $derr);
        if ($out['code'] === 0 && trim($out['stdout']) !== '') {
            if (@file_put_contents($file, $out['stdout']) !== false) {
                clearstatcache(true, $file);
                if (filesize($file) > 0) return $file;
            }
            $error = 'backup file could not be written.';
            return null;
        }
        $error = 'mysqldump failed (code ' . $out['code'] . '): ' . trim(preg_replace('/\s+/', ' ', $derr ?? ''));
    } else {
        $error = 'mysqldump not found; using PHP fallback.';
    }

    $sql = pkb_php_dump($ts);
    if (@file_put_contents($file, $sql) === false) {
        $error = 'backup file could not be written to ' . pkb_backup_dir() . '.';
        return null;
    }
    clearstatcache(true, $file);
    if (filesize($file) === 0) {
        $error = 'backup produced an empty file.';
        return null;
    }
    return $file;
}

/** Validate that a file is a plausible previous backup dump. */
function pkb_valid_backup_file(string $filepath): bool
{
    if (!is_file($filepath) || filesize($filepath) <= 0) return false;
    $head = @fread(fopen($filepath, 'rb'), 4096);
    return is_string($head)
        && (strpos($head, '--') !== false)
        && (stripos($head, 'CREATE TABLE') !== false || stripos($head, 'INSERT INTO') !== false || stripos($head, 'DROP TABLE') !== false);
}

/** Restore a SQL backup. Requires mysql.exe or falls back to PDO. */
function pkb_restore(string $filepath, ?string &$error = null, string $db = DB_NAME): bool
{
    if (!preg_match('/\.sql$/i', $filepath)) {
        $error = 'Only .sql backup files can be restored.';
        return false;
    }
    if (!pkb_valid_backup_file($filepath)) {
        $error = 'Invalid or empty backup file.';
        return false;
    }

    $mysql = pkb_tool('mysql');
    if ($mysql) {
        $args = [$mysql, '-h', DB_HOST, '-u', DB_USER, $db];
        $spec = [0 => ['file', $filepath, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmdline = implode(' ', array_map('escapeshellarg', $args));
        $proc = @proc_open($cmdline, $spec, $pipes, null, pkb_build_env());
        if (!is_resource($proc)) {
            $error = 'unable to launch mysql client.';
            return false;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $error = 'mysql exited ' . $code . ': ' . trim(preg_replace('/\s+/', ' ', $stderr));
            return false;
        }
        return true;
    }

    try {
        $sql = file_get_contents($filepath);
        $pdo = getPDO();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec($sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return false;
    }
}

function pkb_prune(int $keep = 14): void
{
    $glob = pkb_backup_dir() . '/*.sql';
    $files = glob($glob) ?: [];
    if (count($files) <= $keep) return;
    usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
    foreach (array_slice($files, 0, count($files) - $keep) as $f) {
        @unlink($f);
    }
}

if (!defined('PKB_TASK_NAME')) {
    define('PKB_TASK_NAME', 'NARED_Pharma_DailyBackup');
}

function pkb_task_name(): string { return PKB_TASK_NAME; }

function pkb_php_cli(): string
{
    foreach (['C:\xampp\php\php.exe', getenv('PHP_BINARY') ?: '', PHP_BINARY] as $cand) {
        if ($cand && is_file($cand) && stripos($cand, 'php') !== false) return $cand;
    }
    return 'C:\xampp\php\php.exe';
}

function pkb_task_registered(): bool
{
    $res = pkb_run(['schtasks', '/query', '/tn', PKB_TASK_NAME]);
    return $res['code'] === 0;
}

function pkb_task_tr_argument(): string
{
    $q = function (string $s) { return '"' . str_replace('"', '\\"', $s) . '"'; };
    return $q(pkb_php_cli()) . ' ' . $q(__DIR__ . '/../cron_auto_backup.php');
}

/** Register the daily (00:00) backup task. Returns error string, or null on success. */
function pkb_task_register(): ?string
{
    $cmdline = 'schtasks /create /tn ' . PKB_TASK_NAME . ' /sc DAILY /st 00:00 /tr ' . pkb_tr_quoted() . ' /f';
    $res = pkb_run_raw($cmdline, $err);
    if ($res['code'] === 0) return null;
    return trim($err ?: 'schtasks exited ' . $res['code']);
}

function pkb_task_unregister(): ?string
{
    if (!pkb_task_registered()) return null;
    $res = pkb_run(['schtasks', '/delete', '/tn', PKB_TASK_NAME, '/f'], $err);
    if ($res['code'] === 0) return null;
    return trim($err ?: 'schtasks exited ' . $res['code']);
}

function pkb_tr_quoted(): string
{
    return '"' . str_replace('"', '\\"', pkb_task_tr_argument()) . '"';
}

/** Human-friendly manual command for documentation / UI. */
function pkb_task_manual_command(): string
{
    return 'schtasks /create /tn ' . PKB_TASK_NAME . ' /sc DAILY /st 00:00 /tr ' . pkb_tr_quoted() . ' /f';
}

}