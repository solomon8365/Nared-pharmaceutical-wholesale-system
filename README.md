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
5. Configure the database and optional file storage by copying `.env.example` to `.env`. The defaults are host `127.0.0.1`, database `pharma`, user `root`, and an empty password; change them to match your MySQL account. (See [docs/SUPABASE.md](docs/SUPABASE.md) for the optional Supabase Storage settings.)
6. Visit `http://localhost/<project-folder>/index.php`, replacing `<project-folder>` with the folder name you copied into `htdocs`.

## Database & storage architecture

This system uses **MySQL as the single primary database** for all business
data (products, inventory, batches, customers, orders, invoices, payments,
delivery) and the existing role/permission system.

**Supabase** is integrated **only** as optional object storage for uploaded
files (product images, payment screenshots/receipts, delivery POD photos,
CMS images, company logo). It is never used as the business database or as the
login provider.

- Default: files are written to the local `uploads/` folder (unchanged behaviour).
- Opt in via `.env` (`SUPABASE_STORAGE_ENABLED=true` + keys) to store uploads in
  a Supabase Storage bucket instead. If Supabase is unreachable, uploads fall
  back to local disk automatically.

See **[docs/SUPABASE.md](docs/SUPABASE.md)** for the full design, the rationale
for what is (and is not) delegated to Supabase, and setup steps.

### Configuration

Credentials live in server-side environment variables, loaded from a
git-ignored `.env` file:

```
copy .env.example .env
```

Verify both services at any time:

```
php tools/check_services.php
php tools/check_services.php --create-bucket   # create the storage bucket
```

## Default user
- Username: `admin`
- Password: `admin123`

> Replace the default password after first login.
