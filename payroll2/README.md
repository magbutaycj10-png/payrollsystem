# 🗂 Automated Payroll System — PHP + MySQL Edition
**CSV Integration · Predictive Budget Forecasting · Full CRUD**

---

## ⚡ Quick Setup (XAMPP / WAMP)

### 1. Copy files
Place the entire `payroll/` folder inside:
- **XAMPP:** `C:\xampp\htdocs\payroll\`
- **WAMP:**  `C:\wamp64\www\payroll\`

### 2. Create the database
1. Start Apache + MySQL in XAMPP/WAMP Control Panel
2. Open **phpMyAdmin** → `http://localhost/phpmyadmin`
3. Click **Import** → choose `database.sql` → click **Go**

   _Or run from terminal:_
   ```
   mysql -u root -p < database.sql
   ```

### 3. Configure DB connection (if needed)
Edit `includes/db.php` — only change if your MySQL credentials differ:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'payroll_system');
define('DB_USER', 'root');
define('DB_PASS', '');   // Default XAMPP = no password
```

### 4. Open the app
Navigate to: **http://localhost/payroll/**

Sign in with the admin email and password set during installation, then change the password in **Settings**.

---

## 📁 File Structure

```
payroll/
├── index.php               ← Login page
├── dashboard.php           ← Dashboard + forecast chart
├── employee.php            ← Employee CRUD
├── attendance-upload.php   ← CSV/Excel upload + column mapping
├── payroll.php             ← Payroll processing (bonus/deduction)
├── reports.php             ← Payslips + CSV export
├── history.php             ← Bonus & deduction history
├── print-history.php       ← Audit log of prints
├── settings.php            ← Rates, password, company info
├── database.sql            ← Run this first!
├── assets/css/style.css    ← Shared stylesheet
├── includes/
│   ├── db.php              ← PDO connection + helpers
│   └── sidebar.php         ← Shared navigation
└── api/
    ├── save-attendance.php ← POST: saves CSV rows to DB
    ├── create-period.php   ← POST: creates payroll period
    ├── update-payroll.php  ← POST: bonus/deduction/finalize
    └── log-print.php       ← POST: logs print events
```

---

## 🔧 Features

| Feature | Details |
|---|---|
| **CSV / Excel Upload** | Drag-and-drop, auto column detection, preview before save |
| **Column Mapping** | Map any column header to the right field |
| **MySQL Persistence** | All data stored in relational DB (no localStorage) |
| **Payroll Periods** | Create, lock, and switch between periods |
| **Auto Deductions** | SSS, PhilHealth, Pag-IBIG computed from rates in Settings |
| **Bonus / Deduction** | Per employee or bulk; saved to history + payroll |
| **Payslips** | Individual or batch print; 58mm thermal-compatible |
| **CSV Export** | Download any period as CSV |
| **Predictive Forecasting** | Linear regression on finalized periods → next month estimate |
| **Trend Chart** | Bar chart with gross, net, forecast, and trend line |
| **Print Audit Log** | Every print/export action is logged |
| **Settings** | OT rate, late rate, SSS/PH/PI %, password, company name |

---

## 📊 CSV Template (column order)

```
ID, Name, Hours Worked, Overtime, Late Hours, Gross Pay, Tax
001, Juan dela Cruz, 160, 10, 2, 24000, 2400
```
Download from the Upload page using the "Download Template" button.

---

## 🔐 Security Notes

- Sessions used for auth (PHP `$_SESSION`)
- All DB queries use **PDO prepared statements** (SQL injection safe)
- Password stored in `settings` table — change immediately after setup
- For production: add HTTPS, use a non-root DB user, and hash the password

---

## 🗄 Database Tables

| Table | Purpose |
|---|---|
| `employees` | Employee master list |
| `payroll_periods` | Monthly/period tracking |
| `attendance` | Raw uploaded attendance data |
| `payroll` | Computed payroll per period |
| `bonus_deduction_history` | All bonus/deduction events |
| `print_log` | Print/export audit trail |
| `settings` | Key-value config store |
| `v_latest_payroll` | View: most recent record per employee |
| `v_monthly_totals` | View: totals per period (used for forecast) |
