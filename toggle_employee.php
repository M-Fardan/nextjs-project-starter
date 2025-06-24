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

// Get employee ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    $_SESSION['message'] = "ID Karyawan tidak valid!";
    header("Location: employees.php");
    exit();
}

// Prevent self-deactivation
if ($id == $_SESSION['user_id']) {
    $_SESSION['message'] = "Anda tidak dapat menonaktifkan akun Anda sendiri!";
    header("Location: employees.php");
    exit();
}

try {
    // Begin transaction
    $pdo->beginTransaction();

    // First check if employee exists and get current status
    $stmt = $pdo->prepare("SELECT e.*, 
                          (SELECT COUNT(*) FROM transactions t 
                           WHERE t.employee_id = e.id 
                           AND DATE(t.transaction_date) = CURDATE()) as today_transactions,
                          (SELECT COUNT(*) FROM employees 
                           WHERE role = 'admin' AND status = 1 AND id != ?) as active_admins
                          FROM employees e 
                          WHERE e.id = ?");
    $stmt->execute([$id, $id]);
    $employee = $stmt->fetch();

    if (!$employee) {
        throw new Exception("Karyawan tidak ditemukan!");
    }

    // Check if employee has any active transactions today before deactivating
    if ($employee['status'] == 1) {
        if ($employee['today_transactions'] > 0) {
            throw new Exception("Tidak dapat menonaktifkan karyawan yang memiliki transaksi hari ini!");
        }

        // If deactivating an admin, check if they're the last active admin
        if ($employee['role'] === 'admin' && $employee['active_admins'] == 0) {
            throw new Exception("Tidak dapat menonaktifkan admin terakhir yang aktif!");
        }
    }

    // Toggle the status
    $new_status = $employee['status'] ? 0 : 1;
    $action = $new_status ? 'activate' : 'deactivate';
    
    // Update employee status
    $stmt = $pdo->prepare("UPDATE employees SET 
                          status = ?, 
                          updated_at = CURRENT_TIMESTAMP,
                          updated_by = ?
                          WHERE id = ?");
    $stmt->execute([$new_status, $_SESSION['user_id'], $id]);

    // Log the action
    $log_details = sprintf(
        "%s karyawan: %s (%s) - Status: %s",
        $new_status ? 'Mengaktifkan' : 'Menonaktifkan',
        $employee['name'],
        $employee['email'],
        $new_status ? 'Aktif' : 'Tidak Aktif'
    );
    
    logEmployeeActivity($pdo, $_SESSION['user_id'], $action . '_employee', $log_details);

    // If deactivating, check and handle pending transactions
    if ($new_status == 0) {
        // Get any pending transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions 
                             WHERE employee_id = ? 
                             AND DATE(transaction_date) > CURDATE()");
        $stmt->execute([$id]);
        $pending_transactions = $stmt->fetchColumn();

        if ($pending_transactions > 0) {
            // Log pending transactions
            $log_details = sprintf(
                "Terdapat %d transaksi mendatang dari karyawan yang dinonaktifkan: %s",
                $pending_transactions,
                $employee['name']
            );
            logEmployeeActivity($pdo, $_SESSION['user_id'], 'pending_transactions_warning', $log_details);

            $_SESSION['message'] = sprintf(
                "Status karyawan %s berhasil dinonaktifkan! Perhatian: Terdapat %d transaksi mendatang yang perlu ditangani.",
                htmlspecialchars($employee['name']),
                $pending_transactions
            );
        } else {
            $_SESSION['message'] = sprintf(
                "Status karyawan %s berhasil dinonaktifkan!",
                htmlspecialchars($employee['name'])
            );
        }
    } else {
        $_SESSION['message'] = sprintf(
            "Status karyawan %s berhasil diaktifkan!",
            htmlspecialchars($employee['name'])
        );
    }

    // Commit transaction
    $pdo->commit();

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error in toggle_employee.php: " . $e->getMessage());
    $_SESSION['message'] = $e->getMessage();
}

header("Location: employees.php");
exit();
?>
