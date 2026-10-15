-- A freelance payment can now be linked to MULTIPLE client invoices/projects
-- (e.g. one freelancer invoice covers work billed across 2-3 client invoices),
-- so the single client_invoice_id column is replaced with a proper link table.

CREATE TABLE IF NOT EXISTS freelance_payment_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    freelance_payment_id INT NOT NULL,
    client_invoice_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_payment_invoice (freelance_payment_id, client_invoice_id)
);

-- Only run the two statements below if you already applied the earlier
-- "ALTER TABLE freelance_payments ADD COLUMN client_invoice_id" fix.
-- They migrate any existing single links into the new table, then remove the old column.
INSERT INTO freelance_payment_invoices (freelance_payment_id, client_invoice_id)
SELECT id, client_invoice_id FROM freelance_payments WHERE client_invoice_id IS NOT NULL;

ALTER TABLE freelance_payments DROP COLUMN client_invoice_id;
