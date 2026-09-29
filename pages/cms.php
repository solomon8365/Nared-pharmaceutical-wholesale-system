<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('cms', 'view');
requirePermission('cms', 'view');

$pdo = getPDO();
$messages = [];
$errors = [];

$categories = [
    'About the Company',
    'Vision and Mission',
    'Company Services',
    'Privacy Policy',
    'Security Policy',
    'Terms and Conditions',
    'Customer Guidelines',
    'Ordering Process',
    'Payment Policy',
    'Delivery Policy',
    'Return and Refund Policy',
    'Frequently Asked Questions (FAQ)',
    'News and Announcements',
    'Contact Information',
    'Help and Support',
    'General',
];

function logPageAction(PDO $pdo, int $pageId, string $action, ?int $userId, string $details = ''): void
{
    AuditService::cms($pdo, $userId, $action, 'page', $pageId, $details);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['_action'] ?? '';
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    if ($action === 'delete') {
        requirePermission('cms', 'delete');
        $deleteId = (int) ($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $pdo->prepare('SELECT title, featured_image, document FROM pages WHERE id = :id');
            $stmt->execute(['id' => $deleteId]);
            $cmsRow = $stmt->fetch();
            if ($cmsRow) {
                foreach (['featured_image', 'document'] as $col) {
                    if (!empty($cmsRow[$col])) {
                        $fp = __DIR__ . '/../' . $cmsRow[$col];
                        if (file_exists($fp)) @unlink($fp);
                    }
                }
            }
            $pdo->prepare('DELETE FROM cms_activity_log WHERE page_id = :id')->execute(['id' => $deleteId]);
            $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => $deleteId]);
            redirect_with_flash('?page=cms', 'Page "' . htmlspecialchars($cmsRow['title'] ?? '') . '" deleted.');
        }
    }

    if ($action === 'toggle_status') {
        $toggleId = (int) ($_POST['toggle_id'] ?? 0);
        if ($toggleId > 0) {
            $stmt = $pdo->prepare('SELECT id, title, status FROM pages WHERE id = :id');
            $stmt->execute(['id' => $toggleId]);
            $row = $stmt->fetch();
            if ($row) {
                $newStatus = $row['status'] === 'published' ? 'draft' : 'published';
                $pdo->prepare('UPDATE pages SET status = :s WHERE id = :id')->execute(['s' => $newStatus, 'id' => $toggleId]);
                logPageAction($pdo, $toggleId, $newStatus === 'published' ? 'published' : 'unpublished', $userId, 'Status changed to ' . $newStatus);
                redirect_with_flash('?page=cms', '"' . htmlspecialchars($row['title']) . '" is now ' . $newStatus . '.');
            }
        }
    }
}

$activeTab = $_GET['tab'] ?? 'pages';
$search = trim($_GET['search'] ?? '');
$catFilter = trim($_GET['category'] ?? '');
?>
<section>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <h1 style="margin:0">Content Management</h1>
        <?php if (hasPermission('cms', 'create')): ?>
<a href="?page=cms_edit" class="btn btn-primary"><i class="fas fa-plus"></i> Create New Page</a>
<?php endif; ?>
    </div>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="tab-bar">
        <button class="tab-btn<?= $activeTab === 'pages' ? ' active' : '' ?>" onclick="switchTab(this,'pages-tab');window.location.hash=''"><i class="fas fa-file-alt"></i> Pages</button>
        <button class="tab-btn<?= $activeTab === 'logs' ? ' active' : '' ?>" onclick="switchTab(this,'logs-tab');window.location.hash='#logs'"><i class="fas fa-history"></i> Activity Logs</button>
    </div>

    <div id="pages-tab" class="tab-panel<?= $activeTab === 'pages' ? ' active' : '' ?>">
        <div class="panel" style="margin-bottom:16px">
            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                <input type="hidden" name="page" value="cms">
                <input type="hidden" name="tab" value="pages">
                <label style="flex:1;min-width:180px">Search
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by title or content...">
                </label>
                <label style="min-width:150px">Category
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"<?= $catFilter === $cat ? ' selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($search !== '' || $catFilter !== ''): ?>
                    <a href="?page=cms&tab=pages" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="panel">
            <?php
            $sql = 'SELECT id, title, category, status, display_order, created_by, created_at, updated_at FROM pages WHERE 1=1';
            $params = [];
            if ($search !== '') {
                $sql .= ' AND (title LIKE :search OR content LIKE :search2)';
                $params['search'] = "%$search%";
                $params['search2'] = "%$search%";
            }
            if ($catFilter !== '') {
                $sql .= ' AND category = :cat';
                $params['cat'] = $catFilter;
            }
            $sql .= ' ORDER BY display_order ASC, updated_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $pages = $stmt->fetchAll();
            ?>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th style="width:60px">Order</th>
                        <th style="width:100px">Status</th>
                        <th style="width:140px">Updated</th>
                        <th style="width:150px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pages)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--neutral-500)">No pages found. <a href="?page=cms_edit">Create your first page</a>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pages as $p): ?>
                            <tr>
                                <td><?= (int) $p['id'] ?></td>
                                <td><strong><?= htmlspecialchars($p['title']) ?></strong></td>
                                <td><span class="status-badge" style="background:var(--neutral-200);color:var(--neutral-700)"><?= htmlspecialchars($p['category']) ?></span></td>
                                <td style="text-align:center"><?= (int) ($p['display_order'] ?? 0) ?></td>
                                <td><?= $p['status'] === 'published' ? '<span class="status-badge status-approved">Published</span>' : '<span class="status-badge status-pending">Draft</span>' ?></td>
                                <td style="font-size:0.82rem"><?= htmlspecialchars($p['updated_at']) ?></td>
                                <td style="white-space:nowrap">
                                    <a href="?page=info&id=<?= (int) $p['id'] ?>" class="action-link" target="_blank" title="Preview"><i class="fas fa-eye"></i></a>
                                    <?php if (hasPermission('cms', 'edit')): ?>
                                    <a href="?page=cms_edit&id=<?= (int) $p['id'] ?>" class="action-link" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('cms', 'manage')): ?>
                                    <form method="post" style="display:inline" title="<?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="toggle_status">
                                        <input type="hidden" name="toggle_id" value="<?= (int) $p['id'] ?>">
                                        <button type="submit" class="action-link" style="background:none;border:none;cursor:pointer;padding:0;font-size:1rem">
                                            <?php if ($p['status'] === 'published'): ?>
                                                <i class="fas fa-ban" style="color:var(--orange-500)"></i>
                                            <?php else: ?>
                                                <i class="fas fa-check-circle" style="color:var(--green-500)"></i>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (hasPermission('cms', 'delete')): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Delete &quot;<?= htmlspecialchars(addslashes($p['title'])) ?>&quot; permanently?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?= (int) $p['id'] ?>">
                                        <button type="submit" class="action-link" style="background:none;border:none;cursor:pointer;padding:0;font-size:1rem;color:var(--red-500)" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div id="logs-tab" class="tab-panel<?= $activeTab === 'logs' ? ' active' : '' ?>">
        <div class="panel">
            <?php
            $logs = $pdo->query('SELECT l.*, p.title AS page_title, u.full_name AS user_name FROM cms_activity_log l LEFT JOIN pages p ON l.page_id = p.id LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 200')->fetchAll();
            ?>
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Time</th><th>Page</th><th>Action</th><th>User</th><th>Details</th></tr></thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--neutral-500)">No activity recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="font-size:0.82rem;white-space:nowrap"><?= htmlspecialchars($log['created_at']) ?></td>
                                <td><strong><?= htmlspecialchars($log['page_title'] ?? '(deleted)') ?></strong></td>
                                <td><span class="status-badge" style="font-size:0.72rem;background:var(--neutral-200);color:var(--neutral-700)"><?= htmlspecialchars(ucfirst($log['action'])) ?></span></td>
                                <td style="font-size:0.85rem"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                                <td style="font-size:0.85rem;color:var(--neutral-600)"><?= htmlspecialchars((string) ($log['details'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</section>
<style>
.action-link { display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;color:var(--brown-600);text-decoration:none;border-radius:6px;transition:all 0.12s }
.action-link:hover { background:var(--neutral-100) }
#logs-tab { display:<?= $activeTab === 'logs' ? 'block' : 'none' ?> }
</style>
<script>
(function() {
    if (window.location.hash === '#logs') {
        var btn = document.querySelector('.tab-btn[onclick*="logs"]');
        if (btn) btn.click();
    }
})();
</script>
