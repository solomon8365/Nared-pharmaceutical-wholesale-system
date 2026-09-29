<?php
/**
 * Legacy "Delivery Hub" standalone template is retired.
 *
 * The system now has a single Delivery Dashboard rendered inside the main
 * NARED ERP layout (sidebar + header from header.php), served by
 * pages/delivery_dashboard.php. This file is kept only so the historical
 * ?page=delivery_management URL keeps working (it redirects to the unified
 * dashboard). Routing intercepts the page in index.php before this file is
 * required, so this is a defensive fallback only.
 */
header('Location: index.php?page=delivery_dashboard');
exit;