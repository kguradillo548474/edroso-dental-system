-- TC-063: widen payment_method for Cash / Card / GCash / Insurance (run once in phpMyAdmin)
ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash';

-- Map legacy enum values to new labels (safe to re-run if already VARCHAR)
UPDATE payments SET payment_method = 'Card' WHERE payment_method IN ('Credit Card');
UPDATE payments SET payment_method = 'Insurance' WHERE payment_method IN ('PhilHealth', 'HMO', 'Bank Transfer');
