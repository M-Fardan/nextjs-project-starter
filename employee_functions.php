<?php
/**
 * Employee Management Functions Library
 * 
 * This file contains all functions related to employee management,
 * including validation, security, statistics, and utility functions.
 */

/**
 * Validate employee data with comprehensive checks
 * @param array $data Employee data to validate
 * @param PDO $pdo Database connection
 * @param int|null $employee_id Employee ID for updates (null for new employees)
 * @return array ['valid' => bool, 'message' => string, 'fields' => array]
 */
function validateEmployeeData($data, $pdo, $employee_id = null) {
    $errors = [];
    $fields = [];

    // Sanitize and validate name
    $name = trim($data['name'] ?? '');
    if (empty($name)) {
        $errors['name'] = "Nama harus diisi!";
    } elseif (strlen($name) < 3 || strlen($name) > 100) {
        $errors['name'] = "Nama harus antara 3-100 karakter!";
    } elseif (!preg_match("/^[A-Za-z\s]+$/", $name)) {
        $errors['name'] = "Nama hanya boleh berisi huruf dan spasi!";
    }
    $fields['name'] = $name;

    // Sanitize and validate email
    $email = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    if (empty($email)) {
        $errors['email'] = "Email harus diisi!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Format email tidak valid!";
    } elseif (strlen($email) > 100) {
        $errors['email'] = "Email tidak boleh lebih dari 100 karakter!";
    } else {
        // Check email uniqueness
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ? AND deleted_at IS NULL");
        $stmt->execute([$email, $employee_id ?? 0]);
        if ($stmt->rowCount() > 0) {
            $errors['email'] = "Email sudah terdaftar!";
        }
    }
    $fields['email'] = $email;

    // Validate phone (if provided)
    $phone = trim($data['phone'] ?? '');
    if (!empty($phone)) {
        if (!preg_match("/^[0-9\+\-\(\) ]{8,20}$/", $phone)) {
            $errors['phone'] = "Format nomor telepon tidak valid!";
        }
    }
    $fields['phone'] = $phone;

    // Validate address (if provided)
    $address = trim($data['address'] ?? '');
    if (!empty($address) && strlen($address) > 255) {
        $errors['address'] = "Alamat tidak boleh lebih dari 255 karakter!";
    }
    $fields['address'] = $address;

    // Validate role
    $role = trim($data['role'] ?? '');
    if (!empty($role)) {
        if (!in_array($role, ['admin', 'employee'])) {
            $errors['role'] = "Role tidak valid!";
        } else {
            // Check if changing last admin's role
            if ($employee_id && $role !== 'admin') {
                $stmt = $pdo->prepare("SELECT role FROM employees WHERE id = ?");
                $stmt->execute([$employee_id]);
                $current_role = $stmt->fetchColumn();
                
                if ($current_role === 'admin') {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees 
                                         WHERE role = 'admin' 
                                         AND status = 1 
                                         AND deleted_at IS NULL 
                                         AND id != ?");
                    $stmt->execute([$employee_id]);
                    if ($stmt->fetchColumn() == 0) {
                        $errors['role'] = "Tidak dapat mengubah role admin terakhir!";
                    }
                }
            }
        }
    }
    $fields['role'] = $role;

    // Validate password
    $password = $data['password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';
    
    if ($employee_id === null || !empty($password)) {
        if (strlen($password) < 8) {
            $errors['password'] = "Password minimal 8 karakter!";
        } elseif (strlen($password) > 72) {
            $errors['password'] = "Password tidak boleh lebih dari 72 karakter!";
        } elseif ($password !== $confirm_password) {
            $errors['password'] = "Password tidak cocok!";
        } elseif (strpos($password, ' ') !== false) {
            $errors['password'] = "Password tidak boleh mengandung spasi!";
        } elseif (strtolower($password) === strtolower($email)) {
            $errors['password'] = "Password tidak boleh sama dengan email!";
        } else {
            // Check password strength
            $strength_errors = [];
            if (!preg_match('/[A-Z]/', $password)) {
                $strength_errors[] = "huruf besar";
            }
            if (!preg_match('/[a-z]/', $password)) {
                $strength_errors[] = "huruf kecil";
            }
            if (!preg_match('/[0-9]/', $password)) {
                $strength_errors[] = "angka";
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $strength_errors[] = "karakter khusus";
            }
            
            if (!empty($strength_errors)) {
                $errors['password'] = "Password harus mengandung " . 
                                    implode(", ", $strength_errors) . "!";
            }
        }
    }

    // Join date validation
    $join_date = trim($data['join_date'] ?? '');
    if (!empty($join_date)) {
        $date = DateTime::createFromFormat('Y-m-d', $join_date);
        if (!$date || $date->format('Y-m-d') !== $join_date) {
            $errors['join_date'] = "Format tanggal tidak valid!";
        } elseif ($date > new DateTime()) {
            $errors['join_date'] = "Tanggal bergabung tidak boleh di masa depan!";
        }
    }
    $fields['join_date'] = $join_date;

    return [
        'valid' => empty($errors),
        'message' => empty($errors) ? '' : reset($errors),
        'errors' => $errors,
        'fields' => $fields
    ];
}

/**
 * Check if employee can be deactivated with comprehensive checks
 * @param int $employee_id Employee ID
 * @param PDO $pdo Database connection
 * @return array ['can_deactivate' => bool, 'message' => string, 'warnings' => array]
 */
function canDeactivateEmployee($employee_id, $pdo) {
    try {
        $warnings = [];
        
        // Check if employee exists and is not already inactive
        $stmt = $pdo->prepare("SELECT status, role, name FROM employees 
                              WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return [
                'can_deactivate' => false,
                'message' => "Karyawan tidak ditemukan atau sudah dihapus!",
                'warnings' => []
            ];
        }

        if ($employee['status'] == 0) {
            return [
                'can_deactivate' => false,
                'message' => "Karyawan sudah dalam status tidak aktif!",
                'warnings' => []
            ];
        }

        // Check for today's transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions 
                             WHERE employee_id = ? 
                             AND DATE(transaction_date) = DATE('now')
                             AND status != 'cancelled'");
        $stmt->execute([$employee_id]);
        $today_transactions = $stmt->fetchColumn();
        
        if ($today_transactions > 0) {
            return [
                'can_deactivate' => false,
                'message' => "Tidak dapat menonaktifkan karyawan yang memiliki " . 
                           "$today_transactions transaksi aktif hari ini!",
                'warnings' => []
            ];
        }

        // Check if last active admin
        if ($employee['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees 
                                 WHERE role = 'admin' 
                                 AND status = 1 
                                 AND deleted_at IS NULL 
                                 AND id != ?");
            $stmt->execute([$employee_id]);
            if ($stmt->fetchColumn() == 0) {
                return [
                    'can_deactivate' => false,
                    'message' => "Tidak dapat menonaktifkan admin terakhir yang aktif!",
                    'warnings' => []
                ];
            }
        }

        // Check for pending tasks or future transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions 
                             WHERE employee_id = ? 
                             AND status = 'pending'");
        $stmt->execute([$employee_id]);
        $pending_transactions = $stmt->fetchColumn();

        if ($pending_transactions > 0) {
            $warnings[] = "Terdapat $pending_transactions transaksi pending yang perlu ditangani.";
        }

        // Check for future scheduled shifts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_shifts 
                             WHERE employee_id = ? 
                             AND date > CURDATE()
                             AND status = 'scheduled'");
        $stmt->execute([$employee_id]);
        $future_shifts = $stmt->fetchColumn();

        if ($future_shifts > 0) {
            $warnings[] = "Terdapat $future_shifts jadwal shift di masa depan.";
        }

        // Check for recent activity
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log 
                             WHERE admin_id = ? 
                             AND created_at >= datetime('now', '-24 hours')
        $stmt->execute([$employee_id]);
        $recent_activity = $stmt->fetchColumn();

        if ($recent_activity > 0) {
            $warnings[] = "Karyawan memiliki $recent_activity aktivitas dalam 24 jam terakhir.";
        }

        return [
            'can_deactivate' => true,
            'message' => empty($warnings) ? '' : 
                        "Peringatan sebelum menonaktifkan " . $employee['name'],
            'warnings' => $warnings
        ];

    } catch (PDOException $e) {
        error_log("Error in canDeactivateEmployee: " . $e->getMessage());
        return [
            'can_deactivate' => false,
            'message' => "Terjadi kesalahan sistem. Silakan coba lagi.",
            'warnings' => []
        ];
    }
}

/**
 * Check if employee can be deleted with comprehensive checks
 * @param int $employee_id Employee ID
 * @param PDO $pdo Database connection
 * @return array ['can_delete' => bool, 'message' => string, 'warnings' => array]
 */
function canDeleteEmployee($employee_id, $pdo) {
    try {
        $warnings = [];
        
        // Check if employee exists and not already deleted
        $stmt = $pdo->prepare("SELECT role, status, name FROM employees 
                              WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            return [
                'can_delete' => false,
                'message' => "Karyawan tidak ditemukan atau sudah dihapus!",
                'warnings' => []
            ];
        }

        // Check for any transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $transaction_count = $stmt->fetchColumn();
        
        if ($transaction_count > 0) {
            return [
                'can_delete' => false,
                'message' => "Tidak dapat menghapus karyawan yang memiliki " . 
                           "$transaction_count transaksi! Silakan nonaktifkan karyawan sebagai gantinya.",
                'warnings' => []
            ];
        }

        // Check if last admin
        if ($employee['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees 
                                 WHERE role = 'admin' 
                                 AND deleted_at IS NULL 
                                 AND id != ?");
            $stmt->execute([$employee_id]);
            if ($stmt->fetchColumn() == 0) {
                return [
                    'can_delete' => false,
                    'message' => "Tidak dapat menghapus admin terakhir!",
                    'warnings' => []
                ];
            }
        }

        // Check for today's activities
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log 
                             WHERE admin_id = ? 
                             AND DATE(created_at) = DATE('now')
        $stmt->execute([$employee_id]);
        $today_activities = $stmt->fetchColumn();
        
        if ($today_activities > 0) {
            return [
                'can_delete' => false,
                'message' => "Tidak dapat menghapus karyawan yang memiliki " . 
                           "$today_activities aktivitas hari ini!",
                'warnings' => []
            ];
        }

        // Check for future shifts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_shifts 
                             WHERE employee_id = ? 
                             AND date > CURDATE()");
        $stmt->execute([$employee_id]);
        $future_shifts = $stmt->fetchColumn();

        if ($future_shifts > 0) {
            $warnings[] = "Terdapat $future_shifts jadwal shift yang akan dihapus.";
        }

        // Check for recent logins
        $stmt = $pdo->prepare("SELECT last_login_at FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $last_login = $stmt->fetchColumn();

        if ($last_login && strtotime($last_login) > strtotime('-24 hours')) {
            $warnings[] = "Karyawan baru saja login dalam 24 jam terakhir.";
        }

        return [
            'can_delete' => true,
            'message' => empty($warnings) ? '' : 
                        "Peringatan sebelum menghapus " . $employee['name'],
            'warnings' => $warnings
        ];

    } catch (PDOException $e) {
        error_log("Error in canDeleteEmployee: " . $e->getMessage());
        return [
            'can_delete' => false,
            'message' => "Terjadi kesalahan sistem. Silakan coba lagi.",
            'warnings' => []
        ];
    }
}

/**
 * Log employee activity with enhanced details
 * @param PDO $pdo Database connection
 * @param int $admin_id Admin user ID
 * @param string $action Action performed
 * @param string $details Action details
 * @param string|null $entity_type Entity type (optional)
 * @param int|null $entity_id Entity ID (optional)
 * @param array|null $old_values Old values (optional)
 * @param array|null $new_values New values (optional)
 * @return bool Success status
 */
function logEmployeeActivity($pdo, $admin_id, $action, $details, $entity_type = null, 
                           $entity_id = null, $old_values = null, $new_values = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log 
                              (admin_id, action, details, ip_address, user_agent,
                               entity_type, entity_id, old_values, new_values) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        return $stmt->execute([
            $admin_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $entity_type,
            $entity_id,
            $old_values ? json_encode($old_values) : null,
            $new_values ? json_encode($new_values) : null
        ]);
    } catch (PDOException $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Get comprehensive employee statistics
 * @param PDO $pdo Database connection
 * @param array $filters Optional filters
 * @return array Statistics data
 */
function getEmployeeStats($pdo, $filters = []) {
    try {
        $stats = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'admins' => 0,
            'employees' => 0,
            'active_today' => 0,
            'with_transactions' => 0,
            'avg_transactions' => 0,
            'max_transactions' => 0,
            'min_transactions' => 0,
            'total_transactions' => 0,
            'today_transactions' => 0,
            'pending_transactions' => 0,
            'last_registered' => null,
            'last_active' => null,
            'by_status' => [],
            'by_role' => [],
            'activity_summary' => [],
            'transaction_summary' => []
        ];

        // Base employee query
        $where = "WHERE deleted_at IS NULL";
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $where .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }

        // Get basic counts
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                SUM(CASE WHEN role = 'employee' THEN 1 ELSE 0 END) as employees,
                MAX(created_at) as last_registered,
                MAX(last_login_at) as last_active
                FROM employees $where";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $basic_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats = array_merge($stats, $basic_stats);

        // Get status distribution
        $sql = "SELECT status, COUNT(*) as count 
                FROM employees $where 
                GROUP BY status";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get role distribution
        $sql = "SELECT role, COUNT(*) as count 
                FROM employees $where 
                GROUP BY role";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stats['by_role'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get active today count
        $stmt = $pdo->query("SELECT COUNT(DISTINCT admin_id) as active_today 
                            FROM activity_log 
                            WHERE DATE(created_at) = CURDATE()");
        $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['active_today'] = $today_stats['active_today'];

        // Get transaction statistics
        $sql = "SELECT 
                COUNT(DISTINCT t.employee_id) as with_transactions,
                ROUND(AVG(transaction_count), 2) as avg_transactions,
                MAX(transaction_count) as max_transactions,
                MIN(transaction_count) as min_transactions,
                SUM(transaction_count) as total_transactions
                FROM (
                    SELECT employee_id, COUNT(*) as transaction_count 
                    FROM transactions 
                    GROUP BY employee_id
                ) t";
        $stmt = $pdo->query($sql);
        $trans_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats = array_merge($stats, $trans_stats);

        // Get today's transactions
        $stmt = $pdo->query("SELECT COUNT(*) 
                            FROM transactions 
                            WHERE DATE(transaction_date) = CURDATE()");
        $stats['today_transactions'] = $stmt->fetchColumn();

        // Get pending transactions
        $stmt = $pdo->query("SELECT COUNT(*) 
                            FROM transactions 
                            WHERE status = 'pending'");
        $stats['pending_transactions'] = $stmt->fetchColumn();

        // Get activity summary
        $sql = "SELECT action, COUNT(*) as count 
                FROM activity_log 
                WHERE DATE(created_at) >= DATE('now', '-30 days')
                GROUP BY action 
                ORDER BY count DESC 
                LIMIT 5";
        $stmt = $pdo->query($sql);
        $stats['activity_summary'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get transaction summary by day
        $sql = "SELECT DATE(transaction_date) as date, 
                COUNT(*) as count,
                SUM(final_amount) as total_amount
                FROM transactions 
                WHERE transaction_date >= DATE('now', '-30 days')
                GROUP BY DATE(transaction_date)
                ORDER BY date DESC";
        $stmt = $pdo->query($sql);
        $stats['transaction_summary'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    } catch (PDOException $e) {
        error_log("Error getting employee stats: " . $e->getMessage());
        return null;
    }
}

/**
 * Format currency amount with proper Indonesian format
 * @param float $amount Amount to format
 * @param bool $with_symbol Include currency symbol
 * @return string Formatted amount
 */
function formatCurrency($amount, $with_symbol = true) {
    $formatted = number_format($amount, 0, ',', '.');
    return $with_symbol ? 'Rp ' . $formatted : $formatted;
}

/**
 * Format date to Indonesian format with enhanced options
 * @param string $date Date string
 * @param bool $with_time Include time in output
 * @param bool $with_day Include day name
 * @return string Formatted date
 */
function formatDate($date, $with_time = true, $with_day = false) {
    if (empty($date)) return '-';
    
    $timestamp = strtotime($date);
    if ($timestamp === false) return '-';
    
    $format = '';
    if ($with_day) {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $format = $days[date('w', $timestamp)] . ', ';
    }
    
    $format .= 'd/m/Y';
    if ($with_time) {
        $format .= ' H:i';
    }
    
    return date($format, $timestamp);
}

/**
 * Get employee role name in Indonesian with description
 * @param string $role Role code
 * @param bool $with_description Include role description
 * @return string Role name and description
 */
function getEmployeeRoleName($role, $with_description = false) {
    $roles = [
        'admin' => [
            'name' => 'Administrator',
            'description' => 'Akses penuh ke semua fitur sistem'
        ],
        'employee' => [
            'name' => 'Karyawan',
            'description' => 'Akses terbatas untuk operasional harian'
        ]
    ];
    
    if (!isset($roles[$role])) {
        return 'Unknown Role';
    }
    
    return $with_description ? 
           $roles[$role]['name'] . ' - ' . $roles[$role]['description'] : 
           $roles[$role]['name'];
}

/**
 * Check if user has admin privileges with role verification
 * @param PDO $pdo Database connection
 * @param int $user_id User ID to check
 * @return bool True if user is admin
 */
function isAdmin($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT role, status, deleted_at 
                              FROM employees 
                              WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        return $user && 
               $user['role'] === 'admin' && 
               $user['status'] == 1 && 
               $user['deleted_at'] === null;
    } catch (PDOException $e) {
        error_log("Error checking admin status: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate secure random password with enhanced requirements
 * @param int $length Password length
 * @param bool $special Include special characters
 * @return string Generated password
 */
function generateSecurePassword($length = 12, $special = true) {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special_chars = '!@#$%^&*()_+-=[]{}|;:,.<>?';
    
    $password = '';
    
    // Ensure at least one of each required character type
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    
    if ($special) {
        $password .= $special_chars[random_int(0, strlen($special_chars) - 1)];
    }
    
    // Fill the rest with random characters
    $all_chars = $uppercase . $lowercase . $numbers;
    if ($special) {
        $all_chars .= $special_chars;
    }
    
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all_chars[random_int(0, strlen($all_chars) - 1)];
    }
    
    // Shuffle the password
    $password = str_shuffle($password);
    
    // Verify password meets requirements
    if (!preg_match('/[A-Z]/', $password) || 
        !preg_match('/[a-z]/', $password) || 
        !preg_match('/[0-9]/', $password) || 
        ($special && !preg_match('/[^A-Za-z0-9]/', $password))) {
        // If requirements not met, generate again
        return generateSecurePassword($length, $special);
    }
    
    return $password;
}
?>
