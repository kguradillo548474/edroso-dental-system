# Edroso Dental Clinic — Management System

Full-stack **PHP + MySQL** web application for clinic staff and patients: admin workflows, public marketing site, and a logged-in **patient portal** (register, book, view/cancel appointments).

### Changelog (2026-05-11)

This release aligns **staff admin**, **APIs**, **portal booking**, and **documentation** with the current tree. Highlights:

| Area | What changed |
|------|----------------|
| **Core schema location** | Primary import file is **`sql/database.sql`** (root `database.sql` removed). Fresh installs should import that file first. |
| **`includes/db.php`** | Optional **`includes/config.php`** can define `DB_*` and `APP_ENV`. Sessions use **strict mode** and tightened cookie params (`Secure` when HTTPS, `SameSite=Lax`, `HttpOnly`). JSON APIs send **same-host CORS** (`Access-Control-Allow-Origin` only when `Origin` matches the request host) with credentials, plus security headers and **OPTIONS** handling. |
| **Staff credential recovery** | **`api/auth.php`**: `staff_recovery_request` / `staff_recovery_reset` — OTP-based **forgot password** (by username) and **forgot username** (by unique staff full name), rate-limited, **staff role only**. Creates **`credential_recovery_challenges`** and writes **`admin_staff_alerts`** rows (schema ensured on use). **`admin/login.html`** exposes the recovery UI. Optional SQL: **`sql/upgrade_staff_recovery_alerts.sql`** (also seeds demo user `staff` / `password` if missing). |
| **Login audit log** | **`includes/auth_login_log.php`** — append-only **`auth_login_log`** (realms `staff` / `portal`) with IP and user agent; table auto-created on first log attempt. Staff and portal logins call **`log_auth_login_attempt`**. **`api/auth_logs.php`** lists entries for logged-in staff (`?realm=` filter). **`admin/auth-logs.html`** + sidebar **Login activity** (`assets/js/layout.js`). Reference DDL: **`sql/auth_login_log.sql`**. |
| **In-app staff alerts** | **`api/admin_alerts.php`** — list unread/read **`admin_staff_alerts`**, mark read (CSRF on POST). **Dashboard** (`admin/dashboard.html`) can surface recent alerts. |
| **Admin layout** | **`assets/css/style.css`** + **`assets/js/app.js`**: fixed sidebar vs main column (Chrome flex), header **z-index** above sidebar, desktop **`admin-sidebar-collapsed-desktop`**, deferred **`setTimeout(0)`** second **`initSidebar()`** pass, **`window.initAdminSidebar()`** for late-injected chrome. See **Admin shell** under Tech stack. |
| **Appointments (staff)** | **`api/appointments.php`**: optional columns **`internal_change_reason`** / **`slot_modified_at`**; staff edits that change slot sync linked **portal** rows; conflict helpers; session-scoped **portal→admin backfill** via **`includes/portal_admin_sync.php`**. **`assets/js/appointments.js`** + **`admin/appointments.html`**: summary stat cards act as **list filters** (today / upcoming / completed / cancelled). |
| **Portal booking & payments** | **`api/patient_appointments.php`** + **`sql/patient_appointments.sql`**: **`payment_reference`**, **`payment_proof_path`**, staff metadata columns; **GCash proof** upload (`?action=upload_gcash_proof`), transactional booking paths, stricter GCash validation where configured. **`customer-site/portal/book.html`**, **`book.js`**, **`dashboard.html`** updated for the flow. |
| **Clinic / portal settings** | **`api/settings.php`**: admin **cashless payment QR** upload/clear (`upload_cashless_payment_qr`, `clear_cashless_payment_qr` → **`assets/uploads/payment_qr/`**), **`portal_referral_sources`**, **`auto_logout_minutes`** (surfaced on `auth.php?action=me`). **`api/portal_options.php`** exposes **`cashless_payment_qr_path`** and referral list to the portal. **`admin/settings.html`** extended for new controls. |
| **Other API / UI** | **`api/dashboard.php`**, **`api/backup.php`**, **`api/payments.php`**, **`api/services.php`**, **`api/patient_auth.php`** — incremental fixes. **`includes/portal_booking_mirror.php`** — small sync adjustments. Marketing **`login.html` / `register.html` / `service.html` / `services.html`** — minor link or copy tweaks. |
| **Performance (legacy SQL)** | **`sql/add_appointment_indexes.sql`** — same date/status indexes as in **`sql/database.sql`** and **`sql/patient_appointments.sql`**; only for **old** DBs missing them (skip on fresh installs). |

Runtime upload directories (gitignored or local): **`assets/uploads/payment_qr/`**, **`assets/uploads/portal_gcash/`**.

---

## How the system fits together

```mermaid
flowchart LR
  subgraph public["Public customer site"]
    M[Marketing pages]
    R[Register / Login]
    F[Forgot password]
  end
  subgraph portal["Patient portal"]
    D[Dashboard]
    B[Book appointment]
    P[Profile / Settings]
  end
  subgraph staff["Staff admin"]
    A[Appointments]
    PT[Patients]
    DN[Dentists]
    PM[Payments]
    ST[Settings]
  end
  subgraph api["JSON APIs"]
    PA[patient_auth.php]
    PP[patient_appointments.php]
    AP[appointments.php + …]
  end
  M --> R
  R --> PA
  R --> portal
  F --> PA
  portal --> PA
  portal --> PP
  staff --> AP
  PP <--> DB[(MySQL edroso_dental)]
  AP <--> DB
  PA <--> DB
```

**Two audiences, one database**

| Audience | Where they work | Auth |
|----------|-----------------|------|
| **Staff** | `admin/*.html` + root `api/` (except portal-specific JSON) | `api/auth.php` → `users` table, session keys for staff |
| **Patients** | `customer-site/` (marketing + `portal/`) | `api/patient_auth.php` → `portal_users`, session keys `portal_user_*` |

Portal bookings live in **`patient_appointments`** and can be **mirrored** into the main **`appointments`** table for the desk calendar (see `includes/portal_booking_mirror.php`). When a portal user matches an admin **`patients`** row (by email), the portal **dashboard** also lists that patient’s **staff-created** appointments (same date/time as a portal row are deduplicated so nothing shows twice).

---

## End-to-end flows

### Staff day-to-day

1. Open **Admin login** → session created (optional **forgot password / username** recovery for **staff** accounts via OTP on the login page).
2. **Dashboard** — quick stats, recent activity, and **staff alerts** when credential recovery or similar events occur.
3. **Appointments** — create/edit/cancel; filter by dentist; **click stat cards** to filter by today / upcoming / completed / cancelled; list ties to `appointments` + patients + dentists.
4. **Patients / Dentists / Payments / Settings** — maintain master data and clinic settings.

### Patient: discover → account → book

1. Browse **`customer-site/`** (home, services, contact). Footer/clinic text can be driven by **`api/settings.php?public=1`** (see `customer-site/assets/js/main.js`).
2. **Register** (`register.html`) → `portal_users` (+ optional mirror row in `patients`).
3. **Login** → portal session.
4. **Book** (`portal/book.html`) → `patient_appointments` (pending/scheduled); availability comes from dentist schedules and existing bookings.
5. **Dashboard** — upcoming/past/cancelled; cancel eligible rows via API. Admin bookings for the same person appear here when email matches **`patients`**.

### Patient: password recovery (no real email required)

1. **Forgot password** (`customer-site/forgot-password.html`):
   - **Continue with security question** — if the account has a saved question (`portal_users.recovery_question` / hashed answer), the user sees the question and sets a **new password** after answering.
   - **Email me a code** — legacy path: 6-digit token in **`reset_tokens`** + PHP `mail()` (useful when SMTP works).
2. **Reset password** (`customer-site/portal/reset-password.html`) — same two modes in one page (tabs).
3. **While logged in** — **Portal → Settings** can set or change the security question (requires **current password**).

Optional **security question at registration** (`register.html`): recommended for local/dev when email is not reliable. Answers are normalized (case/spacing) and stored with **`password_hash`**.

---

## Project structure

```
edroso-dental-system/
├── admin/                      ← Staff UI (login, dashboard, appointments, auth-logs, …)
├── api/
│   ├── auth.php                ← Staff login / session / recovery / me (auto_logout)
│   ├── auth_logs.php           ← Staff: login audit log (JSON)
│   ├── admin_alerts.php        ← Staff: in-app alerts
│   ├── patient_auth.php        ← Portal register / login / logout / forgot / reset / recovery
│   ├── patient_appointments.php ← Portal bookings, lists, cancel, availability, GCash proof
│   ├── appointments.php        ← Staff appointments API
│   ├── portal_options.php      ← Public portal booking options (payments, QR path, referrals)
│   ├── patients.php, dentists.php, payments.php, dashboard.php, settings.php, …
│   └── …
├── assets/                     ← Staff app JS/CSS
├── customer-site/              ← Public site + patient portal
│   ├── index.html, about.html, services.html, contact.html, …
│   ├── login.html, register.html, forgot-password.html
│   ├── assets/                 ← customer-site CSS/JS/images + booking-cta.js, main.js
│   └── portal/
│       ├── dashboard.html, book.html, profile.html, settings.html
│       ├── reset-password.html
│       └── …
├── includes/                   ← db.php, config.php (optional), csrf.php, auth_login_log.php, portal mirror/sync helpers
├── sql/
│   ├── database.sql            ← Core clinic schema (run first)
│   ├── portal_users.sql        ← portal_users (run after core DB)
│   ├── portal_recovery_columns.sql ← optional manual ALTER for recovery columns
│   ├── patient_appointments.sql
│   ├── auth_login_log.sql      ← optional manual CREATE for login audit table
│   ├── upgrade_staff_recovery_alerts.sql ← staff OTP recovery + admin_staff_alerts + demo staff user
│   ├── upgrade_edroso_features.sql, upgrade_tc063_payment_method.sql, …
│   └── add_appointment_indexes.sql
├── tools/                      ← e.g. sync_portal_to_admin.php
├── .gitignore
└── README.md
```

---

## Installation

### Requirements

- **PHP** 7.4+ (8.x recommended)
- **MySQL** 5.7+ or MariaDB 10.3+
- **Apache** with PHP (XAMPP / WAMP / Laragon)

### Step 1 — Core database

1. Open **phpMyAdmin** → **Import** → **`sql/database.sql`** → **Go**  
2. Creates `edroso_dental`, staff tables, and sample data where included.

### Step 2 — Patient portal tables

On database **`edroso_dental`**, in order:

1. `sql/portal_users.sql` — patient accounts (`portal_users`)
2. `sql/patient_appointments.sql` — portal booking rows

`api/patient_auth.php` and `api/patient_appointments.php` can **auto-create** missing tables/columns on first use; the SQL files are still the clean reference for fresh installs.

Optional: `sql/portal_recovery_columns.sql` — only if you prefer to add **`recovery_question`** / **`recovery_answer_hash`** manually (otherwise the API adds them).

Optional: `sql/auth_login_log.sql` — manual **`auth_login_log`** table (otherwise created on first staff/portal login attempt).

Optional: `sql/upgrade_staff_recovery_alerts.sql` — **`credential_recovery_challenges`**, **`admin_staff_alerts`**, and demo **`staff`** user (`password`) for testing OTP recovery.

Other `sql/upgrade_*.sql` files apply optional schema tweaks; read each file’s comments before running.

### Step 3 — Configure database access

Either add **`includes/config.php`** defining `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, and optional `APP_ENV`, **or** rely on the fallbacks in **`includes/db.php`**:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'edroso_dental');
```

Keep secrets out of Git (`.env` is ignored if you introduce one); do not commit real production passwords.

### Step 4 — Deploy

**XAMPP example:** `C:/xampp/htdocs/edroso-dental-system/`

### Step 5 — Useful URLs

| Area | Example URL |
|------|----------------|
| Staff login | `http://localhost/edroso-dental-system/admin/login.html` |
| Staff login activity (after login) | `…/admin/auth-logs.html` |
| Customer home | `http://localhost/edroso-dental-system/customer-site/index.html` |
| Register | `…/customer-site/register.html` |
| Login | `…/customer-site/login.html` |
| Forgot password | `…/customer-site/forgot-password.html` |
| Reset password (code or security answer) | `…/customer-site/portal/reset-password.html` |
| Portal dashboard | `…/customer-site/portal/dashboard.html` |
| Book (requires login) | `…/customer-site/portal/book.html` |
| Portal settings (password + security question) | `…/customer-site/portal/settings.html` |

---

## Default staff login

| Username | Password   | Role  |
|----------|------------|-------|
| `admin`  | `password` | Admin |
| `edroso` | `password` | Admin |

If you ran **`sql/upgrade_staff_recovery_alerts.sql`**, a demo **`staff`** / **`password`** user may also exist for reception-style testing.

Change passwords in production.

---

## Features (staff app)

| Module | Features |
|--------|----------|
| **Dashboard** | Stats, funnel, recent appointments; staff **alerts** strip when present |
| **Patients** | CRUD, search, filters, pagination |
| **Appointments** | List/calendar, CRUD, dentist filters; **stat-card filters**; portal row sync on slot edits; ties to main `appointments` |
| **Dentists** | Profiles, photos, schedules |
| **Payments** | CRUD, stats, filters |
| **Settings** | Clinic configuration; **cashless QR** upload; portal referral list; session **auto-logout** minutes |
| **Login activity** | Read-only audit of staff + portal sign-in attempts (`api/auth_logs.php`) |
| **Auth** | Session-based staff login via `api/auth.php`; **OTP recovery** for staff; login **audit log** |
| **Exports** | CSV via `api/export.php` — dashboard summary, appointments (filtered), payments (admin) |

---

## Patient portal and customer site

### Registration and login

- **`customer-site/register.html`** → `api/patient_auth.php` (`action: register`). Optional **`recovery_question`** / **`recovery_answer`** for non-email password reset.
- **`customer-site/login.html`** → `action: login`. Sets `$_SESSION['portal_user_id']` and `portal_user_name`.
- Session path is configured in **`includes/db.php`** so `customer-site/` and `api/` share the same origin cookie.

**Login redirect:** `login.html?next=portal/book.html` — after login, redirect to a path under `customer-site/` (validated in client/server patterns such as `portal/*.html`).

### Booking and dashboard

- **`portal/book.html`** — date/time/dentist, submits to **`api/patient_appointments.php`** (JSON). Requires portal session. When **GCash** is selected, the flow can require a **reference** and/or **proof-of-payment** upload (`upload_gcash_proof`), depending on clinic options.
- **`portal/dashboard.html`** — lists portal rows + matching staff appointments; cancel where allowed.

### Password and recovery (`api/patient_auth.php`)

| Action / route | Purpose |
|-----------------|--------|
| `GET ?action=csrf` | CSRF token for POST bodies |
| `GET ?action=me` | Logged-in portal user profile (+ `has_recovery_question`, `recovery_question` text) |
| `POST` `register` | New `portal_users` row |
| `POST` `login` / `logout` | Session |
| `POST` `change_password` | Logged-in; needs current password |
| `POST` `update_recovery` | Logged-in; set/change security question + answer |
| `POST` `recovery_challenge` | Email → returns security **question** if configured |
| `POST` `forgot_password` | Creates 6-digit **`reset_tokens`** row + sends `mail()` |
| `POST` `reset_password` | New password + either **`token`** (email code) or **`recovery_answer`** |

### Booking CTAs on the marketing site

- **`customer-site/assets/js/booking-cta.js`** — `[data-book-cta]`: checks `POST patient_auth.php` `{ action: "me" }`, then routes to book flow or login with `next=`.

### APIs (patient) — summary

| File | Role |
|------|------|
| `api/patient_auth.php` | Auth, profile, password, recovery, CSRF; writes **`auth_login_log`** on portal login |
| `api/patient_appointments.php` | Availability, create booking, list by status, cancel; GCash proof upload; merges admin appointments for linked patients |
| `api/auth_logs.php` | Staff-only: paginated **`auth_login_log`** (`?realm=staff` \| `portal`) |
| `api/admin_alerts.php` | Staff: list / mark-read **`admin_staff_alerts`** |
| `api/portal_options.php` | Public (no staff session): payment methods, referral sources, optional **cashless QR** path for booking UI |

---

## Tech stack

- **Staff & customer UI:** HTML5, Tailwind CDN, vanilla JS, Inter (customer site)
- **Backend:** PHP + MySQLi, JSON `respond()` APIs
- **Auth:** PHP sessions — separate session keys for staff vs portal

### Admin shell (layout & sidebar)

Staff pages under `admin/` share a fixed **sidebar** (`#sidebar`) and **main column** (`#main-content`) with a top bar (`#mainHeader`) and hamburger control (`#sidebar-toggle`). Layout is **Tailwind classes + extra rules** in `assets/css/style.css` so Chrome/Edge flex behavior stays predictable (for example `min-w-0` on the main column where needed).

- **Desktop:** The menu button toggles **`body.admin-sidebar-collapsed-desktop`**. CSS ensures the sidebar can slide fully off-screen even when Tailwind’s `md:translate-x-0` would otherwise win.
- **Mobile (narrow viewports):** The same button opens/closes a drawer; **`#sidebarBackdrop`** closes the drawer when tapped.
- **Stacking:** The header is given a higher stacking order than the fixed sidebar so the hamburger stays clickable when panels overlap during transitions.

**JavaScript (`assets/js/app.js`):** `initSidebar()` wires the toggle, resize behavior, and initial widths. It only sets `document.body.dataset.adminSidebarBound` after both `#sidebar` and `#main-content` exist, so a first pass that runs before the DOM is ready does not “lock” a broken state. A **`setTimeout(..., 0)`** after `DOMContentLoaded` runs `initSidebar()` again if binding never completed (for example when another script injects the chrome in its own `DOMContentLoaded` handler). If you inject sidebar/header **later** (e.g. after `fetch`), call **`window.initAdminSidebar()`** once right after the HTML is in the document.

---

## Troubleshooting

**Database connection failed**  
Check `includes/db.php` and that MySQL is running.

**Blank page / 404 on API**  
Use `http://localhost/.../api/...` (not `file://`). Confirm Apache document root includes the project.

**Staff login does not work**  
Cookies enabled; use `http://localhost/...`.

**Portal register/login fails**  
Ensure `portal_users` exists. Check the browser **Network** tab for JSON errors.

**Booking fails**  
Ensure `patient_appointments` exists and the user is logged in on the **same origin** as `api/`.

**Forgot password / email code never arrives**  
PHP `mail()` depends on server SMTP; use **security question** path or configure mail for your host.

**Security question missing on forgot flow**  
User must set it at **register** or in **Portal → Settings** while logged in.

**Access denied (MySQL)**  
Grant privileges, e.g. `GRANT ALL ON edroso_dental.* TO 'root'@'localhost';`

**Admin sidebar overlaps content or hamburger does nothing**  
Hard-refresh the staff page (`Ctrl+F5`) so `assets/css/style.css` and `assets/js/app.js` reload. Confirm the page includes both `#sidebar` and `#main-content`. If the shell is injected asynchronously, call `window.initAdminSidebar()` after injection.

---

## Security notes

- Security questions are **convenience** recovery for dev/low-email environments; they are weaker than email/SMS MFA. Harden for production as needed.
- Rate limiting on **answer-based** reset: repeated wrong answers block further attempts for a short period (see `patient_auth.php`).
- Never commit real **`.env`**, database passwords, or production keys (`.gitignore` already excludes `.env`).
