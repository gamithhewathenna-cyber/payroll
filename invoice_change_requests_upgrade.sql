-- Run in phpMyAdmin → SQL tab

-- Mirrors expense_change_requests: when an Accounts Manager creates, edits,
-- deletes, or changes the status of an invoice/quotation, the change is queued
-- here instead of being applied directly. An admin reviews and approves/rejects
-- it from the Invoices page; approving applies the change exactly as submitted.
CREATE TABLE IF NOT EXISTS invoice_change_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NULL,
    change_type ENUM('save','delete','status') NOT NULL,
    payload LONGTEXT NOT NULL,
    requested_by VARCHAR(100) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL
);
