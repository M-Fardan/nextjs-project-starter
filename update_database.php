<?php
session_start();
require_once 'config.php';
require_once 'employee_functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verify admin role
$stmt = $pdo->prepare("SELECT role FROM employees WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_role = $stmt->fetchColumn();

if ($user_role !== 'admin') {
    $_SESSION['message'] = "Akses ditolak. Anda tidak memiliki hak akses admin.";
    header("Location: dashboard.php");
    exit();
}

$updates = [];
$errors = [];
$start_time = microtime(true);

function addUpdate($message, $type = 'success') {
    global $updates;
    $updates[] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Create backup of existing tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $backup = [];
    
    foreach ($tables as $table) {
        // Get create table statement
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $backup[$table]['create'] = $create['Create Table'];
        
        // Get table data
        $data = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $backup[$table]['data'] = $data;
    }

    // Save backup to file
    $backup_file = 'backups/db_backup_' . date('Y-m-d_His') . '.json';
    if (!file_exists('backups')) {
        mkdir('backups', 0755, true);
    }
    file_put_contents($backup_file, json_encode($backup));
    addUpdate("Database backup created: " . basename($backup_file));

    // Update employees table
    $employee_updates = [
        // Add new columns
        "ALTER TABLE employees 
         ADD COLUMN IF NOT EXISTS role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
         ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL,
         ADD COLUMN IF NOT EXISTS address TEXT NULL,
         ADD COLUMN IF NOT EXISTS join_date DATE NOT NULL DEFAULT CURRENT_DATE,
         ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
         ADD COLUMN IF NOT EXISTS deleted_by INT NULL,
         ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         ADD COLUMN IF NOT EXISTS updated_by INT NULL,
         ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL,
         ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL,
         ADD COLUMN IF NOT EXISTS failed_login_attempts INT DEFAULT 0,
         ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(100) NULL,
         ADD COLUMN IF NOT EXISTS password_reset_expires DATETIME NULL",

        // Add indexes
        "ALTER TABLE employees 
         ADD INDEX IF NOT EXISTS idx_email (email),
         ADD INDEX IF NOT EXISTS idx_status (status),
         ADD INDEX IF NOT EXISTS idx_role (role),
         ADD INDEX IF NOT EXISTS idx_deleted_at (deleted_at)"
    ];

    foreach ($employee_updates as $sql) {
        try {
            $pdo->exec($sql);
            addUpdate("Employee table structure updated");
        } catch (PDOException $e) {
            if ($e->getCode() != '42S21' && $e->getCode() != '42000') {
                throw $e;
            }
            addUpdate("Some columns already exist in employees table", 'notice');
        }
    }

    // Ensure at least one admin exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE role = 'admin' AND status = 1");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("UPDATE employees SET role = 'admin' WHERE id = 1");
        addUpdate("Default admin role assigned to first employee", 'warning');
    }

    // Create activity_log table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        action VARCHAR(50) NOT NULL,
        details TEXT NULL,
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
    )");
    addUpdate("Activity log table created/verified");

    // Create payment_methods table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
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
        FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL
    )");
    addUpdate("Payment methods table created/verified");

    // Insert default payment methods if not exists
    $default_payment_methods = [
        ['Cash', 'CASH', 'Cash payment', 0, NULL, NULL, NULL, 0, 0, 'fas fa-money-bill', 1],
        ['Debit Card', 'DEBIT', 'Debit card payment', 1, 'Please swipe card and enter PIN', 10000, NULL, 0, 2000, 'fas fa-credit-card', 2],
        ['QRIS', 'QRIS', 'QRIS payment', 1, 'Scan QR code and show payment confirmation', 1000, NULL, 0.7, 0, 'fas fa-qrcode', 4]
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO payment_methods 
        (name, code, description, requires_verification, verification_instructions, min_amount, max_amount, fee_percentage, fee_fixed, icon_class, display_order) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($default_payment_methods as $method) {
        $stmt->execute($method);
    }
    addUpdate("Default payment methods verified");

    // Update transactions table
    $transaction_updates = [
        // Add new columns
        "ALTER TABLE transactions 
         ADD COLUMN IF NOT EXISTS customer_name VARCHAR(100) NULL,
         ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(20) NULL,
         ADD COLUMN IF NOT EXISTS amount DECIMAL(10,2) NOT NULL DEFAULT 0,
         ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) DEFAULT 0,
         ADD COLUMN IF NOT EXISTS final_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
         ADD COLUMN IF NOT EXISTS status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
         ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL,
         ADD COLUMN IF NOT EXISTS completed_by INT NULL,
         ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
         ADD COLUMN IF NOT EXISTS cancelled_by INT NULL,
         ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NULL,
         ADD COLUMN IF NOT EXISTS notes TEXT NULL,
         ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
         ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",

        // Add indexes
        "ALTER TABLE transactions 
         ADD INDEX IF NOT EXISTS idx_employee_date (employee_id, transaction_date),
         ADD INDEX IF NOT EXISTS idx_status_date (status, transaction_date),
         ADD INDEX IF NOT EXISTS idx_license_plate (license_plate)"
    ];

    foreach ($transaction_updates as $sql) {
        try {
            $pdo->exec($sql);
            addUpdate("Transaction table structure updated");
        } catch (PDOException $e) {
            if ($e->getCode() != '42S21' && $e->getCode() != '42000') {
                throw $e;
            }
            addUpdate("Some columns already exist in transactions table", 'notice');
        }
    }

    // Create triggers
    $triggers = [
        "DROP TRIGGER IF EXISTS employees_after_update",
        "CREATE TRIGGER employees_after_update
         AFTER UPDATE ON employees
         FOR EACH ROW
         BEGIN
             IF OLD.status != NEW.status OR OLD.role != NEW.role THEN
                 INSERT INTO activity_log (
                     admin_id, action, details, entity_type, entity_id, 
                     old_values, new_values
                 )
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
         END",

        "DROP TRIGGER IF EXISTS transactions_after_update",
        "CREATE TRIGGER transactions_after_update
         AFTER UPDATE ON transactions
         FOR EACH ROW
         BEGIN
             IF OLD.status != NEW.status THEN
                 INSERT INTO activity_log (
                     admin_id, action, details, entity_type, entity_id, 
                     old_values, new_values
                 )
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
         END"
    ];

    foreach ($triggers as $sql) {
        try {
            $pdo->exec($sql);
            addUpdate("Database triggers updated");
        } catch (PDOException $e) {
            addUpdate("Error updating trigger: " . $e->getMessage(), 'error');
        }
    }

    // Log the update
    logEmployeeActivity($pdo, $_SESSION['user_id'], 'database_update', 
        sprintf("Database structure updated successfully. Updates: %d", count($updates)));

    // Commit transaction
    $pdo->commit();

    $execution_time = round((microtime(true) - $start_time) * 1000, 2);

} catch (Exception $e) {
    // Rollback transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errors[] = $e->getMessage();
    
    // Attempt to restore from backup if exists
    if (isset($backup) && !empty($backup)) {
        try {
            foreach ($backup as $table => $data) {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
                $pdo->exec($data['create']);
                
                if (!empty($data['data'])) {
                    $columns = array_keys($data['data'][0]);
                    $values = array_map(function($row) use ($pdo) {
                        return '(' . implode(',', array_map(function($value) use ($pdo) {
                            return $value === null ? 'NULL' : $pdo->quote($value);
                        }, $row)) . ')';
                    }, $data['data']);
                    
                    $sql = "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES " . 
                           implode(',', $values);
                    $pdo->exec($sql);
                }
            }
            addUpdate("Database restored from backup", 'warning');
        } catch (Exception $restore_e) {
            $errors[] = "Failed to restore database: " . $restore_e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');

        :root {
            --primary-color: #2563eb;
            --success-color: #059669;
            --warning-color: #d97706;
            --error-color: #dc2626;
            --info-color: #3b82f6;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 20px;
            color: #1f2937;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        h1 {
            color: #111827;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }

        .timestamp {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .update-message {
            padding: 16px;
            margin-bottom: 12px;
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .update-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .update-icon {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .update-time {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .success {
            background-color: #d1fae5;
            border: 1px solid #a7f3d0;
        }
        .success .update-icon { color: var(--success-color); }

        .error {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
        }
        .error .update-icon { color: var(--error-color); }

        .warning {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
        }
        .warning .update-icon { color: var(--warning-color); }

        .notice {
            background-color: #dbeafe;
            border: 1px solid #bfdbfe;
        }
        .notice .update-icon { color: var(--info-color); }

        .summary {
            margin-top: 24px;
            padding: 20px;
            background-color: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .summary h2 {
            margin: 0 0 16px 0;
            font-size: 1.25rem;
            color: #374151;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .summary-item {
            padding: 16px;
            background-color: white;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }

        .summary-label {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .summary-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-warning {
            background-color: var(--warning-color);
        }
        .btn-warning:hover {
            background-color: #b45309;
        }

        .execution-time {
            font-size: 0.875rem;
            color: #6b7280;
            text-align: right;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Database Update Results</h1>
            <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></span>
        </div>

        <?php foreach ($updates as $update): ?>
            <div class="update-message <?php echo $update['type']; ?>">
                <div class="update-header">
                    <div class="update-icon">
                        <i class="fas fa-<?php echo 
                            $update['type'] === 'success' ? 'check-circle' : 
                            ($update['type'] === 'error' ? 'times-circle' : 
                            ($update['type'] === 'warning' ? 'exclamation-triangle' : 
                            'info-circle')); ?>"></i>
                        <?php echo htmlspecialchars($update['message']); ?>
                    </div>
                    <div class="update-time"><?php echo $update['timestamp']; ?></div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                <div class="update-message error">
                    <div class="update-header">
                        <div class="update-icon">
                            <i class="fas fa-times-circle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="summary">
            <h2>Update Summary</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Status</div>
                    <div class="summary-value">
                        <?php echo empty($errors) ? 'Completed' : 'Failed'; ?>
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Updates</div>
                    <div class="summary-value"><?php echo count($updates); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Success Rate</div>
                    <div class="summary-value">
                        <?php
                        $success_count = count(array_filter($updates, function($update) {
                            return $update['type'] === 'success';
                        }));
                        echo round(($success_count / count($updates)) * 100) . '%';
                        ?>
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Execution Time</div>
                    <div class="summary-value"><?php echo $execution_time; ?>ms</div>
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i>
                Kembali ke Dashboard
            </a>
            <?php if (!empty($errors)): ?>
                <a href="check_system.php" class="btn btn-warning">
                    <i class="fas fa-sync"></i>
                    Periksa Sistem
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
