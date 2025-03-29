<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "admin") {
    header("location: login.html");
    exit;
}
require_once "config.php";

// Fetch admin's info
$admin_id = $_SESSION["id"];
$admin_name = $_SESSION["name"];

// Initialize variables
$message = '';
$messageType = '';

// Default settings
$default_settings = [
    'system_name' => 'IoT Attendance System',
    'institution_name' => 'University',
    'logo_url' => '',
    'enable_registration' => '0',
    'enable_reset_password' => '1',
    'max_login_attempts' => '5',
    'lockout_time' => '30',
    'session_timeout' => '120',
    'timezone' => 'UTC',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'attendance_grace_period' => '15',
    'late_threshold' => '10',
    'absent_threshold' => '30',
    'enable_email_notifications' => '0',
    'admin_email' => '',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'beacon_uuid' => '',
    'beacon_sensitivity' => 'medium',
    'attendance_verification_method' => 'qrcode',
    'enable_location_verification' => '0',
    'enable_facial_recognition' => '0',
    'backup_frequency' => 'weekly',
    'backup_retention' => '30',
    'maintenance_mode' => '0',
    'enable_log_rotation' => '1',
    'log_rotation_days' => '30'
];

// Check if settings table exists
$settings_table_exists = false;
$create_table = false;

$check_table = "SHOW TABLES LIKE 'settings'";
$result = $link->query($check_table);
if ($result) {
    $settings_table_exists = ($result->num_rows > 0);
}

// Create settings table if it doesn't exist and user confirmed
if (!$settings_table_exists) {
    if (isset($_GET['create_table']) && $_GET['create_table'] === 'yes') {
        $create_sql = "CREATE TABLE settings (
            setting_key VARCHAR(50) NOT NULL,
            setting_value TEXT NULL,
            setting_group VARCHAR(30) NOT NULL DEFAULT 'general',
            display_name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            input_type VARCHAR(20) NOT NULL DEFAULT 'text',
            input_options TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        if ($link->query($create_sql) === TRUE) {
            $settings_table_exists = true;
            
            // Insert default settings
            foreach ($default_settings as $key => $value) {
                $display_name = ucwords(str_replace('_', ' ', $key));
                $group = 'general';
                $input_type = 'text';
                $description = '';
                $options = null;
                
                // Set appropriate groups and input types
                if (strpos($key, 'enable_') === 0) {
                    $input_type = 'switch';
                }
                
                if (strpos($key, 'smtp_') === 0 || $key === 'admin_email') {
                    $group = 'email';
                }
                
                if ($key === 'smtp_password') {
                    $input_type = 'password';
                }
                
                if (strpos($key, 'attendance_') === 0 || $key === 'late_threshold' || $key === 'absent_threshold' || strpos($key, 'beacon_') === 0) {
                    $group = 'attendance';
                }
                
                if ($key === 'timezone') {
                    $group = 'general';
                    $input_type = 'select';
                    $options = json_encode(DateTimeZone::listIdentifiers(DateTimeZone::ALL));
                }
                
                if ($key === 'date_format') {
                    $group = 'general';
                    $input_type = 'select';
                    $options = json_encode(['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y']);
                }
                
                if ($key === 'time_format') {
                    $group = 'general';
                    $input_type = 'select';
                    $options = json_encode(['H:i:s', 'h:i:s A', 'h:i A']);
                }
                
                if (strpos($key, 'backup_') === 0 || strpos($key, 'log_') === 0) {
                    $group = 'system';
                }
                
                if ($key === 'maintenance_mode') {
                    $group = 'system';
                    $input_type = 'switch';
                }
                
                if ($key === 'attendance_verification_method') {
                    $group = 'attendance';
                    $input_type = 'select';
                    $options = json_encode(['qrcode', 'nfc', 'beacon', 'facial', 'manual']);
                }
                
                if ($key === 'beacon_sensitivity') {
                    $group = 'attendance';
                    $input_type = 'select';
                    $options = json_encode(['low', 'medium', 'high']);
                }
                
                // Insert setting
                $insert_sql = "INSERT INTO settings (setting_key, setting_value, setting_group, display_name, description, input_type, input_options) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)";
                              
                if ($stmt = $link->prepare($insert_sql)) {
                    $stmt->bind_param("sssssss", $key, $value, $group, $display_name, $description, $input_type, $options);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            // Log table creation if system_logs table exists
            $check_logs = "SHOW TABLES LIKE 'system_logs'";
            $result = $link->query($check_logs);
            if ($result && $result->num_rows > 0) {
                $ip = $_SERVER['REMOTE_ADDR'];
                $now = date('Y-m-d H:i:s');
                $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                if ($stmt = $link->prepare($log_sql)) {
                    $action = "Table Created";
                    $description = "Settings table was created";
                    $level = "info";
                    $module = "settings";
                    
                    $stmt->bind_param("sisssiss", $now, $admin_id, $admin_name, $action, $description, $ip, $level, $module);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            $message = 'Settings table has been created successfully.';
            $messageType = 'success';
        } else {
            $message = 'Error creating settings table: ' . $link->error;
            $messageType = 'danger';
        }
    } else {
        $create_table = true;
    }
}

// Load current settings
$settings = [];
$setting_groups = [];

if ($settings_table_exists) {
    $sql = "SELECT * FROM settings ORDER BY setting_group, display_name";
    if ($result = $link->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row;
            
            // Track unique setting groups
            if (!in_array($row['setting_group'], $setting_groups)) {
                $setting_groups[] = $row['setting_group'];
            }
        }
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_settings']) && $settings_table_exists) {
    $updated_count = 0;
    $log_entries = [];
    
    foreach ($_POST as $key => $value) {
        // Skip non-setting fields
        if ($key === 'save_settings') {
            continue;
        }
        
        if (isset($settings[$key])) {
            $old_value = $settings[$key]['setting_value'];
            
            // Update setting if changed
            if ($old_value != $value) {
                $update_sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
                
                if ($stmt = $link->prepare($update_sql)) {
                    $stmt->bind_param("ss", $value, $key);
                    
                    if ($stmt->execute()) {
                        $updated_count++;
                        
                        // For password fields, don't log the actual value
                        $log_value = ($settings[$key]['input_type'] === 'password') ? '********' : $value;
                        $log_entries[] = "$key changed from '{$old_value}' to '{$log_value}'";
                        
                        // Update local settings array
                        $settings[$key]['setting_value'] = $value;
                    }
                    
                    $stmt->close();
                }
            }
        }
    }
    
    if ($updated_count > 0) {
        $message = "$updated_count settings have been updated successfully.";
        $messageType = 'success';
        
        // Log changes if system_logs table exists
        $check_logs = "SHOW TABLES LIKE 'system_logs'";
        $result = $link->query($check_logs);
        if ($result && $result->num_rows > 0) {
            $ip = $_SERVER['REMOTE_ADDR'];
            $now = date('Y-m-d H:i:s');
            $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            if ($stmt = $link->prepare($log_sql)) {
                $action = "Settings Updated";
                $description = "Updated " . $updated_count . " settings: " . implode(", ", $log_entries);
                $level = "info";
                $module = "settings";
                
                $stmt->bind_param("sisssiss", $now, $admin_id, $admin_name, $action, $description, $ip, $level, $module);
                $stmt->execute();
                $stmt->close();
            }
        }
    } else {
        $message = "No settings were changed.";
        $messageType = 'info';
    }
}

// Reset settings to default if requested
if (isset($_POST['reset_settings']) && $settings_table_exists) {
    foreach ($default_settings as $key => $value) {
        if (isset($settings[$key])) {
            $update_sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
            
            if ($stmt = $link->prepare($update_sql)) {
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    
    $message = "All settings have been reset to default values.";
    $messageType = 'success';
    
    // Log reset if system_logs table exists
    $check_logs = "SHOW TABLES LIKE 'system_logs'";
    $result = $link->query($check_logs);
    if ($result && $result->num_rows > 0) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $now = date('Y-m-d H:i:s');
        $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        if ($stmt = $link->prepare($log_sql)) {
            $action = "Settings Reset";
            $description = "All settings were reset to default values";
            $level = "warning";
            $module = "settings";
            
            $stmt->bind_param("sisssiss", $now, $admin_id, $admin_name, $action, $description, $ip, $level, $module);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Reload settings
    $settings = [];
    $sql = "SELECT * FROM settings ORDER BY setting_group, display_name";
    if ($result = $link->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row;
        }
    }
}

// Get current date and time
$currentDateTime = isset($settings['timezone']['setting_value']) ? 
                   date($settings['date_format']['setting_value'] . ' ' . $settings['time_format']['setting_value'], 
                        strtotime('2025-03-17 02:42:57')) : 
                   '2025-03-17 02:42:57';

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Admin Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
        }
        .navbar {
            background-color: #343a40 !important;
        }
        .navbar-dark .navbar-brand {
            color: #ffffff;
        }
        .navbar-dark .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.8);
        }
        .navbar-dark .navbar-text {
            color: rgba(255, 255, 255, 0.7);
        }
        .sidebar {
            height: calc(100vh - 56px);
            position: fixed;
            padding-top: 20px;
            background-color: #212529;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            margin-bottom: 5px;
        }
        .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .sidebar .nav-link.active {
            background-color: #007bff;
            color: white;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .welcome-banner {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .datetime {
            color: white;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .card-header {
            font-weight: 600;
            padding: 15px;
        }
        .settings-nav .nav-link {
            padding: 10px 15px;
            border-radius: 5px;
            color: #495057;
        }
        .settings-nav .nav-link.active {
            background-color: #007bff;
            color: white;
        }
        .settings-nav .nav-link i {
            margin-right: 8px;
        }
        .setting-group {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .setting-group:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }
        .custom-switch .custom-control-label::before {
            width: 2rem;
            height: 1.25rem;
            border-radius: 1rem;
        }
        .custom-switch .custom-control-label::after {
            width: calc(1.25rem - 4px);
            height: calc(1.25rem - 4px);
            border-radius: calc(2rem - 4px);
        }
        .custom-switch .custom-control-input:checked ~ .custom-control-label::after {
            transform: translateX(0.75rem);
        }
        .form-group label {
            font-weight: 600;
        }
        .setting-description {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .sticky-buttons {
            position: sticky;
            bottom: 0;
            background-color: #fff;
            padding: 15px 0;
            border-top: 1px solid #dee2e6;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="#">
            <i class="fas fa-user-shield mr-2"></i>
            <?php echo isset($settings['system_name']) ? htmlspecialchars($settings['system_name']['setting_value']) : 'IoT Attendance System'; ?> | Admin
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="admin_dashboard.php">
                        <i class="fas fa-home"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_users.php">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_modules.php">
                        <i class="fas fa-book"></i> Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_reports.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_logs.php">
                        <i class="fas fa-history"></i> Logs
                    </a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="#">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
            <span class="navbar-text ml-auto">
                <i class="fas fa-user-circle mr-1"></i> Welcome, <?php echo htmlspecialchars($admin_name); ?>
            </span>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <nav id="sidebar" class="col-md-2 d-none d-md-block sidebar">
                <div class="sidebar-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="admin_dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_users.php">
                                <i class="fas fa-users"></i>
                                User Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_modules.php">
                                <i class="fas fa-book"></i>
                                Module Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_reports.php">
                                <i class="fas fa-chart-bar"></i>
                                Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_logs.php">
                                <i class="fas fa-history"></i>
                                System Logs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
                                <i class="fas fa-cog"></i>
                                System Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_backup.php">
                                <i class="fas fa-database"></i>
                                Backup & Restore
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">
                                <i class="fas fa-sign-out-alt"></i>
                                Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main role="main" class="col-md-10 ml-sm-auto col-lg-10 px-4 mt-4 pb-5">
                <div class="welcome-banner">
                    <div class="row">
                        <div class="col-md-8">
                            <h1 class="h2 mb-1">System Settings</h1>
                            <p class="mb-0">Configure and manage system preferences</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if ($create_table): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Settings table not found!</strong> Would you like to create it?
                        <div class="mt-2">
                            <a href="?create_table=yes" class="btn btn-sm btn-primary">Yes, create table</a>
                            <button type="button" class="btn btn-sm btn-secondary ml-2" data-dismiss="alert">No, dismiss</button>
                        </div>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php if ($messageType === 'success'): ?>
                            <i class="fas fa-check-circle mr-2"></i>
                        <?php elseif ($messageType === 'danger'): ?>
                            <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php else: ?>
                            <i class="fas fa-info-circle mr-2"></i>
                        <?php endif; ?>
                        <?php echo $message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if ($settings_table_exists && count($settings) > 0): ?>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="card mb-4">
                                <div class="card-header bg-white">
                                    <i class="fas fa-list-alt text-primary mr-2"></i> Settings Categories
                                </div>
                                <div class="card-body p-0">
                                    <ul class="nav flex-column settings-nav">
                                        <?php foreach ($setting_groups as $index => $group): ?>
                                            <li class="nav-item">
                                                <a class="nav-link <?php echo ($index === 0) ? 'active' : ''; ?>" 
                                                   href="#settings-<?php echo $group; ?>" 
                                                   data-toggle="tab">
                                                    <?php if ($group === 'general'): ?>
                                                        <i class="fas fa-cogs"></i>
                                                    <?php elseif ($group === 'attendance'): ?>
                                                        <i class="fas fa-clipboard-check"></i>
                                                    <?php elseif ($group === 'email'): ?>
                                                        <i class="fas fa-envelope"></i>
                                                    <?php elseif ($group === 'system'): ?>
                                                        <i class="fas fa-server"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-sliders-h"></i>
                                                    <?php endif; ?>
                                                    <?php echo ucfirst($group); ?> Settings
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-header bg-white">
                                    <i class="fas fa-question-circle text-primary mr-2"></i> Help
                                </div>
                                <div class="card-body">
                                    <p>Configure your system settings to match your institution's requirements.</p>
                                    <p>Settings are organized by category in the tabs to the left.</p>
                                    <p>Don't forget to save your changes after modifying settings.</p>
                                    <div class="mt-3">
                                        <a href="#" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#settingsHelpModal">
                                            <i class="fas fa-book mr-1"></i> View Documentation
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-9">
                            <form method="post" action="">
                                <div class="card mb-5">
                                    <div class="card-header bg-white">
                                        <i class="fas fa-cog text-primary mr-2"></i> System Configuration
                                    </div>
                                    <div class="card-body">
                                        <div class="tab-content">
                                            <?php foreach ($setting_groups as $index => $group): ?>
                                                <div class="tab-pane fade <?php echo ($index === 0) ? 'show active' : ''; ?>" 
                                                     id="settings-<?php echo $group; ?>">
                                                    
                                                    <h4 class="mb-4"><?php echo ucfirst($group); ?> Settings</h4>
                                                    
                                                    <?php 
                                                    $group_settings = array_filter($settings, function($setting) use ($group) {
                                                        return $setting['setting_group'] === $group;
                                                    });
                                                    
                                                    foreach ($group_settings as $key => $setting): 
                                                    ?>
                                                        <div class="form-group row setting-group">
                                                            <label for="<?php echo $key; ?>" class="col-md-4 col-form-label">
                                                                <?php echo htmlspecialchars($setting['display_name']); ?>
                                                            </label>
                                                            <div class="col-md-8">
                                                                <?php if ($setting['input_type'] === 'text'): ?>
                                                                    <input type="text" class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>" 
                                                                           value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                                                
                                                                <?php elseif ($setting['input_type'] === 'password'): ?>
                                                                    <input type="password" class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>" 
                                                                           value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                                                
                                                                <?php elseif ($setting['input_type'] === 'textarea'): ?>
                                                                    <textarea class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>" 
                                                                              rows="3"><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                                                                
                                                                <?php elseif ($setting['input_type'] === 'number'): ?>
                                                                    <input type="number" class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>" 
                                                                    value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                                                                
                                                                <?php elseif ($setting['input_type'] === 'select'): ?>
                                                                    <select class="form-control" id="<?php echo $key; ?>" name="<?php echo $key; ?>">
                                                                        <?php 
                                                                        $options = [];
                                                                        if (!empty($setting['input_options'])) {
                                                                            $options = json_decode($setting['input_options'], true);
                                                                        }
                                                                        
                                                                        foreach ($options as $option): 
                                                                        ?>
                                                                            <option value="<?php echo htmlspecialchars($option); ?>" 
                                                                                <?php echo ($setting['setting_value'] === $option) ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($option); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                
                                                                <?php elseif ($setting['input_type'] === 'switch'): ?>
                                                                    <div class="custom-control custom-switch">
                                                                        <input type="hidden" name="<?php echo $key; ?>" value="0">
                                                                        <input type="checkbox" class="custom-control-input" id="<?php echo $key; ?>" 
                                                                               name="<?php echo $key; ?>" value="1" 
                                                                               <?php echo ($setting['setting_value'] == '1') ? 'checked' : ''; ?>>
                                                                        <label class="custom-control-label" for="<?php echo $key; ?>"></label>
                                                                    </div>
                                                                <?php endif; ?>
                                                                
                                                                <?php if (!empty($setting['description'])): ?>
                                                                    <small class="form-text text-muted setting-description">
                                                                        <?php echo htmlspecialchars($setting['description']); ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="sticky-buttons text-right">
                                    <button type="submit" name="save_settings" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i> Save Settings
                                    </button>
                                    <button type="button" class="btn btn-secondary ml-2" data-toggle="modal" data-target="#resetSettingsModal">
                                        <i class="fas fa-undo mr-1"></i> Reset to Default
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                
                <?php elseif ($settings_table_exists): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-cog fa-4x text-muted mb-3"></i>
                            <h4>Settings Initialized</h4>
                            <p class="text-muted">The settings table has been created. Configure your system using the options above.</p>
                            <a href="admin_settings.php" class="btn btn-primary mt-3">Refresh Page</a>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Reset Settings Modal -->
    <div class="modal fade" id="resetSettingsModal" tabindex="-1" role="dialog" aria-labelledby="resetSettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resetSettingsModalLabel">Reset Settings</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Are you sure you want to reset all settings to their default values?
                    </div>
                    <p>This action will undo any customizations you've made to the system settings.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form method="post" action="">
                        <button type="submit" name="reset_settings" class="btn btn-danger">Reset All Settings</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Help Modal -->
    <div class="modal fade" id="settingsHelpModal" tabindex="-1" role="dialog" aria-labelledby="settingsHelpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="settingsHelpModalLabel">Settings Documentation</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="settingsHelpAccordion">
                        <?php foreach ($setting_groups as $index => $group): ?>
                        <div class="card">
                            <div class="card-header" id="heading<?php echo ucfirst($group); ?>">
                                <h2 class="mb-0">
                                    <button class="btn btn-link btn-block text-left" type="button" 
                                            data-toggle="collapse" data-target="#collapse<?php echo ucfirst($group); ?>" 
                                            aria-expanded="<?php echo ($index === 0) ? 'true' : 'false'; ?>" 
                                            aria-controls="collapse<?php echo ucfirst($group); ?>">
                                        <?php echo ucfirst($group); ?> Settings
                                    </button>
                                </h2>
                            </div>

                            <div id="collapse<?php echo ucfirst($group); ?>" 
                                 class="collapse <?php echo ($index === 0) ? 'show' : ''; ?>" 
                                 aria-labelledby="heading<?php echo ucfirst($group); ?>" 
                                 data-parent="#settingsHelpAccordion">
                                <div class="card-body">
                                    <?php if ($group === 'general'): ?>
                                        <p><strong>System Name:</strong> The name that appears in the header and browser title.</p>
                                        <p><strong>Institution Name:</strong> The name of your school, university, or organization.</p>
                                        <p><strong>Logo URL:</strong> A URL to your organization's logo image.</p>
                                        <p><strong>Enable Registration:</strong> Allow new users to register for accounts.</p>
                                        <p><strong>Enable Reset Password:</strong> Allow users to reset their passwords via email.</p>
                                        <p><strong>Max Login Attempts:</strong> Number of failed login attempts before account lockout.</p>
                                        <p><strong>Lockout Time:</strong> Duration in minutes for which accounts remain locked.</p>
                                        <p><strong>Session Timeout:</strong> Time in minutes before inactive users are logged out.</p>
                                        <p><strong>Timezone:</strong> The default timezone for date and time display.</p>
                                        <p><strong>Date Format:</strong> How dates are displayed throughout the system.</p>
                                        <p><strong>Time Format:</strong> How times are displayed throughout the system.</p>
                                        
                                    <?php elseif ($group === 'attendance'): ?>
                                        <p><strong>Attendance Grace Period:</strong> Time in minutes after class start when attendance is still counted as on-time.</p>
                                        <p><strong>Late Threshold:</strong> Time in minutes after grace period when attendance is marked as late.</p>
                                        <p><strong>Absent Threshold:</strong> Time in minutes after which students are marked as absent.</p>
                                        <p><strong>Beacon UUID:</strong> The UUID for BLE beacons if using beacon-based attendance.</p>
                                        <p><strong>Beacon Sensitivity:</strong> How sensitive the beacon detection should be.</p>
                                        <p><strong>Attendance Verification Method:</strong> The primary method used to verify attendance.</p>
                                        <p><strong>Enable Location Verification:</strong> Require location verification for attendance.</p>
                                        <p><strong>Enable Facial Recognition:</strong> Use facial recognition for attendance verification.</p>
                                        
                                    <?php elseif ($group === 'email'): ?>
                                        <p><strong>Enable Email Notifications:</strong> Send emails for important events and notifications.</p>
                                        <p><strong>Admin Email:</strong> Email address for administrative notifications.</p>
                                        <p><strong>SMTP Host:</strong> Server address for sending emails.</p>
                                        <p><strong>SMTP Port:</strong> Port number for the email server.</p>
                                        <p><strong>SMTP Username:</strong> Username for authenticating with the email server.</p>
                                        <p><strong>SMTP Password:</strong> Password for authenticating with the email server.</p>
                                        <p><strong>SMTP Encryption:</strong> Type of encryption to use for email (TLS/SSL).</p>
                                        
                                    <?php elseif ($group === 'system'): ?>
                                        <p><strong>Backup Frequency:</strong> How often automatic backups should be created.</p>
                                        <p><strong>Backup Retention:</strong> Number of days to keep backups before deletion.</p>
                                        <p><strong>Maintenance Mode:</strong> Temporarily disable system for maintenance.</p>
                                        <p><strong>Enable Log Rotation:</strong> Automatically archive and delete old logs.</p>
                                        <p><strong>Log Rotation Days:</strong> Number of days to keep logs before rotation.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Current date and time: <?php echo $currentDateTime; ?>
            
            // Prevent submit on Enter key for inputs except in textareas
            $(window).keydown(function(event) {
                if (event.keyCode === 13 && event.target.nodeName !== 'TEXTAREA') {
                    event.preventDefault();
                    return false;
                }
            });
            
            // Tab navigation
            $('.settings-nav a').click(function(e) {
                e.preventDefault();
                $(this).tab('show');
            });
            
            // Toggle password visibility
            $('.toggle-password').click(function() {
                const input = $($(this).attr('toggle'));
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    $(this).find('i').removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    input.attr('type', 'password');
                    $(this).find('i').removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Update date/time preview when changing formats
            $('#date_format, #time_format').change(function() {
                const currentDateTime = new Date('2025-03-17 02:46:58');
                const dateFormat = $('#date_format').val();
                const timeFormat = $('#time_format').val();
                
                // Update preview using a simple implementation
                let formattedDateTime = formatDateTime(currentDateTime, dateFormat, timeFormat);
                $('#datetime-preview').text(formattedDateTime);
            });
            
            function formatDateTime(date, dateFormat, timeFormat) {
                // Simple formatter implementation (real implementation would be more robust)
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');
                
                let formatted = dateFormat
                    .replace('Y', year)
                    .replace('m', month)
                    .replace('d', day);
                    
                formatted += ' ' + timeFormat
                    .replace('H', hours)
                    .replace('h', (hours % 12 || 12))
                    .replace('i', minutes)
                    .replace('s', seconds)
                    .replace('A', (date.getHours() >= 12 ? 'PM' : 'AM'));
                    
                return formatted;
            }
        });
    </script>
</body>
</html>
                                                                           