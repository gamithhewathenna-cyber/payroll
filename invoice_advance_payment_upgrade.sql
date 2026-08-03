-- Run in phpMyAdmin → SQL tab

-- Track advance/deposit payments a client makes against an invoice before it's
-- fully settled (amount + the date it was received). Edited on the invoice
-- edit page (invoice_form.php); shown on the invoices list.
ALTER TABLE invoices
ADD COLUMN advance_amount DECIMAL(12,2) NULL DEFAULT NULL,
ADD COLUMN advance_date DATE NULL DEFAULT NULL;
