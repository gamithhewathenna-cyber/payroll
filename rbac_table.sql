-- Run in phpMyAdmin → SQL tab

-- 1. Roles table
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    role_slug VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Role permissions (which pages/actions each role can access)
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    page_key VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 1,
    can_add TINYINT(1) DEFAULT 0,
    can_edit TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_page (role_id, page_key)
);

-- 3. Add role_id and job_title to users
ALTER TABLE users 
ADD COLUMN role_id INT NULL AFTER role,
ADD COLUMN job_title VARCHAR(100) NULL AFTER role_id,
ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0 AFTER job_title;

-- Mark existing admin as super admin
UPDATE users SET is_super_admin = 1 WHERE role = 'admin';

-- 4. Pending approvals queue
CREATE TABLE IF NOT EXISTS pending_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submitted_by INT NOT NULL,
    submitted_name VARCHAR(100),
    page_key VARCHAR(100) NOT NULL,
    action_type ENUM('add','edit','delete') NOT NULL,
    record_id INT NULL,
    record_table VARCHAR(100) NOT NULL,
    record_data LONGTEXT,
    original_data LONGTEXT,
    description TEXT,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by VARCHAR(100),
    reviewed_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE
);

-- 5. Default roles
INSERT INTO roles (role_name, role_slug, description) VALUES
('Accountant', 'accountant', 'Access to expenses, payroll, reports'),
('Digital Marketing Executive', 'digital_marketing', 'Access to expenses and clients only'),
('Supervisor', 'supervisor', 'View access to most pages'),
('Manager', 'manager', 'Broad access except settings');
