<?php
/**
 * Service Layer Bootstrap
 *
 * Loads all services. Services encapsulate business logic so pages only:
 *   - validate the request
 *   - call the appropriate service
 *   - display the response
 *
 * Pages must NEVER:
 *   - perform workflow transitions directly (use WorkflowService)
 *   - update order/invoice/payment/delivery status directly
 *   - run multi-step operations without a transaction
 */
require_once __DIR__ . '/ServiceException.php';
require_once __DIR__ . '/StatusCatalog.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/CalculationService.php';
require_once __DIR__ . '/AuthenticationService.php';
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/PricingService.php';
require_once __DIR__ . '/WorkflowService.php';
require_once __DIR__ . '/CustomerService.php';
require_once __DIR__ . '/SupplierService.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/PurchaseService.php';
require_once __DIR__ . '/OrderService.php';
require_once __DIR__ . '/InvoiceService.php';
require_once __DIR__ . '/PaymentService.php';
require_once __DIR__ . '/FinanceService.php';
require_once __DIR__ . '/CreditService.php';
require_once __DIR__ . '/CommissionService.php';
require_once __DIR__ . '/DeliveryService.php';
require_once __DIR__ . '/FulfillmentService.php';
require_once __DIR__ . '/ReportService.php';
require_once __DIR__ . '/ErpService.php';
