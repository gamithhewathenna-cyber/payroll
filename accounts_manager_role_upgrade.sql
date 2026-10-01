-- Run in phpMyAdmin → SQL tab

-- Widens users.role to a plain VARCHAR so it safely accepts the new
-- 'accounts_manager' value whether the column is currently VARCHAR already
-- or a strict ENUM('admin','staff','employee'). Existing values (admin,
-- staff, employee) are preserved as-is.
ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'staff';
