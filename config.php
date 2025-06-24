<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

$dbpath = __DIR__ . '/carwash.db';

try {
    $pdo = new PDO("sqlite:$dbpath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Enable foreign key support
    $pdo->exec('PRAGMA foreign_keys = ON;');
    
    // Create tables if they don't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'employee',
        status INTEGER NOT NULL DEFAULT 1,
        phone TEXT,
        address TEXT,
        join_date TEXT,
        last_login_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        entity_type TEXT,
        entity_id INTEGER,
        old_values TEXT,
        new_values TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES employees(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        vehicle_type TEXT NOT NULL,
        license_plate TEXT NOT NULL,
        wash_type_id INTEGER NOT NULL,
        payment_method_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        final_amount REAL NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        transaction_date TEXT DEFAULT CURRENT_TIMESTAMP,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id)
    )");

    // Create default admin if none exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE role = 'admin'");
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO employees (name, email, password, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([
            'Admin',
            'admin@example.com',
            password_hash('admin123', PASSWORD_DEFAULT)
        ]);
    }

} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please check the error logs.");
}
