-- Links a freelance payment to the client invoice/project it was incurred for,
-- so freelance costs can be traced back to the client invoice on the Reports page.
ALTER TABLE freelance_payments ADD COLUMN client_invoice_id INT NULL AFTER bank_reference;
