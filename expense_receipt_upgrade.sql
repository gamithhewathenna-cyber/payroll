-- Run in phpMyAdmin → SQL tab
ALTER TABLE expenses ADD COLUMN receipt_path VARCHAR(500) NULL AFTER notes;
