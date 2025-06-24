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

// Prevent self-deletion
if ($id == $_SESSION['user_id']) {
    $_SESSION['message'] = "Anda tidak dapat menghapus akun Anda sendiri!";
    header("Location: employees.php");
    exit();
}

try {
    // Begin transaction
    $pdo->beginTransaction();

    // First check if employee exists and get their details
    $stmt = $pdo->prepare("SELECT e.*, 
                          (SELECT COUNT(*) FROM transactions t WHERE t.employee_id = e.id) as transaction_count,
                          (SELECT COUNT(*) FROM transactions t 
                           WHERE t.employee_id = e.id 
                           AND DATE(t.transaction_date) = CURDATE()) as today_transactions,
                          (SELECT COUNT(*) FROM employees 
                           WHERE role = 'admin' AND status = 1 
                           AND deleted_at IS NULL AND id != ?) as active_admins
                          FROM employees e 
                          WHERE e.id = ? AND e.deleted_at IS NULL");
    $stmt->execute([$id, $id]);
    $employee = $stmt->fetch();

    if (!$employee) {
        throw new Exception("Karyawan tidak ditemukan atau sudah dihapus!");
    }

    // Various checks before deletion
    $checks = [
        'transactions' => [
            'condition' => $employee['transaction_count'] > 0,
            'message' => "Tidak dapat menghapus karyawan yang memiliki riwayat transaksi! Silakan nonaktifkan karyawan sebagai gantinya."
        ],
        'today_transactions' => [
            'condition' => $employee['today_transactions'] > 0,
            'message' => "Tidak dapat menghapus karyawan yang memiliki transaksi hari ini!"
        ],
        'last_admin' => [
            'condition' => $employee['role'] === 'admin' && $employee['active_admins'] == 0,
            'message' => "Tidak dapat menghapus admin terakhir yang aktif!"
        ]
    ];

    foreach ($checks as $check) {
        if ($check['condition']) {
            throw new Exception($check['message']);
        }
    }

    // Perform soft delete
    $stmt = $pdo->prepare("UPDATE employees SET 
                          deleted_at = CURRENT_TIMESTAMP,
                          deleted_by = ?,
                          status = 0,
                          updated_at = CURRENT_TIMESTAMP,
                          updated_by = ?
                          WHERE id = ?");
    
    if ($stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id])) {
        // Log the deletion
        $log_details = sprintf(
            "Menghapus karyawan: %s (%s) - Role: %s, Total Transaksi: %d",
            $employee['name'],
            $employee['email'],
            $employee['role'],
            $employee['transaction_count']
        );
        
        logEmployeeActivity($pdo, $_SESSION['user_id'], 'delete_employee', $log_details);

        // Handle any pending tasks or reassignments
        $pending_tasks = handlePendingTasks($pdo, $id);
        
        // Commit transaction
        $pdo->commit();

        $_SESSION['message'] = sprintf(
            "Karyawan %s berhasil dihapus!%s",
            htmlspecialchars($employee['name']),
            $pending_tasks ? " " . $pending_tasks : ""
        );
    } else {
        throw new Exception("Gagal menghapus karyawan");
    }

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log the error
    error_log("Error in delete_employee.php: " . $e->getMessage());
    $_SESSION['message'] = $e->getMessage();
}

header("Location: employees.php");
exit();

/**
 * Handle any pending tasks for the deleted employee
 */
function handlePendingTasks($pdo, $employee_id) {
    $messages = [];

    // Check for future transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions 
                          WHERE employee_id = ? 
                          AND DATE(transaction_date) > CURDATE()");
    $stmt->execute([$employee_id]);
    $future_transactions = $stmt->fetchColumn();

    if ($future_transactions > 0) {
        // Cancel or reassign future transactions
        $stmt = $pdo->prepare("UPDATE transactions 
                              SET status = 'cancelled',
                                  notes = CONCAT(COALESCE(notes, ''), ' [Dibatalkan karena karyawan dihapus]')
                              WHERE employee_id = ? 
                              AND DATE(transaction_date) > CURDATE()");
        $stmt->execute([$employee_id]);
        
        $messages[] = sprintf("Terdapat %d transaksi mendatang yang telah dibatalkan.", $future_transactions);
    }

    // Add more task handling as needed
    // For example: reassigning responsibilities, handling ongoing projects, etc.

    return implode(" ", $messages);
}
?>
