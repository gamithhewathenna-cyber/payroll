# PayrollPro — Installation Guide (cPanel)

## Files in this package
```
payroll/
├── index.php          ← Login page
├── config.php         ← ⚠️ Edit database settings here
├── database.sql       ← Run this in phpMyAdmin
├── dashboard.php
├── employees.php
├── payroll.php
├── commissions.php
├── allowances.php
├── payslips.php
├── reports.php
├── my_payslips.php    ← Employee portal
├── my_history.php     ← Employee portal
├── logout.php
├── includes/
│   └── layout.php
└── assets/
    ├── css/style.css
    └── js/app.js
```

---

## Step-by-step cPanel Setup

### 1. Upload Files
- Log into cPanel → File Manager
- Navigate to `public_html/`
- Upload the entire `payroll/` folder
  - Or upload to `public_html/payroll/` directly

### 2. Create Database
- cPanel → MySQL Databases
- Create a new database: e.g. `john_payroll`
- Create a database user with a strong password
- Add the user to the database (give ALL PRIVILEGES)
- Note down: database name, username, password

### 3. Import SQL
- cPanel → phpMyAdmin
- Select your new database (left sidebar)
- Click "Import" tab
- Choose `database.sql` → Click "Go"
- You should see all tables created successfully

### 4. Edit config.php
Open `payroll/config.php` and update:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'john_payroll');       // your cPanel DB username
define('DB_PASS', 'your_password');      // your DB password
define('DB_NAME', 'john_payrolldb');     // your database name

define('SITE_NAME', 'PayrollPro');
define('SITE_URL', 'https://yourdomain.com/payroll');  // no trailing slash
```

### 5. Access the System
Visit: `https://yourdomain.com/payroll/`

**Default Admin Login:**
- Email: `admin@company.com`
- Password: `password`

⚠️ **Change the admin password immediately after first login!**
Go to Employees → find the Admin → Edit → set new password.

---

## Usage Workflow

1. **Add Employees** → Employees page → Add Employee
   - Each employee gets a login (email + password you set)
   
2. **Add Allowances** (optional) → Allowances page → Add Allowance for the month

3. **Add Commissions** (optional) → Commissions page → Add Commission

4. **Process Payroll** → Payroll page → Process Payroll
   - Select employee + month
   - Add bonus/deductions if any
   - System auto-pulls base salary, commissions, allowances
   
5. **Generate Payslips** → Payslips page → View Payslip → Print/Save PDF

6. **Mark as Paid** → Payroll page → ✓ Paid button

7. **Reports** → Reports page → Print monthly summary

---

## Employee Login
Employees log in at the same URL: `https://yourdomain.com/payroll/`
They can view:
- Their payslips (print/download)
- Salary history
- Commission earnings

---

## PHP Requirements
- PHP 7.4+ (cPanel usually has 8.x available)
- PDO MySQL extension (enabled by default on most cPanel hosts)
- Sessions enabled

## Security Notes
- Change default admin password immediately
- Use HTTPS (free via cPanel Let's Encrypt SSL)
- config.php is PHP (not accessible via browser)
