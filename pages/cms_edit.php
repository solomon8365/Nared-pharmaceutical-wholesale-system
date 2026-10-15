<?php
require_once __DIR__ . '/../services/init.php';
requirePermission('cms', 'create');

$pdo = getPDO();
$errors = [];
$success = '';

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

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pageData = null;
if ($editId > 0) {
    requirePermission('cms', 'edit');
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editId]);
    $pageData = $stmt->fetch();
    if (!$pageData) {
        header('Location: ?page=cms');
        exit;
    }
}

$title = $pageData['title'] ?? '';
$slug = $pageData['slug'] ?? '';
$category = $pageData['category'] ?? 'General';
$content = $pageData['content'] ?? '';
$featuredImage = $pageData['featured_image'] ?? '';
$document = $pageData['document'] ?? '';
$metaDescription = $pageData['meta_description'] ?? '';
$keywords = $pageData['keywords'] ?? '';
$displayOrder = (int) ($pageData['display_order'] ?? 0);
$status = $pageData['status'] ?? 'draft';
$isEdit = $editId > 0;
$createdByName = '';
if ($isEdit && !empty($pageData['created_by'])) {
    $createdByName = userFullName($pdo, (int) $pageData['created_by']) ?: 'Unknown';
}
$updatedByName = '';
if ($isEdit && !empty($pageData['updated_by'])) {
    $updatedByName = userFullName($pdo, (int) $pageData['updated_by']) ?: 'Unknown';
}

$uploadDir = __DIR__ . '/../uploads/info/';

function generateSlug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug ?: 'page-' . time();
}

function makeUniqueSlug(PDO $pdo, string $slug, ?int $excludeId = null): string
{
    $original = $slug;
    $counter = 0;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = :s' . ($excludeId ? ' AND id != :eid' : ''));
        $params = ['s' => $slug];
        if ($excludeId) $params['eid'] = $excludeId;
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() === 0) return $slug;
        $counter++;
        $slug = $original . '-' . $counter;
    }
}

function logPageAction(PDO $pdo, int $pageId, string $action, ?int $userId, string $details = ''): void
{
    AuditService::cms($pdo, $userId, $action, 'page', $pageId, $details);
}

function handleFileUpload(array $file, string $uploadDir, string $prefix, array $allowed): ?string
{
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    $fname = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    // $uploadDir is retained for signature compatibility; storage_put resolves
    // the destination (local uploads/info/ or the Supabase bucket).
    return storage_put($file['tmp_name'], 'info', $fname);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $userId = (int) ($_SESSION['user']['id'] ?? 0);

    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $content = $_POST['content'] ?? '';
    $newStatus = $_POST['status'] ?? 'draft';
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');
    $displayOrder = (int) ($_POST['display_order'] ?? 0);

    if ($title === '') {
        $errors[] = 'Page title is required.';
    } else {
        $slug = generateSlug($title);

        // Handle featured image upload
        $newImage = $featuredImage;
        if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === UPLOAD_ERR_OK) {
            $result = handleFileUpload($_FILES['featured_image'], $uploadDir, 'page', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($result) {
                if ($newImage && file_exists($uploadDir . basename($newImage))) @unlink($uploadDir . basename($newImage));
                $newImage = $result;
            } else {
                $errors[] = 'Featured image must be JPG, PNG, GIF, or WebP.';
            }
        } elseif (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if ($newImage && file_exists($uploadDir . basename($newImage))) @unlink($uploadDir . basename($newImage));
            $newImage = '';
        }

        // Handle document upload
        $newDocument = $document;
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $result = handleFileUpload($_FILES['document'], $uploadDir, 'doc', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);
            if ($result) {
                if ($newDocument && file_exists($uploadDir . basename($newDocument))) @unlink($uploadDir . basename($newDocument));
                $newDocument = $result;
            } else {
                $errors[] = 'Document must be PDF, DOC, DOCX, XLS, or XLSX.';
            }
        } elseif (isset($_POST['remove_document']) && $_POST['remove_document'] === '1') {
            if ($newDocument && file_exists($uploadDir . basename($newDocument))) @unlink($uploadDir . basename($newDocument));
            $newDocument = '';
        }

        if (empty($errors)) {
            try {
                $slug = makeUniqueSlug($pdo, $slug, $isEdit ? $editId : null);

                if ($isEdit) {
                    $pdo->prepare('UPDATE pages SET title=:title, slug=:slug, category=:cat, content=:content, featured_image=:img, document=:doc, meta_description=:md, keywords=:kw, display_order=:ord, status=:status, updated_by=:uby WHERE id=:id')->execute([
                        'title' => $title, 'slug' => $slug, 'cat' => $category,
                        'content' => $content, 'img' => $newImage, 'doc' => $newDocument,
                        'md' => $metaDescription, 'kw' => $keywords, 'ord' => $displayOrder,
                        'status' => $newStatus, 'uby' => $userId, 'id' => $editId,
                    ]);
                    logPageAction($pdo, $editId, 'updated', $userId);
                    $success = 'Page updated successfully.';
                } else {
                    $pdo->prepare('INSERT INTO pages (title, slug, category, content, featured_image, document, meta_description, keywords, display_order, status, created_by) VALUES (:title, :slug, :cat, :content, :img, :doc, :md, :kw, :ord, :status, :cby)')->execute([
                        'title' => $title, 'slug' => $slug, 'cat' => $category,
                        'content' => $content, 'img' => $newImage, 'doc' => $newDocument,
                        'md' => $metaDescription, 'kw' => $keywords, 'ord' => $displayOrder,
                        'status' => $newStatus, 'cby' => $userId,
                    ]);
                    $editId = (int) $pdo->lastInsertId();
                    $isEdit = true;
                    logPageAction($pdo, $editId, 'created', $userId);
                    $success = 'Page created successfully.';
                }

                // Reload
                $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $editId]);
                $pageData = $stmt->fetch();
                $title = $pageData['title']; $slug = $pageData['slug']; $category = $pageData['category'];
                $content = $pageData['content']; $featuredImage = $pageData['featured_image'];
                $document = $pageData['document']; $metaDescription = $pageData['meta_description'];
                $keywords = $pageData['keywords']; $displayOrder = (int) ($pageData['display_order'] ?? 0);
                $status = $pageData['status'];
                if (!empty($pageData['created_by'])) {
                    $createdByName = userFullName($pdo, (int) $pageData['created_by']) ?: 'Unknown';
                }
                if (!empty($pageData['updated_by'])) {
                    $updatedByName = userFullName($pdo, (int) $pageData['updated_by']) ?: 'Unknown';
                }
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . ErpService::friendlyError($e);
            }
        }
    }
}
?>
<section>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <h1 style="margin:0"><?= $isEdit ? 'Edit Page' : 'Create New Page' ?></h1>
        <div style="display:flex;gap:8px">
            <?php if ($isEdit && $status === 'published'): ?>
                <a href="?page=info&id=<?= $editId ?>" target="_blank" class="btn btn-secondary"><i class="fas fa-eye"></i> Preview</a>
            <?php endif; ?>
            <a href="?page=cms" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Pages</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($isEdit): ?>
        <div class="panel" style="margin-bottom:16px;padding:10px 16px;font-size:0.82rem;color:var(--neutral-600);display:flex;gap:20px;flex-wrap:wrap">
            <span><strong>Created by:</strong> <?= htmlspecialchars($createdByName) ?> on <?= htmlspecialchars($pageData['created_at'] ?? '') ?></span>
            <?php if (!empty($pageData['updated_at']) && $pageData['updated_at'] !== $pageData['created_at']): ?>
                <span><strong>Last updated by:</strong> <?= htmlspecialchars($updatedByName) ?> on <?= htmlspecialchars($pageData['updated_at']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid" style="max-width:100%">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $isEdit ? 'update' : 'create' ?>">

        <div class="mob-grid-21" style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
            <label>Page Title *
                <input type="text" name="title" value="<?= htmlspecialchars($title) ?>" required placeholder="e.g. Our Delivery Policy">
            </label>
            <label>Category
                <select name="category">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"<?= $category === $cat ? ' selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="mob-grid-3" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
            <label>Status
                <select name="status">
                    <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>>Draft</option>
                    <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>Published</option>
                </select>
            </label>
            <label>Display Order
                <input type="number" name="display_order" value="<?= $displayOrder ?>" min="0" step="1" placeholder="0">
            </label>
            <label>Keywords
                <input type="text" name="keywords" value="<?= htmlspecialchars($keywords) ?>" placeholder="pharmacy, wholesale, policy">
            </label>
        </div>

        <label>Meta Description
            <textarea name="meta_description" rows="2" style="width:100%" placeholder="Brief summary for search engines and page previews..."><?= htmlspecialchars($metaDescription) ?></textarea>
        </label>

        <label>Content <small>(Rich text editor)</small>
            <textarea name="content" id="page-content" rows="20" style="width:100%;min-height:400px"><?= htmlspecialchars($content) ?></textarea>
        </label>

        <div class="mob-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <label>Featured Image
                <input type="file" name="featured_image" accept="image/*">
                <?php if ($featuredImage): ?>
                    <div style="margin-top:6px;display:flex;align-items:center;gap:10px">
                        <img src="<?= htmlspecialchars($featuredImage) ?>" style="max-width:100px;max-height:50px;border-radius:6px;object-fit:cover">
                        <label style="font-size:0.8rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input type="checkbox" name="remove_image" value="1"> Remove
                        </label>
                    </div>
                <?php endif; ?>
            </label>
            <label>Attach Document (PDF, DOC, XLS)
                <input type="file" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx">
                <?php if ($document): ?>
                    <div style="margin-top:6px;display:flex;align-items:center;gap:10px">
                        <a href="<?= htmlspecialchars($document) ?>" target="_blank" style="color:var(--brown-600);text-decoration:none;font-size:0.85rem"><i class="fas fa-file"></i> View Document</a>
                        <label style="font-size:0.8rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input type="checkbox" name="remove_document" value="1"> Remove
                        </label>
                    </div>
                <?php endif; ?>
            </label>
        </div>

        <div style="display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $isEdit ? 'Update Page' : 'Create Page' ?></button>
            <a href="?page=cms" class="cancel-link">Cancel</a>
        </div>
    </form>
</section>

<script src="assets/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#page-content',
    height: 500,
    plugins: 'code advlist autolink lists link image charmap preview anchor searchreplace visualblocks fullscreen table emoticons help',
    toolbar: 'undo redo | blocks fontsize | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | numlist bullist outdent indent | link image table | code fullscreen | help',
    menubar: 'file edit view insert format tools table help',
    content_style: 'body { font-family:Inter,system-ui,sans-serif; font-size:15px; line-height:1.6; color:#1f2937; max-width:900px; padding:15px }',
    branding: false,
    promotion: false,
    setup: function(editor) {
        editor.on('init', function() {
            document.querySelector('form').addEventListener('submit', function() {
                editor.save();
            });
        });
    }
});
</script>
<style>
.cancel-link { display:inline-flex;align-items:center;padding:10px 20px;font-weight:700;color:var(--brown-600);text-decoration:none }
</style>
<style>@media(max-width:768px){.mob-grid-21{grid-template-columns:1fr!important}.mob-grid-3{grid-template-columns:1fr!important}.mob-grid-2{grid-template-columns:1fr!important}}</style>
