<?php
requirePermission('salespeople', 'view');
require_once __DIR__ . '/../includes/auto_migrate.php';
ensureSalesRepMigration(getPDO());
ensureSalespersonOrderApprovalMigration(getPDO());
$pdo = getPDO();
$messages = [];
$errors = [];

$activeTab = $_GET['tab'] ?? 'list';

$territories = $pdo->query('SELECT id, name, region FROM territories ORDER BY name')->fetchAll();
$allSalespeople = $pdo->query("SELECT id, name FROM salespeople WHERE status = 'active' ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY name")->fetchAll();
$products = $pdo->query('SELECT id, name, price, wholesale_price, stock_quantity FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['save_salesperson'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $territoryId = (int) ($_POST['territory_id'] ?? 0);
        $commissionRate = (float) ($_POST['commission_rate'] ?? 5.0);
        $newCustomerBonus = (float) ($_POST['new_customer_bonus'] ?? 0);
        $monthlyTarget = (int) ($_POST['monthly_target'] ?? 0);
        $monthlyTargetBonus = (float) ($_POST['monthly_target_bonus'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        if ($name === '') { $errors[] = 'Name is required.'; }
        else {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE salespeople SET name=:name,phone=:phone,email=:email,territory_id=:tid,commission_rate=:cr,new_customer_bonus=:ncb,monthly_target=:mt,monthly_target_bonus=:mtb,status=:status WHERE id=:id');
                $stmt->execute(['name'=>$name,'phone'=>$phone?:null,'email'=>$email?:null,'tid'=>$territoryId>0?$territoryId:null,'cr'=>$commissionRate,'ncb'=>$newCustomerBonus,'mt'=>$monthlyTarget,'mtb'=>$monthlyTargetBonus,'status'=>$status,'id'=>$id]);
                $messages[] = ['type'=>'success','text'=>'Salesperson updated.'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO salespeople (name,phone,email,territory_id,commission_rate,new_customer_bonus,monthly_target,monthly_target_bonus,status) VALUES (:name,:phone,:email,:tid,:cr,:ncb,:mt,:mtb,:status)');
                $stmt->execute(['name'=>$name,'phone'=>$phone?:null,'email'=>$email?:null,'tid'=>$territoryId>0?$territoryId:null,'cr'=>$commissionRate,'ncb'=>$newCustomerBonus,'mt'=>$monthlyTarget,'mtb'=>$monthlyTargetBonus,'status'=>$status]);
                $messages[] = ['type'=>'success','text'=>'Salesperson added.'];
            }
        }
    }
    if (isset($_POST['delete_salesperson'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM salespeople WHERE id=:id')->execute(['id'=>$id]); $messages[]=['type'=>'success','text'=>'Salesperson deleted.']; }
    }
    if (isset($_POST['save_territory'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $tName = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $region = trim($_POST['region'] ?? '');
        if ($tName === '') { $errors[] = 'Territory name is required.'; }
        else {
            if ($id > 0) {
                $pdo->prepare('UPDATE territories SET name=:name,description=:desc,region=:region WHERE id=:id')->execute(['name'=>$tName,'desc'=>$description?:null,'region'=>$region?:null,'id'=>$id]);
                $messages[] = ['type'=>'success','text'=>'Territory updated.'];
            } else {
                $pdo->prepare('INSERT INTO territories (name,description,region) VALUES (:name,:desc,:region)')->execute(['name'=>$tName,'desc'=>$description?:null,'region'=>$region?:null]);
                $messages[] = ['type'=>'success','text'=>'Territory added.'];
            }
        }
    }
    if (isset($_POST['delete_territory'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM territories WHERE id=:id')->execute(['id'=>$id]); $messages[]=['type'=>'success','text'=>'Territory deleted.']; }
    }
    if (isset($_POST['save_visit'])) {
        $salespersonId = (int) ($_POST['salesperson_id'] ?? 0);
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $visitDate = $_POST['visit_date'] ?? date('Y-m-d');
        $visitType = $_POST['visit_type'] ?? 'initial';
        $notes = trim($_POST['notes'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $followUpDate = $_POST['follow_up_date'] ?? null;
        $status = $_POST['status'] ?? 'completed';
        if ($salespersonId <= 0) { $errors[] = 'Please select a salesperson.'; }
        else {
            $customerName = null;
            if ($customerId > 0) { $cst=$pdo->prepare('SELECT name FROM customers WHERE id=:id'); $cst->execute(['id'=>$customerId]); $customerName=$cst->fetchColumn(); }
            else { $customerName = trim($_POST['customer_name_manual'] ?? ''); }
            $pdo->prepare('INSERT INTO visits (salesperson_id,customer_id,customer_name,visit_date,visit_type,notes,location,follow_up_date,status) VALUES (:spid,:cid,:cname,:vd,:vt,:notes,:loc,:fud,:st)')
                ->execute(['spid'=>$salespersonId,'cid'=>$customerId>0?$customerId:null,'cname'=>$customerName?:null,'vd'=>$visitDate,'vt'=>$visitType,'notes'=>$notes?:null,'loc'=>$location?:null,'fud'=>$followUpDate?:null,'st'=>$status]);
            $messages[] = ['type'=>'success','text'=>'Visit recorded.'];
            $activeTab = 'visits';
        }
    }
    if (isset($_POST['delete_visit'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM visits WHERE id=:id')->execute(['id'=>$id]); $messages[]=['type'=>'success','text'=>'Visit deleted.']; }
    }
    if (isset($_POST['save_customer'])) {
        $salespersonId = (int) ($_POST['salesperson_id'] ?? 0);
        $cName = trim($_POST['name'] ?? '');
        $customerType = $_POST['customer_type'] ?? null;
        $licenseNumber = trim($_POST['license_number'] ?? '');
        $tinNumber = trim($_POST['tin_number'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['physical_address'] ?? '');
        $creditLimit = (float) ($_POST['credit_limit'] ?? 0);
        $paymentTerms = trim($_POST['payment_terms'] ?? '');
        if ($cName === '') { $errors[] = 'Customer name is required.'; }
        else {
            $pdo->prepare('INSERT INTO customers (name,customer_type,license_number,tin_number,contact_person,phone,email,physical_address,credit_limit,payment_terms,customer_status,registered_by,created_at) VALUES (:name,:ctype,:lic,:tin,:contact,:phone,:email,:addr,:cl,:pt,:cstatus,:rb,NOW())')
                ->execute(['name'=>$cName,'ctype'=>$customerType?:null,'lic'=>$licenseNumber?:null,'tin'=>$tinNumber?:null,'contact'=>$contactPerson?:null,'phone'=>$phone?:null,'email'=>$email?:null,'addr'=>$address?:null,'cl'=>$creditLimit,'pt'=>$paymentTerms?:null,'cstatus'=>'active','rb'=>$salespersonId>0?$salespersonId:null]);
            $newCustomerId = (int) $pdo->lastInsertId();
            $messages[] = ['type'=>'success','text'=>'Customer registered.'];
            if ($salespersonId > 0) {
                $spStmt = $pdo->prepare('SELECT new_customer_bonus FROM salespeople WHERE id=:id'); $spStmt->execute(['id'=>$salespersonId]); $bonus=(float)$spStmt->fetchColumn();
                if ($bonus > 0) {
                    CommissionService::create($pdo, $salespersonId, 'new_customer', 'customer', $newCustomerId, $bonus, 'Auto: New customer - '.$cName);
                }
            }
            $activeTab = 'visits';
        }
    }
    if (isset($_POST['save_order'])) {
        $salespersonId = (int) ($_POST['salesperson_id'] ?? 0);
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $items = $_POST['order_items'] ?? [];
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $orderNotes = trim($_POST['order_notes'] ?? '');
        $fulfillmentMethod = ($_POST['fulfillment_method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        if ($customerId <= 0) { $errors[] = 'Please select a customer.'; }
        elseif ($fulfillmentMethod === 'delivery' && $deliveryAddress === '') { $errors[] = 'Delivery orders require a delivery address. Choose Pickup or enter a delivery address.'; }
        else {
            $lineTotals = [];
            foreach ($items as $item) { $pid=(int)($item['product_id']??0); $qty=(int)($item['quantity']??0); if($pid>0&&$qty>0) $lineTotals[]=['product_id'=>$pid,'quantity'=>$qty]; }
            if (empty($lineTotals)) { $errors[] = 'Add at least one product with quantity.'; }
            else {
                $pdo->beginTransaction();
                try {
                    $priceByProduct=[];
                    foreach($lineTotals as $line){ $stmt=$pdo->prepare('SELECT price, wholesale_price FROM products WHERE id=:id'); $stmt->execute(['id'=>$line['product_id']]); $row=$stmt->fetch(); if($row===false) throw new RuntimeException('Invalid product.'); $unit=(float)$row['price']; if($fulfillmentMethod==='pickup' && (float)$row['wholesale_price']>0){ $unit=(float)$row['wholesale_price']; } $priceByProduct[$line['product_id']]=$unit; }
                    $total=0; foreach($lineTotals as $line) $total+=$priceByProduct[$line['product_id']]*$line['quantity'];
                    $adminUser=$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
                    $pdo->prepare("INSERT INTO customer_orders (customer_id,placed_by_user_id,salesperson_id,order_date,status,total_amount,payment_method,payment_detail,delivery_address,fulfillment_method,approval_stage,created_at) VALUES (:cid,:uid,:spid,:od,'pending',:total,'bank','Registered by sales rep',:da,:fm,'admin',NOW())")
                        ->execute(['cid'=>$customerId,'uid'=>$adminUser,'spid'=>$salespersonId>0?$salespersonId:null,'od'=>$orderDate,'total'=>$total,'da'=>$deliveryAddress?:null,'fm'=>$fulfillmentMethod]);
                    $orderId=(int)$pdo->lastInsertId();
                    $ins=$pdo->prepare('INSERT INTO customer_order_items (customer_order_id,product_id,quantity,unit_price) VALUES (:oid,:pid,:qty,:price)');
                    foreach($lineTotals as $line) $ins->execute(['oid'=>$orderId,'pid'=>$line['product_id'],'qty'=>$line['quantity'],'price'=>$priceByProduct[$line['product_id']]]);
                    $pdo->commit();
                    $messages[]=['type'=>'success','text'=>'Order #'.$orderId.' created.'];
                    $activeTab = 'visits';
                } catch (Exception $e) { $pdo->rollBack(); $errors[]=$e->getMessage(); }
            }
        }
    }
    if (isset($_POST['approve_commission'])) {
        $commId=(int)($_POST['commission_id']??0);
        if($commId>0){ $ok=CommissionService::approve($pdo,$commId,currentUser()['id']); $messages[]=['type'=>$ok?'success':'error','text'=>$ok?'Commission approved.':'Commission not found or already processed.']; }
    }
    if (isset($_POST['reject_commission'])) {
        $commId=(int)($_POST['commission_id']??0); $reason=trim($_POST['rejection_reason']??'');
        if($commId>0){ CommissionService::reject($pdo,$commId,currentUser()['id'],$reason?:null); $messages[]=['type'=>'success','text'=>'Commission rejected.']; }
    }
    if (isset($_POST['pay_commission'])) {
        $commId=(int)($_POST['commission_id']??0);
        if($commId>0){ $ok=CommissionService::pay($pdo,$commId,currentUser()['id']); $messages[]=['type'=>$ok?'success':'error','text'=>$ok?'Commission marked as paid.':'Commission not found or not approved.']; }
    }
    if (isset($_POST['batch_action'])) {
        $batchAct=$_POST['batch_action']; $ids=array_map('intval',$_POST['comm_ids']??[]);
        if(!empty($ids)){
            if($batchAct==='approve'){ $messages[]=['type'=>'success','text'=>CommissionService::approveMany($pdo,$ids,currentUser()['id']).' commission(s) approved.']; }
            elseif($batchAct==='pay'){ $messages[]=['type'=>'success','text'=>CommissionService::payMany($pdo,$ids,currentUser()['id']).' commission(s) paid.']; }
        }
    }
}

$salespeople = $pdo->query("SELECT sp.*,t.name AS territory_name,t.region AS territory_region,(SELECT COUNT(*) FROM sales s WHERE s.salesperson_id=sp.id) AS sale_count,COALESCE((SELECT SUM(s.total_amount) FROM sales s WHERE s.salesperson_id=sp.id),0) AS total_sales,(SELECT COUNT(*) FROM visits v WHERE v.salesperson_id=sp.id) AS visit_count,(SELECT COUNT(*) FROM customers c WHERE c.registered_by=sp.id) AS customers_registered,COALESCE((SELECT SUM(c2.commission_amount) FROM commissions c2 WHERE c2.salesperson_id=sp.id AND c2.status='paid'),0) AS commissions_paid,COALESCE((SELECT SUM(c2.commission_amount) FROM commissions c2 WHERE c2.salesperson_id=sp.id AND c2.status IN ('pending','approved')),0) AS commissions_pending FROM salespeople sp LEFT JOIN territories t ON sp.territory_id=t.id ORDER BY sp.name ASC")->fetchAll();
$spSummary = $pdo->query("SELECT COUNT(*) AS total,SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) AS inactive FROM salespeople")->fetch();
$allTerritories = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM salespeople sp WHERE sp.territory_id=t.id) AS rep_count FROM territories t ORDER BY t.region ASC, t.name ASC')->fetchAll();
$terrSummary = $pdo->query('SELECT COUNT(*) AS total FROM territories')->fetch();

$filterSp=(int)($_GET['sp_id']??0); $filterDateFrom=trim($_GET['date_from']??''); $filterDateTo=trim($_GET['date_to']??'');
$vWhere=['1=1']; $vParams=[];
if($filterSp>0){$vWhere[]='v.salesperson_id=:spid';$vParams['spid']=$filterSp;}
if($filterDateFrom!==''){$vWhere[]='v.visit_date>=:df';$vParams['df']=$filterDateFrom;}
if($filterDateTo!==''){$vWhere[]='v.visit_date<=:dt';$vParams['dt']=$filterDateTo;}
$visitsStmt=$pdo->prepare("SELECT v.*,sp.name AS salesperson_name FROM visits v LEFT JOIN salespeople sp ON v.salesperson_id=sp.id WHERE ".implode(' AND ',$vWhere)." ORDER BY v.visit_date DESC, v.id DESC LIMIT 100");
$visitsStmt->execute($vParams); $visits=$visitsStmt->fetchAll();
$recentOrders=$pdo->query("SELECT co.*,sp.name AS salesperson_name,c.name AS customer_name FROM customer_orders co LEFT JOIN salespeople sp ON co.salesperson_id=sp.id LEFT JOIN customers c ON co.customer_id=c.id WHERE co.salesperson_id IS NOT NULL ORDER BY co.created_at DESC LIMIT 20")->fetchAll();

$filterType=trim($_GET['type']??''); $filterStatus=trim($_GET['status']??''); $filterMonth=(int)($_GET['month']??0); $filterYear=(int)($_GET['year']??date('Y')); $commTab=$_GET['comm_tab']??'list';
$cWhere=['1=1']; $cParams=[];
if($filterSp>0){$cWhere[]='c.salesperson_id=:spid';$cParams['spid']=$filterSp;}
if($filterType!==''){$cWhere[]='c.commission_type=:type';$cParams['type']=$filterType;}
if($filterStatus==='pending'){$cWhere[]="c.status='pending'";}elseif($filterStatus==='approved'){$cWhere[]="c.status='approved'";}elseif($filterStatus==='paid'){$cWhere[]="c.status='paid'";}elseif($filterStatus==='rejected'){$cWhere[]="c.status='rejected'";}
if($filterMonth>0){$cWhere[]='c.commission_month=:month';$cParams['month']=$filterMonth;}
if($filterYear>0){$cWhere[]='c.commission_year=:year';$cParams['year']=$filterYear;}
$commissions=$pdo->prepare("SELECT c.*,sp.name AS salesperson_name,CASE c.reference_type WHEN 'sale' THEN (SELECT CONCAT('Sale #',s.id,' - ',cu.name) FROM sales s LEFT JOIN customers cu ON s.customer_id=cu.id WHERE s.id=c.reference_id) WHEN 'customer_order' THEN (SELECT CONCAT('Order #',co.id,' - ',cu2.name) FROM customer_orders co LEFT JOIN customers cu2 ON co.customer_id=cu2.id WHERE co.id=c.reference_id) WHEN 'customer' THEN (SELECT name FROM customers WHERE id=c.reference_id) ELSE NULL END AS reference_desc FROM commissions c LEFT JOIN salespeople sp ON c.salesperson_id=sp.id WHERE ".implode(' AND ',$cWhere)." ORDER BY c.created_at DESC LIMIT 200");
$commissions->execute($cParams); $commissions=$commissions->fetchAll();
$agg=$pdo->prepare("SELECT COUNT(*) AS total_count,COALESCE(SUM(commission_amount),0) AS total_amt,COALESCE(SUM(CASE WHEN status='pending' THEN commission_amount ELSE 0 END),0) AS pending_amt,COALESCE(SUM(CASE WHEN status='approved' THEN commission_amount ELSE 0 END),0) AS approved_amt,COALESCE(SUM(CASE WHEN status='paid' THEN commission_amount ELSE 0 END),0) AS paid_amt,SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_count,SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_count,SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count FROM commissions c WHERE ".implode(' AND ',$cWhere));
$agg->execute($cParams); $agg=$agg->fetch();
$cmMonth=$filterMonth>0?$filterMonth:(int)date('n');
$monthlyRows=$pdo->query("SELECT sp.id AS spid,sp.name,sp.commission_rate,sp.new_customer_bonus,sp.monthly_target,sp.monthly_target_bonus,COALESCE(SUM(CASE WHEN c.commission_type='sale' AND c.status='paid' THEN c.commission_amount ELSE 0 END),0) AS sale_commissions,COALESCE(SUM(CASE WHEN c.commission_type='new_customer' AND c.status='paid' THEN c.commission_amount ELSE 0 END),0) AS nc_commissions,(SELECT COUNT(*) FROM commissions cc WHERE cc.salesperson_id=sp.id AND cc.commission_type='new_customer' AND cc.status='paid' AND cc.commission_year=$filterYear) AS new_customers,(SELECT COUNT(*) FROM sales ss WHERE ss.salesperson_id=sp.id AND MONTH(ss.sale_date)=$cmMonth AND YEAR(ss.sale_date)=$filterYear) AS monthly_sales_count,COALESCE((SELECT SUM(ss2.total_amount) FROM sales ss2 WHERE ss2.salesperson_id=sp.id AND MONTH(ss2.sale_date)=$cmMonth AND YEAR(ss2.sale_date)=$filterYear),0) AS monthly_revenue FROM salespeople sp LEFT JOIN commissions c ON c.salesperson_id=sp.id AND c.commission_year=$filterYear AND c.commission_month=$cmMonth WHERE sp.status='active' GROUP BY sp.id,sp.name,sp.commission_rate,sp.new_customer_bonus,sp.monthly_target,sp.monthly_target_bonus ORDER BY sp.name")->fetchAll();
$paymentHistory=$pdo->query("SELECT c.*,sp.name AS salesperson_name FROM commissions c LEFT JOIN salespeople sp ON c.salesperson_id=sp.id WHERE c.status='paid' ORDER BY c.paid_at DESC LIMIT 30")->fetchAll();

$perfMonth=(int)($_GET['perf_month']??(int)date('n')); $perfYear=(int)($_GET['perf_year']??(int)date('Y'));
$performance=$pdo->query("SELECT sp.id,sp.name,t.name AS territory_name,t.region,sp.commission_rate,sp.new_customer_bonus,sp.monthly_target,sp.monthly_target_bonus,(SELECT COUNT(*) FROM sales s WHERE s.salesperson_id=sp.id AND MONTH(s.sale_date)=$perfMonth AND YEAR(s.sale_date)=$perfYear) AS monthly_sales,COALESCE((SELECT SUM(s2.total_amount) FROM sales s2 WHERE s2.salesperson_id=sp.id AND MONTH(s2.sale_date)=$perfMonth AND YEAR(s2.sale_date)=$perfYear),0) AS monthly_revenue,(SELECT COUNT(*) FROM visits v WHERE v.salesperson_id=sp.id AND MONTH(v.visit_date)=$perfMonth AND YEAR(v.visit_date)=$perfYear) AS monthly_visits,(SELECT COUNT(*) FROM customers c WHERE c.registered_by=sp.id AND MONTH(c.created_at)=$perfMonth AND YEAR(c.created_at)=$perfYear) AS new_customers,COALESCE((SELECT SUM(s3.total_amount) FROM sales s3 WHERE s3.salesperson_id=sp.id AND YEAR(s3.sale_date)=$perfYear),0) AS ytd_revenue,(SELECT COUNT(*) FROM sales s4 WHERE s4.salesperson_id=sp.id AND YEAR(s4.sale_date)=$perfYear) AS ytd_sales,(SELECT COUNT(*) FROM visits v2 WHERE v2.salesperson_id=sp.id AND YEAR(v2.visit_date)=$perfYear) AS ytd_visits,(SELECT COUNT(*) FROM customers c2 WHERE c2.registered_by=sp.id AND YEAR(c2.created_at)=$perfYear) AS ytd_customers FROM salespeople sp LEFT JOIN territories t ON sp.territory_id=t.id WHERE sp.status='active' ORDER BY monthly_revenue DESC")->fetchAll();
$totalRevenue=$totalSales=$totalVisits=$totalNewCustomers=0;
foreach($performance as $p){$totalRevenue+=(float)$p['monthly_revenue'];$totalSales+=(int)$p['monthly_sales'];$totalVisits+=(int)$p['monthly_visits'];$totalNewCustomers+=(int)$p['new_customers'];}
$chartLabels=$chartData=[];
foreach($performance as $p){if((float)$p['monthly_revenue']>0){$chartLabels[]=$p['name'];$chartData[]=(float)$p['monthly_revenue'];}}
$monthTrend=$pdo->query("SELECT DATE_FORMAT(s.sale_date,'%Y-%m') AS month_key,DATE_FORMAT(s.sale_date,'%b %Y') AS month_label,COUNT(*) AS sale_count,SUM(s.total_amount) AS revenue,COUNT(DISTINCT s.salesperson_id) AS active_reps FROM sales s WHERE s.salesperson_id IS NOT NULL AND s.sale_date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH) GROUP BY month_key,month_label ORDER BY month_key ASC")->fetchAll();
?>
<section>
    <h1>Salespeople</h1>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert alert-<?= htmlspecialchars($m['type']) ?>"><?= htmlspecialchars($m['text']) ?></div>
    <?php endforeach; ?>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
        <div class="card"><h2>Total Salespeople</h2><p><?= (int)($spSummary['total']??0) ?></p></div>
        <div class="card"><h2>Active</h2><p style="color:var(--brown-700)"><?= (int)($spSummary['active']??0) ?></p></div>
        <div class="card"><h2>Territories</h2><p><?= (int)($terrSummary['total']??0) ?></p></div>
        <div class="card" style="border-top-color:#f59e0b"><h2>Pending Comm.</h2><p style="color:#f59e0b"><?= fmt((float)$agg['pending_amt']) ?></p></div>
    </div>

    <div class="tab-bar">
        <button class="tab-btn<?= $activeTab==='list'?' active':'' ?>" onclick="switchTab(this,'sp-list')"><i class="fas fa-users"></i> Salespeople</button>
        <button class="tab-btn<?= $activeTab==='territories'?' active':'' ?>" onclick="switchTab(this,'sp-territories')"><i class="fas fa-map-marked-alt"></i> Territories</button>
        <button class="tab-btn<?= $activeTab==='visits'?' active':'' ?>" onclick="switchTab(this,'sp-visits')"><i class="fas fa-walking"></i> Visits</button>
        <button class="tab-btn<?= $activeTab==='commissions'?' active':'' ?>" onclick="switchTab(this,'sp-commissions')"><i class="fas fa-hand-holding-usd"></i> Commissions</button>
        <button class="tab-btn<?= $activeTab==='performance'?' active':'' ?>" onclick="switchTab(this,'sp-performance')"><i class="fas fa-chart-line"></i> Performance</button>
    </div>
    <div class="tab-content">

        <!-- SALESPEOPLE TAB -->
        <div id="sp-list" class="tab-panel<?= $activeTab==='list'?' active':'' ?>">
            <div style="display:flex;gap:8px;margin-bottom:12px">
                <button class="tab-btn active" onclick="switchTab(this,'sp-main-list')"><i class="fas fa-list"></i> List</button>
                <button class="tab-btn" onclick="switchTab(this,'sp-main-add')"><i class="fas fa-plus"></i> Add</button>
            </div>
            <div class="tab-content">
                <div id="sp-main-list" class="tab-panel active">
                    <div class="panel">
                        <table>
                            <thead><tr><th>Name</th><th>Territory</th><th>Phone</th><th>Sales</th><th>Revenue</th><th>Visits</th><th>Customers</th><th>Commissions</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($salespeople)): ?>
                                    <tr><td colspan="10">No salespeople registered.</td></tr>
                                <?php else: foreach ($salespeople as $sp): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($sp['name']) ?></strong></td>
                                        <td><?php if ($sp['territory_name']): ?><span style="font-size:0.82rem;color:var(--brown-700)"><?= htmlspecialchars($sp['territory_name']) ?></span><?php if ($sp['territory_region']): ?><br><span style="font-size:0.72rem;color:var(--neutral-500)"><?= htmlspecialchars($sp['territory_region']) ?></span><?php endif; ?><?php else: ?><span style="color:var(--neutral-400)">-</span><?php endif; ?></td>
                                        <td><?= htmlspecialchars((string)($sp['phone']??'')) ?></td>
                                        <td><?= (int)$sp['sale_count'] ?></td>
                                        <td><?= fmt((float)$sp['total_sales']) ?></td>
                                        <td><?= (int)$sp['visit_count'] ?></td>
                                        <td><?= (int)$sp['customers_registered'] ?></td>
                                        <td><?php if ((float)$sp['commissions_paid']>0): ?><span style="color:var(--brown-600);font-weight:600"><?= fmt((float)$sp['commissions_paid']) ?></span><?php endif; ?><?php if ((float)$sp['commissions_pending']>0): ?><br><span style="color:var(--red-600);font-size:0.8rem"><?= fmt((float)$sp['commissions_pending']) ?> pending</span><?php endif; ?><?php if ((float)$sp['commissions_paid']==0&&(float)$sp['commissions_pending']==0): ?><span style="color:var(--neutral-400)"><?= fmt(0) ?></span><?php endif; ?></td>
                                        <td><span class="status-badge status-<?= $sp['status']==='active'?'approved':'pending' ?>"><?= htmlspecialchars($sp['status']) ?></span></td>
                                        <td style="white-space:nowrap">
                                            <button onclick="editSalesperson(<?= (int)$sp['id'] ?>,'<?= htmlspecialchars(addslashes($sp['name'])) ?>','<?= htmlspecialchars(addslashes((string)($sp['phone']??''))) ?>','<?= htmlspecialchars(addslashes((string)($sp['email']??''))) ?>',<?= (int)($sp['territory_id']??0) ?>,<?= (float)$sp['commission_rate'] ?>,<?= (float)$sp['new_customer_bonus'] ?>,<?= (int)$sp['monthly_target'] ?>,<?= (float)$sp['monthly_target_bonus'] ?>,'<?= htmlspecialchars($sp['status']) ?>')" style="color:var(--brown-700);font-size:0.82rem;background:none;border:none;cursor:pointer;margin-right:8px"><i class="fas fa-edit"></i></button>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this salesperson?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="delete_salesperson" value="1">
                                                <input type="hidden" name="id" value="<?= (int)$sp['id'] ?>">
                                                <button type="submit" style="color:var(--red-600);background:none;border:none;cursor:pointer;font-size:0.82rem"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="sp-main-add" class="tab-panel">
                    <div class="panel" style="max-width:600px">
                        <h2 id="sp-form-title">Add Salesperson</h2>
                        <form method="post" class="form-grid" id="sp-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="save_salesperson" value="1">
                            <input type="hidden" name="id" id="sp-id" value="0">
                            <label>Name<input type="text" name="name" id="sp-name" required placeholder="Full name"></label>
                            <label>Phone<input type="text" name="phone" id="sp-phone" placeholder="Optional"></label>
                            <label>Email<input type="email" name="email" id="sp-email" placeholder="Optional"></label>
                            <label>Territory<select name="territory_id" id="sp-territory"><option value="0">-- No territory --</option><?php foreach ($territories as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?><?= $t['region']?' ('.htmlspecialchars($t['region']).')':'' ?></option><?php endforeach; ?></select></label>
                            <label>Commission Rate (%)<input type="number" name="commission_rate" id="sp-commission-rate" step="0.01" min="0" max="100" value="5.00"></label>
                            <label>New Customer Bonus (<?= currency_sym() ?>)<input type="number" name="new_customer_bonus" id="sp-new-customer-bonus" step="0.01" min="0" value="0"></label>
                            <label>Monthly Target (orders)<input type="number" name="monthly_target" id="sp-monthly-target" min="0" value="0"></label>
                            <label>Monthly Target Bonus (<?= currency_sym() ?>)<input type="number" name="monthly_target_bonus" id="sp-monthly-target-bonus" step="0.01" min="0" value="0"></label>
                            <label>Status<select name="status" id="sp-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                            <button type="submit" id="sp-save-btn">Save Salesperson</button>
                            <button type="button" id="sp-cancel-btn" style="display:none;background:var(--neutral-200);color:var(--neutral-700)" onclick="resetSpForm()">Cancel</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!-- TERRITORIES TAB -->
        <div id="sp-territories" class="tab-panel<?= $activeTab==='territories'?' active':'' ?>">
            <div style="display:flex;gap:8px;margin-bottom:12px">
                <button class="tab-btn active" onclick="switchTab(this,'terr-list')"><i class="fas fa-list"></i> List</button>
                <button class="tab-btn" onclick="switchTab(this,'terr-add')"><i class="fas fa-plus"></i> Add Territory</button>
            </div>
            <div class="tab-content">
                <div id="terr-list" class="tab-panel active">
                    <div class="panel">
                        <table>
                            <thead><tr><th>ID</th><th>Name</th><th>Region</th><th>Description</th><th>Reps</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($allTerritories)): ?>
                                    <tr><td colspan="6">No territories defined.</td></tr>
                                <?php else: foreach ($allTerritories as $t): ?>
                                    <tr>
                                        <td><?= (int)$t['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                                        <td><?= htmlspecialchars((string)($t['region']??'-')) ?></td>
                                        <td><?= htmlspecialchars(mb_strimwidth((string)($t['description']??''),0,80,'...')) ?></td>
                                        <td><?= (int)$t['rep_count'] ?></td>
                                        <td style="white-space:nowrap">
                                            <button onclick="editTerritory(<?= (int)$t['id'] ?>,'<?= htmlspecialchars(addslashes($t['name'])) ?>','<?= htmlspecialchars(addslashes((string)($t['description']??''))) ?>','<?= htmlspecialchars(addslashes((string)($t['region']??''))) ?>')" style="color:var(--brown-700);font-size:0.82rem;background:none;border:none;cursor:pointer;margin-right:8px"><i class="fas fa-edit"></i></button>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this territory?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="delete_territory" value="1">
                                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" style="color:var(--red-600);background:none;border:none;cursor:pointer;font-size:0.82rem"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
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
        </div>

        <!-- VISITS TAB -->
        <div id="sp-visits" class="tab-panel<?= $activeTab==='visits'?' active':'' ?>">
            <div style="display:flex;gap:8px;margin-bottom:12px">
                <button class="tab-btn active" onclick="switchTab(this,'v-visits')"><i class="fas fa-walking"></i> Visit Log</button>
                <button class="tab-btn" onclick="switchTab(this,'v-register')"><i class="fas fa-user-plus"></i> Register Customer</button>
                <button class="tab-btn" onclick="switchTab(this,'v-orders')"><i class="fas fa-shopping-cart"></i> Create Order</button>
            </div>
            <div class="tab-content">
                <div id="v-visits" class="tab-panel active">
                    <div class="panel">
                        <h2>Record Visit</h2>
                        <form method="post" class="form-grid" style="max-width:700px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="save_visit" value="1">
                            <label>Salesperson<select name="salesperson_id" required><option value="0">-- Select --</option><?php foreach ($allSalespeople as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Customer (existing)<select name="customer_id"><option value="0">-- Walk-in / New prospect --</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Customer Name (if walk-in)<input type="text" name="customer_name_manual" placeholder="Enter name manually"></label>
                            <label>Visit Date<input type="date" name="visit_date" value="<?= date('Y-m-d') ?>"></label>
                            <label>Visit Type<select name="visit_type"><option value="initial">Initial Visit</option><option value="follow_up">Follow-up</option><option value="closing">Closing</option><option value="delivery">Delivery</option></select></label>
                            <label>Status<select name="status"><option value="completed">Completed</option><option value="pending_follow_up">Pending Follow-up</option></select></label>
                            <label>Location / Address<input type="text" name="location" placeholder="Where the visit took place"></label>
                            <label>Follow-up Date<input type="date" name="follow_up_date"></label>
                            <label>Notes<textarea name="notes" rows="2" placeholder="Visit notes..."></textarea></label>
                            <button type="submit">Record Visit</button>
                        </form>
                    </div>
                    <div class="panel" style="margin-top:16px">
                        <h2>Visit History</h2>
                        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                            <input type="hidden" name="page" value="salespeople">
                            <input type="hidden" name="tab" value="visits">
                            <select name="sp_id" style="margin:0;min-width:150px"><option value="0">All Salespeople</option><?php foreach ($allSalespeople as $sp): ?><option value="<?= (int)$sp['id'] ?>"<?= $filterSp===(int)$sp['id']?' selected':'' ?>><?= htmlspecialchars($sp['name']) ?></option><?php endforeach; ?></select>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" style="margin:0">
                            <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" style="margin:0">
                            <button type="submit" style="margin:0">Filter</button>
                        </form>
                        <table>
                            <thead><tr><th>Date</th><th>Salesperson</th><th>Customer</th><th>Type</th><th>Location</th><th>Follow-up</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($visits)): ?>
                                    <tr><td colspan="8">No visits recorded.</td></tr>
                                <?php else: foreach ($visits as $v): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($v['visit_date']) ?></td>
                                        <td><strong><?= htmlspecialchars($v['salesperson_name']??'-') ?></strong></td>
                                        <td><?= htmlspecialchars($v['customer_name']??$v['notes']??'-') ?></td>
                                        <td><span style="font-size:0.8rem;background:var(--neutral-100);padding:2px 8px;border-radius:4px"><?= ucfirst(str_replace('_',' ',$v['visit_type'])) ?></span></td>
                                        <td style="font-size:0.85rem"><?= htmlspecialchars((string)($v['location']??'-')) ?></td>
                                        <td><?= $v['follow_up_date']?htmlspecialchars($v['follow_up_date']):'-' ?></td>
                                        <td><span class="status-badge status-<?= $v['status']==='completed'?'approved':'pending' ?>"><?= ucfirst(str_replace('_',' ',$v['status'])) ?></span></td>
                                        <td>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Delete this visit?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="delete_visit" value="1">
                                                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                                <button type="submit" style="color:var(--red-600);background:none;border:none;cursor:pointer;font-size:0.82rem"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="v-register" class="tab-panel">
                    <div class="panel" style="max-width:700px">
                        <h2>Register New Business Customer</h2>
                        <form method="post" class="form-grid">
                            <?= csrf_field() ?>
                            <input type="hidden" name="save_customer" value="1">
                            <label>Registered By (Salesperson)<select name="salesperson_id"><option value="0">-- Select salesperson --</option><?php foreach ($allSalespeople as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Customer Name<input type="text" name="name" required placeholder="Business name"></label>
                            <label>Customer Type<select name="customer_type"><option value="">-- Select --</option><option value="pharmacy">Pharmacy</option><option value="drug_store">Drug Store</option><option value="hospital">Hospital</option><option value="clinic">Clinic</option><option value="wholesaler">Wholesaler</option><option value="other_licensed">Other Licensed</option></select></label>
                            <label>Contact Person<input type="text" name="contact_person" placeholder="Primary contact"></label>
                            <label>Phone<input type="text" name="phone" placeholder="Phone number"></label>
                            <label>Email<input type="email" name="email" placeholder="Email address"></label>
                            <label>License Number<input type="text" name="license_number" placeholder="License #"></label>
                            <label>TIN Number<input type="text" name="tin_number" placeholder="TIN #"></label>
                            <label>Physical Address<textarea name="physical_address" rows="2" placeholder="Address"></textarea></label>
                            <label>Credit Limit (<?= currency_sym() ?>)<input type="number" name="credit_limit" step="0.01" min="0" value="0"></label>
                            <label>Payment Terms<input type="text" name="payment_terms" placeholder="e.g. Net 30 days"></label>
                            <button type="submit">Register Customer</button>
                        </form>
                    </div>
                </div>
                <div id="v-orders" class="tab-panel">
                    <div class="panel" style="max-width:800px">
                        <h2>Create Customer Order</h2>
                        <form method="post" class="form-grid" id="order-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="save_order" value="1">
                            <label>Salesperson<select name="salesperson_id" required><option value="0">-- Select --</option><?php foreach ($allSalespeople as $sp): ?><option value="<?= (int)$sp['id'] ?>"><?= htmlspecialchars($sp['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Customer<select name="customer_id" required><option value="0">-- Select customer --</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></label>
                            <label>Order Date<input type="date" name="order_date" value="<?= date('Y-m-d') ?>"></label>
                            <label>Fulfillment Method<select name="fulfillment_method" id="sp-fm" onchange="spToggleDelivery(this)"><option value="delivery">Delivery</option><option value="pickup">Customer Pickup</option></select></label>
                            <label id="sp-da-label">Delivery Address<textarea name="delivery_address" rows="2" placeholder="Delivery address (required for delivery)"></textarea></label>
                            <label>Notes<input type="text" name="order_notes" placeholder="Optional notes"></label>
                        </form>
                        <div id="order-lines">
                            <table style="width:100%">
                                <thead><tr><th>Product</th><th style="width:100px">Qty</th><th style="width:120px">Unit Price</th><th style="width:120px">Subtotal</th><th style="width:40px"></th></tr></thead>
                                <tbody id="order-items-body">
                                    <tr>
                                        <td><select name="order_items[0][product_id]" class="order-product" style="width:100%;margin:0" onchange="updatePrice(0)"><option value="0">-- Select product --</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" data-price="<?= (float)$p['price'] ?>" data-wprice="<?= (float)$p['wholesale_price'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= fmt((float)$p['price']) ?>)</option><?php endforeach; ?></select></td>
                                        <td><input type="number" name="order_items[0][quantity]" class="order-qty" min="1" value="1" style="margin:0" oninput="updateSubtotal(0)"></td>
                                        <td><input type="text" class="order-price" readonly style="margin:0;background:var(--neutral-100)"></td>
                                        <td><input type="text" class="order-subtotal" readonly style="margin:0;background:var(--neutral-100)"></td>
                                        <td><button type="button" onclick="addOrderLine()" style="background:none;border:none;color:var(--brown-600);cursor:pointer"><i class="fas fa-plus"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div style="display:flex;justify-content:flex-end;margin-top:12px"><strong style="font-size:1.1rem">Total: <span id="order-total"><?= fmt(0) ?></span></strong></div>
                        <div style="margin-top:16px"><button type="button" onclick="submitOrder()" style="background:var(--brown-700);color:#fff;padding:10px 24px;border:none;border-radius:8px;cursor:pointer;font-size:0.95rem;font-weight:600">Submit Order</button></div>
                    </div>
                    <?php if (!empty($recentOrders)): ?>
                    <div class="panel" style="margin-top:16px">
                        <h2>Recent Orders by Salespeople</h2>
                        <table>
                            <thead><tr><th>Order #</th><th>Date</th><th>Salesperson</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
                            <tbody><?php foreach ($recentOrders as $ro): ?>
                                <tr>
                                    <td>#<?= (int)$ro['id'] ?></td>
                                    <td><?= htmlspecialchars($ro['order_date']) ?></td>
                                    <td><?= htmlspecialchars($ro['salesperson_name']??'-') ?></td>
                                    <td><?= htmlspecialchars($ro['customer_name']??'-') ?></td>
                                    <td style="font-weight:700"><?= fmt((float)$ro['total_amount']) ?></td>
                                    <td><span class="status-badge status-<?= $ro['status']==='approved'?'approved':($ro['status']==='rejected'?'rejected':'pending') ?>"><?= ucfirst($ro['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?></tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- COMMISSIONS TAB -->
        <div id="sp-commissions" class="tab-panel<?= $activeTab==='commissions'?' active':'' ?>">
            <div style="display:flex;gap:8px;margin-bottom:12px">
                <button class="tab-btn<?= $commTab==='list'?' active':'' ?>" onclick="switchTab(this,'comm-list')"><i class="fas fa-list"></i> All Commissions</button>
                <button class="tab-btn<?= $commTab==='monthly'?' active':'' ?>" onclick="switchTab(this,'comm-monthly')"><i class="fas fa-calendar-alt"></i> Monthly Report</button>
                <button class="tab-btn<?= $commTab==='history'?' active':'' ?>" onclick="switchTab(this,'comm-history')"><i class="fas fa-credit-card"></i> Payment History</button>
            </div>
            <div class="tab-content">
                <div id="comm-list" class="tab-panel<?= $commTab==='list'?' active':'' ?>">
                    <div class="panel">
                        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                            <input type="hidden" name="page" value="salespeople">
                            <input type="hidden" name="tab" value="commissions">
                            <input type="hidden" name="comm_tab" value="list">
                            <select name="sp_id" style="margin:0;min-width:140px"><option value="0">All Salespeople</option><?php foreach ($allSalespeople as $sp): ?><option value="<?= (int)$sp['id'] ?>"<?= $filterSp===(int)$sp['id']?' selected':'' ?>><?= htmlspecialchars($sp['name']) ?></option><?php endforeach; ?></select>
                            <select name="type" style="margin:0"><option value="">All Types</option><option value="sale"<?= $filterType==='sale'?' selected':'' ?>>Sale Commission</option><option value="new_customer"<?= $filterType==='new_customer'?' selected':'' ?>>New Customer</option><option value="monthly_target"<?= $filterType==='monthly_target'?' selected':'' ?>>Monthly Target</option></select>
                            <select name="status" style="margin:0"><option value="">All Status</option><option value="pending"<?= $filterStatus==='pending'?' selected':'' ?>>Pending</option><option value="approved"<?= $filterStatus==='approved'?' selected':'' ?>>Approved</option><option value="paid"<?= $filterStatus==='paid'?' selected':'' ?>>Paid</option><option value="rejected"<?= $filterStatus==='rejected'?' selected':'' ?>>Rejected</option></select>
                            <button type="submit" style="margin:0">Filter</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <div style="display:flex;gap:8px;margin-bottom:8px">
                                <button type="submit" name="batch_action" value="approve" onclick="return confirm('Approve selected?')">Approve Selected</button>
                                <button type="submit" name="batch_action" value="pay" onclick="return confirm('Mark selected as paid?')">Pay Selected</button>
                            </div>
                            <table>
                                <thead><tr><th><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th><th>Salesperson</th><th>Type</th><th>Reference</th><th>Sale Amount</th><th>Rate</th><th>Commission</th><th>Status</th><th>Date</th><th></th></tr></thead>
                                <tbody>
                                    <?php if (empty($commissions)): ?>
                                        <tr><td colspan="10">No commissions found.</td></tr>
                                    <?php else: foreach ($commissions as $c): ?>
                                        <tr>
                                            <td><input type="checkbox" name="comm_ids[]" value="<?= (int)$c['id'] ?>" class="comm-check"></td>
                                            <td><strong><?= htmlspecialchars($c['salesperson_name']??'-') ?></strong></td>
                                            <td><span style="font-size:0.78rem;padding:2px 8px;border-radius:4px;background:<?= $c['commission_type']==='sale'?'#dbeafe':($c['commission_type']==='new_customer'?'#d1fae5':'#fef3c7') ?>;color:<?= $c['commission_type']==='sale'?'#1d4ed8':($c['commission_type']==='new_customer'?'#047857':'#b45309') ?>"><?= $c['commission_type']==='sale'?'Sale':($c['commission_type']==='new_customer'?'New Customer':'Monthly Target') ?></span></td>
                                            <td style="font-size:0.85rem"><?= htmlspecialchars((string)($c['reference_desc']??'-')) ?></td>
                                            <td><?= $c['sale_amount']!==null?fmt((float)$c['sale_amount']):'-' ?></td>
                                            <td><?= $c['commission_rate']!==null?(float)$c['commission_rate'].'%':'-' ?></td>
                                            <td style="font-weight:700;color:var(--brown-600)"><?= fmt((float)$c['commission_amount']) ?></td>
                                            <td><?php if($c['status']==='pending'):?><span style="color:#f59e0b;font-weight:600">Pending</span><?php elseif($c['status']==='approved'):?><span style="color:#3b82f6;font-weight:600">Approved</span><?php elseif($c['status']==='paid'):?><span style="color:var(--brown-600);font-weight:700">Paid <?= $c['paid_at']?htmlspecialchars(date('M j',strtotime($c['paid_at']))):'' ?></span><?php elseif($c['status']==='rejected'):?><span style="color:var(--red-600);font-weight:600">Rejected</span><?php endif;?></td>
                                            <td style="font-size:0.82rem"><?= htmlspecialchars($c['created_at']) ?></td>
                                            <td style="white-space:nowrap">
                                                <?php if($c['status']==='pending'):?>
                                                    <form method="post" style="display:inline" onsubmit="return confirm('Approve this commission?')">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="commission_id" value="<?= (int)$c['id'] ?>">
                                                        <button type="submit" name="approve_commission" style="font-size:0.78rem;padding:2px 8px;background:var(--brown-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Approve</button>
                                                    </form>
                                                    <form method="post" style="display:inline" onsubmit="return confirm('Reject this commission?')">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="commission_id" value="<?= (int)$c['id'] ?>">
                                                        <input type="text" name="rejection_reason" placeholder="Reason" style="width:100px;margin:0;font-size:0.78rem">
                                                        <button type="submit" name="reject_commission" style="font-size:0.78rem;padding:2px 8px;background:var(--red-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Reject</button>
                                                    </form>
                                                <?php elseif($c['status']==='approved'):?>
                                                    <form method="post" style="display:inline" onsubmit="return confirm('Mark as paid?')">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="commission_id" value="<?= (int)$c['id'] ?>">
                                                        <button type="submit" name="pay_commission" style="font-size:0.78rem;padding:2px 8px;background:var(--brown-600);color:#fff;border:none;border-radius:4px;cursor:pointer">Pay</button>
                                                    </form>
                                                <?php endif;?>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                </div>
                <div id="comm-monthly" class="tab-panel<?= $commTab==='monthly'?' active':'' ?>">
                    <div class="panel">
                        <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                            <input type="hidden" name="page" value="salespeople">
                            <input type="hidden" name="tab" value="commissions">
                            <input type="hidden" name="comm_tab" value="monthly">
                            <select name="month" style="margin:0"><?php for($m=1;$m<=12;$m++):?><option value="<?= $m ?>"<?= $cmMonth==$m?' selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option><?php endfor;?></select>
                            <select name="year" style="margin:0"><?php for($y=date('Y');$y>=date('Y')-3;$y--):?><option value="<?= $y ?>"<?= $filterYear==$y?' selected':'' ?>><?= $y ?></option><?php endfor;?></select>
                            <button type="submit" style="margin:0">View Report</button>
                        </form>
                        <table>
                            <thead><tr><th>Salesperson</th><th>Rate</th><th>Sales</th><th>Revenue</th><th>Sale Comms</th><th>New Customers</th><th>NC Bonus</th><th>Total Earned</th><th>Target</th></tr></thead>
                            <tbody>
                                <?php if (empty($monthlyRows)): ?>
                                    <tr><td colspan="9">No data for this period.</td></tr>
                                <?php else: foreach ($monthlyRows as $mr): ?>
                                    <?php $totalEarned=(float)$mr['sale_commissions']+(float)$mr['nc_commissions']; $targetBonus=0; if((int)$mr['monthly_target']>0&&(int)$mr['monthly_sales_count']>=(int)$mr['monthly_target']){$targetBonus=(float)$mr['monthly_target_bonus'];$totalEarned+=$targetBonus;} ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($mr['name']) ?></strong></td>
                                        <td><?= (float)$mr['commission_rate'] ?>%</td>
                                        <td><?= (int)$mr['monthly_sales_count'] ?></td>
                                        <td><?= fmt((float)$mr['monthly_revenue']) ?></td>
                                        <td><?= fmt((float)$mr['sale_commissions']) ?></td>
                                        <td><?= (int)$mr['new_customers'] ?></td>
                                        <td><?= fmt((float)$mr['nc_commissions']) ?></td>
                                        <td style="font-weight:700;color:var(--brown-600)"><?= fmt($totalEarned) ?></td>
                                        <td><?php if((int)$mr['monthly_target']>0):?><small>Target: <?= (int)$mr['monthly_target'] ?> orders</small><?php if((int)$mr['monthly_sales_count']>=(int)$mr['monthly_target']):?><br><span style="color:var(--brown-600);font-weight:600">Met! +<?= fmt($targetBonus) ?></span><?php else:?><br><span style="color:var(--neutral-500)"><?= (int)$mr['monthly_target']-(int)$mr['monthly_sales_count'] ?> more needed</span><?php endif;?><?php endif;?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="comm-history" class="tab-panel<?= $commTab==='history'?' active':'' ?>">
                    <div class="panel" style="border-top-color:var(--brown-600)">
                        <h2>Payment History</h2>
                        <?php if (empty($paymentHistory)): ?>
                            <p class="hint">No commission payments recorded yet.</p>
                        <?php else: ?>
                        <table>
                            <thead><tr><th>Date</th><th>Salesperson</th><th>Type</th><th>Amount</th><th>Reference</th></tr></thead>
                            <tbody><?php foreach ($paymentHistory as $ph): ?>
                                <tr>
                                    <td><?= htmlspecialchars($ph['paid_at']?date('M j, Y H:i',strtotime($ph['paid_at'])):'-') ?></td>
                                    <td><strong><?= htmlspecialchars($ph['salesperson_name']??'-') ?></strong></td>
                                    <td><?= ucfirst(str_replace('_',' ',$ph['commission_type'])) ?></td>
                                    <td style="font-weight:700;color:var(--brown-600)"><?= fmt((float)$ph['commission_amount']) ?></td>
                                    <td style="font-size:0.85rem"><?= htmlspecialchars((string)($ph['reference_desc']??$ph['notes']??'-')) ?></td>
                                </tr>
                            <?php endforeach; ?></tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- PERFORMANCE TAB -->
        <div id="sp-performance" class="tab-panel<?= $activeTab==='performance'?' active':'' ?>">
            <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px">
                <input type="hidden" name="page" value="salespeople">
                <input type="hidden" name="tab" value="performance">
                <select name="perf_month" style="margin:0"><?php for($m=1;$m<=12;$m++):?><option value="<?= $m ?>"<?= $perfMonth==$m?' selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option><?php endfor;?></select>
                <select name="perf_year" style="margin:0"><?php for($y=date('Y');$y>=date('Y')-3;$y--):?><option value="<?= $y ?>"<?= $perfYear==$y?' selected':'' ?>><?= $y ?></option><?php endfor;?></select>
                <button type="submit" style="margin:0">View</button>
            </form>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
                <div class="card"><h2>Monthly Revenue</h2><p><?= fmt($totalRevenue) ?></p></div>
                <div class="card"><h2>Total Sales</h2><p><?= $totalSales ?></p></div>
                <div class="card"><h2>Total Visits</h2><p><?= $totalVisits ?></p></div>
                <div class="card"><h2>New Customers</h2><p><?= $totalNewCustomers ?></p></div>
            </div>
            <?php if (!empty($chartLabels)): ?>
            <div class="panel" style="margin-bottom:16px;max-width:700px">
                <h2>Revenue by Salesperson</h2>
                <canvas id="revenueChart" height="300"></canvas>
            </div>
            <?php endif; ?>
            <?php if (!empty($monthTrend)): ?>
            <div class="panel" style="margin-bottom:16px;max-width:700px">
                <h2>Monthly Trend (Rep-Attributed Sales)</h2>
                <canvas id="trendChart" height="250"></canvas>
            </div>
            <?php endif; ?>
            <div class="panel">
                <h2>Performance by Salesperson</h2>
                <table>
                    <thead><tr><th>Salesperson</th><th>Territory</th><th>Sales</th><th>Revenue</th><th>Visits</th><th>New Customers</th><th>Conversion</th><th>Target</th><th>YTD Revenue</th></tr></thead>
                    <tbody>
                        <?php if (empty($performance)): ?>
                            <tr><td colspan="9">No salespeople found.</td></tr>
                        <?php else: foreach ($performance as $p): ?>
                            <?php $conversion=(int)$p['monthly_visits']>0?round(((int)$p['monthly_sales']/(int)$p['monthly_visits'])*100):0; $targetMet=(int)$p['monthly_target']>0&&(int)$p['monthly_sales']>=(int)$p['monthly_target']; ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td style="font-size:0.85rem"><?= htmlspecialchars((string)($p['territory_name']??'-')) ?><?php if($p['region']):?><br><small style="color:var(--neutral-500)"><?= htmlspecialchars($p['region']) ?></small><?php endif;?></td>
                                <td><?= (int)$p['monthly_sales'] ?></td>
                                <td style="font-weight:700"><?= fmt((float)$p['monthly_revenue']) ?></td>
                                <td><?= (int)$p['monthly_visits'] ?></td>
                                <td><?= (int)$p['new_customers'] ?></td>
                                <td><div style="display:flex;align-items:center;gap:6px"><div style="background:var(--neutral-200);border-radius:4px;height:8px;width:60px;overflow:hidden"><div style="background:<?= $conversion>=50?'var(--brown-600)':($conversion>=25?'#f59e0b':'var(--red-600)') ?>;height:100%;width:<?= min($conversion,100) ?>%"></div></div><small><?= $conversion ?>%</small></div></td>
                                <td><?php if((int)$p['monthly_target']>0):?><?php if($targetMet):?><span style="color:var(--brown-600);font-weight:700"><?= (int)$p['monthly_sales'] ?>/<?= (int)$p['monthly_target'] ?></span><br><small style="color:var(--brown-600)">Met! +<?= fmt((float)$p['monthly_target_bonus']) ?></small><?php else:?><span><?= (int)$p['monthly_sales'] ?>/<?= (int)$p['monthly_target'] ?></span><br><small style="color:var(--red-600)"><?= (int)$p['monthly_target']-(int)$p['monthly_sales'] ?> more</small><?php endif;?><?php else:?><span style="color:var(--neutral-400)">-</span><?php endif;?></td>
                                <td><?= fmt((float)$p['ytd_revenue']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>
<?php if (!empty($chartLabels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('revenueChart'),{type:'bar',data:{labels:<?= json_encode($chartLabels) ?>,datasets:[{label:'Revenue (<?= currency_sym() ?>)',data:<?= json_encode($chartData) ?>,backgroundColor:'#6b4226',borderRadius:6}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
</script>
<?php endif; ?>
<?php if (!empty($monthTrend)): ?>
<script>
new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:<?= json_encode(array_column($monthTrend,'month_label')) ?>,datasets:[{label:'Revenue',data:<?= json_encode(array_map('floatval',array_column($monthTrend,'revenue'))) ?>,borderColor:'#6b4226',backgroundColor:'rgba(107,66,38,0.1)',fill:true,tension:0.3},{label:'Sales Count',data:<?= json_encode(array_map('intval',array_column($monthTrend,'sale_count'))) ?>,borderColor:'#059669',borderDash:[5,5],fill:false,tension:0.3,yAxisID:'y1'}]},options:{responsive:true,scales:{y:{beginAtZero:true,position:'left'},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}}}});
</script>
<?php endif; ?>
<script>
function editSalesperson(id,name,phone,email,territoryId,commRate,newCustBonus,monthlyTarget,monthlyTargetBonus,status){
    document.getElementById('sp-id').value=id;
    document.getElementById('sp-name').value=name;
    document.getElementById('sp-phone').value=phone;
    document.getElementById('sp-email').value=email;
    document.getElementById('sp-territory').value=territoryId;
    document.getElementById('sp-commission-rate').value=commRate;
    document.getElementById('sp-new-customer-bonus').value=newCustBonus;
    document.getElementById('sp-monthly-target').value=monthlyTarget;
    document.getElementById('sp-monthly-target-bonus').value=monthlyTargetBonus;
    document.getElementById('sp-status').value=status;
    document.getElementById('sp-form-title').textContent='Edit Salesperson';
    document.getElementById('sp-save-btn').textContent='Update Salesperson';
    document.getElementById('sp-cancel-btn').style.display='inline-block';
    switchTab(document.querySelectorAll('#sp-list .tab-btn')[1],'sp-main-add');
}
function resetSpForm(){
    document.getElementById('sp-id').value=0;
    document.getElementById('sp-name').value='';
    document.getElementById('sp-phone').value='';
    document.getElementById('sp-email').value='';
    document.getElementById('sp-territory').value='0';
    document.getElementById('sp-commission-rate').value='5.00';
    document.getElementById('sp-new-customer-bonus').value='0';
    document.getElementById('sp-monthly-target').value='0';
    document.getElementById('sp-monthly-target-bonus').value='0';
    document.getElementById('sp-status').value='active';
    document.getElementById('sp-form-title').textContent='Add Salesperson';
    document.getElementById('sp-save-btn').textContent='Save Salesperson';
    document.getElementById('sp-cancel-btn').style.display='none';
}
function editTerritory(id,name,desc,region){
    document.getElementById('terr-id').value=id;
    document.getElementById('terr-name').value=name;
    document.getElementById('terr-desc').value=desc;
    document.getElementById('terr-region').value=region;
    document.getElementById('terr-form-title').textContent='Edit Territory';
    document.getElementById('terr-save-btn').textContent='Update Territory';
    document.getElementById('terr-cancel-btn').style.display='inline-block';
    switchTab(document.querySelectorAll('#sp-territories .tab-btn')[1],'terr-add');
}
function resetTerrForm(){
    document.getElementById('terr-id').value=0;
    document.getElementById('terr-name').value='';
    document.getElementById('terr-desc').value='';
    document.getElementById('terr-region').value='';
    document.getElementById('terr-form-title').textContent='Add Territory';
    document.getElementById('terr-save-btn').textContent='Save Territory';
    document.getElementById('terr-cancel-btn').style.display='none';
}
function toggleAll(cb){document.querySelectorAll('.comm-check').forEach(function(c){c.checked=cb.checked;})}
var orderLineCount=1;
function addOrderLine(){var tbody=document.getElementById('order-items-body');var row=tbody.querySelector('tr').cloneNode(true);var idx=orderLineCount++;row.querySelectorAll('select,input').forEach(function(el){if(el.name)el.name=el.name.replace(/\[\d+\]/,'['+idx+']');if(el.tagName==='SELECT')el.value='0';if(el.tagName==='INPUT'&&el.type==='number')el.value='1';if(el.className.indexOf('order-price')>=0||el.className.indexOf('order-subtotal')>=0)el.value='';});tbody.appendChild(row);updateTotal();}
function spUnitPrice(opt){var fm=document.getElementById('sp-fm');var retail=parseFloat(opt.getAttribute('data-price'))||0;if(fm&&fm.value==='pickup'){var wholesale=parseFloat(opt.getAttribute('data-wprice'))||0;if(wholesale>0)return wholesale;}return retail;}
function updatePrice(idx){var selects=document.querySelectorAll('.order-product');if(!selects[idx])return;var opt=selects[idx].options[selects[idx].selectedIndex];var price=spUnitPrice(opt);var rows=document.querySelectorAll('#order-items-body tr');var priceInput=rows[idx].querySelector('.order-price');var qtyInput=rows[idx].querySelector('.order-qty');priceInput.value=price>0?price.toFixed(2):'';var sub=price*(parseInt(qtyInput.value)||0);rows[idx].querySelector('.order-subtotal').value=sub>0?sub.toFixed(2):'';updateTotal();}
function updateSubtotal(idx){var rows=document.querySelectorAll('#order-items-body tr');var selects=document.querySelectorAll('.order-product');if(!selects[idx])return;var opt=selects[idx].options[selects[idx].selectedIndex];var price=spUnitPrice(opt);var qty=parseInt(rows[idx].querySelector('.order-qty').value)||0;var sub=price*qty;rows[idx].querySelector('.order-subtotal').value=sub>0?sub.toFixed(2):'';updateTotal();}
function updateTotal(){var total=0;document.querySelectorAll('.order-subtotal').forEach(function(el){total+=parseFloat(el.value)||0;});document.getElementById('order-total').textContent=total.toFixed(2);}
function spToggleDelivery(sel){var lbl=document.getElementById('sp-da-label');if(lbl){var isDelivery=sel.value==='delivery';var ta=lbl.querySelector('textarea');lbl.style.display=isDelivery?'':'none';if(ta)ta.required=isDelivery;}var selects=document.querySelectorAll('.order-product');for(var i=0;i<selects.length;i++)updatePrice(i);}
function submitOrder(){var form=document.getElementById('order-form');var h=document.createElement('input');h.type='hidden';h.name='save_order';h.value='1';form.appendChild(h);form.submit();}
</script>
