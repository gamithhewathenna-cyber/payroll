-- Run in phpMyAdmin → SQL tab

-- 1. Multiple CC email addresses per client (comma-separated)
ALTER TABLE clients
ADD COLUMN cc_emails TEXT NULL AFTER email;

-- 2. Track when each reminder was sent, so they only go out once
ALTER TABLE invoices
ADD COLUMN reminder1_sent_at DATETIME NULL AFTER paid_date,
ADD COLUMN reminder2_sent_at DATETIME NULL AFTER reminder1_sent_at;

-- 3. Audit log of every invoice/reminder email sent
CREATE TABLE IF NOT EXISTS invoice_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    email_type ENUM('invoice','reminder1','reminder2') NOT NULL,
    sent_to VARCHAR(500),
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

-- 4. Default settings for reminders + always-cc addresses
INSERT INTO settings (setting_key, setting_value) VALUES
    ('invoice_reminders_enabled', '1'),
    ('reminder_days_before_1', '3'),
    ('reminder_days_before_2', '1'),
    ('invoice_cc_emails', 'accounts@creativelements.co,reach@creativelements.co'),
    ('cron_token', MD5(RAND()))
ON DUPLICATE KEY UPDATE setting_value = setting_value;
