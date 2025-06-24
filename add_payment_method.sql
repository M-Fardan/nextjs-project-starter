-- Set proper character set and collation
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create payment_methods table for flexible payment method management
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    requires_verification TINYINT(1) NOT NULL DEFAULT 0,
    verification_instructions TEXT NULL,
    min_amount DECIMAL(10,2) NULL,
    max_amount DECIMAL(10,2) NULL,
    fee_percentage DECIMAL(5,2) DEFAULT 0,
    fee_fixed DECIMAL(10,2) DEFAULT 0,
    icon_class VARCHAR(50) NULL,
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_status (status),
    INDEX idx_code (code),
    INDEX idx_display_order (display_order),
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create payment_method_logs table for tracking payment verifications
CREATE TABLE IF NOT EXISTS payment_method_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    payment_method_id INT NOT NULL,
    verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    verification_code VARCHAR(50) NULL,
    verification_notes TEXT NULL,
    verified_by INT NULL,
    verified_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction (transaction_id),
    INDEX idx_payment_method (payment_method_id),
    INDEX idx_verification_status (verification_status),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modify transactions table to use payment_methods
ALTER TABLE transactions
    DROP COLUMN IF EXISTS payment_method,
    ADD COLUMN payment_method_id INT NOT NULL AFTER wash_type_id,
    ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_method_id,
    ADD COLUMN payment_notes TEXT NULL AFTER payment_reference,
    ADD COLUMN payment_verified TINYINT(1) DEFAULT 0 AFTER payment_notes,
    ADD COLUMN payment_verified_at DATETIME NULL AFTER payment_verified,
    ADD COLUMN payment_verified_by INT NULL AFTER payment_verified_at,
    ADD FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
    ADD FOREIGN KEY (payment_verified_by) REFERENCES employees(id) ON DELETE SET NULL,
    ADD INDEX idx_payment_method (payment_method_id),
    ADD INDEX idx_payment_verified (payment_verified);

-- Insert default payment methods
INSERT INTO payment_methods 
(name, code, description, requires_verification, verification_instructions, min_amount, max_amount, fee_percentage, fee_fixed, icon_class, display_order) 
VALUES
(
    'Cash', 
    'CASH', 
    'Cash payment', 
    0, 
    NULL,
    NULL,
    NULL,
    0,
    0,
    'fas fa-money-bill',
    1
),
(
    'Debit Card', 
    'DEBIT', 
    'Debit card payment', 
    1, 
    'Please swipe card and enter PIN',
    10000,
    NULL,
    0,
    2000,
    'fas fa-credit-card',
    2
),
(
    'Credit Card', 
    'CREDIT', 
    'Credit card payment', 
    1, 
    'Please swipe card and enter PIN',
    10000,
    NULL,
    2.5,
    0,
    'fas fa-credit-card',
    3
),
(
    'QRIS', 
    'QRIS', 
    'QRIS payment', 
    1, 
    'Scan QR code and show payment confirmation',
    1000,
    NULL,
    0.7,
    0,
    'fas fa-qrcode',
    4
),
(
    'OVO', 
    'OVO', 
    'OVO digital payment', 
    1, 
    'Show payment confirmation screen',
    1000,
    NULL,
    0.7,
    0,
    'fas fa-mobile-alt',
    5
),
(
    'GoPay', 
    'GOPAY', 
    'GoPay digital payment', 
    1, 
    'Show payment confirmation screen',
    1000,
    NULL,
    0.7,
    0,
    'fas fa-mobile-alt',
    6
),
(
    'Dana', 
    'DANA', 
    'Dana digital payment', 
    1, 
    'Show payment confirmation screen',
    1000,
    NULL,
    0.7,
    0,
    'fas fa-mobile-alt',
    7
);

-- Create trigger for payment verification logging
DELIMITER //

CREATE TRIGGER payment_verification_after_update
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
    IF OLD.payment_verified != NEW.payment_verified THEN
        INSERT INTO payment_method_logs 
        (transaction_id, payment_method_id, verification_status, verified_by, verified_at)
        VALUES (
            NEW.id,
            NEW.payment_method_id,
            IF(NEW.payment_verified = 1, 'verified', 'pending'),
            NEW.payment_verified_by,
            NEW.payment_verified_at
        );
        
        -- Log the activity
        INSERT INTO activity_log 
        (admin_id, action, details, entity_type, entity_id, old_values, new_values)
        VALUES (
            NEW.payment_verified_by,
            'payment_verification',
            CONCAT('Payment verification for transaction #', NEW.id),
            'transactions',
            NEW.id,
            JSON_OBJECT('payment_verified', OLD.payment_verified),
            JSON_OBJECT(
                'payment_verified', NEW.payment_verified,
                'payment_verified_at', NEW.payment_verified_at,
                'payment_reference', NEW.payment_reference
            )
        );
    END IF;
END//

DELIMITER ;

-- Update existing transactions to use default cash payment method
UPDATE transactions t
SET t.payment_method_id = (SELECT id FROM payment_methods WHERE code = 'CASH'),
    t.payment_verified = 1,
    t.payment_verified_at = t.created_at,
    t.payment_verified_by = t.employee_id
WHERE t.payment_method_id IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
