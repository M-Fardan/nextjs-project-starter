-- Ensure proper character set and collation
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create employees table if not exists
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    deleted_at DATETIME NULL,
    deleted_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    failed_login_attempts INT DEFAULT 0,
    password_reset_token VARCHAR(100) NULL,
    password_reset_expires DATETIME NULL,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_role (role),
    INDEX idx_deleted_at (deleted_at),
    FOREIGN KEY (deleted_by) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create activity_log table for comprehensive action tracking
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    old_values TEXT NULL,
    new_values TEXT NULL,
    INDEX idx_admin_action (admin_id, action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (admin_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create wash_types table for service offerings
CREATE TABLE IF NOT EXISTS wash_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    duration_minutes INT NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_status (status),
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create transactions table with enhanced tracking
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    customer_name VARCHAR(100) NULL,
    customer_phone VARCHAR(20) NULL,
    vehicle_type VARCHAR(50) NOT NULL,
    license_plate VARCHAR(20) NOT NULL,
    wash_type_id INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0,
    final_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT NULL,
    cancellation_reason TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_date (employee_id, transaction_date),
    INDEX idx_status_date (status, transaction_date),
    INDEX idx_license_plate (license_plate),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    FOREIGN KEY (wash_type_id) REFERENCES wash_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (completed_by) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (cancelled_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create payment_methods table
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    requires_verification TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_status (status),
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create shifts table for employee scheduling
CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_status (status),
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create employee_shifts table for shift assignments
CREATE TABLE IF NOT EXISTS employee_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    shift_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('scheduled', 'completed', 'absent', 'late') DEFAULT 'scheduled',
    check_in DATETIME NULL,
    check_out DATETIME NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_employee_date (employee_id, date),
    INDEX idx_shift_date (shift_id, date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add necessary indexes for better performance
ALTER TABLE employees 
    ADD INDEX idx_status_role (status, role),
    ADD INDEX idx_created_at (created_at);

ALTER TABLE transactions 
    ADD INDEX idx_dates (transaction_date, completed_at, cancelled_at),
    ADD INDEX idx_amounts (amount, final_amount);

-- Update existing data
UPDATE employees SET deleted_at = NULL WHERE deleted_at IS NOT NULL;
UPDATE employees SET status = 1 WHERE status IS NULL;
UPDATE employees SET role = 'employee' WHERE role IS NULL;

-- Insert default payment methods if not exists
INSERT IGNORE INTO payment_methods (name, description) VALUES
('Cash', 'Cash payment'),
('Credit Card', 'Credit card payment'),
('Debit Card', 'Debit card payment'),
('Digital Wallet', 'Digital wallet payment');

-- Insert default shifts if not exists
INSERT IGNORE INTO shifts (name, start_time, end_time) VALUES
('Morning', '06:00:00', '14:00:00'),
('Afternoon', '14:00:00', '22:00:00');

SET FOREIGN_KEY_CHECKS = 1;

-- Add triggers for audit logging
DELIMITER //

CREATE TRIGGER IF NOT EXISTS employees_after_update
AFTER UPDATE ON employees
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status OR OLD.role != NEW.role THEN
        INSERT INTO activity_log (admin_id, action, details, entity_type, entity_id, old_values, new_values)
        VALUES (
            NEW.updated_by,
            CASE 
                WHEN OLD.status != NEW.status THEN 'update_status'
                WHEN OLD.role != NEW.role THEN 'update_role'
            END,
            CONCAT('Updated employee: ', NEW.name),
            'employees',
            NEW.id,
            JSON_OBJECT(
                'status', OLD.status,
                'role', OLD.role
            ),
            JSON_OBJECT(
                'status', NEW.status,
                'role', NEW.role
            )
        );
    END IF;
END//

CREATE TRIGGER IF NOT EXISTS transactions_after_update
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO activity_log (admin_id, action, details, entity_type, entity_id, old_values, new_values)
        VALUES (
            COALESCE(NEW.completed_by, NEW.cancelled_by),
            CONCAT('transaction_', LOWER(NEW.status)),
            CONCAT('Updated transaction status: ', NEW.status),
            'transactions',
            NEW.id,
            JSON_OBJECT('status', OLD.status),
            JSON_OBJECT('status', NEW.status)
        );
    END IF;
END//

DELIMITER ;
