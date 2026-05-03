# Edroso Dental Clinic — Management System
## Full-Stack PHP + MySQL Web Application

This repository contains two areas:

1. **Staff / admin app** (root HTML + `api/` except portal endpoints) — appointments, patients, dentists, payments, dashboard.
2. **Patient-facing site** (`customer-site/`) — marketing pages, registration, login, portal dashboard, and online booking.

---

## Project structure

```
edroso-dental-system/
├── api/
│   ├── auth.php                 ← Staff login / session (users table)
│   ├── patient_auth.php       ← Portal register / login / logout / session (portal_users)
│   ├── patient_appointments.php ← Portal bookings, availability by date, cancel
│   ├── appointments.php
│   ├── dashboard.php
│   ├── dentists.php
│   ├── patients.php
│   ├── payments.php
│   └── …
├── assets/                      ← Staff app (css, js, layout)
├── customer-site/             ← Public site + patient portal
│   ├── index.html, about.html, services.html, contact.html
│   ├── login.html, register.html
│   ├── assets/
│   │   ├── css/style.css
│   │   └── js/
│   │       ├── main.js        ← Nav, footer, scroll helpers
│   │       └── booking-cta.js ← Auth check before opening book flow
│   └── portal/
│       ├── dashboard.html     ← Upcoming / past appointments, book CTA
│       └── book.html          ← Date/time + booking form
├── includes/
│   └── db.php                   ← DB + JSON headers + session bootstrap
├── sql/
│   ├── portal_users.sql         ← Run for patient accounts (if not already)
│   └── patient_appointments.sql ← Run for portal bookings
├── appointments.html, dashboard.html, dentists.html, …
├── database.sql                 ← Core clinic DB (run first)
├── .htaccess
└── README.md
```

---

## Installation

### Requirements

- **PHP** 7.4+ (8.x recommended)
- **MySQL** 5.7+ or MariaDB 10.3+ (JSON columns used for portal appointment details)
- **Apache** with PHP (XAMPP / WAMP / Laragon)

### Step 1 — Database (core clinic)

1. Open **phpMyAdmin** → **Import** → choose `database.sql` → **Go**  
2. This creates `edroso_dental` and staff-related tables + sample data.

### Step 2 — Database (patient portal)

Run these in phpMyAdmin on `edroso_dental` **after** `database.sql` (order matters: `portal_users` before `patient_appointments` because of the foreign key):

1. `sql/portal_users.sql` — `portal_users` (patient accounts)
2. `sql/patient_appointments.sql` — `patient_appointments` (portal booking requests)

### Step 3 — Configure `includes/db.php`

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'edroso_dental');
```

### Step 4 — Deploy

**XAMPP example:** project folder under `C:/xampp/htdocs/edroso-dental-system/`

### Step 5 — URLs

| Area | Example URL |
|------|----------------|
| Staff login | `http://localhost/edroso-dental-system/admin/login.html` |
| Customer site home | `http://localhost/edroso-dental-system/customer-site/index.html` |
| Patient register | `…/customer-site/register.html` |
| Patient login | `…/customer-site/login.html` |
| Portal dashboard | `…/customer-site/portal/dashboard.html` |
| Book appointment | `…/customer-site/portal/book.html` (requires portal login) |

---

## Default staff login

| Username | Password   | Role  |
|----------|------------|-------|
| `admin`  | `password` | Admin |
| `edroso` | `password` | Admin |

Change passwords in production (e.g. bcrypt hashes in `users`).

---

## Features (staff app)

| Module | Features |
|--------|----------|
| **Dashboard** | Stats, funnel, recent appointments |
| **Patients** | CRUD, search, filters, pagination |
| **Appointments** | Calendar / list, CRUD, dentist filters |
| **Dentists** | Profiles, photos, workload |
| **Payments** | CRUD, stats, filters |
| **Auth** | Session-based staff login via `api/auth.php` |

---

## Patient portal and customer site

### Registration and login

- **`customer-site/register.html`** — Creates rows in `portal_users` via `api/patient_auth.php` (`action: register`).
- **`customer-site/login.html`** — `api/patient_auth.php` (`action: login`). Sets `$_SESSION['portal_user_id']` and `portal_user_name`.
- Session cookies use the path set in `includes/db.php` (same site for `customer-site/` and `api/`).

Optional query string after login:

- `login.html?next=portal/book.html` — After successful login (or if already logged in), redirect to that path under `customer-site/` (validated server-side pattern in login script: `portal/*.html` only).

### Booking and dashboard

- **`customer-site/portal/book.html`** — Chooses date/time, fills details, submits to `api/patient_appointments.php` (JSON POST). Requires portal session (`patient_auth.php?action=me`).
- **`customer-site/portal/dashboard.html`** — Lists pending/scheduled and past (completed/cancelled) appointments; cancel calls `POST api/patient_appointments.php?action=cancel&id=…`.

### APIs (patient)

| File | Role |
|------|------|
| `api/patient_auth.php` | `GET ?action=me`, `POST` JSON: `register`, `login`, `logout`, `me` (POST `me` used by booking CTA pre-check) |
| `api/patient_appointments.php` | `GET ?date=` booked times; `GET ?user_id=&status=`; JSON POST create booking; `POST ?action=cancel&id=` |

### Booking CTAs on the marketing site

- **`customer-site/assets/js/booking-cta.js`** — Listens for clicks on `[data-book-cta]`. Calls `POST patient_auth.php` with `{ action: "me" }`. If `data.id` exists → go to `portal/book.html`; else → `login.html?next=portal/book.html`.
- **`customer-site/index.html`** — Hero and banner “Book an Appointment” use `data-book-cta`.
- Portal dashboard book links also use `data-book-cta` (script path `../assets/js/booking-cta.js` from `portal/`).

---

## Tech stack

- **Staff UI:** HTML5, Tailwind CDN, vanilla JS, Font Awesome (where used)
- **Customer site:** HTML5, Tailwind CDN, Inter, vanilla JS
- **Backend:** PHP + MySQLi, JSON APIs
- **Auth:** PHP sessions (staff keys vs `portal_user_*` keys)

---

## Troubleshooting

**Database connection failed**  
Check `includes/db.php` and that MySQL is running.

**Blank page / 404 on API**  
Use `http://localhost/.../api/...` (not `file://`). Confirm Apache document root includes the project.

**Staff login does not work**  
Cookies must be enabled; use `http://localhost/...`.

**Portal register/login fails**  
Ensure `portal_users` table exists (`sql/portal_users.sql`). Check browser network tab for JSON errors.

**Booking fails**  
Ensure `patient_appointments` exists (`sql/patient_appointments.sql`). User must be logged in on the customer site (same origin as `api/`).

**Access denied (MySQL)**  
Grant privileges, e.g. `GRANT ALL ON edroso_dental.* TO 'root'@'localhost';`
