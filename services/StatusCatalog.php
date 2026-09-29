<?php
/**
 * StatusCatalog
 *
 * Single source of truth for every business status value in the ERP. Pages
 * and services must reference these constants instead of hardcoding strings,
 * and every status change must go through WorkflowService / WorkflowEngine so
 * the same values stay consistent across modules.
 *
 * Values intentionally match what is already persisted in the database so the
 * catalog can be introduced without migrating existing rows.
 */
class StatusCatalog
{
    // ─── Order (customer_orders.status) ──────────────────────────────
    public const ORDER_PENDING = 'pending';
    public const ORDER_APPROVED = 'approved';
    public const ORDER_REJECTED = 'rejected';
    public const ORDER_CANCELLED = 'cancelled';

    // ─── Delivery (customer_orders.delivery_status) ─────────────────
    public const DELIVERY_UNASSIGNED = null;
    public const DELIVERY_ASSIGNED = 'assigned';
    public const DELIVERY_ACCEPTED = 'accepted';
    public const DELIVERY_PICKED_UP = 'picked_up';
    public const DELIVERY_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERY_POD_PENDING = 'pod_pending';
    public const DELIVERY_DELIVERED = 'delivered';
    public const DELIVERY_FAILED = 'failed';
    public const DELIVERY_RETURNED = 'returned';

    // ─── Picking (customer_orders.picking_status) ───────────────────
    public const PICKING_NOT_STARTED = null;
    public const PICKING_PICKED = 'picked';
    public const PICKING_READY_FOR_DELIVERY = 'ready_for_delivery';

    // ─── Purchase order (purchase_orders.status) ────────────────────
    public const PO_PENDING = 'pending';
    public const PO_APPROVED = 'approved';
    public const PO_PAYMENT_PENDING = 'payment_pending';
    public const PO_PAYMENT_APPROVED = 'payment_approved';
    public const PO_PAYMENT_REJECTED = 'payment_rejected';
    public const PO_RECEIVED = 'received';
    public const PO_CANCELLED = 'cancelled';

    // ─── Purchase payment (purchase_orders.payment_status) ──────────
    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIAL = 'partial';
    public const PAYMENT_PAID = 'paid';

    // ─── Invoice (invoices.payment_status) ──────────────────────────
    public const INVOICE_UNPAID = 'unpaid';
    public const INVOICE_PARTIAL = 'partial';
    public const INVOICE_PAID = 'paid';

    // ─── Invoice fulfillment (invoices.status) ──────────────────────
    public const INVOICE_STATUS_PENDING = 'pending';
    public const INVOICE_STATUS_COMPLETED = 'completed';

    // ─── Commission (commissions.status) ────────────────────────────
    public const COMMISSION_PENDING = 'pending';
    public const COMMISSION_APPROVED = 'approved';
    public const COMMISSION_REJECTED = 'rejected';
    public const COMMISSION_PAID = 'paid';

    // ─── Credit approval (customers.credit_approval_status) ─────────
    public const CREDIT_PENDING = 'pending';
    public const CREDIT_APPROVED = 'approved';
    public const CREDIT_SUSPENDED = 'suspended';
    public const CREDIT_REJECTED = 'rejected';
    public const CREDIT_OVER_LIMIT = 'over_limit';

    // ─── Inventory batch (product_batches.workflow_status) ──────────
    // Purchase Order -> Goods Receiving -> Batch Verification ->
    // Expiry Verification -> Available for Sales (chain for store ops).
    public const INV_AWAITING_BATCH_VERIFICATION = 'awaiting_batch_verification';
    public const INV_AWAITING_EXPIRY_VERIFICATION = 'awaiting_expiry_verification';
    public const INV_AVAILABLE_FOR_SALES = 'available_for_sales';
    public const INV_REORDER_NEEDED = 'reorder_needed';
    public const INV_EXPIRED = 'expired';
    public const INV_QUARANTINED = 'quarantined';
    public const INV_DISPOSED = 'disposed';

    // ─── Order fulfillment (customer_orders.fulfillment_status) ──────
    // Approved order -> Stock Check -> Ready to Prepare -> Reserved ->
    // Preparing -> Prepared (Waiting Payment) -> Paid / Credit Approved ->
    // branch: Waiting Customer Pickup (pickup) or Ready for Delivery (delivery)
    // -> Completed.
    public const FULFILLMENT_NOT_STARTED = null;
    public const FULFILLMENT_WAITING_STOCK_CHECK = 'waiting_stock_check';
    public const FULFILLMENT_READY_TO_PREPARE = 'ready_to_prepare';
    public const FULFILLMENT_OUT_OF_STOCK = 'out_of_stock';
    public const FULFILLMENT_RESERVED = 'reserved';
    public const FULFILLMENT_PREPARING = 'preparing';
    public const FULFILLMENT_PREPARED = 'prepared';
    public const FULFILLMENT_PAID = 'paid';
    public const FULFILLMENT_CREDIT_APPROVED = 'credit_approved';
    public const FULFILLMENT_WAITING_CUSTOMER_PICKUP = 'waiting_customer_pickup';
    public const FULFILLMENT_READY_FOR_DELIVERY = 'ready_for_delivery';
    public const FULFILLMENT_COMPLETED = 'fulfillment_completed';
    public const FULFILLMENT_CANCELLED = 'fulfillment_cancelled';

    // ─── Fulfillment method (customer_orders.fulfillment_method) ─────
    public const FULFILLMENT_METHOD_PICKUP = 'pickup';
    public const FULFILLMENT_METHOD_DELIVERY = 'delivery';

    // ─── Reserved (customer_orders.reserved_status) ──────────────────
    public const RESERVED_NONE = null;
    public const RESERVED_RESERVED = 'reserved';
    public const RESERVED_RELEASED = 'released';

    // ─── Workflow names ─────────────────────────────────────────────
    public const WORKFLOW_ORDER = 'order';
    public const WORKFLOW_DELIVERY = 'delivery';
    public const WORKFLOW_PICKING = 'picking';
    public const WORKFLOW_FULFILLMENT = 'fulfillment';
    public const WORKFLOW_PURCHASE = 'purchase';
    public const WORKFLOW_PURCHASE_PAYMENT = 'purchase_payment';
    public const WORKFLOW_INVOICE = 'invoice';
    public const WORKFLOW_INVOICE_FULFILLMENT = 'invoice_fulfillment';
    public const WORKFLOW_CREDIT = 'credit';
    public const WORKFLOW_INVENTORY = 'inventory';

    public static function allWorkflows(): array
    {
        return [
            self::WORKFLOW_ORDER,
            self::WORKFLOW_DELIVERY,
            self::WORKFLOW_PICKING,
            self::WORKFLOW_FULFILLMENT,
            self::WORKFLOW_PURCHASE,
            self::WORKFLOW_PURCHASE_PAYMENT,
            self::WORKFLOW_INVOICE,
            self::WORKFLOW_INVOICE_FULFILLMENT,
            self::WORKFLOW_CREDIT,
            self::WORKFLOW_INVENTORY,
        ];
    }

    /** Human-readable label for a workflow status. Single source of truth for display. */
    public static function statusLabel(string $workflow, ?string $status): string
    {
        if ($status === null || $status === '') return '—';
        $labels = [
            self::WORKFLOW_PICKING => [
                self::PICKING_PICKED => 'Picked',
                self::PICKING_READY_FOR_DELIVERY => 'Ready for Delivery',
            ],
            self::WORKFLOW_FULFILLMENT => [
                self::FULFILLMENT_WAITING_STOCK_CHECK => 'Stock Check',
                self::FULFILLMENT_READY_TO_PREPARE => 'Stock Verified',
                self::FULFILLMENT_OUT_OF_STOCK => 'Out of Stock',
                self::FULFILLMENT_RESERVED => 'Reserved',
                self::FULFILLMENT_PREPARING => 'Preparing',
                self::FULFILLMENT_PREPARED => 'Prepared · Waiting Payment',
                self::FULFILLMENT_PAID => 'Paid',
                self::FULFILLMENT_CREDIT_APPROVED => 'Credit Approved',
                self::FULFILLMENT_WAITING_CUSTOMER_PICKUP => 'Waiting Customer Pickup',
                self::FULFILLMENT_READY_FOR_DELIVERY => 'Ready for Delivery',
                self::FULFILLMENT_COMPLETED => 'Completed',
                self::FULFILLMENT_CANCELLED => 'Cancelled',
            ],
            self::WORKFLOW_PURCHASE => [
                self::PO_PENDING => 'Pending',
                self::PO_APPROVED => 'Approved',
                self::PO_PAYMENT_PENDING => 'Awaiting Finance',
                self::PO_PAYMENT_APPROVED => 'Payment Approved',
                self::PO_PAYMENT_REJECTED => 'Payment Rejected',
                self::PO_RECEIVED => 'Received',
                self::PO_CANCELLED => 'Cancelled',
            ],
            self::WORKFLOW_INVENTORY => [
                self::INV_AWAITING_BATCH_VERIFICATION => 'Awaiting Batch Verification',
                self::INV_AWAITING_EXPIRY_VERIFICATION => 'Awaiting Expiry Verification',
                self::INV_AVAILABLE_FOR_SALES => 'Available for Sales',
                self::INV_REORDER_NEEDED => 'Reorder Needed',
                self::INV_EXPIRED => 'Expired',
                self::INV_QUARANTINED => 'Quarantined',
                self::INV_DISPOSED => 'Disposed',
            ],
        ];
        return $labels[$workflow][$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    /** Semantic severity for a workflow status: success / warning / danger / info / neutral. */
    public static function statusTone(string $workflow, ?string $status): string
    {
        $tones = [
            self::WORKFLOW_PICKING => [
                self::PICKING_PICKED => 'info',
                self::PICKING_READY_FOR_DELIVERY => 'success',
            ],
            self::WORKFLOW_FULFILLMENT => [
                self::FULFILLMENT_WAITING_STOCK_CHECK => 'warning',
                self::FULFILLMENT_READY_TO_PREPARE => 'info',
                self::FULFILLMENT_OUT_OF_STOCK => 'danger',
                self::FULFILLMENT_RESERVED => 'info',
                self::FULFILLMENT_PREPARING => 'info',
                self::FULFILLMENT_PREPARED => 'warning',
                self::FULFILLMENT_PAID => 'success',
                self::FULFILLMENT_CREDIT_APPROVED => 'success',
                self::FULFILLMENT_WAITING_CUSTOMER_PICKUP => 'warning',
                self::FULFILLMENT_READY_FOR_DELIVERY => 'info',
                self::FULFILLMENT_COMPLETED => 'success',
                self::FULFILLMENT_CANCELLED => 'danger',
            ],
            self::WORKFLOW_PURCHASE => [
                self::PO_PENDING => 'warning',
                self::PO_APPROVED => 'info',
                self::PO_PAYMENT_PENDING => 'warning',
                self::PO_PAYMENT_APPROVED => 'success',
                self::PO_PAYMENT_REJECTED => 'danger',
                self::PO_RECEIVED => 'success',
                self::PO_CANCELLED => 'danger',
            ],
            self::WORKFLOW_INVENTORY => [
                self::INV_AWAITING_BATCH_VERIFICATION => 'warning',
                self::INV_AWAITING_EXPIRY_VERIFICATION => 'warning',
                self::INV_AVAILABLE_FOR_SALES => 'success',
                self::INV_REORDER_NEEDED => 'danger',
                self::INV_EXPIRED => 'danger',
                self::INV_QUARANTINED => 'info',
                self::INV_DISPOSED => 'neutral',
            ],
        ];
        return $tones[$workflow][$status] ?? 'neutral';
    }
}
