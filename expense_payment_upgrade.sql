-- Run in phpMyAdmin → SQL tab

-- Track how a "Paid" expense was actually settled (bank transfer vs. other),
-- the date it was paid, and the bank reference number when applicable.
-- Mirrors freelance_payments' payment_date/bank_reference columns.
ALTER TABLE expenses
ADD COLUMN payment_date DATE NULL DEFAULT NULL AFTER approved_at,
ADD COLUMN payment_method VARCHAR(30) NULL DEFAULT NULL AFTER payment_date,
ADD COLUMN bank_reference VARCHAR(255) NULL DEFAULT NULL AFTER payment_method;
