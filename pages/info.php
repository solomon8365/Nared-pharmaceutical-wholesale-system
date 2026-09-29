<?php
$pdo = getPDO();

$pageId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$slug = trim($_GET['slug'] ?? '');

$pageData = null;

if ($pageId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $pageId]);
    $pageData = $stmt->fetch();
} elseif ($slug !== '') {
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $pageData = $stmt->fetch();
}

if ($pageData) {
    $canPreviewDrafts = hasPermission('cms', 'view');
    if ($pageData['status'] !== 'published' && !$canPreviewDrafts) {
        $pageData = null;
    }
}

// Sidebar nav — published pages ordered by display_order then title
$navPages = $pdo->query("SELECT id, title, slug, category FROM pages WHERE status = 'published' ORDER BY display_order ASC, title ASC")->fetchAll();
$grouped = [];
foreach ($navPages as $np) {
    $grouped[$np['category']][] = $np;
}
?>
<section>
    <div class="info-main-layout" style="display:flex;gap:24px;flex-wrap:wrap">
        <div class="info-sidebar-el" style="flex:0 0 240px;min-width:200px">
            <div class="panel" style="padding:0;overflow:hidden">
                <div style="padding:14px 16px;background:var(--brown-600);color:#fff;font-weight:700;font-size:0.9rem"><i class="fas fa-info-circle"></i> Information Center</div>
                <div style="padding:8px 0">
                    <?php if (empty($grouped)): ?>
                        <p style="padding:12px 16px;color:var(--neutral-500);font-size:0.85rem">No published pages yet.</p>
                    <?php else: ?>
                        <?php foreach ($grouped as $gcat => $gpages): ?>
                            <div style="padding:6px 16px 2px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--neutral-500)"><?= htmlspecialchars($gcat) ?></div>
                            <?php foreach ($gpages as $gp): ?>
                                <a href="?page=info&id=<?= (int) $gp['id'] ?>" style="display:block;padding:6px 16px 6px 24px;font-size:0.85rem;color:<?= ($pageData && (int)$pageData['id'] === (int)$gp['id']) ? 'var(--brown-600)' : 'var(--neutral-700)' ?>;text-decoration:none;border-left:3px solid <?= ($pageData && (int)$pageData['id'] === (int)$gp['id']) ? 'var(--brown-600)' : 'transparent' ?>;background:<?= ($pageData && (int)$pageData['id'] === (int)$gp['id']) ? 'var(--neutral-50)' : 'transparent' ?>;transition:all 0.12s" onmouseover="this.style.background='var(--neutral-50)'" onmouseout="this.style.background='<?= ($pageData && (int)$pageData['id'] === (int)$gp['id']) ? 'var(--neutral-50)' : 'transparent' ?>'"><?= htmlspecialchars($gp['title']) ?></a>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="flex:1;min-width:0">
            <?php if ($pageData): ?>
                <div class="panel" style="padding:0;overflow:hidden">
                    <?php if ($pageData['featured_image']): ?>
                        <img src="<?= htmlspecialchars($pageData['featured_image']) ?>" alt="" style="width:100%;max-height:300px;object-fit:cover;display:block">
                    <?php endif; ?>
                    <div style="padding:24px 32px">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                            <a href="?page=info" style="display:inline-flex;align-items:center;gap:6px;color:var(--brown-600);text-decoration:none;font-size:0.85rem;font-weight:600"><i class="fas fa-arrow-left"></i> Back to Information Center</a>
                            <?php if (hasPermission('cms', 'edit')): ?>
                                <a href="?page=cms_edit&id=<?= (int) $pageData['id'] ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:var(--brown-600);color:#fff;border-radius:6px;text-decoration:none;font-size:0.82rem;font-weight:600"><i class="fas fa-edit"></i> Edit Page</a>
                            <?php endif; ?>
                        </div>
                        <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <span class="status-badge" style="background:var(--neutral-200);color:var(--neutral-700);font-size:0.75rem"><?= htmlspecialchars($pageData['category']) ?></span>
                            <?php if (!empty($pageData['keywords'])): ?>
                                <?php foreach (array_slice(explode(',', $pageData['keywords']), 0, 4) as $kw): ?>
                                    <span style="font-size:0.7rem;color:var(--neutral-500);background:var(--neutral-100);padding:2px 8px;border-radius:10px"><?= htmlspecialchars(trim($kw)) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <h1 style="margin:0 0 4px;font-size:1.6rem"><?= htmlspecialchars($pageData['title']) ?></h1>
                        <?php if (!empty($pageData['meta_description'])): ?>
                            <p style="margin:4px 0 12px;font-size:0.9rem;color:var(--neutral-500)"><?= htmlspecialchars($pageData['meta_description']) ?></p>
                        <?php endif; ?>
                        <div style="font-size:0.82rem;color:var(--neutral-500);margin-bottom:20px">
                            Last updated: <?= htmlspecialchars($pageData['updated_at']) ?>
                            <?php if ($pageData['status'] !== 'published'): ?>
                                <span class="status-badge status-pending" style="margin-left:8px">Draft (preview)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($pageData['document'])): ?>
                            <div style="margin-bottom:20px">
                                <a href="<?= htmlspecialchars($pageData['document']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:var(--brown-600);color:#fff;border-radius:8px;text-decoration:none;font-size:0.85rem;font-weight:600;transition:opacity 0.12s" onmouseover="this.style.opacity=0.85" onmouseout="this.style.opacity=1"><i class="fas fa-download"></i> Download Document</a>
                            </div>
                        <?php endif; ?>
                        <div class="info-content" style="line-height:1.8;font-size:0.95rem;color:var(--neutral-800)">
                            <?= $pageData['content'] ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="panel" style="text-align:center;padding:40px">
                    <i class="fas fa-info-circle" style="font-size:3rem;color:var(--neutral-300);margin-bottom:12px;display:block"></i>
                    <h2 style="margin:0 0 8px;font-size:1.3rem">Information Center</h2>
                    <p style="color:var(--neutral-500);max-width:400px;margin:0 auto">Select a page from the sidebar to view company information, policies, and guidelines.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<style>
.info-content h1, .info-content h2, .info-content h3 { margin-top:1.5em; margin-bottom:0.5em; color:var(--neutral-900) }
.info-content h1 { font-size:1.4rem }
.info-content h2 { font-size:1.2rem }
.info-content h3 { font-size:1.05rem }
.info-content p { margin:0.75em 0 }
.info-content ul, .info-content ol { margin:0.75em 0; padding-left:1.5em }
.info-content li { margin:0.3em 0 }
.info-content a { color:var(--brown-600); text-decoration:underline }
.info-content blockquote { border-left:4px solid var(--neutral-300); padding:8px 16px; margin:1em 0; background:var(--neutral-50); border-radius:0 8px 8px 0 }
.info-content table { border-collapse:collapse; width:100%; margin:1em 0 }
.info-content th, .info-content td { border:1px solid var(--neutral-300); padding:8px 12px; text-align:left }
.info-content th { background:var(--neutral-100); font-weight:700 }
.info-content img { max-width:100%; height:auto; border-radius:8px; margin:1em 0 }
.info-content pre { background:var(--neutral-100); padding:16px; border-radius:8px; overflow-x:auto; font-size:0.85rem }
</style>
<style>@media(max-width:768px){.info-main-layout{flex-direction:column!important}.info-sidebar-el{flex:0 0 auto!important;min-width:0!important;width:100%!important}}</style>
