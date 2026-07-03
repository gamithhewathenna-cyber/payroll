-- Run in phpMyAdmin → SQL tab

-- Add approval status to freelance_payments
ALTER TABLE freelance_payments 
ADD COLUMN approval_status ENUM('approved','pending_approval') DEFAULT 'approved' AFTER payment_status;

-- Vendor submitted invoices (pending approval)
CREATE TABLE IF NOT EXISTS vendor_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT NOT NULL,
    project_name VARCHAR(200) NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    invoice_date DATE NOT NULL,
    payment_amount DECIMAL(10,2) NOT NULL,
    month VARCHAR(7) NOT NULL,
    invoice_file VARCHAR(500),
    invoice_file_name VARCHAR(255),
    submission_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    rejection_reason TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    reviewed_by VARCHAR(100),
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
);
