# ProcureERP — Cloud-Based Procurement & Logistics ERP

A complete PHP + MySQL ERP for managing special procurement orders with real-time shipping cost estimation, document management, and a state-machine order workflow.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Pure PHP 8+ (no framework) |
| Database | MySQL (PDO) |
| Frontend | HTML5 + Tailwind CSS (CDN) + vanilla JS |
| Icons | Lucide (CDN) |
| Auth | PHP sessions (admin / employee roles) |
| File storage | Local `uploads/` folder |

---

## Requirements

- PHP 8.0 or higher (with `pdo_mysql` extension)
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache / Nginx / PHP built-in)
- Writable `uploads/` directory

---

## Installation

### 1. Clone / Copy files

```bash
git clone <repo-url> /var/www/erp
cd /var/www/erp
```

### 2. Set folder permissions

```bash
chmod 775 uploads/
```

### 3. Configure environment variables (recommended)

Set these in your server config, `.env` loader, or PHP-FPM pool:

```
DB_HOST=localhost
DB_NAME=order_erp
DB_USER=your_mysql_user
DB_PASS=your_mysql_password
```

Or edit `config/db.php` directly to hard-code credentials *(not recommended for production)*.

### 4. Run the installer

Navigate to:

```
http://your-domain/install.php
```

This will:
- Create the database (`order_erp` by default)
- Apply `schema.sql`
- Insert default settings (USD rate, SNS anchors)
- Create the default admin user (`admin` / `admin123`)
- Write an `install.lock` file to prevent re-installation

> ⚠️ **Change the admin password immediately after installation!**

### 5. Secure `install.php`

The installer creates `install.lock` automatically. To prevent any access:

```bash
rm install.php  # or restrict via web server
```

---

## Default Credentials

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `admin123` |

**Change immediately after first login.**

---

## File Structure

```
index.php               → Redirect to login or dashboard
login.php               → Login form
logout.php              → Destroy session
install.php             → One-time DB setup
schema.sql              → Database schema
config/
  db.php                → PDO connection helper
  auth.php              → Session helpers + flash messages
includes/
  calc.php              → All shipping calculation functions
  header.php            → HTML head + sidebar nav
  footer.php            → Closing HTML + JS
pages/
  dashboard.php         → Order list (All / Pending / Archive)
  new_order.php         → Create new order (Draft)
  order_detail.php      → Order detail + state-machine actions
  brands.php            → Admin: CRUD for brands & discounts
  settings.php          → Admin: exchange rate & SNS anchors
  estimator.php         → Standalone shipping calculator
uploads/                → Uploaded files (writable at runtime)
```

---

## Order Workflow (State Machine)

```
Draft
  │  (Employee uploads Customer PO)
  ▼
Requested
  │  (Admin marks as Ordered)
  ▼
Ordered
  │  (Admin adds tracking + uploads Supplier Invoice)
  ▼
In Transit (USA)
  │  (Employee uploads Forwarder doc + marks Arrived)
  ▼
At Forwarder
  │  (Employee requests Ship-Out — admin notified)
  ▼
Ship-Out Requested
```

Role enforcement is done **server-side** on every transition.

---

## Calculation Logic

### SelfShip PRO
- Volumetric weight = (L × W × H) / 5000
- Chargeable weight = ceil(max(actual, volumetric))
- First kg: $7.23; extra: $4.85/kg (5% bulk discount if ≥ 15 kg)
- **Ineligible if** weight > 35 kg **or** any dimension > 120 cm

### Shop&Ship
- Chargeable = ceil(actual × 10) / 10 kg
- Priced via anchor table (linear interpolation)

### Tax / Customs
- VAT: 5% on (discounted price + shipping)
- Customs: 5% on discounted price — **only if** discounted price > AED 1,000

---

## Security Notes

- All DB queries use PDO prepared statements (no SQL injection)
- All output uses `htmlspecialchars()` (no XSS)
- File uploads: MIME type validated, stored with `uniqid()` filename
- Session regenerated on login
- Role checks enforced server-side on every protected page/action
- `install.php` is blocked after first run via `install.lock`

---

## License

MIT
