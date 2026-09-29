<?php
requirePermission('territories', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureSalesRepMigration(getPDO());
$pdo = getPDO();
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['save_territory'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $region = trim($_POST['region'] ?? '');

        if ($name === '') {
            $errors[] = 'Territory name is required.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE territories SET name = :name, description = :desc, region = :region WHERE id = :id');
                $stmt->execute(['name' => $name, 'desc' => $description ?: null, 'region' => $region ?: null, 'id' => $id]);
                $messages[] = ['type' => 'success', 'text' => 'Territory updated.'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO territories (name, description, region) VALUES (:name, :desc, :region)');
                $stmt->execute(['name' => $name, 'desc' => $description ?: null, 'region' => $region ?: null]);
                $messages[] = ['type' => 'success', 'text' => 'Territory added.'];
            }
        }
    }

    if (isset($_POST['delete_territory'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM territories WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() > 0) {
                $messages[] = ['type' => 'success', 'text' => 'Territory deleted.'];
            }
        }
    }
}

$territories = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM salespeople sp WHERE sp.territory_id = t.id) AS rep_count FROM territories t ORDER BY t.region ASC, t.name ASC')->fetchAll();
$summary = $pdo->query('SELECT COUNT(*) AS total FROM territories')->fetch();
?>
<section>
    <h1>Territories</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card"><h2>Total Territories</h2><p><?= (int) ($summary['total'] ?? 0) ?></p></div>
    </div>

    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab(this,'terr-list')"><i class="fas fa-map-marked-alt"></i> Territories</button>
        <button class="tab-btn" onclick="switchTab(this,'terr-add')"><i class="fas fa-plus"></i> Add Territory</button>
    </div>
    <div class="tab-content">
        <div id="terr-list" class="tab-panel active">
            <div class="panel">
                <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Region</th><th>Description</th><th>Reps</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($territories)): ?>
                            <tr><td colspan="6">No territories defined.</td></tr>
                        <?php else: ?>
                            <?php foreach ($territories as $t): ?>
                                <tr>
                                    <td><?= (int) $t['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                                    <td><?= htmlspecialchars((string) ($t['region'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars(mb_strimwidth((string) ($t['description'] ?? ''), 0, 80, '...')) ?></td>
                                    <td><?= (int) $t['rep_count'] ?></td>
                                    <td style="white-space:nowrap">
                                        <button onclick="editTerritory(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['name'])) ?>', '<?= htmlspecialchars(addslashes((string)($t['description'] ?? ''))) ?>', '<?= htmlspecialchars(addslashes((string)($t['region'] ?? ''))) ?>')" style="color:var(--brown-700);font-size:0.82rem;background:none;border:none;cursor:pointer;margin-right:8px"><i class="fas fa-edit"></i></button>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Delete territory &quot;<?= htmlspecialchars(addslashes($t['name'])) ?>&quot;?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_territory" value="1">
                                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                            <button type="submit" style="color:var(--red-600);background:none;border:none;cursor:pointer;font-size:0.82rem"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="terr-add" class="tab-panel">
            <div class="panel" style="max-width:500px">
                <h2 id="terr-form-title">Add Territory</h2>
                <form method="post" class="form-grid" id="terr-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="save_territory" value="1">
                    <input type="hidden" name="id" id="terr-id" value="0">
                    <label>Name<input type="text" name="name" id="terr-name" required placeholder="e.g. Addis Ababa Central"></label>
                    <label>Region<input type="text" name="region" id="terr-region" placeholder="e.g. Addis Ababa"></label>
                    <label>Description<textarea name="description" id="terr-desc" rows="3" placeholder="Optional description"></textarea></label>
                    <button type="submit" id="terr-save-btn">Save Territory</button>
                    <button type="button" id="terr-cancel-btn" style="display:none;background:var(--neutral-200);color:var(--neutral-700)" onclick="resetTerrForm()">Cancel</button>
                </form>
            </div>
        </div>
    </div>
</section>
<script>
function editTerritory(id, name, desc, region) {
    document.getElementById('terr-id').value = id;
    document.getElementById('terr-name').value = name;
    document.getElementById('terr-desc').value = desc;
    document.getElementById('terr-region').value = region;
    document.getElementById('terr-form-title').textContent = 'Edit Territory';
    document.getElementById('terr-save-btn').textContent = 'Update Territory';
    document.getElementById('terr-cancel-btn').style.display = 'inline-block';
    switchTab(document.querySelector('.tab-btn:last-child'), 'terr-add');
}
function resetTerrForm() {
    document.getElementById('terr-id').value = 0;
    document.getElementById('terr-name').value = '';
    document.getElementById('terr-desc').value = '';
    document.getElementById('terr-region').value = '';
    document.getElementById('terr-form-title').textContent = 'Add Territory';
    document.getElementById('terr-save-btn').textContent = 'Save Territory';
    document.getElementById('terr-cancel-btn').style.display = 'none';
}
</script>
