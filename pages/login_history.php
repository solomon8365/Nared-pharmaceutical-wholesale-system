<?php
requirePermission('users', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

$cleared = (int) ($_GET['cleared'] ?? 0);
if ($cleared > 0) {
    $messages[] = ['type' => 'success', 'text' => number_format($cleared) . ' failed login attempt' . ($cleared === 1 ? '' : 's') . ' cleared.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['_action'] ?? '') === 'clear') {
        requirePermission('users', 'delete');
        $removed = (int) $pdo->exec('DELETE FROM login_attempts');
        // Post/Redirect/Get so a refresh cannot re-submit the clear action.
        safeRedirect('index.php?page=login_history&cleared=' . max($removed, 1));
    }
}

$range = $_GET['range'] ?? '7d';
$rangeMap = [
    '1d' => 'Last 24 Hours',
    '7d' => 'Last 7 Days',
    '30d' => 'Last 30 Days',
    'all' => 'All Time',
];
if (!isset($rangeMap[$range])) $range = '7d';
$ipFilter = trim($_GET['ip'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($range !== 'all') {
    $days = (int) rtrim($range, 'd');
    $where .= ' AND attempted_at > DATE_SUB(NOW(), INTERVAL :days DAY)';
    $params['days'] = $days;
}
if ($ipFilter !== '') {
    $where .= ' AND ip_address LIKE :ip';
    $params['ip'] = '%' . $ipFilter . '%';
}

$stmt = $pdo->prepare("SELECT id, ip_address, attempted_at FROM login_attempts {$where} ORDER BY attempted_at DESC LIMIT 500");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total24 = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
$total7 = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$total30 = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$uniqueIps = (int) $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$currentIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Per-IP breakdown for the current window, so the page is useful even without
// scrolling the raw list (and replaces the old empty trailing table column).
$byIp = $pdo->prepare("SELECT ip_address, COUNT(*) AS attempts, MAX(attempted_at) AS last_seen FROM login_attempts {$where} GROUP BY ip_address ORDER BY attempts DESC, last_seen DESC LIMIT 20");
$byIp->execute($params);
$byIpRows = $byIp->fetchAll();

$lastSeen = $pdo->query("SELECT MAX(attempted_at) FROM login_attempts")->fetchColumn();
$distinctTotal = (int) $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM login_attempts")->fetchColumn();

$filterLabel = $rangeMap[$range];
if ($ipFilter !== '') $filterLabel .= ' · IP contains "' . $ipFilter . '"';
$clearQs = 'page=login_history&range=' . urlencode($range) . ($ipFilter !== '' ? '&ip=' . urlencode($ipFilter) : '');

$fmtTime = static function ($ts): string {
    if (!$ts) return '—';
    try { return (new DateTimeImmutable((string) $ts))->format('M j, Y · H:i:s'); } catch (Throwable $e) { return (string) $ts; }
};
?>
<section>
    <div class="as-page-title"><i class="fas fa-shield-halved" style="color:#3b82f6"></i> Login Attempts</div>
    <div class="as-page-sub">Failed sign-in attempts recorded by the security system — used to spot brute-force and lockout activity</div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:16px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error" style="margin-bottom:16px"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <div class="as-info-box as-info-blue"><i class="fas fa-circle-info"></i> Only <strong>failed</strong> logins are recorded; successful sign-ins are not stored. An IP is blocked after the configured limit set in Security Settings.</div>

    <div class="as-stat-grid">
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total24) ?></div><div class="as-stat-label">Last 24 Hours</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total7) ?></div><div class="as-stat-label">Last 7 Days</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total30) ?></div><div class="as-stat-label">Last 30 Days</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($uniqueIps) ?></div><div class="as-stat-label">Unique IPs (7d)</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($distinctTotal) ?></div><div class="as-stat-label">Distinct IPs (All)</div></div>
    </div>

    <div class="as-card">
        <div class="as-card-title"><i class="fas fa-sliders"></i> Filters &amp; Actions</div>
        <form method="get" class="lh-filter">
            <input type="hidden" name="page" value="login_history">
            <label class="lh-field">
                <span>Time range</span>
                <select name="range">
                    <?php foreach ($rangeMap as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>"<?= $range === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="lh-field lh-field-grow">
                <span>IP address</span>
                <input type="text" name="ip" value="<?= htmlspecialchars($ipFilter) ?>" placeholder="e.g. 192.168.1." autocomplete="off">
            </label>
            <div class="lh-actions">
                <button type="submit" class="as-btn as-btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="?page=login_history" class="as-btn as-btn-outline"><i class="fas fa-rotate"></i> Reset</a>
                <button type="submit" class="as-btn as-btn-danger" form="clearHistoryForm" data-swal-confirm-msg="Clear all recorded failed login attempts? This cannot be undone."><i class="fas fa-trash"></i> Clear History</button>
            </div>
        </form>
        <form id="clearHistoryForm" method="post" action="?<?= htmlspecialchars($clearQs) ?>" class="lh-clear-form">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="clear">
        </form>
    </div>

    <?php if (!empty($byIpRows)): ?>
    <div class="as-card">
        <div class="as-card-title"><i class="fas fa-network-wired"></i> Top Source IPs <span class="lh-scope"><?= htmlspecialchars($filterLabel) ?></span></div>
        <div class="lh-table-wrap">
            <table>
                <thead>
                    <tr><th>#</th><th>IP Address</th><th class="lh-num">Attempts</th><th>Last Attempt</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($byIpRows as $i => $ip): ?>
                    <tr>
                        <td class="lh-muted"><?= $i + 1 ?></td>
                        <td>
                            <code class="lh-ip"><?= htmlspecialchars($ip['ip_address']) ?></code>
                            <?php if ($ip['ip_address'] === $currentIp): ?><span class="status-badge status-approved lh-badge">You</span><?php endif; ?>
                        </td>
                        <td class="lh-num"><span class="lh-count"><?= number_format((int) $ip['attempts']) ?></span></td>
                        <td class="lh-muted"><?= htmlspecialchars($fmtTime($ip['last_seen'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="as-card">
        <div class="as-card-title"><i class="fas fa-list"></i> Recent Failed Attempts <span class="lh-scope"><?= htmlspecialchars($filterLabel) ?></span></div>
        <?php if (empty($rows)): ?>
            <div class="as-empty"><i class="fas fa-shield-halved"></i> No failed login attempts<?= $range !== 'all' || $ipFilter !== '' ? ' match the current filter' : ' recorded' ?>.</div>
        <?php else: ?>
        <div class="lh-table-wrap">
            <table>
                <thead>
                    <tr><th>Attempt</th><th>IP Address</th><th>Date &amp; Time</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="lh-muted">#<?= (int) $r['id'] ?></td>
                        <td>
                            <code class="lh-ip"><?= htmlspecialchars($r['ip_address']) ?></code>
                            <?php if ($r['ip_address'] === $currentIp): ?><span class="status-badge status-approved lh-badge">Your IP</span><?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($fmtTime($r['attempted_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="lh-foot">
            <span>Showing the most recent <?= number_format(count($rows)) ?> attempt<?= count($rows) === 1 ? '' : 's' ?><?= $lastSeen ? ' · latest ' . htmlspecialchars($fmtTime($lastSeen)) : '' ?></span>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.lh-filter{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
.lh-field{display:flex;flex-direction:column;gap:5px;min-width:170px}
.lh-field>span{font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8}
.lh-field-grow{flex:1 1 240px;min-width:200px}
.lh-filter select,.lh-filter input[type="text"]{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.85rem;color:#1e293b;background:#fff;box-sizing:border-box}
.lh-filter select:focus,.lh-filter input[type="text"]:focus{outline:none;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.lh-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.lh-clear-form{display:none}
.lh-scope{font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:none;letter-spacing:0}
.lh-table-wrap{overflow-x:auto;border-radius:10px}
.lh-num{text-align:right}
.lh-muted{color:#94a3b8;font-size:.8rem;white-space:nowrap}
.lh-ip{font-size:.84rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:3px 8px;color:#334155;white-space:nowrap}
.lh-badge{margin-left:8px;vertical-align:middle}
.lh-count{display:inline-block;min-width:34px;padding:2px 10px;border-radius:999px;background:#fef3c7;color:#b45309;font-weight:800;font-size:.78rem}
.lh-foot{margin-top:12px;font-size:.78rem;color:#94a3b8}
@media(max-width:640px){.lh-field,.lh-field-grow{min-width:100%}.lh-actions{width:100%}.lh-actions .as-btn{flex:1;justify-content:center}}
</style>
