<?php
/**
 * NARED Pharmacon — service connectivity / configuration check.
 *
 * Verifies the two data services the application uses:
 *   1. MySQL      — the PRIMARY database (all business data)
 *   2. Supabase   — OPTIONAL object storage for uploaded files
 *
 * Usage (command line only):
 *   php tools/check_services.php
 *   php tools/check_services.php --create-bucket     # create the bucket if missing
 *   php tools/check_services.php --no-roundtrip      # skip the upload/delete probe
 *
 * This script never modifies business data and never drops anything. The only
 * remote mutation is the optional bucket creation and a self-deleting probe
 * object, both additive and safe.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This diagnostic must be run from the command line.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/db.php';

$opts = $argv ?? [];
$createBucket = in_array('--create-bucket', $opts, true);
$doRoundtrip = !in_array('--no-roundtrip', $opts, true);

$line = str_repeat('─', 64);
$okMark = 'OK  ';
$failMark = 'FAIL';

$exit = 0;

echo $line . PHP_EOL;
echo " NARED Pharmacon — service check" . PHP_EOL;
echo " " . date('Y-m-d H:i:s') . PHP_EOL;
echo $line . PHP_EOL;

/* ───────────────────────────── 1. MySQL ───────────────────────────── */
echo PHP_EOL . "[1] MySQL (primary database)" . PHP_EOL;
echo '    host=' . DB_HOST . ' db=' . DB_NAME . ' user=' . DB_USER . PHP_EOL;

try {
    $pdo = getPDO();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "    {$okMark} connected — server: {$version}" . PHP_EOL;

    $coreTables = [
        'products', 'product_batches', 'customers', 'users', 'settings',
        'customer_orders', 'invoices', 'invoice_payments',
        'customer_payment_submissions', 'suppliers', 'roles', 'role_permissions',
    ];
    $missing = [];
    $stmt = $pdo->query('SHOW TABLES');
    $have = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($coreTables as $t) {
        if (!in_array(strtolower($t), $have, true)) {
            $missing[] = $t;
        }
    }
    $tableCount = count($have);
    echo "    table count: {$tableCount}" . PHP_EOL;
    if ($missing) {
        echo "    {$failMark} missing expected tables: " . implode(', ', $missing) . PHP_EOL;
    } else {
        echo "    {$okMark} all core business tables present" . PHP_EOL;
    }

    $perms = 0;
    try {
        $perms = (int) $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE module = 'invoice' AND permission = 'view'")->fetchColumn();
    } catch (Throwable $e) {
        // ignore if the table does not exist
    }
    if ($perms > 0) {
        echo "    {$okMark} role permissions intact (invoice.view grants: {$perms})" . PHP_EOL;
    } else {
        echo "    {$failMark} no invoice.view role permissions found" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "    {$failMark} MySQL connection failed: " . $e->getMessage() . PHP_EOL;
    $exit = 1;
}

/* ──────────────────────── 2. Supabase Storage ─────────────────────── */
echo PHP_EOL . "[2] Supabase (optional object storage)" . PHP_EOL;

$enabled = defined('SUPABASE_STORAGE_ENABLED') && SUPABASE_STORAGE_ENABLED;
$configured = function_exists('storage_supabase_configured') && storage_supabase_configured();
$backend = function_exists('storage_backend') ? storage_backend() : 'local';

echo "    SUPABASE_STORAGE_ENABLED = " . ($enabled ? 'true' : 'false') . PHP_EOL;
echo "    URL set                  = " . (defined('SUPABASE_URL') && SUPABASE_URL !== '' ? 'yes' : 'no') . PHP_EOL;
echo "    service_role key set     = " . (defined('SUPABASE_SERVICE_ROLE_KEY') && SUPABASE_SERVICE_ROLE_KEY !== '' ? 'yes' : 'no') . " (value hidden)" . PHP_EOL;
echo "    anon key set             = " . (defined('SUPABASE_ANON_KEY') && SUPABASE_ANON_KEY !== '' ? 'yes' : 'no') . " (value hidden)" . PHP_EOL;
echo "    bucket                   = " . (defined('SUPABASE_STORAGE_BUCKET') ? SUPABASE_STORAGE_BUCKET : 'nared-uploads') . PHP_EOL;
echo "    active storage backend   = {$backend}" . PHP_EOL;

if (!$configured) {
    echo "    {$okMark} Supabase not configured — files stay on local disk (uploads/)." . PHP_EOL;
    echo "         Set SUPABASE_URL + SUPABASE_SERVICE_ROLE_KEY and SUPABASE_STORAGE_ENABLED=true in .env to enable." . PHP_EOL;
} else {
    $client = storage_client();
    if ($client === null) {
        echo "    {$failMark} Supabase client could not be constructed." . PHP_EOL;
        $exit = 1;
    } else {
        $health = $client->health();
        echo "    reachable           = " . ($health['reachable'] ? 'yes' : 'no') . PHP_EOL;
        echo "    storage authorized  = " . ($health['storage_authorized'] ? 'yes' : 'no') . PHP_EOL;
        if ($health['error']) {
            echo "    detail: " . $health['error'] . PHP_EOL;
        }

        $bucket = $client->bucketExists();
        if ($bucket['ok']) {
            echo "    {$okMark} bucket '" . storage_bucket() . "' exists" . PHP_EOL;
        } elseif ($createBucket) {
            $mk = $client->ensureBucket(true);
            if ($mk['exists']) {
                echo "    {$okMark} bucket '" . storage_bucket() . "' " . ($mk['created'] ? 'created (public)' : 'already present') . PHP_EOL;
            } else {
                echo "    {$failMark} could not create bucket: " . $mk['error'] . PHP_EOL;
                $exit = 1;
            }
        } else {
            echo "    {$failMark} bucket '" . storage_bucket() . "' not accessible" . ($bucket['error'] ? ' — ' . $bucket['error'] : '') . PHP_EOL;
            echo "         Re-run with --create-bucket to create it." . PHP_EOL;
            $exit = 1;
        }

        if ($doRoundtrip && $health['ok']) {
            $probeName = 'health/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.txt';
            $tmp = tempnam(sys_get_temp_dir(), 'naredchk');
            file_put_contents($tmp, 'nared health probe');
            $up = $client->uploadFile($probeName, $tmp, 'text/plain', true);
            @unlink($tmp);
            if ($up['ok']) {
                echo "    {$okMark} upload probe OK -> " . $up['url'] . PHP_EOL;
                $child = $client->objectPathFromUrl((string) $up['url']);
                $rm = $client->remove([$child ?? $probeName]);
                echo ($rm['ok'] ? "    {$okMark}" : "    {$failMark}") . " cleanup probe" . ($rm['ok'] ? ' OK' : ': ' . $rm['error']) . PHP_EOL;
            } else {
                echo "    {$failMark} upload probe failed: " . $up['error'] . PHP_EOL;
                $exit = 1;
            }
        }
    }
}

/* ───────────────────────────── 3. Files ───────────────────────────── */
echo PHP_EOL . "[3] Local upload directories" . PHP_EOL;
foreach (['products', 'payments', 'pod', 'info', 'receipts', 'refunds'] as $area) {
    $dir = storage_local_dir($area);
    if (is_dir($dir)) {
        $writable = is_writable($dir) ? 'writable' : 'NOT writable';
        $count = count(glob($dir . DIRECTORY_SEPARATOR . '*') ?: []);
        echo "    {$okMark} uploads/{$area} — {$writable}, {$count} file(s)" . PHP_EOL;
    } else {
        echo "    --   uploads/{$area} — not present (created on first upload)" . PHP_EOL;
    }
}

echo PHP_EOL . $line . PHP_EOL;
echo ($exit === 0 ? ' RESULT: all checks passed' : ' RESULT: one or more checks need attention') . PHP_EOL;
echo $line . PHP_EOL;

exit($exit);
