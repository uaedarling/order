# Special Order Management System

A PHP + MySQL web application for employees to calculate shipping costs and submit special orders, and for admins to manage those orders.

---

## File Structure

```
/install.php              — One-time installer (creates DB, tables, seeds data)
/login.php                — Login page for admin and employees
/logout.php               — Destroys session and redirects to login
/index.php                — Employee dashboard: order form + live AJAX calculation
/admin.php                — Admin dashboard: manage orders, set tracking numbers
/calculate.php            — AJAX endpoint for live shipping calculation
/config/db.php            — PDO database connection
/includes/functions.php   — All calculation functions (SelfShip PRO, Shop&Ship)
/includes/auth.php        — Session helpers (requireLogin, requireAdmin, isAdmin)
/uploads/                 — PO file uploads directory
/schema.sql               — MySQL schema reference
```

---

## Setup Instructions

### Requirements

- PHP 8.0+ with PDO and PDO_MySQL extensions
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache, Nginx, or PHP built-in server)

### 1. Clone / Deploy

Place all files in your web server's document root (e.g., `/var/www/html/` or `htdocs/`).

### 2. Run the Installer

Open your browser and navigate to:

```
http://your-domain/install.php
```

Fill in your database credentials:
- **DB Host** — usually `localhost`
- **DB Name** — e.g. `order_management`
- **DB User** — your MySQL username
- **DB Password** — your MySQL password

Click **Run Installer**. The installer will:
1. Create the database (if it doesn't exist)
2. Create all tables (`brands`, `orders`, `users`)
3. Seed brand data (Apple, Samsung, Sony, LG, Dell)
4. Create default admin and employee accounts
5. Write the correct credentials to `config/db.php`

### 3. Log In

Navigate to `/login.php` and use the default credentials below.

---

## Default Credentials

| Role     | Username   | Password   |
|----------|------------|------------|
| Admin    | `admin`    | `admin123` |
| Employee | `employee` | `emp123`   |

> ⚠️ Change these passwords after first login in production.

---

## Manual Database Configuration

If you prefer to configure the database manually, edit `config/db.php`:

```php
<?php
$host = 'localhost';
$db   = 'order_management';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
```

You can also import `schema.sql` directly into MySQL:

```bash
mysql -u root -p < schema.sql
```

---

## Features

### Employee Dashboard (`index.php`)
- Select brand (with discount %), enter part number, link, price, weight, and dimensions
- **Live AJAX calculation** updates as you type — no page refresh needed
- Compares **SelfShip PRO** vs **Shop&Ship** shipping costs with AED totals (VAT + Customs included)
- Highlights the cheapest option with a green ✓ badge
- Submit order with agreed price and optional PO file upload (JPG, PNG, PDF)

### Admin Dashboard (`admin.php`)
- Stats cards: Total Orders, Pending, Ordered
- Tabs: Pending Orders | All Orders
- Inline tracking number input — saving a tracking number automatically sets status to **Ordered**
- View PO file links
- Admin can also use the employee form to submit orders

### Calculation Logic
- **USD → AED**: rate of 3.699
- **SelfShip PRO**: volumetric weight = (L×W×H)/5000; chargeable = max(actual, volumetric), rounded up; first kg $7.23, extra $4.85/kg (5% bulk discount ≥ 15 kg); ineligible if weight > 35 kg or any dimension > 120 cm
- **Shop&Ship**: chargeable = actual kg rounded up to 0.1 kg; priced via anchor table with linear interpolation
- **VAT**: 5% on (discounted price + shipping)
- **Customs**: 5% on discounted price if > AED 1,000

---

## Security Notes

- All database queries use PDO prepared statements
- Passwords are hashed with `password_hash()` (bcrypt)
- File uploads are validated for type and size
- `install.php` should be removed or access-restricted after installation