# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Sodai Lagbe Shareholder ERP** — a PHP/MySQL web application for managing shareholders, projects, finances, KPIs, and voting. The UI is in Bengali (বাংলা). Timezone is `Asia/Dhaka`.

## Architecture

There are two distinct access areas:

| Area | Path | Session key | Role |
|------|------|-------------|------|
| Shareholder portal | `/` (root) | `$_SESSION['user_logged_in']` | Regular shareholders |
| Admin panel | `/admin/` | `$_SESSION['admin_logged_in']` | Admins + staff with RBAC |

Each area has its own `db.php`, `login.php`, `logout.php`, and `index.php`. There is **no shared framework or router** — every page is a self-contained PHP file that includes `db.php` and handles its own HTML output.

### Database access

Both `db.php` files connect to the same MySQL database (`erp-sodailagbe` on `srv999373.hstgr.cloud`) via PDO. The admin `db.php` uses `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC` and real prepared statements; the root `db.php` does not set these options.

### Schema migration pattern

Tables and columns are created/altered inline at the top of each PHP file using `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` inside a `try/catch`. There is no migration tool.

### Authentication

- Shareholder login: `shareholder_accounts` table, username **or** phone + plaintext password.
- Admin login: separate admin table, with an `admin_role` column (`admin` or `staff`). Staff permissions are stored in `$_SESSION['staff_permissions']` as an array and checked per-page.
- Sessions last 24 hours (`session.cookie_lifetime = 86400`).
- OTP password reset uses the **sms.net.bd** API (`sendSMS()` in `login.php`).

### Key pages

| File | Purpose |
|------|---------|
| `index.php` | Public landing/login redirect |
| `login.php` | Shareholder login + OTP forgot-password |
| `dashboard.php` | Shareholder dashboard |
| `transactions.php` | Shareholder transaction history |
| `user_kpi.php` | Shareholder KPI view |
| `user_votes.php` | Shareholder voting UI |
| `admin/index.php` | Admin dashboard |
| `admin/manage_shareholders.php` | CRUD for shareholders |
| `admin/manage_projects.php` | CRUD for projects |
| `admin/manage_kpi.php` | KPI management |
| `admin/manage_votes.php` | Voting/proposal management |
| `admin/financial_reports.php` | Financial reports |
| `admin/send_sms.php` | Bulk SMS via sms.net.bd |

### File uploads

Uploaded files (profile pictures, etc.) go into `uploads/` (root) and `admin/uploads/`. No file-type validation framework — validate manually when adding upload features.

## Security Issues to Fix

- **Credentials in source**: `db.php` (both copies) contain the plaintext DB password. Move to environment variables or a config file excluded from version control.
- **Plaintext passwords**: `shareholder_accounts` stores passwords as plaintext. Should be hashed with `password_hash()`/`password_verify()`.
- **SMS API key** hardcoded in `login.php`.

## Deployment

Hosted on **CloudPanel** (`srv999373.hstgr.cloud`). No build step — deploy by copying PHP files directly to the server.
