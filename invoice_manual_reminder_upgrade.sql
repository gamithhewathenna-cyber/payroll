-- Run in phpMyAdmin → SQL tab
-- Adds a distinct email_type for reminders sent manually via the invoice page button,
-- kept separate from 'reminder1'/'reminder2' so the automatic schedule's audit trail stays clean.

ALTER TABLE invoice_emails
MODIFY COLUMN email_type ENUM('invoice','reminder1','reminder2','reminder_manual') NOT NULL;
