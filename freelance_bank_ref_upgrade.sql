-- Run in phpMyAdmin → SQL tab
ALTER TABLE freelance_payments ADD COLUMN bank_reference VARCHAR(255) NULL AFTER payment_date;
