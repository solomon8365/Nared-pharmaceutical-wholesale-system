<?php
requirePermission('settings', 'view');
$pdo = getPDO();

$source = $_GET['source'] ?? 'all';
$allowedSources = ['all', 'status', 'payment', 'delivery', 'credit', 'bank', 'cms'];
if (!in_array($source, $allowedSources, true)) $source = 'all';
$range = $_GET['range'] ?? '30d';
$rangeMap = ['1d' => 'Last 24 Hours', '7d' => 'Last 7 Days', '30d' => 'Last 30 Days', '90d' => 'Last 90 Days', 'all' => 'All Time'];
if (!isset($rangeMap[$range])) $range = '30d';
$search = trim($_GET['q'] ?? '');
$perPage = 100;
$pageNo = max(1, (int) ($_GET['p'] ?? 1));
$offset = ($pageNo - 1) * $perPage;

$base = "
    SELECT source, action, entity, description, user_name, created_at FROM (
        SELECT 'status' AS source, CONCAT(COALESCE(sh.from_status,''),' → ',COALESCE(sh.to_status,'')) AS action,
               CONCAT(sh.workflow,' #',sh.entity_id) AS entity, COALESCE(sh.notes,'') AS description,
               COALESCE(u.username COLLATE utf8mb4_general_ci,'—') AS user_name, sh.created_at AS created_at
        FROM status_history sh LEFT JOIN users u ON u.id = sh.changed_by
        UNION ALL
        SELECT 'payment', pl.action,
               CONCAT(IF(pl.customer_order_id IS NULL,'Invoice / account',CONCAT('Order #',pl.customer_order_id))),
               CONCAT(COALESCE(pl.payment_method,''),COALESCE(CONCAT(' · ',pl.transaction_ref),''),COALESCE(CONCAT(' · ',pl.notes),'')),
               COALESCE(u.username COLLATE utf8mb4_general_ci,'—'), pl.created_at
        FROM payment_log pl LEFT JOIN users u ON u.id = pl.performed_by
        UNION ALL
        SELECT 'delivery', dl.action, CONCAT('Order #',dl.order_id),
               CONCAT(COALESCE(dl.old_status,''),' → ',COALESCE(dl.new_status,''),COALESCE(CONCAT(' · ',dl.notes),'')),
               COALESCE(u.username COLLATE utf8mb4_general_ci,'—'), dl.created_at
        FROM delivery_logs dl LEFT JOIN users u ON u.id = dl.performed_by
        UNION ALL
        SELECT 'credit', c.action, CONCAT('Customer #',c.customer_id),
               CONCAT(COALESCE(c.old_value,''),' → ',COALESCE(c.new_value,'')),
               COALESCE(u.username COLLATE utf8mb4_general_ci,'—'), c.created_at
        FROM credit_audit_log c LEFT JOIN users u ON u.id = c.user_id
        UNION ALL
        SELECT 'bank', b.action, CONCAT(b.entity_type,' #',COALESCE(b.entity_id,'')),
               CONCAT(COALESCE(b.old_value,''),' → ',COALESCE(b.new_value,'')),
               COALESCE(NULLIF(b.username COLLATE utf8mb4_general_ci,''),'—'), b.created_at
        FROM bank_audit_log b
        UNION ALL
        SELECT 'cms', c.action, CONCAT('Page #', COALESCE(c.page_id, 0)),
               COALESCE(c.details, ''),
               COALESCE(u.username COLLATE utf8mb4_general_ci, '—'), c.created_at
        FROM cms_activity_log c LEFT JOIN users u ON u.id = c.user_id
    ) t
";

$where = 'WHERE 1=1';
$params = [];
if ($source !== 'all') {
    $where .= ' AND source = :source';
    $params['source'] = $source;
}
if ($range !== 'all') {
    $where .= ' AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)';
    $params['days'] = (int) rtrim($range, 'd');
}
if ($search !== '') {
    $where .= ' AND (action LIKE :q OR entity LIKE :q OR description LIKE :q OR user_name LIKE :q)';
    $params['q'] = '%' . $search . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$base}) base {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($pageNo > $totalPages) { $pageNo = $totalPages; $offset = ($pageNo - 1) * $perPage; }

$listStmt = $pdo->prepare("SELECT * FROM ({$base}) base {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$listStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue('offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) $listStmt->bindValue(':' . $k, $v);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$sourceCounts = [];
foreach ($pdo->query("
    SELECT source, COUNT(*) AS c FROM (
        SELECT 'status' AS source FROM status_history
        UNION ALL SELECT 'payment' FROM payment_log
        UNION ALL SELECT 'delivery' FROM delivery_logs
        UNION ALL SELECT 'credit' FROM credit_audit_log
        UNION ALL SELECT 'bank' FROM bank_audit_log
        UNION ALL SELECT 'cms' FROM cms_activity_log
    ) t GROUP BY source
") as $r) $sourceCounts[$r['source']] = (int) $r['c'];

$sourceBadge = ['status' => 'approved', 'payment' => 'info', 'delivery' => 'warning', 'credit' => 'pending', 'bank' => 'danger', 'cms' => 'neutral'];
$sourceLabel = ['status' => 'Status Change', 'payment' => 'Payment', 'delivery' => 'Delivery', 'credit' => 'Credit', 'bank' => 'Bank', 'cms' => 'CMS'];
$qs = 'source=' . urlencode($source) . '&range=' . urlencode($range) . '&q=' . urlencode($search);
?>
<section>
    <div class="as-page-title"><i class="fas fa-scroll" style="color:#3b82f6"></i> Audit Logs</div>
    <div class="as-page-sub">Consolidated trail of status changes, payments, delivery, credit, bank, and content activity</div>

    <div class="as-stat-grid">
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total) ?></div><div class="as-stat-label"><?= $source === 'all' ? 'Total Entries' : htmlspecialchars($sourceLabel[$source]) ?></div></div>
        <?php foreach (['status', 'payment', 'delivery', 'credit', 'bank', 'cms'] as $s): ?>
            <div class="as-stat"><div class="as-stat-val" style="font-size:0.95rem"><?= number_format($sourceCounts[$s] ?? 0) ?></div><div class="as-stat-label"><?= htmlspecialchars($sourceLabel[$s]) ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="as-card" style="margin:0">
        <form method="get" style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="page" value="audit_logs">
            <select name="source" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem">
                <option value="all"<?= $source === 'all' ? ' selected' : '' ?>>All Sources</option>
                <?php foreach ($sourceLabel as $val => $label): ?>
                    <option value="<?= $val ?>"<?= $source === $val ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="range" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem">
                <?php foreach ($rangeMap as $val => $label): ?>
                    <option value="<?= $val ?>"<?= $range === $val ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search action, entity, user…" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;min-width:0;flex:1">
            <button type="submit" class="as-btn as-btn-primary"><i class="fas fa-filter"></i> Apply</button>
            <a href="?page=audit_logs" class="as-btn as-btn-outline">Reset</a>
        </form>

        <?php if (empty($rows)): ?>
            <div class="as-empty"><i class="fas fa-scroll"></i> No audit log entries match the current filter.</div>
        <?php else: ?>
        <div class="panel" style="padding:0;overflow-x:auto">
            <table>
                <thead><tr><th>Source</th><th>Action</th><th>Entity</th><th>Details</th><th>User</th><th>Timestamp</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="status-badge status-<?= $sourceBadge[$r['source']] ?? 'neutral' ?>"><?= htmlspecialchars($sourceLabel[$r['source']] ?? $r['source']) ?></span></td>
                        <td style="font-weight:600;color:#1e293b;white-space:nowrap"><?= htmlspecialchars($r['action']) ?></td>
                        <td style="white-space:nowrap"><?= htmlspecialchars($r['entity']) ?></td>
                        <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['description']) ?>"><?= htmlspecialchars($r['description']) ?: '—' ?></td>
                        <td><?= htmlspecialchars($r['user_name']) ?></td>
                        <td style="white-space:nowrap"><?= htmlspecialchars($r['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px">
            <span style="font-size:0.78rem;color:#94a3b8">Showing <?= ($offset + 1) ?>–<?= min($offset + count($rows), $total) ?> of <?= number_format($total) ?> entries</span>
            <div style="display:flex;gap:8px">
                <?php if ($pageNo > 1): ?><a href="?page=audit_logs&<?= $qs ?>&p=<?= $pageNo - 1 ?>" class="as-btn as-btn-outline"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
                <span style="font-size:0.78rem;color:#64748b;align-self:center">Page <?= $pageNo ?> of <?= $totalPages ?></span>
                <?php if ($pageNo < $totalPages): ?><a href="?page=audit_logs&<?= $qs ?>&p=<?= $pageNo + 1 ?>" class="as-btn as-btn-outline">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
