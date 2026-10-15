# Database & Storage Architecture — MySQL + Supabase

This document records **which service handles what** and why. It is the
authoritative reference for the NARED Pharmacon data/storage design.

---

## 1. Summary table

| Concern | Service | Where it lives | Notes |
|---|---|---|---|
| Products, inventory, batches | **MySQL** | `products`, `product_batches`, … | Primary DB |
| Customers & contacts | **MySQL** | `customers` | |
| Suppliers & purchases | **MySQL** | `suppliers`, `purchase_orders`, … | |
| Orders & order lines | **MySQL** | `customer_orders`, `order_items`, … | |
| Invoices & payments | **MySQL** | `invoices`, `invoice_payments`, `customer_payment_submissions`, … | |
| Delivery / POD records | **MySQL** | `delivery_*`, `customer_orders.delivery_photo` column stores the file *reference* | |
| Users, roles, permissions | **MySQL** | `users`, `roles`, `role_permissions` | Existing session auth unchanged |
| Settings, CMS text, audit logs | **MySQL** | `settings`, `pages`, audit tables | |
| **Uploaded files** (images, screenshots, receipts, POD photos) | **Supabase Storage** *when enabled*; otherwise local `uploads/` | object keys under one bucket | Only the binary file moves |
| Login / authentication | **MySQL** (custom session + RBAC) | `users` | Supabase Auth is **not** used |
| Relational queries / transactions | **MySQL** | — | Supabase Postgres is **not** used |

**One rule:** every row, every number, every business relationship stays in
MySQL. Supabase only ever receives *bytes* of an uploaded file, and only when
you explicitly turn it on.

---

## 2. Why this split

### MySQL is, and stays, the primary database

The application is a server-rendered PHP/MariaDB system with heavy
relational + transactional work: stock deduction inside order transactions,
credit limits, invoicing, role permissions, audit trails. Moving any of that
to a second datastore would split a single transaction across two systems and
break the consistency guarantees the code relies on.

There is also a hard technical constraint: this PHP build has **no `pdo_pgsql`
/ `pgsql` extension**. A direct Postgres connection to Supabase is impossible
without changing the runtime, so "Supabase as a second database" is not even
an option here without a PHP rebuild.

### Supabase is used for object storage — and only that

The app already stores binary uploads on the local disk (`uploads/…`). That
works on one XAMPP box but ties file durability and delivery to that machine.
Supabase Storage is a natural, **non-duplicating** upgrade: the *file* lives in
one place (Supabase), while MySQL keeps only the reference to it — the same
column, the same semantics as before.

### What was deliberately **not** integrated

| Candidate | Decision | Reason |
|---|---|---|
| **Supabase Auth** | ❌ Not used | Would require replacing the custom session + `roles` / `role_permissions` system. Requirement 6 says preserve all role permissions; a second auth system means two sources of truth for identity and permissions. |
| **Supabase Postgres** (business data) | ❌ Not used | Requirement 4 forbids duplicating business data; a live mirror would need a documented sync contract, conflict resolution and back-fill — none of which the business logic needs. Also impossible without `pdo_pgsql`. |
| **Supabase Realtime / Edge Functions** | ❌ Not used | The app is request/response PHP. Realtime needs a persistent client connection model the app does not have, and Edge Functions would introduce a second deployment/runtime. |

---

## 3. How the storage layer works

All file writes now go through one helper, `storage_put()` in
`includes/storage.php`:

```
storage_put($tmpPath, $area, $filename, $contentType)
```

* **`SUPABASE_STORAGE_ENABLED=false` (default)** → writes
  `uploads/<area>/<filename>` and returns that relative path. **Byte-identical
  to the previous behaviour**, so nothing changes until you opt in.
* **`SUPABASE_STORAGE_ENABLED=true` + keys set** → uploads to
  `https://<project>.supabase.co/storage/v1/object/<bucket>/<area>/<filename>`
  and returns the **absolute public URL**, which is stored in the very same
  MySQL column and rendered directly in `<img src>` / `<a href>`.
* **Supabase enabled but unreachable** → the upload is logged and **falls back
  to local disk**. A receipt is never lost because a remote endpoint was down.

Reading is handled by:

* `storage_url($ref, $area)` — resolves absolute Supabase URL, legacy
  `uploads/…` path, or a bare filename.
* `storage_exists($ref, $area)` — replaces the old `file_exists()` checks for
  product images and the company logo.

Because most pages render the stored value directly, an absolute Supabase URL
"just works". The only places that used server-side `file_exists()` (product
images, brand logo) were switched to the helpers above.

---

## 4. Configuration (server-side secrets)

Copy the template and fill in real values:

```
copy .env.example .env
```

`.env` is git-ignored. **Real process/system environment variables always win
over the `.env` file**, so production can inject secrets without a file on disk.

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL (primary DB) |
| `SUPABASE_URL` | Project URL, e.g. `https://abcdefgh.supabase.co` |
| `SUPABASE_SERVICE_ROLE_KEY` | **Server-side only secret.** Used for Storage writes/deletes. |
| `SUPABASE_ANON_KEY` | Fallback if no service key is provided. |
| `SUPABASE_STORAGE_BUCKET` | Bucket name (default `nared-uploads`). |
| `SUPABASE_STORAGE_ENABLED` | `true` to make Supabase the file backend. |
| `APP_PRODUCTION` | `true` hides PHP errors. |

Security notes:

* The service-role key is read on the server only and is **never emitted into
  HTML/JS**. It has no client counterpart anywhere in this codebase.
* No secret is committed — `.env` is in `.gitignore`.
* Upload validation (extension, MIME, size) is unchanged and happens **before**
  any storage call, so a remote endpoint cannot be used to store arbitrary
  file types.

---

## 5. Enabling Supabase Storage

1. Create a project at <https://supabase.com> and copy the **Project URL** and
   the **service_role** key from *Project Settings → API*.
2. In `.env`, set:
   ```
   SUPABASE_URL=https://<your-project>.supabase.co
   SUPABASE_SERVICE_ROLE_KEY=<service_role key>
   SUPABASE_STORAGE_BUCKET=nared-uploads
   SUPABASE_STORAGE_ENABLED=true
   ```
3. Create the bucket (public, so the stored public URLs render in `<img>`):
   ```
   php tools/check_services.php --create-bucket
   ```
4. Verify:
   ```
   php tools/check_services.php
   ```
   It checks MySQL, Supabase reachability/authorization, the bucket, and runs
   a self-deleting upload probe.

### Backwards compatibility for existing files

Existing local files under `uploads/` keep working forever: their references
are relative paths, and `storage_url()`/`storage_exists()` resolve them
unchanged. Only **new** uploads move to Supabase, so there is no migration to
run and no data is copied or overwritten.

---

## 6. Verification performed

* `php -l` clean on every touched file.
* `tools/check_services.php`: MySQL connected (MariaDB 10.4.32, 88 tables, all
  core business tables present, 7 role grants for `invoice.view`).
* Logged in as admin through HTTP and loaded 17 pages (products, payments,
  catalog, expiry, settings, supplier payments, sales, invoice, refunds, …) —
  all `HTTP 200`, **no PHP errors**.
* Real **multipart upload** posted to the app under the web SAPI:
  `stored = uploads/payments/probe_*.png`, `exists = true`.
* Same upload with Supabase **enabled but unreachable**: app reported
  `backend=supabase` and still stored the file to local disk (graceful
  fallback) — uploads are never lost.
* With `.env` removed, backend reverts to `local` and behaviour is identical
  to before this change.
