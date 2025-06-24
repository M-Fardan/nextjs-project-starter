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

$messages = [];
$success = true;
$start_time = microtime(true);

function addMessage($type, $message, $details = '') {
    global $messages;
    $messages[] = [
        'type' => $type,
        'message' => $message,
        'details' => $details,
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
    $backup_file = 'backups/backup_' . date('Y-m-d_His') . '.json';
    if (!file_exists('backups')) {
        mkdir('backups', 0755, true);
    }
    file_put_contents($backup_file, json_encode($backup));
    addMessage('success', 'Database backup created successfully', $backup_file);

    // Read and parse SQL file
    $sql = file_get_contents('update_schema.sql');
    if ($sql === false) {
        throw new Exception("Could not read update_schema.sql file");
    }

    // Split SQL file into individual queries
    $delimiter = ';';
    $queries = [];
    $current_query = '';
    
    foreach (explode("\n", $sql) as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '--') === 0) continue;
        
        if (strpos($line, 'DELIMITER') === 0) {
            $delimiter = trim(str_replace('DELIMITER', '', $line));
            continue;
        }
        
        $current_query .= $line . "\n";
        
        if (substr($line, -strlen($delimiter)) === $delimiter) {
            $queries[] = substr($current_query, 0, -strlen($delimiter));
            $current_query = '';
        }
    }

    // Execute queries
    $total_queries = count($queries);
    $executed_queries = 0;
    
    foreach ($queries as $query) {
        if (empty(trim($query))) continue;
        
        try {
            $pdo->exec($query);
            $executed_queries++;
            addMessage('success', 'Query executed successfully', substr($query, 0, 100) . '...');
        } catch (PDOException $e) {
            // Ignore certain errors
            if ($e->getCode() == '42S21') { // Duplicate column
                addMessage('notice', 'Column already exists', substr($query, 0, 100) . '...');
            } elseif ($e->getCode() == '42S01') { // Table already exists
                addMessage('notice', 'Table already exists', substr($query, 0, 100) . '...');
            } else {
                throw $e;
            }
        }
    }

    // Verify database structure
    $required_tables = [
        'employees' => [
            'columns' => ['id', 'name', 'email', 'password', 'status', 'role', 'deleted_at', 'created_at'],
            'indexes' => ['idx_email', 'idx_status', 'idx_role', 'idx_deleted_at']
        ],
        'activity_log' => [
            'columns' => ['id', 'admin_id', 'action', 'details', 'created_at'],
            'indexes' => ['idx_admin_action', 'idx_created_at']
        ],
        'transactions' => [
            'columns' => ['id', 'employee_id', 'amount', 'status', 'transaction_date'],
            'indexes' => ['idx_employee_date', 'idx_status_date']
        ]
    ];

    foreach ($required_tables as $table => $requirements) {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() == 0) {
            throw new Exception("Required table '$table' is missing");
        }

        // Check columns
        $stmt = $pdo->query("SHOW COLUMNS FROM $table");
        $existing_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing_columns = array_diff($requirements['columns'], $existing_columns);
        
        if (!empty($missing_columns)) {
            addMessage('warning', "Missing columns in $table", implode(', ', $missing_columns));
        }

        // Check indexes
        $stmt = $pdo->query("SHOW INDEX FROM $table");
        $existing_indexes = [];
        while ($row = $stmt->fetch()) {
            $existing_indexes[] = $row['Key_name'];
        }
        
        $missing_indexes = array_diff($requirements['indexes'], $existing_indexes);
        if (!empty($missing_indexes)) {
            addMessage('warning', "Missing indexes in $table", implode(', ', $missing_indexes));
        }
    }

    // Ensure at least one admin exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE role = 'admin' AND status = 1 AND deleted_at IS NULL");
    if ($stmt->fetchColumn() == 0) {
        // Set the first active employee as admin
        $pdo->exec("UPDATE employees SET role = 'admin' WHERE status = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1");
        addMessage('warning', 'Default admin role assigned to first active employee');
    }

    // Log the update
    logEmployeeActivity($pdo, $_SESSION['user_id'], 'database_update', 
        sprintf("Database update completed. Executed %d of %d queries", $executed_queries, $total_queries));

    // Commit transaction
    $pdo->commit();

} catch (Exception $e) {
    // Rollback transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $success = false;
    addMessage('error', 'Critical Error: ' . $e->getMessage(), $e->getTraceAsString());
    
    // Attempt to restore from backup if exists
    if (isset($backup) && !empty($backup)) {
        try {
            foreach ($backup as $table => $data) {
                $pdo->exec("DROP TABLE IF EXISTS `$table`");
                $pdo->exec($data['create']);
                
                if (!empty($data['data'])) {
                    $columns = array_keys($data['data'][0]);
                    $values = array_map(function($row) {
                        return '(' . implode(',', array_map(function($value) {
                            return $value === null ? 'NULL' : $pdo->quote($value);
                        }, $row)) . ')';
                    }, $data['data']);
                    
                    $sql = "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES " . 
                           implode(',', $values);
                    $pdo->exec($sql);
                }
            }
            addMessage('success', 'Database restored from backup');
        } catch (Exception $restore_e) {
            addMessage('error', 'Failed to restore database: ' . $restore_e->getMessage());
        }
    }
}

$execution_time = round((microtime(true) - $start_time) * 1000, 2);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f3f4f6;
            color: #1f2937;
        }
        .container {
            max-width: 1000px;
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
        .message {
            padding: 16px;
            margin-bottom: 12px;
            border-radius: 8px;
            font-size: 0.875rem;
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .message-icon {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        .message-time {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .message-details {
            margin-top: 8px;
            padding: 8px;
            background-color: rgba(0,0,0,0.05);
            border-radius: 4px;
            font-family: monospace;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .success {
            background-color: #d1fae5;
            border: 1px solid #a7f3d0;
        }
        .success .message-icon {
            color: #059669;
        }
        .error {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
        }
        .error .message-icon {
            color: #dc2626;
        }
        .warning {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
        }
        .warning .message-icon {
            color: #d97706;
        }
        .notice {
            background-color: #dbeafe;
            border: 1px solid #bfdbfe;
        }
        .notice .message-icon {
            color: #2563eb;
        }
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
            background-color: #2563eb;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        .btn-warning {
            background-color: #d97706;
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
        
        <?php foreach ($messages as $msg): ?>
            <div class="message <?php echo $msg['type']; ?>">
                <div class="message-header">
                    <div class="message-icon">
                        <i class="fas fa-<?php echo 
                            $msg['type'] === 'success' ? 'check-circle' : 
                            ($msg['type'] === 'error' ? 'times-circle' : 
                            ($msg['type'] === 'warning' ? 'exclamation-triangle' : 
                            'info-circle')); ?>"></i>
                        <?php echo htmlspecialchars($msg['message']); ?>
                    </div>
                    <div class="message-time"><?php echo $msg['timestamp']; ?></div>
                </div>
                <?php if (!empty($msg['details'])): ?>
                    <div class="message-details"><?php echo htmlspecialchars($msg['details']); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="summary">
            <h2>Update Summary</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Status</div>
                    <div class="summary-value">
                        <?php echo $success ? 'Completed' : 'Failed'; ?>
                    </div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Operations</div>
                    <div class="summary-value"><?php echo count($messages); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Success Rate</div>
                    <div class="summary-value">
                        <?php
                        $success_count = count(array_filter($messages, function($msg) {
                            return $msg['type'] === 'success';
                        }));
                        echo round(($success_count / count($messages)) * 100) . '%';
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
            <a href="employees.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i>
                Kembali ke Daftar Karyawan
            </a>
            <?php if (!$success): ?>
                <a href="check_system.php" class="btn btn-warning">
                    <i class="fas fa-sync"></i>
                    Periksa Sistem
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
