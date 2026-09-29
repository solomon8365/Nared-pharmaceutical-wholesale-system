<?php
require_once __DIR__ . '/../includes/workflow_helpers.php';
requireCustomer();
$pdo = getPDO();
$cid = customerAccountCustomerId();
if ($cid === null) { echo '<div class="panel"><p>Your account is not linked to a customer profile.</p></div>'; return; }

$tab = $_GET['tab'] ?? 'faq';

/* ── Handle Support Ticket ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'submit_ticket') {
    require_csrf();
    $stmt = $pdo->prepare('INSERT INTO support_tickets (customer_id, user_id, type, subject, message, priority) VALUES (:cid, :uid, :type, :subj, :msg, :pri)');
    $stmt->execute([
        'cid' => $cid, 'uid' => (int)($_SESSION['user']['id'] ?? 0),
        'type' => $_POST['ticket_type'] ?? 'general',
        'subj' => $_POST['ticket_subject'] ?? '',
        'msg'  => $_POST['ticket_message'] ?? '',
        'pri'  => $_POST['ticket_priority'] ?? 'medium',
    ]);
    header('Location: index.php?page=help_center&tab=tickets&msg=sent');
    exit;
}

$msg = $_GET['msg'] ?? '';
$faqCat = $_GET['faq_cat'] ?? 'all';

/* ── My Tickets ── */
$myTickets = [];
try { $mtStmt = $pdo->prepare('SELECT * FROM support_tickets WHERE customer_id = :cid ORDER BY created_at DESC'); $mtStmt->execute(['cid' => $cid]); $myTickets = $mtStmt->fetchAll(); } catch (Exception $e) {}
$openCount = 0; foreach ($myTickets as $t) { if ($t['status'] === 'open' || $t['status'] === 'in_progress') $openCount++; }
?>
<style>
.hc{max-width:1200px;margin:0 auto}
.hc-title{font-size:1.3rem;font-weight:700;color:#1e293b;margin:0 0 20px;display:flex;align-items:center;gap:10px}
.hc-title i{color:#2563eb}
.hc-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;overflow-x:auto}
.hc-tab{padding:10px 22px;font-size:0.88rem;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;text-decoration:none;display:flex;align-items:center;gap:6px;transition:all 0.15s;white-space:nowrap}
.hc-tab:hover{color:#1e293b}
.hc-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.hc-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:24px}
.hc-panel-head{padding:18px 22px;border-bottom:1px solid #f1f5f9}
.hc-panel-title{font-size:0.95rem;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.hc-panel-title i{color:#2563eb}
.hc-panel-body{padding:20px 22px}
.hc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.hc-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;text-align:center;text-decoration:none;color:#1e293b;transition:all 0.2s}
.hc-card:hover{transform:translateY(-3px);box-shadow:0 8px 25px rgba(0,0,0,0.08);border-color:#2563eb}
.hc-card-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin:0 auto 12px}
.hc-card-title{font-size:0.95rem;font-weight:700;margin:0 0 4px}
.hc-card-desc{font-size:0.82rem;color:#64748b;margin:0}
.hc-faq{margin-bottom:8px}
.hc-faq-q{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all 0.15s;font-weight:600;font-size:0.88rem;color:#1e293b}
.hc-faq-q:hover{background:#eff6ff;border-color:#bfdbfe}
.hc-faq-q i{color:#64748b;transition:transform 0.2s}
.hc-faq.open .hc-faq-q i{transform:rotate(180deg)}
.hc-faq-a{display:none;padding:14px 18px;font-size:0.85rem;color:#475569;line-height:1.6;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;margin-top:-9px}
.hc-faq.open .hc-faq-a{display:block}
.hc-faq-cats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.hc-faq-cat{padding:6px 14px;border-radius:20px;font-size:0.78rem;font-weight:600;text-decoration:none;border:1px solid #e2e8f0;color:#64748b;transition:all 0.15s}
.hc-faq-cat:hover,.hc-faq-cat.active{background:#2563eb;color:#fff;border-color:#2563eb}
.hc-ticket{padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px}
.hc-ticket-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.hc-ticket-id{font-weight:700;color:#1e293b;font-size:0.88rem}
.hc-ticket-status{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600}
.hc-ticket-status.open{background:#eff6ff;color:#1d4ed8}
.hc-ticket-status.in_progress{background:#fffbeb;color:#b45309}
.hc-ticket-status.resolved{background:#f0fdf4;color:#15803d}
.hc-ticket-status.closed{background:#f1f5f9;color:#64748b}
.hc-ticket-meta{font-size:0.78rem;color:#64748b;margin-bottom:4px}
.hc-ticket-reply{margin-top:10px;padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;color:#475569}
.hc-ticket-reply strong{color:#1e293b;display:block;margin-bottom:4px}
.hc-form{max-width:600px}
.hc-fg{margin-bottom:14px}
.hc-fg label{display:block;font-size:0.8rem;font-weight:600;color:#475569;margin-bottom:4px}
.hc-fg select,.hc-fg input,.hc-fg textarea{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font:inherit;font-size:0.88rem;color:#1e293b;background:#fff;box-sizing:border-box}
.hc-fg select:focus,.hc-fg input:focus,.hc-fg textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.1)}
.hc-fg textarea{min-height:100px;resize:vertical}
.hc-fg-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hc-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:8px;font-weight:600;font-size:0.88rem;cursor:pointer;transition:all 0.15s;border:none;font-family:inherit}
.hc-btn-primary{background:#2563eb;color:#fff}.hc-btn-primary:hover{background:#1d4ed8}
.hc-msg{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 20px;margin-bottom:16px;color:#166534;font-weight:600;font-size:0.88rem;display:flex;align-items:center;gap:10px}
.hc-empty{text-align:center;padding:40px;color:#94a3b8}
.hc-empty i{font-size:2rem;color:#cbd5e1;margin-bottom:8px;display:block}
.hc-empty p{font-size:0.88rem;margin:4px 0}
@media(max-width:768px){.hc-grid{grid-template-columns:1fr}.hc-fg-row{grid-template-columns:1fr}}
</style>

<section class="hc">
<div class="hc-title"><i class="fas fa-question-circle"></i> Help Center</div>

<?php if ($msg === 'sent'): ?><div class="hc-msg"><i class="fas fa-check-circle"></i> Support ticket submitted! We'll get back to you soon.</div><?php endif; ?>

<!-- Quick Links -->
<div class="hc-grid">
    <a href="?page=help_center&tab=faq" class="hc-card">
        <div class="hc-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-book"></i></div>
        <h3 class="hc-card-title">FAQ</h3>
        <p class="hc-card-desc">Find answers to common questions</p>
    </a>
    <a href="?page=help_center&tab=ticket" class="hc-card">
        <div class="hc-card-icon" style="background:#fffbeb;color:#d97706"><i class="fas fa-ticket-alt"></i></div>
        <h3 class="hc-card-title">Submit Ticket</h3>
        <p class="hc-card-desc">Get personalized support</p>
    </a>
    <a href="?page=help_center&tab=tickets" class="hc-card">
        <div class="hc-card-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-headset"></i></div>
        <h3 class="hc-card-title">My Tickets</h3>
        <p class="hc-card-desc"><?= $openCount ?> open ticket<?= $openCount !== 1 ? 's' : '' ?></p>
    </a>
</div>

<!-- Tabs -->
<div class="hc-tabs">
    <a href="?page=help_center&tab=faq" class="hc-tab <?= $tab === 'faq' ? 'active' : '' ?>"><i class="fas fa-book"></i> FAQ</a>
    <a href="?page=help_center&tab=ticket" class="hc-tab <?= $tab === 'ticket' ? 'active' : '' ?>"><i class="fas fa-ticket-alt"></i> Submit Ticket</a>
    <a href="?page=help_center&tab=tickets" class="hc-tab <?= $tab === 'tickets' ? 'active' : '' ?>"><i class="fas fa-list"></i> My Tickets <span style="background:#f1f5f9;padding:1px 6px;border-radius:8px;font-size:0.7rem;margin-left:4px"><?= count($myTickets) ?></span></a>
    <a href="?page=help_center&tab=guide" class="hc-tab <?= $tab === 'guide' ? 'active' : '' ?>"><i class="fas fa-book-reader"></i> User Guide</a>
    <a href="?page=help_center&tab=contact" class="hc-tab <?= $tab === 'contact' ? 'active' : '' ?>"><i class="fas fa-phone"></i> Contact</a>
</div>

<?php if ($tab === 'faq'): ?>
<!-- FAQ -->
<div class="hc-faq-cats">
    <a href="?page=help_center&tab=faq&faq_cat=all" class="hc-faq-cat <?= $faqCat === 'all' ? 'active' : '' ?>">All</a>
    <a href="?page=help_center&tab=faq&faq_cat=ordering" class="hc-faq-cat <?= $faqCat === 'ordering' ? 'active' : '' ?>">Ordering</a>
    <a href="?page=help_center&tab=faq&faq_cat=payment" class="hc-faq-cat <?= $faqCat === 'payment' ? 'active' : '' ?>">Payment</a>
    <a href="?page=help_center&tab=faq&faq_cat=delivery" class="hc-faq-cat <?= $faqCat === 'delivery' ? 'active' : '' ?>">Delivery</a>
    <a href="?page=help_center&tab=faq&faq_cat=account" class="hc-faq-cat <?= $faqCat === 'account' ? 'active' : '' ?>">Account</a>
    <a href="?page=help_center&tab=faq&faq_cat=products" class="hc-faq-cat <?= $faqCat === 'products' ? 'active' : '' ?>">Products</a>
</div>
<?php
$faqs = [
    ['cat'=>'ordering','q'=>'How do I place an order?','a'=>'Go to the Product Catalog, browse or search for products, add items to your cart, then proceed to Place Order. Select your delivery address and payment method, then confirm.'],
    ['cat'=>'ordering','q'=>'Can I modify my order after placing it?','a'=>'You can modify or cancel your order while it is still in "Pending" status. Once approved by the admin, modifications are no longer possible. Contact support for urgent changes.'],
    ['cat'=>'ordering','q'=>'What is the minimum order amount?','a'=>'There is no minimum order amount. However, bulk orders above 10,000 ETB qualify for free delivery.'],
    ['cat'=>'payment','q'=>'What payment methods are accepted?','a'=>'We accept Cash, Bank Transfer, Telebirr, CBE Birr, and Credit Account payments. Credit account is available for approved customers with a credit limit.'],
    ['cat'=>'payment','q'=>'How do I make a payment?','a'=>'Go to Payments page, select the unpaid invoice you want to pay, choose your payment method, enter the reference number, and submit. Your payment will be verified by our finance team.'],
    ['cat'=>'payment','q'=>'When is my payment confirmed?','a'=>'Payments are typically confirmed within 24 hours during business days. You will receive a notification once your payment is verified.'],
    ['cat'=>'delivery','q'=>'How long does delivery take?','a'=>'Standard delivery within Addis Ababa takes 1-2 business days. Delivery to other regions may take 3-5 business days depending on location.'],
    ['cat'=>'delivery','q'=>'How can I track my delivery?','a'=>'Go to My Orders and click on the order you want to track. The order tracker shows the current status from order placement to delivery.'],
    ['cat'=>'account','q'=>'How do I reset my password?','a'=>'Go to Settings > Change Password to update your password. You will need to enter your current password first.'],
    ['cat'=>'account','q'=>'How do I update my profile information?','a'=>'Go to Profile Settings to update your contact information, delivery address, and notification preferences.'],
    ['cat'=>'products','q'=>'Are products in stock?','a'=>'Stock availability is shown on each product in the catalog. Green indicates good stock, orange indicates low stock, and red means out of stock.'],
    ['cat'=>'products','q'=>'Do I need a prescription to order?','a'=>'Some prescription medications require a valid prescription. These products are marked with a prescription badge in the catalog.'],
];
$filtered = $faqCat === 'all' ? $faqs : array_filter($faqs, function($f) use ($faqCat) { return $f['cat'] === $faqCat; });
?>
<?php foreach ($filtered as $i => $f): ?>
<div class="hc-faq" onclick="this.classList.toggle('open')">
    <div class="hc-faq-q"><span><?= htmlspecialchars($f['q']) ?></span><i class="fas fa-chevron-down"></i></div>
    <div class="hc-faq-a"><?= htmlspecialchars($f['a']) ?></div>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'ticket'): ?>
<!-- SUBMIT TICKET -->
<div class="hc-panel">
    <div class="hc-panel-head"><div class="hc-panel-title"><i class="fas fa-ticket-alt"></i> Submit Support Ticket</div></div>
    <div class="hc-panel-body">
        <form method="post" class="hc-form">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="submit_ticket">
            <div class="hc-fg-row">
                <div class="hc-fg"><label>Type</label><select name="ticket_type"><option value="general">General Inquiry</option><option value="complaint">Complaint</option><option value="rfq">Request for Quote</option><option value="product_request">Product Request</option><option value="contact_rep">Contact Sales Rep</option></select></div>
                <div class="hc-fg"><label>Priority</label><select name="ticket_priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
            </div>
            <div class="hc-fg"><label>Subject</label><input type="text" name="ticket_subject" required placeholder="Brief description"></div>
            <div class="hc-fg"><label>Message</label><textarea name="ticket_message" required placeholder="Describe your issue in detail..."></textarea></div>
            <button type="submit" class="hc-btn hc-btn-primary"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'tickets'): ?>
<!-- MY TICKETS -->
<div class="hc-panel">
    <div class="hc-panel-head"><div class="hc-panel-title"><i class="fas fa-list"></i> My Support Tickets (<?= count($myTickets) ?>)</div></div>
    <div class="hc-panel-body">
    <?php if (empty($myTickets)): ?>
        <div class="hc-empty"><i class="fas fa-ticket-alt"></i><p>No support tickets yet.</p><p><a href="?page=help_center&tab=ticket" style="color:#2563eb;font-weight:600">Submit a ticket</a> for assistance.</p></div>
    <?php else: ?>
        <?php foreach ($myTickets as $t): ?>
        <div class="hc-ticket">
            <div class="hc-ticket-head">
                <div><span class="hc-ticket-id">#<?= $t['id'] ?> <?= htmlspecialchars($t['subject']) ?></span><span class="hc-ticket-status <?= $t['status'] ?>" style="margin-left:8px"><?= ucfirst(str_replace('_', ' ', $t['status'])) ?></span></div>
                <div style="font-size:0.75rem;color:#94a3b8"><?= date('M d, Y', strtotime($t['created_at'])) ?></div>
            </div>
            <div class="hc-ticket-meta"><span style="text-transform:capitalize"><?= $t['type'] ?></span> · Priority: <span style="text-transform:capitalize"><?= $t['priority'] ?></span></div>
            <p style="font-size:0.85rem;color:#475569;margin:6px 0 0;line-height:1.5"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
            <?php if ($t['admin_reply']): ?>
            <div class="hc-ticket-reply">
                <strong><i class="fas fa-reply" style="color:#2563eb"></i> Admin Reply (<?= date('M d, Y', strtotime($t['replied_at'])) ?>)</strong>
                <?= nl2br(htmlspecialchars($t['admin_reply'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'guide'): ?>
<!-- USER GUIDE -->
<div class="hc-panel">
    <div class="hc-panel-head"><div class="hc-panel-title"><i class="fas fa-book-reader"></i> User Guide</div></div>
    <div class="hc-panel-body">
        <div style="display:grid;gap:16px">
            <div style="padding:16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
                <h4 style="margin:0 0 8px;color:#1e293b;display:flex;align-items:center;gap:8px"><i class="fas fa-shopping-cart" style="color:#2563eb"></i> Placing an Order</h4>
                <ol style="font-size:0.85rem;color:#475569;line-height:1.8;padding-left:20px;margin:0"><li>Browse the Product Catalog or search for specific items</li><li>Click "Add to Cart" on the products you need</li><li>Go to Place Order to review your cart</li><li>Select delivery address and payment method</li><li>Review the order summary with VAT and totals</li><li>Click "Place Order" to confirm</li></ol>
            </div>
            <div style="padding:16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
                <h4 style="margin:0 0 8px;color:#1e293b;display:flex;align-items:center;gap:8px"><i class="fas fa-credit-card" style="color:#16a34a"></i> Making a Payment</h4>
                <ol style="font-size:0.85rem;color:#475569;line-height:1.8;padding-left:20px;margin:0"><li>Go to the Payments page</li><li>Select "Make Payment" tab</li><li>Choose the invoice you want to pay</li><li>Select your payment method (Cash, Bank, Telebirr, CBE Birr)</li><li>Enter the transaction reference number</li><li>Upload payment receipt if available</li><li>Click "Submit Payment"</li></ol>
            </div>
            <div style="padding:16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
                <h4 style="margin:0 0 8px;color:#1e293b;display:flex;align-items:center;gap:8px"><i class="fas fa-truck" style="color:#d97706"></i> Tracking Your Order</h4>
                <ol style="font-size:0.85rem;color:#475569;line-height:1.8;padding-left:20px;margin:0"><li>Go to My Orders page</li><li>Click on any order to see its details</li><li>The order tracker shows: Placed → Approved → Processing → Out for Delivery → Delivered</li><li>Delivery details (driver, vehicle, estimated date) appear when assigned</li></ol>
            </div>
            <div style="padding:16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
                <h4 style="margin:0 0 8px;color:#1e293b;display:flex;align-items:center;gap:8px"><i class="fas fa-user" style="color:#7c3aed"></i> Managing Your Account</h4>
                <ol style="font-size:0.85rem;color:#475569;line-height:1.8;padding-left:20px;margin:0"><li>Go to Profile Settings to update contact info and delivery address</li><li>Go to Change Password to update your password</li><li>View your credit limit and reward points on the Dashboard</li></ol>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'contact'): ?>
<!-- CONTACT -->
<div class="hc-panel">
    <div class="hc-panel-head"><div class="hc-panel-title"><i class="fas fa-phone"></i> Contact Support</div></div>
    <div class="hc-panel-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">
            <div style="padding:20px;background:#f8fafc;border-radius:10px;text-align:center;border:1px solid #e2e8f0">
                <div style="width:48px;height:48px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin:0 auto 12px"><i class="fas fa-phone-alt"></i></div>
                <h4 style="margin:0 0 4px;font-size:0.95rem">Phone Support</h4>
                <p style="font-size:0.88rem;color:#64748b;margin:0"><?= htmlspecialchars(get_setting('company_phone', '+251-XX-XXX-XXXX')) ?></p>
                <p style="font-size:0.75rem;color:#94a3b8;margin:4px 0 0">Mon-Sat: 8:00 AM - 6:00 PM</p>
            </div>
            <div style="padding:20px;background:#f8fafc;border-radius:10px;text-align:center;border:1px solid #e2e8f0">
                <div style="width:48px;height:48px;border-radius:50%;background:#f0fdf4;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin:0 auto 12px"><i class="fas fa-envelope"></i></div>
                <h4 style="margin:0 0 4px;font-size:0.95rem">Email Support</h4>
                <p style="font-size:0.88rem;color:#64748b;margin:0"><?= htmlspecialchars(get_setting('company_email', 'info@naredpharma.com')) ?></p>
                <p style="font-size:0.75rem;color:#94a3b8;margin:4px 0 0">Response within 24 hours</p>
            </div>
            <div style="padding:20px;background:#f8fafc;border-radius:10px;text-align:center;border:1px solid #e2e8f0">
                <div style="width:48px;height:48px;border-radius:50%;background:#fffbeb;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin:0 auto 12px"><i class="fas fa-map-marker-alt"></i></div>
                <h4 style="margin:0 0 4px;font-size:0.95rem">Visit Us</h4>
                <p style="font-size:0.88rem;color:#64748b;margin:0"><?= htmlspecialchars(get_setting('company_address', 'Addis Ababa, Ethiopia')) ?></p>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
</section>
