<?php
require_once __DIR__ . '/../includes/auto_migrate.php';
requirePermission('users', 'view');
$pdo = getPDO();
ensureMessagesMigration($pdo);
$uid = (int)(currentUser()['id'] ?? 0);
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['send_reply'])) {
        requirePermission('users', 'edit');
        $mid = (int)($_POST['message_id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        if ($mid <= 0 || $reply === '') {
            $messages[] = ['type' => 'error', 'text' => 'Reply text is required.'];
        } else {
            $stmt = $pdo->prepare('UPDATE messages SET reply = :reply, replied_by = :uid, replied_at = NOW() WHERE id = :id');
            $stmt->execute(['reply' => $reply, 'uid' => $uid, 'id' => $mid]);
            if ($stmt->rowCount() > 0) {
                redirect_with_flash('?page=admin_messages', 'Reply sent.');
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Message not found.'];
            }
        }
    }
    if (isset($_POST['delete_message'])) {
        requirePermission('users', 'delete');
        $mid = (int)($_POST['message_id'] ?? 0);
        if ($mid > 0) {
            $pdo->prepare('DELETE FROM messages WHERE id = :id')->execute(['id' => $mid]);
            redirect_with_flash('?page=admin_messages', 'Message deleted.');
        }
    }
}

$filter = trim($_GET['filter'] ?? 'unread');
if ($filter === 'read') {
    $where = 'm.is_read = 1 AND m.reply IS NOT NULL';
} elseif ($filter === 'replied') {
    $where = 'm.reply IS NOT NULL';
} elseif ($filter === 'all') {
    $where = '1=1';
} else {
    $where = '(m.reply IS NULL OR m.is_read = 0)';
}

$search = trim($_GET['search'] ?? '');
$params = [];
if ($search !== '') {
    $where .= ' AND (m.subject LIKE :s OR m.message LIKE :s2 OR u.full_name LIKE :s3)';
    $params['s'] = "%$search%";
    $params['s2'] = "%$search%";
    $params['s3'] = "%$search%";
}

$stmt = $pdo->prepare("SELECT m.*, u.full_name AS sender_name, ru.full_name AS replied_by_name
    FROM messages m
    LEFT JOIN users u ON m.sender_id = u.id
    LEFT JOIN users ru ON m.replied_by = ru.id
    WHERE $where
    ORDER BY m.created_at DESC");
$stmt->execute($params);
$msgList = $stmt->fetchAll();

$counts = ['total' => 0, 'unread' => 0, 'replied' => 0];
$allStmt = $pdo->query('SELECT COUNT(*) as total, SUM(CASE WHEN reply IS NULL THEN 1 ELSE 0 END) as unread, SUM(CASE WHEN reply IS NOT NULL THEN 1 ELSE 0 END) as replied FROM messages');
$allCounts = $allStmt->fetch();
if ($allCounts) {
    $counts['total'] = (int)$allCounts['total'];
    $counts['unread'] = (int)$allCounts['unread'];
    $counts['replied'] = (int)$allCounts['replied'];
}
?>
<style>
.am-wrap{max-width:1100px;margin:0 auto}
.am-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.am-stat{background:#fff;border-radius:10px;border:1px solid var(--neutral-200);padding:14px;display:flex;align-items:center;gap:10px}
.am-stat-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem}
.am-stat-val{font-size:1.1rem;font-weight:700;color:var(--brown-800)}
.am-stat-label{font-size:0.72rem;color:var(--neutral-500)}
.am-card{background:#fff;border-radius:12px;border:1px solid var(--neutral-200);margin-bottom:14px;overflow:hidden}
.am-card-head{padding:16px 20px;border-bottom:1px solid var(--neutral-100);display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
.am-card-body{padding:16px 20px}
.am-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:6px;font-size:0.82rem;margin-bottom:10px}
.am-grid .lbl{color:var(--neutral-500);font-size:0.72rem;display:block}
.am-grid .val{font-weight:600;color:var(--brown-800)}
.am-msg{background:var(--neutral-50);border-radius:8px;padding:12px;font-size:0.85rem;line-height:1.5;white-space:pre-wrap;margin-bottom:10px;border:1px solid var(--neutral-100)}
.am-reply-box{background:var(--green-50);border:1px solid var(--green-200);border-radius:8px;padding:12px;font-size:0.85rem;line-height:1.5;white-space:pre-wrap;margin-bottom:10px}
.am-btn{padding:8px 16px;min-height:40px;border:none;border-radius:8px;font-size:0.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px}
.am-btn:hover{opacity:0.85}
.am-btn-green{background:var(--green-600);color:#fff}
.am-btn-red{background:var(--red-100);color:var(--red-700)}
.am-btn-outline{background:transparent;border:1px solid var(--neutral-300);color:var(--neutral-600)}
.am-reply-form{display:none;margin-top:10px;padding:12px;background:var(--neutral-50);border:1px solid var(--neutral-200);border-radius:8px}
.am-reply-form.active{display:block}
.am-empty{text-align:center;padding:30px;color:var(--neutral-500)}
.am-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600}
.am-unread{border-left:4px solid var(--orange-500)}
.am-replied{border-left:4px solid var(--green-500)}
</style>

<section class="am-wrap">
    <h1 style="font-size:1.3rem;font-weight:700;color:var(--brown-800);margin-bottom:16px"><i class="fas fa-envelope-open-text"></i> Customer Messages</h1>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="am-stats">
        <div class="am-stat">
            <div class="am-stat-icon" style="background:var(--brown-100);color:var(--brown-700)"><i class="fas fa-envelope"></i></div>
            <div><div class="am-stat-val"><?= $counts['total'] ?></div><div class="am-stat-label">Total</div></div>
        </div>
        <div class="am-stat">
            <div class="am-stat-icon" style="background:var(--orange-100);color:var(--orange-700)"><i class="fas fa-envelope-open"></i></div>
            <div><div class="am-stat-val"><?= $counts['unread'] ?></div><div class="am-stat-label">Unread</div></div>
        </div>
        <div class="am-stat">
            <div class="am-stat-icon" style="background:var(--green-100);color:var(--green-700)"><i class="fas fa-reply"></i></div>
            <div><div class="am-stat-val"><?= $counts['replied'] ?></div><div class="am-stat-label">Replied</div></div>
        </div>
    </div>

    <div class="am-card" style="padding:16px 20px">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:0">
            <input type="hidden" name="page" value="admin_messages">
            <select name="filter" class="msg-input" style="flex:1;min-width:120px">
                <option value="unread"<?= $filter === 'unread' ? ' selected' : '' ?>>Unread</option>
                <option value="replied"<?= $filter === 'replied' ? ' selected' : '' ?>>Replied</option>
                <option value="all"<?= $filter === 'all' ? ' selected' : '' ?>>All</option>
            </select>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search subject, message, or customer..." style="flex:2;min-width:200px" class="msg-input">
            <button type="submit" class="am-btn am-btn-green"><i class="fas fa-search"></i> Filter</button>
            <?php if ($filter !== 'unread' || $search !== ''): ?><a href="?page=admin_messages" class="am-btn am-btn-outline"><i class="fas fa-times"></i> Clear</a><?php endif; ?>
        </form>
    </div>

    <?php if (empty($msgList)): ?>
        <div class="am-card"><div class="am-empty"><i class="fas fa-envelope-open" style="font-size:2rem;color:var(--neutral-300);display:block;margin-bottom:8px"></i>No messages found.</div></div>
    <?php endif; ?>

    <?php foreach ($msgList as $msg): ?>
        <?php $isUnread = empty($msg['reply']) || !$msg['is_read']; ?>
        <div class="am-card <?= $isUnread ? 'am-unread' : 'am-replied' ?>">
            <div class="am-card-head">
                <div>
                    <div style="font-weight:700;font-size:1rem;color:var(--brown-800)">
                        <?= htmlspecialchars($msg['subject']) ?>
                        <?php if (empty($msg['reply'])): ?>
                            <span class="am-badge" style="background:var(--orange-100);color:var(--orange-700)">No Reply</span>
                        <?php else: ?>
                            <span class="am-badge" style="background:var(--green-100);color:var(--green-700)"><i class="fas fa-check"></i> Replied</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.82rem;color:var(--neutral-500);margin-top:2px">
                        From: <strong><?= htmlspecialchars($msg['sender_name'] ?? 'Unknown') ?></strong> &middot; <?= htmlspecialchars($msg['created_at']) ?>
                    </div>
                </div>
            </div>
            <div class="am-card-body">
                <div class="am-msg"><?= htmlspecialchars($msg['message']) ?></div>

                <?php if ($msg['reply']): ?>
                    <div class="am-reply-box">
                        <strong style="color:var(--green-700)"><i class="fas fa-reply"></i> Admin Reply</strong>
                        <span style="font-size:0.72rem;color:var(--neutral-500);margin-left:6px"><?= htmlspecialchars($msg['replied_by_name'] ?? 'Admin') ?> &middot; <?= htmlspecialchars($msg['replied_at']) ?></span>
                        <div style="margin-top:6px"><?= htmlspecialchars($msg['reply']) ?></div>
                    </div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <button type="button" class="am-btn <?= $msg['reply'] ? 'am-btn-outline' : 'am-btn-green' ?>" onclick="toggleReply(<?= (int)$msg['id'] ?>)">
                        <i class="fas fa-reply"></i> <?= $msg['reply'] ? 'Edit Reply' : 'Reply' ?>
                    </button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this message?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="delete_message" value="1">
                        <input type="hidden" name="message_id" value="<?= (int)$msg['id'] ?>">
                        <button type="submit" class="am-btn am-btn-red"><i class="fas fa-trash"></i> Delete</button>
                    </form>

                    <div class="am-reply-form" id="reply-<?= (int)$msg['id'] ?>">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="send_reply" value="1">
                            <input type="hidden" name="message_id" value="<?= (int)$msg['id'] ?>">
                            <div style="margin-bottom:8px">
                                <textarea name="reply" rows="4" class="msg-input" required placeholder="Type your reply..."><?= htmlspecialchars($msg['reply'] ?? '') ?></textarea>
                            </div>
                            <div style="display:flex;gap:8px">
                                <button type="submit" class="am-btn am-btn-green"><i class="fas fa-paper-plane"></i> Send Reply</button>
                                <button type="button" class="am-btn am-btn-outline" onclick="toggleReply(<?= (int)$msg['id'] ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<script>
function toggleReply(id) {
    var el = document.getElementById('reply-' + id);
    if (el) el.classList.toggle('active');
}
</script>
