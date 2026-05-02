# Sodai Lagbe Shareholder ERP — Full System Prompt Report

> **Purpose:** This document is a complete technical and functional analysis of the "Sodai Lagbe Shareholder ERP" system. Use it to evaluate the platform, redesign it, or rebuild it as a modern web app and mobile app.

---

## 1. SYSTEM OVERVIEW

**Product Name:** Sodai Lagbe Shareholder ERP  
**Type:** Internal web-based ERP (Enterprise Resource Planning)  
**Language/Region:** Bengali (বাংলা), Timezone: Asia/Dhaka  
**Current Stack:** PHP (no framework), MySQL, plain HTML/CSS/JS  
**Hosting:** CloudPanel on `srv999373.hstgr.cloud`  
**Purpose:** Manage a shareholder-based business — tracking investments, profit distribution, project finances, KPI performance of staff advisors, voting/proposals, and internal communications via SMS.

---

## 2. USER ROLES

### 2.1 Super Admin
- Full unrestricted access to all admin features.
- Authenticated via `admins` table (username + password).
- Can delete records using a secret PIN.
- Can manage staff accounts and their permissions.

### 2.2 Staff (RBAC)
- Sub-admin with granular permissions stored as a JSON array.
- Authenticated via `staff_accounts` table.
- Permissions are checked on every page with hard `die()` on denial.
- **Available Permission Keys:**
  - `dashboard` — View admin dashboard
  - `add_entry` — Add financial entries
  - `financial_reports` — View and approve financials
  - `manage_shareholders` — Edit shareholder details
  - `add_shareholder` — Create new shareholder accounts
  - `manage_projects` — Create and edit projects
  - `manage_kpi` — KPI roles, metrics, evaluations
  - `manage_votes` — Proposal and voting management
  - `manage_video` — Upload dashboard video
  - `send_sms` — Bulk SMS broadcasting

### 2.3 Shareholder (Regular User)
- Authenticated via `shareholder_accounts` table (username OR phone + password).
- Accesses the shareholder-facing portal at the root path `/`.
- Sees personal investment data, profit share, proposals, KPI panel.
- `can_vote` flag controls whether a shareholder can create proposals.

---

## 3. ARCHITECTURE

```
/                          ← Shareholder Portal
├── index.php              ← Landing / login redirect
├── login.php              ← Login + OTP forgot-password
├── logout.php
├── dashboard.php          ← Main shareholder view
├── transactions.php       ← Financial history viewer
├── user_kpi.php           ← KPI / advisor performance panel
├── user_votes.php         ← Proposals & voting UI
├── db.php                 ← PDO DB connection
├── theme.css              ← Dark/light theme styles
└── theme.js               ← Theme toggle (localStorage)

/admin/                    ← Admin Panel
├── db.php                 ← PDO connection (FETCH_ASSOC mode)
├── login.php              ← Admin/staff login
├── logout.php
├── index.php              ← Admin dashboard (stats + settings)
├── add_shareholder.php
├── manage_shareholders.php
├── manage_projects.php
├── add_project.php
├── add_entry.php          ← Financial data entry
├── financial_reports.php
├── manage_kpi.php
├── manage_votes.php
├── manage_staff.php
├── manage_video.php
├── manage_permanent_expenses.php
├── rider_calculation.php
├── send_sms.php
└── voting.php             ← Legacy (merged into manage_votes)

/uploads/                  ← Shareholder file uploads
├── profiles/
├── receipts/
└── settings/              ← Site logo & favicon

/admin/uploads/            ← Admin file uploads
├── images/
└── videos/
```

**Design pattern:** No MVC, no router, no framework. Each PHP file is self-contained — it connects to DB, checks session, runs business logic, and outputs HTML inline.

**Schema migrations:** Done inline at the top of each PHP file using `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` inside try/catch. No migration tool.

---

## 4. DATABASE SCHEMA (21 Tables)

### 4.1 Users & Auth

**`shareholder_accounts`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| username | VARCHAR | Login identifier |
| password | VARCHAR | ⚠️ Stored in plaintext |
| name | VARCHAR | Display name |
| phone | VARCHAR | Used for login + OTP SMS |
| profile_picture | VARCHAR | Path to uploaded image |
| can_vote | TINYINT | 1 = can create proposals |
| created_at | TIMESTAMP | |

**`admins`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| username | VARCHAR | |
| password | VARCHAR | ⚠️ Plaintext |
| secret_pin | VARCHAR | Used for destructive actions |
| created_at | TIMESTAMP | |

**`staff_accounts`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| name | VARCHAR | |
| username | VARCHAR | |
| password | VARCHAR | ⚠️ Plaintext |
| permissions | JSON | Array of permission keys |
| created_at | TIMESTAMP | |

### 4.2 Business Core

**`shareholders`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| account_id | INT FK | → shareholder_accounts |
| name | VARCHAR | |
| assigned_project_id | INT FK | → projects |
| number_of_shares | DECIMAL | |
| investment_credit | DECIMAL | Total investment amount |
| share_type | ENUM | 'active' or 'passive' |
| deadline_date | DATE | Investment deadline |
| slot_numbers | TEXT | CSV of slot numbers owned |
| created_at | TIMESTAMP | |

**`projects`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| project_name | VARCHAR | |
| active_percent | FLOAT | % of profit for active shareholders |
| passive_percent | FLOAT | % of profit for passive shareholders |
| dist_type | ENUM | 'by_share' or 'by_investment' |
| mother_commission_pct | FLOAT | % commission paid to mother project |
| has_active_split | TINYINT | If active % is further split |
| active_investment_percent | FLOAT | % of active share going to investors |
| active_labor_percent | FLOAT | % of active share going to labor |
| created_at | TIMESTAMP | |

**`financials`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| type | ENUM | 'profit' or 'expense' |
| amount | DECIMAL | |
| description | TEXT | |
| expense_category | ENUM | 'general', 'third_party', 'online_purchase', 'no_record' |
| receipt_image | VARCHAR | Path to uploaded receipt |
| date_added | DATE | |
| project_id | INT FK | → projects |
| added_by | VARCHAR | Username of creator |
| status | ENUM | 'pending' or 'approved' |
| created_at | TIMESTAMP | |

### 4.3 Voting & Engagement

**`proposals`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| account_id | INT FK | Creator |
| proposal_text | TEXT | The proposal content |
| options | JSON | Array of vote option strings |
| status | ENUM | 'pending', 'approved', 'closed' |
| is_secret | TINYINT | 1 = anonymous voting |
| end_time | DATETIME | When voting closes |
| created_at | TIMESTAMP | |

**`votes`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| proposal_id | INT FK | |
| account_id | INT FK | |
| vote | TEXT | The chosen option |
| created_at | TIMESTAMP | |

**`proposal_comments`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| proposal_id | INT FK | |
| account_id | INT FK | |
| comment | TEXT | |
| created_at | TIMESTAMP | |

**`dashboard_reactions`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| user_account_id | INT FK | |
| reaction_type | ENUM | 'like' or 'love' |

**`dashboard_comments`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| user_account_id | INT FK | |
| comment_text | TEXT | |
| created_at | TIMESTAMP | |

**`dashboard_video_views`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| user_account_id | INT FK | |
| created_at | TIMESTAMP | |

### 4.4 KPI System

**`kpi_roles`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| role_name | VARCHAR UNIQUE | |
| role_description | TEXT | |
| department | VARCHAR | |
| color | VARCHAR | Hex color code |
| icon | VARCHAR | FontAwesome class |
| profit_share_pct | FLOAT | % of advisor fund for this role |
| is_active | TINYINT | |
| created_at | TIMESTAMP | |

**`kpi_metrics`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| role_id | INT FK | |
| role_name | VARCHAR | Denormalized for queries |
| metric_name | VARCHAR | |
| metric_description | TEXT | |
| category | VARCHAR | |
| max_score | INT | |
| weight_pct | FLOAT | Weight in overall score |
| measurement_type | VARCHAR | |
| target_value | FLOAT | |
| sub_fields | JSON | |
| is_active | TINYINT | |

**`kpi_evaluations`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| user_id | INT FK | |
| role_name | VARCHAR | |
| eval_month | INT | 1–12 |
| eval_year | INT | |
| total_score | FLOAT | 0–100 |
| performance_grade | VARCHAR | Grade label |
| remarks | TEXT | |
| evaluated_by | VARCHAR | Admin username |
| metrics_data | JSON | Detailed score breakdown |
| created_at | TIMESTAMP | |

**`advisor_targets`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| user_id | INT FK | |
| role_name | VARCHAR | |
| target_data | JSON | Custom target values |
| assigned_at | TIMESTAMP | |

**`kpi_daily_updates`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| user_id | INT FK | |
| role_name | VARCHAR | |
| report_date | DATE | |
| update_data | JSON | Field values for that day |
| status | ENUM | 'pending', 'verified', 'rejected' |
| admin_remarks | TEXT | |
| created_at | TIMESTAMP | |

**`role_settings`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| role_name | VARCHAR UNIQUE | |
| profit_share_pct | FLOAT | |

### 4.5 System & Misc

**`system_settings`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| setting_name | VARCHAR UNIQUE | |
| setting_value | TEXT | |

Stored settings:
- `site_logo` — path to logo image
- `site_favicon` — path to favicon
- `mother_project_id` — which project is the mother
- `total_share_slots` — total number of slots available
- `dashboard_video` — path to uploaded video
- `advisor_fund_pct` — default 10%, % of total profit allocated to KPI advisors

**`slot_sales`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| slot_number | INT | |
| account_id | INT FK | |
| created_at | TIMESTAMP | |

**`sms_history`**
| Column | Type | Notes |
|---|---|---|
| id | INT PK AUTO | |
| message | TEXT | |
| target_type | VARCHAR | 'all' or specific group |
| recipient_count | INT | |
| created_at | TIMESTAMP | |

---

## 5. CORE BUSINESS LOGIC

### 5.1 Mother/Child Project Profit Distribution

The business runs one **Mother Project** and multiple **Child Projects**.

**Flow:**
1. Shareholders invest in the Mother Project (via `shareholders` + `projects` tables).
2. Child Projects are funded from the Mother Project pool.
3. Each Child Project generates its own gross profit (tracked in `financials`).
4. Each Child Project deducts a **commission percentage** and sends it back to the Mother Project.
5. Mother Project accumulates: own gross profit + all commission income.
6. Final net profit is distributed to Mother Project shareholders.

**Distribution formula (by_share):**
```
shareholder_profit = (shareholder_shares / total_all_shares) × project_net_profit
```

**Distribution formula (by_investment):**
```
shareholder_profit = (shareholder_investment / total_all_investments) × project_net_profit
```

**Active vs Passive Split:**
- Each project has `active_percent` and `passive_percent`.
- Active shareholders may have their portion further split between `active_investment_percent` (return on capital) and `active_labor_percent` (sweat equity).
- Passive shareholders receive a fixed percentage of net profit proportional to their share/investment.

### 5.2 KPI Advisor Fund

- A configurable percentage of total company profit (default: 10%) is set aside as an **Advisor Fund**.
- Each KPI role has a `profit_share_pct` that determines what portion of the Advisor Fund goes to that role's advisors.
- Individual advisor bonus: `max_monthly_bonus × (evaluation_score / 100)`
- Grades: Exceptional (≥95), Excellent (≥85), Good (≥75), Average (≥65), Needs Improvement (≥50), Poor (<50)

### 5.3 Slot System

- Total company slots are configured in `system_settings.total_share_slots`.
- Each shareholder holds specific slot numbers (stored as CSV in `shareholders.slot_numbers`).
- Shareholders can list slots for sale via `slot_sales` table.
- Admin can also list slots for sale on behalf of the company.

### 5.4 Voting / Proposal Flow

1. Shareholder with `can_vote = 1` creates a proposal (text + options array + optional end_time).
2. Proposal starts as `pending` — admin must approve it.
3. Once `approved`, shareholders can vote (one vote per proposal per user).
4. `is_secret = 1` hides voter identities.
5. Admin can close voting at any time (sets status = 'closed').
6. Shareholders can comment on proposals (comment thread).

### 5.5 OTP Password Reset

1. Shareholder enters phone number.
2. System generates 6-digit OTP, stores in `$_SESSION['reset_otp']`.
3. SMS sent via sms.net.bd API with the OTP.
4. Shareholder enters OTP on reset form.
5. New password saved (currently in plaintext — should be hashed).

---

## 6. COMPLETE FEATURE LIST

### Shareholder Portal (`/`)

| Feature | Description |
|---|---|
| Login | Username or phone + password |
| OTP Forgot Password | SMS-based reset via sms.net.bd |
| Personal Dashboard | Shares, investment, profit share display |
| Project Profit View | Breakdown per project (mother + child) |
| Incentive Bonus Tracking | Shows earned commission from child projects |
| Profile Management | Update name, username, phone, photo |
| Password Change | With OTP SMS verification |
| Transaction History | View approved financials with receipt images |
| Pending Transaction Count | Shows how many are awaiting approval |
| Proposal Creation | Create vote proposals (if can_vote = 1) |
| Voting | Cast votes on approved active proposals |
| Proposal Comments | Comment thread on each proposal |
| KPI Dashboard | Assigned roles, targets, daily reports |
| Daily Report Submit | Submit daily work updates for each KPI role |
| Evaluation View | See monthly evaluation scores and grades |
| Bonus Estimation | Estimated vs earned bonus visualization |
| Video Engagement | React (like/love) and comment on dashboard video |
| Slot Management | View owned slots, list for sale, cancel sale |
| Dark/Light Theme | Toggle stored in localStorage |

### Admin Panel (`/admin/`)

| Feature | Description |
|---|---|
| System Dashboard | Total credit, debit, profit, balance, surplus/deficit |
| Site Branding | Upload logo & favicon |
| Shareholder CRUD | Create, read, update, delete shareholders |
| Share Management | Adjust shares, investment, type, project, deadline |
| Project CRUD | Create projects, set commission %, dist type |
| Mother Project Setting | Mark one project as the mother |
| Financial Entry | Add profit/expense entries with categories |
| Receipt Upload | Attach receipt images to online_purchase entries |
| Financial Approval | Approve pending entries before they show in reports |
| Financial Edit/Delete | Modify or delete entries (delete requires PIN) |
| KPI Role Management | Define advisor roles with colors, icons, dept |
| KPI Metric Builder | Create metrics per role with weights and targets |
| Advisor Assignment | Assign shareholders to KPI roles with targets |
| Daily Report Review | Verify or reject daily advisor submissions |
| Monthly Evaluation | Score advisors, assign grades, calculate bonuses |
| Staff Account Management | Create staff accounts, assign permissions |
| Proposal Management | Approve, close, delete proposals |
| Voting Permission | Grant/revoke can_vote per shareholder |
| SMS Broadcasting | Send bulk SMS to all or selected shareholders |
| SMS History | View log of all sent messages |
| Video Upload | Upload video shown on shareholder dashboard |
| Slot Management | Mark company slots as for-sale |
| Secret PIN | Required for destructive delete operations |

---

## 7. UI/UX DESIGN SYSTEM

### Theme
- **Default:** Dark mode (CSS variables-based)
- **Toggle:** Light mode, persisted to `localStorage` as key `erpTheme`
- **Application:** `html[data-theme="dark"]` or `html[data-theme="light"]`

### Typography
- **Primary:** Sora (Google Fonts) — used in headers and body
- **Monospace:** Space Mono — used for numerical data
- **Secondary:** Plus Jakarta Sans — used in some admin pages

### Icons
- FontAwesome 6.5.2 (CDN)

### Color Palette (Dark Mode)
- Background: `#0f1117`
- Card surfaces: `#1a1d27`
- Borders: `#2d3348`
- Primary accent: `#6366f1` (Indigo)
- Success: `#10b981` (Green)
- Warning: `#f59e0b` (Amber)
- Danger: `#ef4444` (Red)
- Text primary: `#e2e8f0`
- Text muted: `#94a3b8`

### Components Used
- Cards with glassmorphism effect (backdrop-filter)
- Progress rings (SVG circle-based for KPI scores)
- Modal dialogs (plain JS show/hide)
- Toast/alert notifications (inline HTML)
- AJAX-powered forms (fetch API)
- Countdown timers (JS intervals for proposal end_time)
- Reaction buttons (like/love toggle with AJAX)
- Tailwind CSS utility classes (used on some admin pages via CDN)

---

## 8. EXTERNAL INTEGRATIONS

### SMS — sms.net.bd
- **Use cases:** OTP password reset, bulk shareholder notifications
- **API key:** Hardcoded in `login.php` and `send_sms.php` (security issue)
- **Sender ID masking:** Custom sender name used
- **AJAX progress:** Live sending progress shown in broadcast UI

---

## 9. KNOWN SECURITY ISSUES (Must Fix in Rebuild)

| Issue | Severity | Fix |
|---|---|---|
| Plaintext passwords | Critical | Use `password_hash()` / `password_verify()` or bcrypt |
| DB credentials in source | Critical | Move to `.env` file, exclude from git |
| SMS API key in source | High | Move to `.env` |
| No session regeneration on login | High | Call `session_regenerate_id(true)` on login |
| Root `db.php` lacks PDO options | Medium | Add `ATTR_DEFAULT_FETCH_MODE`, `ATTR_EMULATE_PREPARES => false` |
| Some SQL string concatenation | Medium | Use parameterized queries everywhere |
| No file type validation framework | Medium | MIME type + extension validation on all uploads |
| Secret PIN exposed in HTML POST | Low | Use server-side PIN re-verification |
| No audit log | Low | Add action logging table |
| No rate limiting on login/OTP | Medium | Implement attempt throttling |

---

## 10. RECOMMENDED MODERN REBUILD STACK

### Option A — Full-Stack Web + Mobile (Recommended)

**Backend API:**
- Language: PHP 8.2+ with Laravel 11, OR Node.js with Express/Fastify
- Database: MySQL 8.0+ with proper ORM (Eloquent / Prisma)
- Auth: JWT + refresh tokens, bcrypt passwords
- API: REST or GraphQL
- File storage: Local disk or S3-compatible (Cloudflare R2)
- SMS: sms.net.bd (keep existing), abstracted behind service class
- Environment: `.env` via `vlucas/phpdotenv` or `dotenv`

**Web Frontend:**
- Framework: Next.js 14+ (React) or Nuxt 3 (Vue)
- UI: Tailwind CSS + shadcn/ui or Radix UI primitives
- State: Zustand / Pinia
- Charts: Recharts or Chart.js
- Icons: Lucide React
- i18n: next-intl (for Bengali)
- Theme: CSS variables dark/light system

**Mobile App:**
- Framework: React Native (Expo) — single codebase for iOS + Android
- Navigation: Expo Router
- State: Zustand + React Query
- Push notifications: Firebase Cloud Messaging (supplement SMS)
- Biometric auth: expo-local-authentication

### Option B — Simpler Upgrade Path

- Keep PHP, refactor to Laravel (same DB, gradual migration)
- Add Inertia.js + Vue/React for interactive pages
- Upgrade DB security, password hashing, env config
- Add REST endpoints for a future mobile app

---

## 11. FEATURE PARITY CHECKLIST (For Rebuild Validation)

Use this checklist to verify the rebuilt platform matches all existing functionality:

### Authentication
- [ ] Shareholder login (username or phone)
- [ ] Admin login (super admin)
- [ ] Staff login with RBAC permission enforcement
- [ ] OTP-based forgot password via SMS
- [ ] 24-hour session management
- [ ] Logout with session destroy

### Shareholder Portal
- [ ] Personal investment & share summary
- [ ] Per-project profit calculation (by_share and by_investment modes)
- [ ] Mother/child project commission flow
- [ ] Active vs Passive shareholder display
- [ ] Active split (investment % + labor %) calculation
- [ ] Incentive bonus from child project commissions
- [ ] Transaction history (approved financials only)
- [ ] Pending transaction count indicator
- [ ] Receipt image modal viewer
- [ ] Proposal creation (if can_vote = 1)
- [ ] Proposal listing with countdown timer
- [ ] Vote casting (one vote per user per proposal)
- [ ] Secret ballot mode (hide voter names)
- [ ] Proposal comment thread
- [ ] KPI assigned role view
- [ ] Daily KPI report submission
- [ ] Daily report status (pending/verified/rejected)
- [ ] Monthly evaluation score and grade view
- [ ] Bonus estimation (potential vs earned)
- [ ] Work pacing & streak metrics
- [ ] Dashboard video with react + comment
- [ ] Slot ownership display
- [ ] Slot listing for sale / cancel sale
- [ ] Profile picture upload and display
- [ ] Profile info update (name, username, phone)
- [ ] Password change with OTP verification
- [ ] Dark/light theme toggle

### Admin Panel
- [ ] Global financial summary (credit/debit/profit/balance/surplus)
- [ ] Expense percentage visualization
- [ ] Mother project earnings & commission breakdown
- [ ] Site logo and favicon management
- [ ] Shareholder listing with account grouping
- [ ] Add new shareholder account
- [ ] Edit shareholder account details + profile picture
- [ ] Add shares to shareholder (project, type, amount, investment, deadline, slots)
- [ ] Edit existing shares
- [ ] Delete shares (with PIN confirmation)
- [ ] Project creation with all parameters
- [ ] Project editing (commission %, dist type, split config)
- [ ] Mother project designation
- [ ] Financial entry creation (profit/expense/categories)
- [ ] Receipt image upload for online_purchase entries
- [ ] Pending financial approval workflow
- [ ] Financial entry editing and deletion (with PIN)
- [ ] KPI role CRUD with department, color, icon
- [ ] KPI metric CRUD with weights and sub-fields
- [ ] Advisor role assignment with targets
- [ ] Daily report verification / rejection
- [ ] Monthly evaluation scoring and grading
- [ ] Bonus calculation display
- [ ] Staff account CRUD with permission sets
- [ ] Proposal approval / closing
- [ ] Proposal deletion (with PIN)
- [ ] can_vote permission toggle per shareholder
- [ ] Bulk SMS to all shareholders
- [ ] Bulk SMS to selected shareholders
- [ ] SMS history log
- [ ] Dashboard video upload
- [ ] Slot for-sale listing management
- [ ] Total slot count configuration

---

## 12. DATA FLOW DIAGRAMS

### Profit Distribution Flow
```
[Child Project A] ──commission%──┐
[Child Project B] ──commission%──┤
[Child Project C] ──commission%──┤──→ [Mother Project Pool]
                                       │
                              + own gross profit
                                       │
                              - advisor fund (10%)
                                       │
                              = Net Distributable Profit
                                       │
                    ┌──────────────────┴──────────────────┐
                    │                                      │
            [Active Shareholders]               [Passive Shareholders]
             (active_percent %)                  (passive_percent %)
                    │
          ┌─────────┴─────────┐
   [Investment Split]    [Labor Split]
  (active_investment_%)  (active_labor_%)
```

### KPI Bonus Flow
```
[Total Company Profit]
         │
    × advisor_fund_pct (10%)
         │
    = Advisor Fund Pool
         │
    ÷ by role profit_share_pct weights
         │
    = Per-Role Budget
         │
    × (individual_score / 100)
         │
    = Individual Advisor Monthly Bonus
```

### Voting Flow
```
[Shareholder creates proposal] → status: pending
         │
    [Admin approves] → status: approved
         │
    [Active until end_time]
         │
    [Shareholders vote] (one vote each)
         │
    [Admin closes OR auto-closes at end_time] → status: closed
         │
    [Results displayed]
```

---

## 13. SAMPLE ENTITIES

### Sample Project Structure
```json
{
  "project_name": "Mother Project (Sodai Lagbe)",
  "dist_type": "by_share",
  "active_percent": 70,
  "passive_percent": 30,
  "has_active_split": 1,
  "active_investment_percent": 40,
  "active_labor_percent": 30,
  "mother_commission_pct": 0,
  "is_mother": true
}
```

```json
{
  "project_name": "Grocery Division",
  "dist_type": "by_investment",
  "active_percent": 60,
  "passive_percent": 40,
  "has_active_split": 0,
  "mother_commission_pct": 5,
  "is_mother": false
}
```

### Sample KPI Roles
```json
[
  { "role_name": "Strategy Advisor", "department": "Strategy", "color": "#8B5CF6", "profit_share_pct": 15 },
  { "role_name": "Marketing Advisor", "department": "Marketing", "color": "#EC4899", "profit_share_pct": 20 },
  { "role_name": "Operation Advisor", "department": "Operations", "color": "#F59E0B", "profit_share_pct": 20 },
  { "role_name": "Logistics & Delivery", "department": "Operations", "color": "#EF4444", "profit_share_pct": 15 },
  { "role_name": "Tech Advisor", "department": "Technology", "color": "#06B6D4", "profit_share_pct": 10 },
  { "role_name": "Financial Advisor", "department": "Finance", "color": "#10B981", "profit_share_pct": 10 },
  { "role_name": "HR Advisor", "department": "HR", "color": "#F97316", "profit_share_pct": 5 },
  { "role_name": "Design Advisor", "department": "Creative", "color": "#A78BFA", "profit_share_pct": 5 }
]
```

### Sample Proposal
```json
{
  "proposal_text": "কোম্পানির নতুন অফিস কোথায় স্থাপন করা হবে?",
  "options": ["ঢাকা", "চট্টগ্রাম", "সিলেট"],
  "is_secret": 0,
  "end_time": "2026-06-01 23:59:59",
  "status": "approved"
}
```

---

## 14. LOCALIZATION REQUIREMENTS

- All UI text in **Bengali (বাংলা)**
- Date format: `d M Y` (e.g., ০১ মে ২০২৬)
- Currency: BDT (Bangladeshi Taka, ৳)
- Timezone: `Asia/Dhaka` (UTC+6)
- Numbers may use Bengali numeral system optionally
- SMS messages sent in Bengali

---

## 15. PAGES REQUIRED IN REBUILD

### Shareholder Portal Pages
1. Landing/Login page
2. Forgot password (OTP request)
3. OTP verification
4. Password reset
5. Dashboard (main summary)
6. Transactions (financial history)
7. Voting (proposals list + detail + vote form)
8. KPI Panel (roles, daily submit, evaluation history)
9. Profile settings (edit info, change password, upload photo)

### Admin Panel Pages
1. Login (admin/staff)
2. Dashboard (stats overview + branding + slot settings)
3. Add Shareholder
4. Manage Shareholders (list + edit + share management)
5. Add Project
6. Manage Projects (list + edit + mother designation)
7. Add Financial Entry
8. Financial Reports (list + approve + edit + delete)
9. Manage KPI (roles + metrics + assignments + evaluations + daily reports)
10. Manage Votes (proposals + status + vote counts + comments)
11. Manage Staff (list + create + edit permissions)
12. Send SMS (compose + send + history)
13. Manage Video (upload + preview)

---

*End of Prompt Report — Sodai Lagbe Shareholder ERP*  
*Generated: 2026-05-02 | Source: Full codebase analysis of H:\Ai Work\ERP\archive (1)*
