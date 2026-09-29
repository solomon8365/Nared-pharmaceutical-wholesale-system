<?php
require_once __DIR__ . '/../services/init.php';
require_once __DIR__ . '/../includes/backup_helper.php';
requirePermission('backup', 'view');
$pdo = getPDO();
$messages = [];
$errors = [];

$backupDir = pkb_backup_dir();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['create_backup'])) {
    require_csrf();
    $backupErr = null;
    $filepath = pkb_create_backup($backupErr);
    if (!$filepath) {
        $errors[] = $backupErr ?? 'Backup failed.';
    } else {
        $filename = basename($filepath);
        if (isset($_POST['download'])) {
            if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        }
        $messages[] = ['type' => 'success', 'text' => "Backup created: $filename"];
    }
}

if (isset($_GET['restore'])) {
    require_csrf();
    $file = basename($_GET['restore']);
    $filepath = $backupDir . '/' . $file;
    if (!preg_match('/\.sql$/i', $file)) {
        $errors[] = 'Invalid backup file: only .sql files can be restored.';
    } elseif (file_exists($filepath)) {
        $restoreErr = null;
        if (pkb_restore($filepath, $restoreErr)) {
            $messages[] = ['type' => 'success', 'text' => "Database restored from: $file"];
        } else {
            $errors[] = 'Restore failed: ' . ($restoreErr ?? 'Unknown restore error.');
        }
    } else {
        $errors[] = 'Backup file not found.';
    }
}

if (isset($_GET['error'])) {
    $errors[] = $_GET['error'];
}

if (isset($_GET['download_file'])) {
    require_csrf();
    $file = basename($_GET['download_file']);
    $filepath = $backupDir . '/' . $file;
    if (file_exists($filepath)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        $errors[] = 'Backup file not found.';
    }
}

if (isset($_GET['delete'])) {
    require_csrf();
    $file = basename($_GET['delete']);
    $filepath = $backupDir . '/' . $file;
    if (file_exists($filepath) && unlink($filepath)) {
        $messages[] = ['type' => 'success', 'text' => "Backup deleted: $file"];
    }
}

$backups = glob($backupDir . '/*.sql') ?: [];
usort($backups, function($a, $b) { return filemtime($b) - filemtime($a); });
?>
<section>
    <h1>Backup & Restore</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>
    <div class="panel" style="max-width:500px;margin-bottom:16px">
        <h2>Create Backup</h2>
        <?php if (hasPermission('backup', 'create')): ?>
        <form method="post" style="display:flex;gap:8px;align-items:flex-end" data-ajax-off="true">
            <?= csrf_field() ?>
            <input type="hidden" name="create_backup" value="1">
            <button type="submit" style="flex:1"><i class="fas fa-database"></i> Create Backup Now</button>
            <button type="submit" name="download" value="1" style="background:var(--brown-600);color:#fff"><i class="fas fa-download"></i> Backup & Download</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="panel">
        <h2>Existing Backups</h2>
        <?php if (empty($backups)): ?>
            <p class="hint">No backups found.</p>
        <?php else: ?>
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>File</th><th>Size</th><th>Date</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($backups as $bf): ?>
                        <?php $filename = basename($bf); $size = filesize($bf); ?>
                        <tr>
                            <td><code><?= htmlspecialchars($filename) ?></code></td>
                            <td><?= round($size / 1024, 1) ?> KB</td>
                            <td><?= date('Y-m-d H:i:s', filemtime($bf)) ?></td>
                            <td style="white-space:nowrap">
                                <a href="?page=backup&download_file=<?= urlencode($filename) ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" style="color:var(--brown-700);font-size:0.82rem;margin-right:8px"><i class="fas fa-download"></i></a>
                                <?php if (hasPermission('backup', 'restore')): ?>
                                <a href="?page=backup&restore=<?= urlencode($filename) ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" onclick="return confirm('Restore database from this backup? This will overwrite all data.')" style="color:var(--green-700);font-size:0.82rem;margin-right:8px"><i class="fas fa-undo"></i></a>
                                <?php endif; ?>
                                <?php if (hasPermission('backup', 'delete')): ?>
                                <a href="?page=backup&delete=<?= urlencode($filename) ?>&csrf_token=<?= htmlspecialchars(csrf_token()) ?>" onclick="return confirm('Delete this backup?')" style="color:var(--red-600);font-size:0.82rem"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</section>
