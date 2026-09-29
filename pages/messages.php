<?php
require_once __DIR__ . '/../includes/auto_migrate.php';
requireCustomer();
$pdo = getPDO();
ensureMessagesMigration($pdo);
$uid = (int)(currentUser()['id'] ?? 0);
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['send_message'])) {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['body'] ?? '');
        if ($subject === '' || $message === '') {
            $messages[] = ['type' => 'error', 'text' => 'Subject and message are required.'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO messages (sender_id, subject, message, created_at) VALUES (:sid, :subj, :msg, NOW())');
            $stmt->execute(['sid' => $uid, 'subj' => $subject, 'msg' => $message]);
            $messages[] = ['type' => 'success', 'text' => 'Message sent to admin. You will see the reply here.'];
        }
    }
    if (isset($_POST['delete_message'])) {
        $mid = (int)($_POST['message_id'] ?? 0);
        if ($mid > 0) {
            $pdo->prepare('DELETE FROM messages WHERE id = :id AND sender_id = :uid')->execute(['id' => $mid, 'uid' => $uid]);
            $messages[] = ['type' => 'success', 'text' => 'Message deleted.'];
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM messages WHERE sender_id = :uid ORDER BY created_at DESC');
$stmt->execute(['uid' => $uid]);
$msgList = $stmt->fetchAll();

$unreadCount = 0;
$readStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender_id = :uid AND reply IS NOT NULL AND is_read = 0');
$readStmt->execute(['uid' => $uid]);
$unreadCount = (int) $readStmt->fetchColumn();
if ($unreadCount > 0) {
    $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = :uid AND reply IS NOT NULL AND is_read = 0')->execute(['uid' => $uid]);
}
?>
<style>
.msg-wrap{max-width:900px;margin:0 auto}
.msg-card{background:#fff;border-radius:12px;border:1px solid var(--neutral-200);padding:20px;margin-bottom:14px}
.msg-card h2{font-size:1rem;font-weight:700;color:var(--brown-800);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.msg-card h2 i{color:var(--brown-600)}
.msg-input{width:100%;padding:10px 12px;border:1px solid var(--neutral-300);border-radius:8px;font-size:0.88rem}
.msg-input:focus{outline:none;border-color:var(--brown-500);box-shadow:0 0 0 2px rgba(139,105,20,0.15)}
.msg-label{display:block;font-size:0.82rem;font-weight:600;color:var(--neutral-600);margin-bottom:5px}
.msg-field{margin-bottom:12px}
.msg-btn{padding:10px 24px;border:none;border-radius:8px;font-size:0.88rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.msg-btn:hover{opacity:0.85}
.msg-btn-green{background:var(--green-600,#059669);color:#fff}
.msg-btn-red{background:var(--red-100,#fee2e2);color:var(--red-700,#b91c1c)}
.msg-thread{border:1px solid var(--neutral-200);border-radius:10px;margin-bottom:12px;overflow:hidden}
.msg-bubble{padding:14px 18px;font-size:0.85rem}
.msg-bubble.sent{background:var(--brown-50,#f5f0e8);border-bottom:1px solid var(--neutral-200)}
.msg-bubble.reply{background:var(--green-50,#f0fdf4)}
.msg-bubble .msg-meta{font-size:0.72rem;color:var(--neutral-500);margin-bottom:6px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:4px}
.msg-bubble .msg-subject{font-weight:700;color:var(--brown-800);font-size:0.92rem;margin-bottom:4px}
.msg-bubble .msg-text{color:var(--neutral-700);line-height:1.5;white-space:pre-wrap}
.msg-empty{text-align:center;padding:30px;color:var(--neutral-500)}
.msg-badge{background:var(--red-600);color:#fff;border-radius:50%;font-size:0.68rem;padding:2px 6px;font-weight:700;margin-left:4px}
</style>

<section class="msg-wrap">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--brown-800);margin:0"><i class="fas fa-envelope"></i> Messages</h1>
        <?php if ($unreadCount > 0): ?><span class="msg-badge"><?= $unreadCount ?> new repl<?= $unreadCount > 1 ? 'ies' : 'y' ?></span><?php endif; ?>
    </div>

    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div class="msg-card">
        <h2><i class="fas fa-paper-plane"></i> Send a Message</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="send_message" value="1">
            <div class="msg-field">
                <span class="msg-label">Subject *</span>
                <input type="text" name="subject" class="msg-input" placeholder="e.g. Order inquiry, delivery issue, product question..." required maxlength="200">
            </div>
            <div class="msg-field">
                <span class="msg-label">Message *</span>
                <textarea name="body" rows="5" class="msg-input" placeholder="Describe your question or issue in detail..." required></textarea>
            </div>
            <button type="submit" class="msg-btn msg-btn-green"><i class="fas fa-paper-plane"></i> Send Message</button>
        </form>
    </div>

    <div class="msg-card">
        <h2><i class="fas fa-inbox"></i> Message History</h2>
        <?php if (empty($msgList)): ?>
            <div class="msg-empty"><i class="fas fa-envelope-open" style="font-size:2rem;color:var(--neutral-300);display:block;margin-bottom:8px"></i>No messages yet. Send a message above to contact admin.</div>
        <?php else: ?>
            <?php foreach ($msgList as $msg): ?>
                <div class="msg-thread">
                    <div class="msg-bubble sent">
                        <div class="msg-meta">
                            <span><strong>You</strong> &middot; <?= htmlspecialchars($msg['created_at']) ?></span>
                            <?php if ($msg['reply']): ?><span style="color:var(--green-600);font-weight:600"><i class="fas fa-check"></i> Replied</span><?php endif; ?>
                        </div>
                        <div class="msg-subject"><?= htmlspecialchars($msg['subject']) ?></div>
                        <div class="msg-text"><?= htmlspecialchars($msg['message']) ?></div>
                    </div>
                    <?php if ($msg['reply']): ?>
                        <div class="msg-bubble reply">
                            <div class="msg-meta">
                                <span><strong>Admin Reply</strong> &middot; <?= htmlspecialchars($msg['replied_at']) ?></span>
                            </div>
                            <div class="msg-text"><?= htmlspecialchars($msg['reply']) ?></div>
                        </div>
                    <?php endif; ?>
                    <div style="padding:8px 14px;display:flex;justify-content:flex-end;border-top:1px solid var(--neutral-100)">
                        <form method="post" onsubmit="return confirm('Delete this message?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_message" value="1">
                            <input type="hidden" name="message_id" value="<?= (int)$msg['id'] ?>">
                            <button type="submit" class="msg-btn msg-btn-red" style="padding:8px 14px;min-height:40px;font-size:0.78rem"><i class="fas fa-trash"></i> Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
