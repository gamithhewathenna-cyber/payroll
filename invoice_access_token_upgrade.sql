-- Run in phpMyAdmin → SQL tab

-- Secure per-invoice token so clients can open "View Invoice" from an email
-- link without logging in. Generated lazily the first time an invoice/reminder
-- email is sent (see getInvoiceAccessToken() in config.php).
ALTER TABLE invoices
ADD COLUMN access_token VARCHAR(64) NULL DEFAULT NULL AFTER reminder2_sent_at;
