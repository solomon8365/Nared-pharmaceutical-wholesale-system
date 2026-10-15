-- =============================================================
-- Migration: Grant Delivery Staff (role_id=3) full delivery
-- operations. Admin (role_id=1) already has all permissions via
-- __all__ but is deliberately NOT granted these modules. The new
-- business rule restricts delivery operations to the Delivery
-- Staff role and makes Delivery Reports the only delivery module
-- visible to Admin.
-- Idempotent: safe to run repeatedly.
-- =============================================================

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_staff', 'create'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_staff' AND rp.`permission` = 'create');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_staff', 'edit'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_staff' AND rp.`permission` = 'edit');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_staff', 'delete'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_staff' AND rp.`permission` = 'delete');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'assign_deliveries', 'create'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'assign_deliveries' AND rp.`permission` = 'create');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'assign_deliveries', 'edit'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'assign_deliveries' AND rp.`permission` = 'edit');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'assign_deliveries', 'assign_delivery'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'assign_deliveries' AND rp.`permission` = 'assign_delivery');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_tracking', 'edit'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_tracking' AND rp.`permission` = 'edit');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_confirmation', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_confirmation' AND rp.`permission` = 'view');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_confirmation', 'confirm'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_confirmation' AND rp.`permission` = 'confirm');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_returns', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_returns' AND rp.`permission` = 'view');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_returns', 'create'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_returns' AND rp.`permission` = 'create');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_history', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_history' AND rp.`permission` = 'view');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_management', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_management' AND rp.`permission` = 'view');

-- `view` is required by the page guards (requirePermission($module, 'view')) on
-- Driver Management, Assign Driver and Delivery Tracking. Without it the
-- delivery operator passed every action check yet could never open the screen.
INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_staff', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_staff' AND rp.`permission` = 'view');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'assign_deliveries', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'assign_deliveries' AND rp.`permission` = 'view');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_tracking', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_tracking' AND rp.`permission` = 'view');
