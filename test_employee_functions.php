<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'employee_functions.php';

// Create test admin session for running tests
$_SESSION['user_id'] = 1; // Default admin ID
$_SESSION['user_role'] = 'admin';

// Initialize results array
$test_results = [];
$test_data = [];
$backup_data = [];

/**
 * Run a test case and record results
 */
function runTest($test_name, $test_function) {
    global $test_results;
    try {
        $start_time = microtime(true);
        $result = $test_function();
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        $test_results[] = [
            'name' => $test_name,
            'status' => $result['status'],
            'message' => $result['message'],
            'execution_time' => $execution_time,
            'details' => $result['details'] ?? [],
            'memory_usage' => memory_get_usage(true)
        ];
    } catch (Exception $e) {
        $test_results[] = [
            'name' => $test_name,
            'status' => false,
            'message' => "Error: " . $e->getMessage(),
            'execution_time' => 0,
            'details' => ['error_trace' => $e->getTraceAsString()],
            'memory_usage' => memory_get_usage(true)
        ];
    }
}

/**
 * Backup existing data before tests
 */
function backupData() {
    global $pdo, $backup_data;
    
    try {
        // Backup employees table
        $stmt = $pdo->query("SELECT * FROM employees WHERE role != 'admin'");
        $backup_data['employees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Backup activity_log table
        $stmt = $pdo->query("SELECT * FROM activity_log");
        $backup_data['activity_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return true;
    } catch (Exception $e) {
        error_log("Backup failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Restore data after tests
 */
function restoreData() {
    global $pdo, $backup_data;
    
    try {
        $pdo->beginTransaction();

        // Clean up test data
        $pdo->exec("DELETE FROM activity_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $pdo->exec("DELETE FROM employees WHERE email LIKE 'test.%@example.com'");

        // Restore employees
        foreach ($backup_data['employees'] as $employee) {
            $columns = implode(', ', array_keys($employee));
            $values = implode(', ', array_fill(0, count($employee), '?'));
            $sql = "INSERT IGNORE INTO employees ($columns) VALUES ($values)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($employee));
        }

        // Restore activity log
        foreach ($backup_data['activity_log'] as $log) {
            $columns = implode(', ', array_keys($log));
            $values = implode(', ', array_fill(0, count($log), '?'));
            $sql = "INSERT IGNORE INTO activity_log ($columns) VALUES ($values)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($log));
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Restore failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Setup test data
 */
function setupTestData() {
    global $pdo, $test_data;
    
    try {
        $pdo->beginTransaction();
        
        // Create test employee with various attributes
        $stmt = $pdo->prepare("INSERT INTO employees (
            name, email, password, role, status, phone, address, join_date
        ) VALUES (?, ?, ?, 'employee', 1, ?, ?, CURRENT_DATE)");
        
        $stmt->execute([
            'Test Employee',
            'test.employee.' . time() . '@example.com',
            password_hash('TestPass123!', PASSWORD_DEFAULT),
            '081234567890',
            'Test Address'
        ]);
        $test_data['employee_id'] = $pdo->lastInsertId();
        
        // Create test admin
        $stmt = $pdo->prepare("INSERT INTO employees (
            name, email, password, role, status
        ) VALUES (?, ?, ?, 'admin', 1)");
        
        $stmt->execute([
            'Test Admin',
            'test.admin.' . time() . '@example.com',
            password_hash('AdminPass123!', PASSWORD_DEFAULT)
        ]);
        $test_data['admin_id'] = $pdo->lastInsertId();
        
        // Create test transactions
        $stmt = $pdo->prepare("INSERT INTO transactions (
            employee_id, vehicle_type, license_plate, wash_type_id,
            payment_method_id, amount, final_amount, status
        ) VALUES (?, ?, ?, 1, 1, 50000, 50000, 'completed')");
        
        for ($i = 0; $i < 3; $i++) {
            $stmt->execute([
                $test_data['employee_id'],
                'mobil',
                'B ' . rand(1000, 9999) . ' XX',
            ]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Backup existing data
backupData();

// Setup test environment
try {
    setupTestData();
} catch (Exception $e) {
    die("Failed to setup test environment: " . $e->getMessage());
}

// Test 1: Employee Data Validation
runTest('Employee Data Validation', function() {
    global $pdo;
    $test_cases = [
        [
            'name' => 'Valid data',
            'data' => [
                'name' => 'John Doe',
                'email' => 'john.doe.' . time() . '@example.com',
                'password' => 'TestPass123!',
                'confirm_password' => 'TestPass123!',
                'phone' => '081234567890',
                'address' => 'Test Address',
                'role' => 'employee'
            ],
            'expected' => true
        ],
        [
            'name' => 'Invalid name (numbers)',
            'data' => [
                'name' => 'J0hn D0e',
                'email' => 'john.doe@example.com',
                'password' => 'TestPass123!',
                'confirm_password' => 'TestPass123!'
            ],
            'expected' => false
        ],
        [
            'name' => 'Invalid email',
            'data' => [
                'name' => 'John Doe',
                'email' => 'not-an-email',
                'password' => 'TestPass123!',
                'confirm_password' => 'TestPass123!'
            ],
            'expected' => false
        ],
        [
            'name' => 'Password mismatch',
            'data' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'password' => 'TestPass123!',
                'confirm_password' => 'DifferentPass123!'
            ],
            'expected' => false
        ],
        [
            'name' => 'Weak password',
            'data' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'password' => 'password',
                'confirm_password' => 'password'
            ],
            'expected' => false
        ],
        [
            'name' => 'Invalid phone number',
            'data' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'password' => 'TestPass123!',
                'confirm_password' => 'TestPass123!',
                'phone' => 'abc123'
            ],
            'expected' => false
        ],
        [
            'name' => 'Long address',
            'data' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'password' => 'TestPass123!',
                'confirm_password' => 'TestPass123!',
                'address' => str_repeat('a', 300)
            ],
            'expected' => false
        ]
    ];
    
    $results = [];
    foreach ($test_cases as $case) {
        $result = validateEmployeeData($case['data'], $pdo);
        $results[] = [
            'test' => $case['name'],
            'passed' => $result['valid'] === $case['expected'],
            'message' => $result['message']
        ];
    }
    
    $all_passed = array_reduce($results, function($carry, $item) {
        return $carry && $item['passed'];
    }, true);
    
    return [
        'status' => $all_passed,
        'message' => "Validation tests completed",
        'details' => $results
    ];
});

// Test 2: Employee Deactivation Check
runTest('Employee Deactivation Check', function() {
    global $pdo, $test_data;
    
    $test_cases = [
        [
            'name' => 'Regular employee deactivation',
            'setup' => function() use ($pdo, $test_data) {
                return $test_data['employee_id'];
            },
            'expected' => true
        ],
        [
            'name' => 'Last admin deactivation',
            'setup' => function() use ($pdo, $test_data) {
                // Make test admin the only active admin
                $stmt = $pdo->prepare("UPDATE employees SET status = 0 WHERE role = 'admin' AND id != ?");
                $stmt->execute([$test_data['admin_id']]);
                return $test_data['admin_id'];
            },
            'expected' => false
        ],
        [
            'name' => 'Employee with pending transactions',
            'setup' => function() use ($pdo, $test_data) {
                // Create pending transaction
                $stmt = $pdo->prepare("INSERT INTO transactions (
                    employee_id, vehicle_type, license_plate, wash_type_id,
                    payment_method_id, amount, final_amount, status
                ) VALUES (?, 'mobil', 'B 1234 XX', 1, 1, 50000, 50000, 'pending')");
                $stmt->execute([$test_data['employee_id']]);
                return $test_data['employee_id'];
            },
            'expected' => true
        ]
    ];
    
    $results = [];
    foreach ($test_cases as $case) {
        $employee_id = $case['setup']();
        $result = canDeactivateEmployee($employee_id, $pdo);
        $results[] = [
            'test' => $case['name'],
            'passed' => $result['can_deactivate'] === $case['expected'],
            'message' => $result['message']
        ];
    }
    
    $all_passed = array_reduce($results, function($carry, $item) {
        return $carry && $item['passed'];
    }, true);
    
    return [
        'status' => $all_passed,
        'message' => "Deactivation check tests completed",
        'details' => $results
    ];
});

// Test 3: Activity Logging
runTest('Activity Logging', function() {
    global $pdo, $test_data;
    
    $test_cases = [
        [
            'name' => 'Basic activity log',
            'action' => 'test_action',
            'details' => 'Test activity log entry',
            'entity_type' => null,
            'entity_id' => null
        ],
        [
            'name' => 'Log with entity reference',
            'action' => 'update_employee',
            'details' => 'Updated employee details',
            'entity_type' => 'employees',
            'entity_id' => $test_data['employee_id']
        ],
        [
            'name' => 'Log with special characters',
            'action' => 'test_action',
            'details' => "Test with special chars: !@#$%^&*()",
            'entity_type' => null,
            'entity_id' => null
        ],
        [
            'name' => 'Log with value changes',
            'action' => 'update_status',
            'details' => 'Status updated',
            'entity_type' => 'employees',
            'entity_id' => $test_data['employee_id'],
            'old_values' => ['status' => 1],
            'new_values' => ['status' => 0]
        ]
    ];
    
    $results = [];
    foreach ($test_cases as $case) {
        $logged = logEmployeeActivity(
            $pdo, 
            $_SESSION['user_id'], 
            $case['action'], 
            $case['details'],
            $case['entity_type'],
            $case['entity_id'],
            $case['old_values'] ?? null,
            $case['new_values'] ?? null
        );
        
        // Verify log entry
        $stmt = $pdo->prepare("SELECT * FROM activity_log 
                              WHERE admin_id = ? 
                              AND action = ? 
                              AND details = ?
                              ORDER BY id DESC LIMIT 1");
        $stmt->execute([$_SESSION['user_id'], $case['action'], $case['details']]);
        $log_entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $verification_passed = $logged && 
                             $log_entry && 
                             $log_entry['entity_type'] === $case['entity_type'] &&
                             $log_entry['entity_id'] === $case['entity_id'];
        
        if (isset($case['old_values'])) {
            $verification_passed = $verification_passed && 
                                 json_decode($log_entry['old_values'], true) == $case['old_values'];
        }
        
        $results[] = [
            'test' => $case['name'],
            'passed' => $verification_passed,
            'message' => $verification_passed ? 'Log entry verified' : 'Log verification failed'
        ];
    }
    
    $all_passed = array_reduce($results, function($carry, $item) {
        return $carry && $item['passed'];
    }, true);
    
    return [
        'status' => $all_passed,
        'message' => "Activity logging tests completed",
        'details' => $results
    ];
});

// Test 4: Employee Statistics
runTest('Employee Statistics', function() {
    global $pdo;
    
    // Get initial stats
    $initial_stats = getEmployeeStats($pdo);
    
    // Add test data
    $stmt = $pdo->prepare("INSERT INTO employees (
        name, email, password, role, status
    ) VALUES (?, ?, ?, 'employee', 1)");
    
    $stmt->execute([
        'Stats Test Employee',
        'stats.test.' . time() . '@example.com',
        password_hash('TestPass123!', PASSWORD_DEFAULT)
    ]);
    
    // Get updated stats
    $updated_stats = getEmployeeStats($pdo);
    
    $test_cases = [
        [
            'name' => 'Basic stats presence',
            'test' => function() use ($updated_stats) {
                $required_keys = [
                    'total', 'active', 'inactive', 'admins', 'employees',
                    'active_today', 'with_transactions', 'avg_transactions'
                ];
                return array_reduce($required_keys, function($carry, $key) use ($updated_stats) {
                    return $carry && isset($updated_stats[$key]);
                }, true);
            }
        ],
        [
            'name' => 'Stats accuracy',
            'test' => function() use ($initial_stats, $updated_stats) {
                return $updated_stats['total'] === $initial_stats['total'] + 1 &&
                       $updated_stats['active'] === $initial_stats['active'] + 1;
            }
        ],
        [
            'name' => 'Transaction stats',
            'test' => function() use ($updated_stats) {
                return is_numeric($updated_stats['avg_transactions']) &&
                       is_numeric($updated_stats['total_transactions']);
            }
        ],
        [
            'name' => 'Date formatting',
            'test' => function() use ($updated_stats) {
                return !empty($updated_stats['last_registered']) &&
                       strtotime($updated_stats['last_registered']) !== false;
            }
        ]
    ];
    
    $results = [];
    foreach ($test_cases as $case) {
        $passed = $case['test']();
        $results[] = [
            'test' => $case['name'],
            'passed' => $passed,
            'message' => $passed ? 'Test passed' : 'Test failed'
        ];
    }
    
    $all_passed = array_reduce($results, function($carry, $item) {
        return $carry && $item['passed'];
    }, true);
    
    return [
        'status' => $all_passed,
        'message' => "Statistics tests completed",
        'details' => $results
    ];
});

// Test 5: Formatting Functions
runTest('Formatting Functions', function() {
    $test_cases = [
        [
            'name' => 'Currency formatting with symbol',
            'type' => 'currency',
            'input' => 150000,
            'params' => [true],
            'expected' => 'Rp 150.000'
        ],
        [
            'name' => 'Currency formatting without symbol',
            'type' => 'currency',
            'input' => 150000,
            'params' => [false],
            'expected' => '150.000'
        ],
        [
            'name' => 'Date formatting with time',
            'type' => 'date',
            'input' => '2023-12-31 15:30:00',
            'params' => [true],
            'expected' => '31/12/2023 15:30'
        ],
        [
            'name' => 'Date formatting without time',
            'type' => 'date',
            'input' => '2023-12-31',
            'params' => [false],
            'expected' => '31/12/2023'
        ],
        [
            'name' => 'Date formatting with day name',
            'type' => 'date',
            'input' => '2023-12-31',
            'params' => [true, true],
            'expected' => 'Minggu, 31/12/2023'
        ],
        [
            'name' => 'Invalid date handling',
            'type' => 'date',
            'input' => 'invalid-date',
            'params' => [true],
            'expected' => '-'
        ],
        [
            'name' => 'Role name basic',
            'type' => 'role',
            'input' => 'admin',
            'params' => [false],
            'expected' => 'Administrator'
        ],
        [
            'name' => 'Role name with description',
            'type' => 'role',
            'input' => 'employee',
            'params' => [true],
            'expected' => 'Karyawan - Akses terbatas untuk operasional harian'
        ]
    ];
    
    $results = [];
    foreach ($test_cases as $case) {
        if ($case['type'] === 'currency') {
            $result = formatCurrency($case['input'], ...$case['params']);
        } elseif ($case['type'] === 'date') {
            $result = formatDate($case['input'], ...$case['params']);
        } else {
            $result = getEmployeeRoleName($case['input'], ...$case['params']);
        }
        
        $results[] = [
            'test' => $case['name'],
            'passed' => $result === $case['expected'],
            'message' => "Expected: {$case['expected']}, Got: {$result}"
        ];
    }
    
    $all_passed = array_reduce($results, function($carry, $item) {
        return $carry && $item['passed'];
    }, true);
    
    return [
        'status' => $all_passed,
        'message' => "Formatting tests completed",
        'details' => $results
    ];
});

// Test 6: Password Generation
runTest('Password Generation', function() {
    $test_cases = [
        [
            'name' => 'Default password generation',
            'length' => 12,
            'special' => true
        ],
        [
            'name' => 'Long password',
            'length' => 24,
            'special' => true
        ],
        [
            'name' => 'Without special characters',
            'length' => 12,
            'special' => false
        ],
        [
            'name' => 'Minimum length password',
            'length' => 8,
            'special' => true
        ]
    ];
    
    $results = [];
    foreach ($test_cases as $case) {
        $password = generateSecurePassword($case['length'], $case['special']);
        
        $checks = [
            'length' => strlen($password) === $case['length'],
            'uppercase' => preg_match('/[A-Z]/', $password) === 1,
            'lowercase' => preg_match('/[a-z]/', $password) === 1,
            'numbers' => preg_match('/[0-9]/', $password) === 1,
            'special' => !$case['special'] || preg_match('/[^A-Za-z0-9]/', $password) === 1
        ];
        
        $passed = array_reduce($checks, function($carry, $item) {
            return $carry && $item;
        }, true);
        
        $results[] = [
            'test' => $case['name'],
            'passed' => $passed,
            'message' => $passed ? 
                        "Password meets all requirements" : 
                        "Password failed some requirements: " . 
                        implode(', ', array_keys(array_filter($checks, function($v) { return !$v; })))
        ];
    }
    
    $all_passed = array_reduce($results, function($carry, $item) {
        return $carry && $item['passed'];
    }, true);
    
    return [
        'status' => $all_passed,
        'message' => "Password generation tests completed",
        'details' => $results
    ];
});

// Restore original data
restoreData();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Functions Test Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');

        :root {
            --primary-color: #2563eb;
            --success-color: #059669;
            --warning-color: #d97706;
            --error-color: #dc2626;
        }

        body {
            font-family: 'Inter', sans-serif;
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

        .test-result {
            padding: 16px;
            margin-bottom: 16px;
            border-radius: 8px;
            background-color: white;
            border: 1px solid #e5e7eb;
        }

        .test-result.pass {
            border-left: 4px solid var(--success-color);
        }

        .test-result.fail {
            border-left: 4px solid var(--error-color);
        }

        .test-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .test-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .test-name {
            font-weight: 600;
            color: #111827;
        }

        .test-status {
            font-size: 0.875rem;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .test-status.pass {
            background-color: #d1fae5;
            color: var(--success-color);
        }

        .test-status.fail {
            background-color: #fee2e2;
            color: var(--error-color);
        }

        .test-meta {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .test-message {
            font-size: 0.875rem;
            color: #4b5563;
            margin-bottom: 12px;
        }

        .test-details {
            background-color: #f9fafb;
            border-radius: 6px;
            overflow: hidden;
        }

        .detail-item {
            padding: 8px 12px;
            font-size: 0.875rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-item.pass {
            color: var(--success-color);
        }

        .detail-item.fail {
            color: var(--error-color);
        }

        .detail-status {
            display: flex;
            align-items: center;
            gap: 4px;
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
            color: #111827;
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

        .progress-bar {
            height: 4px;
            background-color: #e5e7eb;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .progress-value {
            height: 100%;
            background-color: var(--success-color);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .actions {
            margin-top: 24px;
            display: flex;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
        }

        .btn-secondary:hover {
            background-color: #d1d5db;
        }

        .memory-usage {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Employee Functions Test Results</h1>
            <div class="test-meta">
                <?php echo date('Y-m-d H:i:s'); ?>
            </div>
        </div>
        
        <?php foreach ($test_results as $result): ?>
            <div class="test-result <?php echo $result['status'] ? 'pass' : 'fail'; ?>">
                <div class="test-header">
                    <div class="test-title">
                        <span class="test-name"><?php echo htmlspecialchars($result['name']); ?></span>
                        <span class="test-status <?php echo $result['status'] ? 'pass' : 'fail'; ?>">
                            <?php echo $result['status'] ? 'PASSED' : 'FAILED'; ?>
                        </span>
                    </div>
                    <div class="test-meta">
                        <?php echo $result['execution_time']; ?>ms
                        <div class="memory-usage">
                            <?php echo number_format($result['memory_usage'] / 1024 / 1024, 2); ?> MB
                        </div>
                    </div>
                </div>
                
                <div class="test-message"><?php echo htmlspecialchars($result['message']); ?></div>
                
                <?php if (!empty($result['details'])): ?>
                    <div class="test-details">
                        <?php foreach ($result['details'] as $detail): ?>
                            <div class="detail-item <?php echo $detail['passed'] ? 'pass' : 'fail'; ?>">
                                <span><?php echo htmlspecialchars($detail['test']); ?></span>
                                <span class="detail-status">
                                    <i class="fas fa-<?php echo $detail['passed'] ? 'check' : 'times'; ?>"></i>
                                    <?php echo htmlspecialchars($detail['message']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="summary">
            <h2>Test Summary</h2>
            <?php
            $total_tests = count($test_results);
            $passed_tests = count(array_filter($test_results, function($result) {
                return $result['status'];
            }));
            $success_rate = $total_tests > 0 ? round(($passed_tests / $total_tests) * 100) : 0;
            $total_time = array_sum(array_column($test_results, 'execution_time'));
            $total_memory = array_sum(array_column($test_results, 'memory_usage'));
            ?>
            
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Total Tests</div>
                    <div class="summary-value"><?php echo $total_tests; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Passed</div>
                    <div class="summary-value"><?php echo $passed_tests; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Failed</div>
                    <div class="summary-value"><?php echo $total_tests - $passed_tests; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Time</div>
                    <div class="summary-value"><?php echo number_format($total_time, 2); ?>ms</div>
                </div>
            </div>

            <div class="summary-item">
                <div class="summary-label">Success Rate</div>
                <div class="summary-value"><?php echo $success_rate; ?>%</div>
                <div class="progress-bar">
                    <div class="progress-value" style="width: <?php echo $success_rate; ?>%"></div>
                </div>
            </div>

            <div class="summary-item">
                <div class="summary-label">Memory Usage</div>
                <div class="summary-value">
                    <?php echo number_format($total_memory / 1024 / 1024, 2); ?> MB
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="employees.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i>
                Kembali ke Daftar Karyawan
            </a>
            <a href="test_employee_functions.php" class="btn btn-secondary">
                <i class="fas fa-sync"></i>
                Jalankan Ulang Test
            </a>
        </div>
    </div>
</body>
</html>
