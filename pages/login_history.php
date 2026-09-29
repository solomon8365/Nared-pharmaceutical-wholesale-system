<?php
requirePermission('users', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['_action'] ?? '') === 'clear') {
        requirePermission('users', 'delete');
        $pdo->exec('DELETE FROM login_attempts');
        $messages[] = ['type' => 'success', 'text' => 'Login history cleared.'];
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
?>
<section>
    <div class="as-page-title"><i class="fas fa-history" style="color:#3b82f6"></i> Login History</div>
    <div class="as-page-sub">Failed login attempts recorded by the security system</div>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>" style="margin-bottom:16px"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="as-info-box as-info-blue"><i class="fas fa-info-circle"></i> The system records failed login attempts (locked after the configured limit in Security Settings). Successful logins are not stored.</div>

    <div class="as-stat-grid">
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total24) ?></div><div class="as-stat-label">Last 24 Hours</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total7) ?></div><div class="as-stat-label">Last 7 Days</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total30) ?></div><div class="as-stat-label">Last 30 Days</div></div>
        <div class="as-stat"><div class="as-stat-val"><?= number_format($uniqueIps) ?></div><div class="as-stat-label">Unique IPs (7d)</div></div>
    </div>

    <div class="as-card" style="margin:0">
        <div class="as-card-title"><i class="fas fa-list"></i> Failed Attempts (<?= count($rows) ?>)</div>
        <form method="get" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="page" value="login_history">
            <select name="range" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem">
                <?php foreach ($rangeMap as $val => $label): ?>
                    <option value="<?= $val ?>"<?= $range === $val ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="ip" value="<?= htmlspecialchars($ipFilter) ?>" placeholder="Filter by IP address" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;min-width:0;flex:1">
            <button type="submit" class="as-btn as-btn-primary"><i class="fas fa-filter"></i> Apply</button>
            <a href="?page=login_history" class="as-btn as-btn-outline">Reset</a>
            <div style="flex:1"></div>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="clear">
                <button type="submit" class="as-btn as-btn-danger" onclick="return confirm('Clear all login history?')"><i class="fas fa-trash"></i> Clear History</button>
            </form>
        </form>
        <?php if (empty($rows)): ?>
            <div class="as-empty"><i class="fas fa-shield-alt"></i> No failed login attempts<?= $range !== 'all' || $ipFilter !== '' ? ' matching the current filter' : '' ?>.</div>
        <?php else: ?>
        <div class="panel" style="padding:0;overflow-x:auto">
            <table>
                <thead><tr><th>ID</th><th>IP Address</th><th>Attempted At</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td><code style="font-size:0.85rem"><?= htmlspecialchars($r['ip_address']) ?></code><?= $r['ip_address'] === $currentIp ? ' <span class="status-badge status-approved">current</span>' : '' ?></td>
                        <td><?= htmlspecialchars($r['attempted_at']) ?></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>
