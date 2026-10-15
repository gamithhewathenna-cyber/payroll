-- Vendors must now pick which client their invoice/project was for, from the same
-- Clients list used elsewhere in the app. Carried through from the submission into
-- the freelance payment once an admin approves it.
ALTER TABLE vendor_submissions ADD COLUMN client_id INT NULL AFTER freelancer_id;
ALTER TABLE freelance_payments ADD COLUMN client_id INT NULL AFTER freelancer_id;
