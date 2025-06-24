<?php
session_start();
require_once 'config.php';

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

$system_checks = [];
$start_time = microtime(true);

function addCheck($name, $status, $message, $details = [], $severity = 'error') {
    global $system_checks;
    $system_checks[] = [
        'name' => $name,
        'status' => $status,
        'message' => $message,
        'details' => $details,
        'severity' => $severity,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Check PHP version and extensions
$required_extensions = [
    'pdo',
    'pdo_mysql',
    'json',
    'mbstring',
    'openssl',
    'session'
];

$missing_extensions = array_filter($required_extensions, function($ext) {
    return !extension_loaded($ext);
});

addCheck(
    'PHP Environment',
    empty($missing_extensions),
    sprintf(
        "PHP Version: %s (%s recommended)",
        PHP_VERSION,
        '7.4.0'
    ),
    $missing_extensions,
    empty($missing_extensions) ? 'success' : 'warning'
);

// Check required files
$required_files = [
    'config.php' => 'Configuration file',
    'employees.php' => 'Employee management',
    'add_employee.php' => 'Add employee form',
    'edit_employee.php' => 'Edit employee form',
    'delete_employee.php' => 'Delete employee handler',
    'toggle_employee.php' => 'Toggle employee status',
    'export_employees.php' => 'Export functionality',
    'employee_functions.php' => 'Employee functions',
    'update_schema.sql' => 'Database schema',
    'apply_updates.php' => 'Database updater',
    'dashboard.php' => 'Main dashboard',
    'login.php' => 'Login page',
    'logout.php' => 'Logout handler'
];

$missing_files = [];
$outdated_files = [];

foreach ($required_files as $file => $description) {
    if (!file_exists($file)) {
        $missing_files[$file] = $description;
    } else {
        // Check file permissions and last modified date
        $perms = fileperms($file);
        $last_modified = filemtime($file);
        if (time() - $last_modified > 30 * 24 * 60 * 60) { // Older than 30 days
            $outdated_files[$file] = date('Y-m-d', $last_modified);
        }
    }
}

addCheck(
    'Required Files',
    empty($missing_files),
    empty($missing_files) ? 'All required files present' : 'Missing files detected',
    $missing_files
);

if (!empty($outdated_files)) {
    addCheck(
        'File Updates',
        false,
        'Some files might need updates',
        $outdated_files,
        'warning'
    );
}

// Check database connection and performance
try {
    $start_db = microtime(true);
    $pdo->query("SELECT 1");
    $db_response_time = round((microtime(true) - $start_db) * 1000, 2);
    
    addCheck(
        'Database Connection',
        true,
        sprintf(
            'Connected to database (Response time: %sms)',
            $db_response_time
        ),
        [],
        $db_response_time > 100 ? 'warning' : 'success'
    );
} catch (PDOException $e) {
    addCheck(
        'Database Connection',
        false,
        'Database connection failed: ' . $e->getMessage()
    );
}

// Check required tables and their structure
$required_tables = [
    'employees' => [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'name' => 'VARCHAR(100) NOT NULL',
        'email' => 'VARCHAR(100) NOT NULL UNIQUE',
        'password' => 'VARCHAR(255) NOT NULL',
        'status' => 'TINYINT(1) DEFAULT 1',
        'role' => "ENUM('admin','employee') NOT NULL",
        'deleted_at' => 'DATETIME NULL',
        'deleted_by' => 'INT NULL',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'updated_by' => 'INT NULL'
    ],
    'transactions' => [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'employee_id' => 'INT NOT NULL',
        'vehicle_type' => 'VARCHAR(50) NOT NULL',
        'license_plate' => 'VARCHAR(20) NOT NULL',
        'wash_type_id' => 'INT NOT NULL',
        'payment_method' => 'VARCHAR(50) NOT NULL',
        'status' => "ENUM('pending','completed','cancelled') DEFAULT 'pending'",
        'transaction_date' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'completed_at' => 'DATETIME NULL',
        'cancelled_at' => 'DATETIME NULL',
        'notes' => 'TEXT NULL'
    ],
    'activity_log' => [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'admin_id' => 'INT NOT NULL',
        'action' => 'VARCHAR(50) NOT NULL',
        'details' => 'TEXT NULL',
        'ip_address' => 'VARCHAR(45) NULL',
        'user_agent' => 'TEXT NULL',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
    ]
];

foreach ($required_tables as $table => $required_columns) {
    try {
        // Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $table_exists = $stmt->rowCount() > 0;

        if ($table_exists) {
            // Check columns
            $stmt = $pdo->query("SHOW COLUMNS FROM $table");
            $existing_columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_columns[$row['Field']] = $row['Type'] . 
                    ($row['Null'] === 'NO' ? ' NOT NULL' : '') .
                    ($row['Default'] ? " DEFAULT '" . $row['Default'] . "'" : '');
            }

            $missing_columns = array_diff_key($required_columns, $existing_columns);
            $different_columns = [];

            foreach ($required_columns as $column => $definition) {
                if (isset($existing_columns[$column]) && 
                    !stristr($existing_columns[$column], $definition)) {
                    $different_columns[$column] = [
                        'expected' => $definition,
                        'found' => $existing_columns[$column]
                    ];
                }
            }

            if (empty($missing_columns) && empty($different_columns)) {
                addCheck(
                    "Table: $table",
                    true,
                    "Table structure is correct",
                    [],
                    'success'
                );
            } else {
                $details = [];
                if (!empty($missing_columns)) {
                    $details['missing_columns'] = $missing_columns;
                }
                if (!empty($different_columns)) {
                    $details['different_columns'] = $different_columns;
                }
                addCheck(
                    "Table: $table",
                    false,
                    "Table structure needs updates",
                    $details,
                    'warning'
                );
            }

            // Check indexes
            $stmt = $pdo->query("SHOW INDEX FROM $table");
            $existing_indexes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_indexes[$row['Key_name']][] = $row['Column_name'];
            }

            // Add any specific index checks here
            if ($table === 'employees') {
                if (!isset($existing_indexes['email'])) {
                    addCheck(
                        "Index: $table",
                        false,
                        "Missing index on email column",
                        [],
                        'warning'
                    );
                }
            }
        } else {
            addCheck(
                "Table: $table",
                false,
                "Table does not exist",
                ['required_columns' => array_keys($required_columns)]
            );
        }

        // Check table size and performance
        $row_count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        $table_status = $pdo->query("SHOW TABLE STATUS LIKE '$table'")->fetch();
        
        addCheck(
            "Table Stats: $table",
            true,
            sprintf(
                "Rows: %d, Size: %s",
                $row_count,
                formatBytes($table_status['Data_length'] + $table_status['Index_length'])
            ),
            [
                'row_count' => $row_count,
                'data_size' => formatBytes($table_status['Data_length']),
                'index_size' => formatBytes($table_status['Index_length'])
            ],
            'info'
        );
    } catch (PDOException $e) {
        addCheck(
            "Table: $table",
            false,
            "Error checking table: " . $e->getMessage()
        );
    }
}

// Check for at least one admin user
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total, 
                         SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active 
                         FROM employees 
                         WHERE role = 'admin' AND deleted_at IS NULL");
    $admin_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    addCheck(
        'Admin Users',
        $admin_stats['active'] > 0,
        sprintf(
            "Found %d admin(s) (%d active)",
            $admin_stats['total'],
            $admin_stats['active']
        ),
        [],
        $admin_stats['active'] > 0 ? 'success' : 'error'
    );
} catch (PDOException $e) {
    addCheck(
        'Admin Users',
        false,
        'Error checking admin users: ' . $e->getMessage()
    );
}

// Check file permissions
$writable_dirs = [
    '.' => 'Application root',
    'exports' => 'Export directory'
];
$permission_issues = [];

foreach ($writable_dirs as $dir => $description) {
    if (!file_exists($dir)) {
        $permission_issues[$dir] = "Directory does not exist: $description";
    } elseif (!is_writable($dir)) {
        $permission_issues[$dir] = "Not writable: $description";
    }
}

addCheck(
    'File Permissions',
    empty($permission_issues),
    empty($permission_issues) ? 'All directories are writable' : 'Permission issues detected',
    $permission_issues,
    empty($permission_issues) ? 'success' : 'error'
);

// Check system resources
$memory_limit = ini_get('memory_limit');
$post_max_size = ini_get('post_max_size');
$upload_max_filesize = ini_get('upload_max_filesize');
$max_execution_time = ini_get('max_execution_time');

addCheck(
    'System Resources',
    true,
    'System resource limits',
    [
        'Memory Limit' => $memory_limit,
        'Post Max Size' => $post_max_size,
        'Upload Max Filesize' => $upload_max_filesize,
        'Max Execution Time' => $max_execution_time . 's'
    ],
    'info'
);

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

// Calculate execution time
$execution_time = round((microtime(true) - $start_time) * 1000, 2);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Check Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', Arial, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 20px;
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
        .check-result {
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 8px;
        }
        .check-result.success {
            background-color: #d1fae5;
            border: 1px solid #a7f3d0;
        }
        .check-result.error {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
        }
        .check-result.warning {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
        }
        .check-result.info {
            background-color: #dbeafe;
            border: 1px solid #bfdbfe;
        }
        .check-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .check-header i {
            font-size: 1.25rem;
        }
        .check-result.success i { color: #059669; }
        .check-result.error i { color: #dc2626; }
        .check-result.warning i { color: #d97706; }
        .check-result.info i { color: #3b82f6; }
        .check-name {
            font-weight: 600;
            color: #374151;
            flex: 1;
        }
        .check-timestamp {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .check-message {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .check-details {
            font-size: 0.875rem;
            color: #6b7280;
            background-color: rgba(0,0,0,0.05);
            padding: 12px;
            border-radius: 6px;
            margin-top: 8px;
        }
        .check-details ul {
            margin: 0;
            padding-left: 20px;
        }
        .check-details li {
            margin: 4px 0;
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
            margin-bottom: 20px;
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
            font-size: 1.5rem;
            font-weight: 600;
            color: #111827;
        }
        .health-indicator {
            height: 6px;
            background-color: #e5e7eb;
            border-radius: 3px;
            margin-top: 12px;
            overflow: hidden;
        }
        .health-bar {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        .health-bar.good { background-color: #059669; }
        .health-bar.moderate { background-color: #d97706; }
        .health-bar.poor { background-color: #dc2626; }
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
        .btn-danger {
            background-color: #dc2626;
        }
        .btn-danger:hover {
            background-color: #b91c1c;
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
            <h1>System Check Results</h1>
            <span class="timestamp"><?php echo date('Y-m-d H:i:s'); ?></span>
        </div>
        
        <?php foreach ($system_checks as $check): ?>
            <div class="check-result <?php echo $check['severity']; ?>">
                <div class="check-header">
                    <i class="fas fa-<?php 
                        echo $check['severity'] === 'success' ? 'check-circle' : 
                            ($check['severity'] === 'warning' ? 'exclamation-triangle' : 
                            ($check['severity'] === 'info' ? 'info-circle' : 'times-circle')); 
                    ?>"></i>
                    <div class="check-name"><?php echo htmlspecialchars($check['name']); ?></div>
                    <div class="check-timestamp"><?php echo $check['timestamp']; ?></div>
                </div>
                <div class="check-message"><?php echo htmlspecialchars($check['message']); ?></div>
                <?php if (!empty($check['details'])): ?>
                    <div class="check-details">
                        <?php if (is_array($check['details'])): ?>
                            <ul>
                                <?php foreach ($check['details'] as $key => $value): ?>
                                    <li>
                                        <?php
                                        if (is_array($value)) {
                                            echo htmlspecialchars($key) . ': ' . 
                                                 htmlspecialchars(json_encode($value));
                                        } else {
                                            echo is_int($key) ? 
                                                htmlspecialchars($value) : 
                                                htmlspecialchars($key) . ': ' . htmlspecialchars($value);
                                        }
                                        ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <?php echo htmlspecialchars($check['details']); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="summary">
            <h2>System Health Summary</h2>
            <?php
            $total_checks = count($system_checks);
            $success_checks = count(array_filter($system_checks, function($check) {
                return $check['severity'] === 'success';
            }));
            $warning_checks = count(array_filter($system_checks, function($check) {
                return $check['severity'] === 'warning';
            }));
            $error_checks = count(array_filter($system_checks, function($check) {
                return $check['severity'] === 'error';
            }));
            $health_score = round(($success_checks * 100 + $warning_checks * 50) / $total_checks);
            ?>
            
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Total Checks</div>
                    <div class="summary-value"><?php echo $total_checks; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Successful</div>
                    <div class="summary-value"><?php echo $success_checks; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Warnings</div>
                    <div class="summary-value"><?php echo $warning_checks; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Errors</div>
                    <div class="summary-value"><?php echo $error_checks; ?></div>
                </div>
            </div>

            <div class="summary-item">
                <div class="summary-label">System Health Score</div>
                <div class="summary-value"><?php echo $health_score; ?>%</div>
                <div class="health-indicator">
                    <div class="health-bar <?php 
                        echo $health_score >= 80 ? 'good' : 
                            ($health_score >= 60 ? 'moderate' : 'poor'); 
                    ?>" style="width: <?php echo $health_score; ?>%"></div>
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="employees.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i>
                Kembali ke Daftar Karyawan
            </a>
            <?php if ($warning_checks > 0): ?>
                <a href="apply_updates.php" class="btn btn-warning">
                    <i class="fas fa-sync"></i>
                    Jalankan Update Database
                </a>
            <?php endif; ?>
            <?php if ($error_checks > 0): ?>
                <a href="check_system.php" class="btn btn-danger">
                    <i class="fas fa-redo"></i>
                    Periksa Ulang Sistem
                </a>
            <?php endif; ?>
        </div>

        <div class="execution-time">
            Execution time: <?php echo $execution_time; ?>ms
        </div>
    </div>
</body>
</html>
