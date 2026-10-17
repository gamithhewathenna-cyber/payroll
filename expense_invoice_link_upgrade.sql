-- An expense (Bank Transfer page) can be linked to one or more client invoices/projects,
-- the same way freelance payments already link to invoices via freelance_payment_invoices.
CREATE TABLE IF NOT EXISTS expense_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    client_invoice_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_expense_invoice (expense_id, client_invoice_id)
);
