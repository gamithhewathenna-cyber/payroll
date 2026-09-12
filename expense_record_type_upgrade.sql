-- Run in phpMyAdmin → SQL tab

-- Distinguishes a normal billable expense from a pure bank-transaction
-- record created via the "Bank Transfer" option in Add Expense. Bank
-- Transfer records are excluded from Revenue/Profit and all expense
-- totals — they only ever appear in the Payment Report tab.
ALTER TABLE expenses
ADD COLUMN record_type VARCHAR(20) NOT NULL DEFAULT 'expense' AFTER billing_type;
