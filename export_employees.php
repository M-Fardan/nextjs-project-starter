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

// Get export format (default to CSV)
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

try {
    // Base query
    $sql = "SELECT 
                e.*,
                (SELECT COUNT(*) FROM transactions t WHERE t.employee_id = e.id) as total_transactions,
                (SELECT COUNT(*) FROM transactions t 
                 WHERE t.employee_id = e.id 
                 AND DATE(t.transaction_date) = CURDATE()) as today_transactions,
                (SELECT SUM(w.price) 
                 FROM transactions t 
                 JOIN wash_types w ON t.wash_type_id = w.id 
                 WHERE t.employee_id = e.id) as total_revenue,
                (SELECT MAX(transaction_date) 
                 FROM transactions 
                 WHERE employee_id = e.id) as last_transaction,
                (SELECT COUNT(*) FROM transactions t 
                 WHERE t.employee_id = e.id 
                 AND t.status = 'completed') as completed_transactions,
                (SELECT COUNT(*) FROM transactions t 
                 WHERE t.employee_id = e.id 
                 AND t.status = 'cancelled') as cancelled_transactions,
                (SELECT AVG(w.price) 
                 FROM transactions t 
                 JOIN wash_types w ON t.wash_type_id = w.id 
                 WHERE t.employee_id = e.id) as avg_transaction_value,
                (SELECT COUNT(DISTINCT DATE(transaction_date)) 
                 FROM transactions 
                 WHERE employee_id = e.id) as active_days
            FROM employees e 
            WHERE e.deleted_at IS NULL";

    $params = [];

    // Add date range filter
    if ($date_range !== 'all') {
        switch ($date_range) {
            case 'today':
                $sql .= " AND DATE(e.created_at) = CURDATE()";
                break;
            case 'week':
                $sql .= " AND e.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $sql .= " AND e.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
                break;
        }
    }

    // Add status filter
    if ($status_filter !== 'all') {
        $sql .= " AND e.status = ?";
        $params[] = ($status_filter === 'active' ? 1 : 0);
    }

    $sql .= " ORDER BY e.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    // Calculate summary statistics
    $summary = [
        'total_employees' => count($employees),
        'active_employees' => 0,
        'total_transactions' => 0,
        'total_revenue' => 0,
        'avg_transactions_per_employee' => 0
    ];

    foreach ($employees as $employee) {
        $summary['active_employees'] += $employee['status'];
        $summary['total_transactions'] += $employee['total_transactions'];
        $summary['total_revenue'] += $employee['total_revenue'];
    }

    $summary['avg_transactions_per_employee'] = $summary['total_employees'] > 0 ? 
        round($summary['total_transactions'] / $summary['total_employees'], 2) : 0;

    // Log the export action
    $log_details = sprintf(
        "Exported employees data (%d records) - Format: %s, Date Range: %s, Status: %s",
        count($employees),
        strtoupper($format),
        $date_range,
        $status_filter
    );
    logEmployeeActivity($pdo, $_SESSION['user_id'], 'export_employees', $log_details);

    // Set headers based on format
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="employees_' . date('Y-m-d_His') . '.csv"');
            exportCSV($employees, $summary);
            break;
            
        case 'excel':
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="employees_' . date('Y-m-d_His') . '.xls"');
            exportExcel($employees, $summary);
            break;
            
        default:
            throw new Exception("Format tidak didukung");
    }

    exit();

} catch (Exception $e) {
    error_log("Error in export_employees.php: " . $e->getMessage());
    $_SESSION['message'] = "Terjadi kesalahan saat mengekspor data. Silakan coba lagi.";
    header("Location: employees.php");
    exit();
}

function exportCSV($employees, $summary) {
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // Add UTF-8 BOM

    // Add summary section
    fputcsv($output, ['Ringkasan Data']);
    fputcsv($output, ['Total Karyawan', $summary['total_employees']]);
    fputcsv($output, ['Karyawan Aktif', $summary['active_employees']]);
    fputcsv($output, ['Total Transaksi', $summary['total_transactions']]);
    fputcsv($output, ['Total Pendapatan', formatCurrency($summary['total_revenue'])]);
    fputcsv($output, ['Rata-rata Transaksi per Karyawan', $summary['avg_transactions_per_employee']]);
    fputcsv($output, []); // Empty line separator

    // Add headers
    fputcsv($output, [
        'ID',
        'Nama',
        'Email',
        'Status',
        'Role',
        'Total Transaksi',
        'Transaksi Selesai',
        'Transaksi Dibatalkan',
        'Transaksi Hari Ini',
        'Total Pendapatan',
        'Rata-rata Nilai Transaksi',
        'Hari Aktif',
        'Transaksi Terakhir',
        'Tanggal Registrasi',
        'Terakhir Diperbarui',
        'Diperbarui Oleh'
    ]);

    // Add data
    foreach ($employees as $employee) {
        fputcsv($output, [
            $employee['id'],
            $employee['name'],
            $employee['email'],
            $employee['status'] ? 'Aktif' : 'Tidak Aktif',
            ucfirst($employee['role']),
            $employee['total_transactions'] ?? 0,
            $employee['completed_transactions'] ?? 0,
            $employee['cancelled_transactions'] ?? 0,
            $employee['today_transactions'] ?? 0,
            formatCurrency($employee['total_revenue'] ?? 0),
            formatCurrency($employee['avg_transaction_value'] ?? 0),
            $employee['active_days'] ?? 0,
            $employee['last_transaction'] ? formatDate($employee['last_transaction']) : '-',
            formatDate($employee['created_at']),
            formatDate($employee['updated_at']),
            $employee['updated_by'] ?? '-'
        ]);
    }

    fclose($output);
}

function exportExcel($employees, $summary) {
    // Start HTML table with styles
    echo '
    <html>
    <head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 5px; }
        th { background-color: #f0f0f0; }
        .summary { background-color: #e6e6e6; font-weight: bold; }
    </style>
    </head>
    <body>
    ';

    // Summary section
    echo '
    <table>
        <tr><td colspan="2" class="summary">Ringkasan Data</td></tr>
        <tr><td>Total Karyawan</td><td>' . $summary['total_employees'] . '</td></tr>
        <tr><td>Karyawan Aktif</td><td>' . $summary['active_employees'] . '</td></tr>
        <tr><td>Total Transaksi</td><td>' . $summary['total_transactions'] . '</td></tr>
        <tr><td>Total Pendapatan</td><td>' . formatCurrency($summary['total_revenue']) . '</td></tr>
        <tr><td>Rata-rata Transaksi per Karyawan</td><td>' . $summary['avg_transactions_per_employee'] . '</td></tr>
    </table>
    <br>
    ';

    // Main data table
    echo '
    <table>
        <tr>
            <th>ID</th>
            <th>Nama</th>
            <th>Email</th>
            <th>Status</th>
            <th>Role</th>
            <th>Total Transaksi</th>
            <th>Transaksi Selesai</th>
            <th>Transaksi Dibatalkan</th>
            <th>Transaksi Hari Ini</th>
            <th>Total Pendapatan</th>
            <th>Rata-rata Nilai Transaksi</th>
            <th>Hari Aktif</th>
            <th>Transaksi Terakhir</th>
            <th>Tanggal Registrasi</th>
            <th>Terakhir Diperbarui</th>
            <th>Diperbarui Oleh</th>
        </tr>
    ';

    foreach ($employees as $employee) {
        echo '<tr>';
        echo '<td>' . $employee['id'] . '</td>';
        echo '<td>' . htmlspecialchars($employee['name']) . '</td>';
        echo '<td>' . htmlspecialchars($employee['email']) . '</td>';
        echo '<td>' . ($employee['status'] ? 'Aktif' : 'Tidak Aktif') . '</td>';
        echo '<td>' . ucfirst($employee['role']) . '</td>';
        echo '<td>' . ($employee['total_transactions'] ?? 0) . '</td>';
        echo '<td>' . ($employee['completed_transactions'] ?? 0) . '</td>';
        echo '<td>' . ($employee['cancelled_transactions'] ?? 0) . '</td>';
        echo '<td>' . ($employee['today_transactions'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($employee['total_revenue'] ?? 0) . '</td>';
        echo '<td>' . formatCurrency($employee['avg_transaction_value'] ?? 0) . '</td>';
        echo '<td>' . ($employee['active_days'] ?? 0) . '</td>';
        echo '<td>' . ($employee['last_transaction'] ? formatDate($employee['last_transaction']) : '-') . '</td>';
        echo '<td>' . formatDate($employee['created_at']) . '</td>';
        echo '<td>' . formatDate($employee['updated_at']) . '</td>';
        echo '<td>' . ($employee['updated_by'] ?? '-') . '</td>';
        echo '</tr>';
    }

    echo '</table></body></html>';
}
?>
