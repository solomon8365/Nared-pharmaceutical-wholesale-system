-- CMS / Information Center Module
-- Run: mysql -u root pharma < sql/migration_cms.sql

CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    content LONGTEXT,
    featured_image VARCHAR(255) DEFAULT NULL,
    document VARCHAR(255) DEFAULT NULL,
    meta_description TEXT DEFAULT NULL,
    keywords VARCHAR(255) DEFAULT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    display_order INT DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_category (category),
    UNIQUE KEY idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity log table
CREATE TABLE IF NOT EXISTS cms_activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    details TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_page (page_id),
    KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed admin permissions
SET @admin_role_id = 1;

INSERT IGNORE INTO role_permissions (role_id, module, permission)
SELECT @admin_role_id, 'cms', p
FROM (SELECT 'view' AS p UNION SELECT 'create' UNION SELECT 'edit' UNION SELECT 'delete' UNION SELECT 'manage') AS perms;

INSERT IGNORE INTO role_permissions (role_id, module, permission)
SELECT @admin_role_id, 'info', p
FROM (SELECT 'view' AS p) AS perms;

-- Seed default pages
INSERT INTO pages (title, slug, category, content, meta_description, status, display_order) VALUES
('About the Company', 'about-the-company', 'About the Company', '<h2>About NARED PHARMACON</h2><p>NARED PHARMACON is a leading pharmaceutical wholesale distributor dedicated to providing high-quality pharmaceutical products and healthcare solutions to businesses across the region.</p><p>With years of experience in the pharmaceutical industry, we have built a reputation for reliability, quality, and exceptional customer service.</p>', 'Learn about NARED PHARMACON, a trusted pharmaceutical wholesale distributor.', 'published', 1),
('Vision and Mission', 'vision-and-mission', 'Vision and Mission', '<h2>Our Vision</h2><p>To be the most trusted pharmaceutical wholesale distributor, setting the standard for quality, reliability, and innovation in healthcare supply chain management.</p><h2>Our Mission</h2><p>To provide exceptional pharmaceutical products and services that improve patient outcomes, support healthcare providers, and promote community health and well-being.</p><h2>Our Core Values</h2><ul><li><strong>Quality:</strong> We never compromise on the quality of our products and services.</li><li><strong>Integrity:</strong> We conduct our business with the highest ethical standards.</li><li><strong>Reliability:</strong> Our customers can count on us for timely and accurate deliveries.</li><li><strong>Innovation:</strong> We continuously improve our systems and processes.</li><li><strong>Customer Focus:</strong> Our customers'' success is our success.</li></ul>', 'Our vision, mission, and core values that guide our business.', 'published', 2),
('Company Services', 'company-services', 'Company Services', '<h2>Our Services</h2><p>NARED PHARMACON offers a comprehensive range of services to meet the needs of healthcare businesses:</p><ul><li><strong>Pharmaceutical Wholesale Distribution:</strong> Wide range of pharmaceutical products at competitive prices.</li><li><strong>Order Management:</strong> Easy online ordering system with real-time inventory tracking.</li><li><strong>Delivery Services:</strong> Reliable and timely delivery to your business location.</li><li><strong>Credit Services:</strong> Flexible payment terms for qualified business customers.</li></ul>', 'Explore the range of services offered by NARED PHARMACON.', 'published', 3),
('Privacy Policy', 'privacy-policy', 'Privacy Policy', '<h2>Privacy Policy</h2><p>NARED PHARMACON is committed to protecting the privacy of our customers and users.</p><h3>Information We Collect</h3><ul><li>Business and contact information</li><li>Order history and transaction records</li><li>Account login credentials</li></ul><h3>How We Use Your Information</h3><ul><li>To process and fulfill orders</li><li>To communicate order status and updates</li><li>To improve our products and services</li></ul><h3>Data Security</h3><p>We implement appropriate technical and organizational measures to protect your personal data against unauthorized access, alteration, disclosure, or destruction.</p>', 'How NARED PHARMACON collects, uses, and protects your personal information.', 'published', 10),
('Terms and Conditions', 'terms-and-conditions', 'Terms and Conditions', '<h2>Terms and Conditions</h2><p>By using the NARED PHARMACON wholesale management system, you agree to the following terms and conditions.</p><h3>Account Registration</h3><p>You must provide accurate and complete information when registering for an account.</p><h3>Orders and Payments</h3><p>All orders are subject to availability and confirmation. Payment terms are as agreed upon during account setup.</p><h3>Limitation of Liability</h3><p>NARED PHARMACON shall not be liable for any indirect, incidental, or consequential damages.</p>', 'Terms and conditions governing the use of the NARED PHARMACON system.', 'published', 11),
('Frequently Asked Questions (FAQ)', 'frequently-asked-questions', 'Frequently Asked Questions (FAQ)', '<h2>Frequently Asked Questions</h2><h3>How do I place an order?</h3><p>Log in to your customer account, browse the product catalog, add items to your cart, and submit your order through the Place Order page.</p><h3>How can I check my order status?</h3><p>You can view the status of all your orders in the My Orders section of your account dashboard.</p><h3>What payment methods are accepted?</h3><p>Payment options are outlined in your account agreement. Please contact our support team for details.</p><h3>How do I return a product?</h3><p>Please refer to our Return and Refund Policy for detailed information on the return process.</p>', 'Find answers to commonly asked questions about using the system.', 'published', 20),
('Contact Information', 'contact-information', 'Contact Information', '<h2>Contact Us</h2><p><strong>Company:</strong> NARED PHARMACON</p><p><strong>Phone:</strong> +251-XXX-XXXXXX</p><p><strong>Email:</strong> info@naredpharmacon.com</p><p><strong>Address:</strong> Addis Ababa, Ethiopia</p><h3>Business Hours</h3><p>Monday - Friday: 8:00 AM - 6:00 PM</p><p>Saturday: 8:00 AM - 1:00 PM</p><p>Sunday: Closed</p>', 'Get in touch with NARED PHARMACON.', 'published', 30);
