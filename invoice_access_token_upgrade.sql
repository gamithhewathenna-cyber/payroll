-- Run in phpMyAdmin → SQL tab

-- Secure per-invoice token so clients can open "View Invoice" from an email
-- link without logging in. Generated lazily the first time an invoice/reminder
-- email is sent (see includes/invoice_access.php).
ALTER TABLE invoices
ADD COLUMN access_token VARCHAR(64) NULL DEFAULT NULL;
