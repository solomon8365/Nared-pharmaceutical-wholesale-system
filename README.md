# Pharma Distribution System

## Features
- User authentication
- Product inventory management
- Supplier management
- Order creation with stock adjustments
- Dashboard summary

## Setup
1. Copy the project folder into your web server's document root (for XAMPP, `C:\xampp\htdocs`).
2. Start Apache and MySQL in the XAMPP Control Panel.
3. In phpMyAdmin, create a database named `pharma` using the `utf8mb4` character set, then import `sql/schema.sql` into it.
4. Make sure PHP's `pdo_mysql` extension is enabled.
5. Check the database settings in `config.php`. The defaults are host `127.0.0.1`, database `pharma`, user `root`, and an empty password; change them to match your MySQL account.
6. Visit `http://localhost/<project-folder>/index.php`, replacing `<project-folder>` with the folder name you copied into `htdocs`.

## Default user
- Username: `admin`
- Password: `admin123`

> Replace the default password after first login.
