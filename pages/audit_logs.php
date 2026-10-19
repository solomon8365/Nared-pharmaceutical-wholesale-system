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
$sourceSelect = ['status' => 'status', 'payment' => 'pay', 'delivery' => 'ship', 'credit' => 'credit', 'bank' => 'bank', 'cms' => 'cms'];
$qs = 'source=' . urlencode($source) . '&range=' . urlencode($range) . '&q=' . urlencode($search);

// Exports use the same filters as the current view (permission-gated).
$export = $_GET['export'] ?? '';
if ($export !== '') requirePermission('settings', 'export');
$exportWhere = $where;
$exportParams = $params;
$exportSql = "SELECT source, action, entity, description, user_name, created_at FROM ({$base}) base {$exportWhere} ORDER BY created_at DESC LIMIT 5000";
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Source', 'Action', 'Entity', 'Details', 'User', 'Timestamp']);
    $es = $pdo->prepare($exportSql); $es->execute($exportParams);
    while ($r = $es->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$sourceLabel[$r['source']] ?? $r['source'], $r['action'], $r['entity'], $r['description'], $r['user_name'], $r['created_at']]);
    }
    fclose($out); exit;
}
if ($export === 'xls') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd') . '.xls"');
    echo '<html><head><meta charset="utf-8"><style>td,th{border:1px solid #ccc;padding:4px 8px;font-family:Arial}th{background:#1e3a5f;color:#fff}</style></head><body>';
    echo '<table><tr><th colspan="6">' . htmlspecialchars(company_name()) . ' — Audit Logs (' . htmlspecialchars($rangeMap[$range]) . ')</th></tr>';
    echo '<tr><th>Source</th><th>Action</th><th>Entity</th><th>Details</th><th>User</th><th>Timestamp</th></tr>';
    $es = $pdo->prepare($exportSql); $es->execute($exportParams);
    while ($r = $es->fetch(PDO::FETCH_ASSOC)) {
        echo '<tr><td>' . htmlspecialchars($sourceLabel[$r['source']] ?? $r['source']) . '</td><td>' . htmlspecialchars($r['action']) . '</td><td>' . htmlspecialchars($r['entity']) . '</td><td>' . htmlspecialchars($r['description']) . '</td><td>' . htmlspecialchars($r['user_name']) . '</td><td>' . htmlspecialchars($r['created_at']) . '</td></tr>';
    }
    echo '</table></body></html>'; exit;
}

// Per-source counts for the current time window (independent of the source filter
// so every tab keeps its number).
$windowWhere = 'WHERE 1=1';
$windowParams = [];
if ($range !== 'all') {
    $windowWhere .= ' AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)';
    $windowParams['days'] = (int) rtrim($range, 'd');
}
if ($search !== '') {
    $windowWhere .= ' AND (action LIKE :q OR entity LIKE :q OR description LIKE :q OR user_name LIKE :q)';
    $windowParams['q'] = '%' . $search . '%';
}
$windowCounts = [];
$wcStmt = $pdo->prepare("SELECT source, COUNT(*) AS c FROM ({$base}) base {$windowWhere} GROUP BY source");
$wcStmt->execute($windowParams);
foreach ($wcStmt as $r) $windowCounts[$r['source']] = (int) $r['c'];
?>
<section>
    <div class="as-page-title"><i class="fas fa-scroll" style="color:#3b82f6"></i> Audit Logs</div>
    <div class="as-page-sub">Consolidated trail of status changes, payments, delivery, credit, bank, and content activity<?= $range !== 'all' ? ' · ' . htmlspecialchars($rangeMap[$range]) : '' ?></div>

    <div class="as-stat-grid">
        <div class="as-stat"><div class="as-stat-val"><?= number_format($total) ?></div><div class="as-stat-label"><?= $source === 'all' ? 'Entries (filtered)' : htmlspecialchars($sourceLabel[$source]) ?></div></div>
        <?php foreach (['status', 'payment', 'delivery', 'credit', 'bank', 'cms'] as $s): ?>
            <div class="as-stat"><div class="as-stat-val" style="font-size:0.95rem"><?= number_format($sourceCounts[$s] ?? 0) ?></div><div class="as-stat-label"><?= htmlspecialchars($sourceLabel[$s]) ?> (all)</div></div>
        <?php endforeach; ?>
    </div>

    <div class="as-card">
        <div class="as-card-title"><i class="fas fa-sliders"></i> Filters &amp; Export</div>
        <form method="get" class="al-filter">
            <input type="hidden" name="page" value="audit_logs">
            <label class="al-field">
                <span>Source</span>
                <select name="source">
                    <option value="all"<?= $source === 'all' ? ' selected' : '' ?>>All Sources</option>
                    <?php foreach ($sourceLabel as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>"<?= $source === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="al-field">
                <span>Time range</span>
                <select name="range">
                    <?php foreach ($rangeMap as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>"<?= $range === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="al-field al-field-grow">
                <span>Search</span>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Action, entity, details or user…" autocomplete="off">
            </label>
            <div class="al-actions">
                <button type="submit" class="as-btn as-btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="?page=audit_logs" class="as-btn as-btn-outline"><i class="fas fa-rotate"></i> Reset</a>
                <?php if (hasPermission('settings', 'export') && $total > 0): ?>
                <div class="export-dropdown">
                    <button type="button" class="export-btn" onclick="toggleExport(this)"><i class="fas fa-download"></i> Export</button>
                    <div class="export-dropdown-menu">
                        <a href="?page=audit_logs&<?= $qs ?>&export=csv"><i class="fas fa-file-csv"></i> CSV</a>
                        <a href="?page=audit_logs&<?= $qs ?>&export=xls"><i class="fas fa-file-excel"></i> Excel</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="as-card">
        <div class="as-card-title"><i class="fas fa-list"></i> Activity <span class="al-scope"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> · <?= htmlspecialchars($source === 'all' ? 'All Sources' : $sourceLabel[$source]) ?> · <?= htmlspecialchars($rangeMap[$range]) ?></span></div>
        <?php if (empty($rows)): ?>
            <div class="as-empty"><i class="fas fa-scroll"></i> No audit log entries match the current filter.</div>
        <?php else: ?>
        <div class="al-table-wrap">
            <table class="al-table">
                <thead>
                    <tr><th>Source</th><th>Action</th><th>Entity</th><th class="al-details">Details</th><th>User</th><th>Timestamp</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="status-badge status-<?= htmlspecialchars($sourceBadge[$r['source']] ?? 'neutral') ?>"><?= htmlspecialchars($sourceLabel[$r['source']] ?? $r['source']) ?></span></td>
                        <td class="al-action"><?= htmlspecialchars($r['action']) ?></td>
                        <td class="al-entity"><?= htmlspecialchars($r['entity']) ?></td>
                        <td class="al-details"><span class="al-clip" title="<?= htmlspecialchars($r['description']) ?>"><?= htmlspecialchars($r['description'] ?: '—') ?></span></td>
                        <td class="al-muted"><?= htmlspecialchars($r['user_name']) ?></td>
                        <td class="al-muted al-nowrap"><?= htmlspecialchars($r['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="al-foot">
            <span>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + count($rows), $total)) ?> of <?= number_format($total) ?> entries</span>
            <div class="al-pager">
                <?php if ($pageNo > 1): ?><a href="?page=audit_logs&<?= $qs ?>&p=<?= $pageNo - 1 ?>" class="as-btn as-btn-outline"><i class="fas fa-chevron-left"></i> Prev</a><?php endif; ?>
                <span class="al-page-label">Page <?= number_format($pageNo) ?> of <?= number_format($totalPages) ?></span>
                <?php if ($pageNo < $totalPages): ?><a href="?page=audit_logs&<?= $qs ?>&p=<?= $pageNo + 1 ?>" class="as-btn as-btn-outline">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.al-filter{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
.al-field{display:flex;flex-direction:column;gap:5px;min-width:170px}
.al-field>span{font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8}
.al-field-grow{flex:1 1 240px;min-width:200px}
.al-filter select,.al-filter input[type="text"]{width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.85rem;color:#1e293b;background:#fff;box-sizing:border-box}
.al-filter select:focus,.al-filter input[type="text"]:focus{outline:none;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.al-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.al-scope{font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:none;letter-spacing:0}
.al-table-wrap{overflow-x:auto;border-radius:10px}
.al-table{table-layout:fixed;min-width:760px}
.al-table td{vertical-align:middle}
.al-action{font-weight:600;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.al-entity{color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.al-details{width:38%}
.al-clip{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b}
.al-table th.al-details{width:38%}
.al-muted{color:#64748b;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.al-nowrap{white-space:nowrap}
.al-table thead th:nth-child(1){width:110px}
.al-table thead th:nth-child(5){width:130px}
.al-table thead th:nth-child(6){width:150px}
.al-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;flex-wrap:wrap}
.al-foot>span{font-size:.78rem;color:#94a3b8}
.al-pager{display:flex;gap:8px;align-items:center}
.al-page-label{font-size:.78rem;color:#64748b}
@media(max-width:640px){.al-field,.al-field-grow{min-width:100%}.al-actions{width:100%}.al-actions .as-btn,.al-actions .export-btn{flex:1;justify-content:center}}
</style>
