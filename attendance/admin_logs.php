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

// Pagination and filter parameters
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = 100;
$offset = ($page - 1) * $records_per_page;

$log_type = isset($_GET['type']) ? $_GET['type'] : '';
$level = isset($_GET['level']) ? $_GET['level'] : '';
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Check if logs table exists
$table_exists = false;
$create_table = false;

$check_table = "SHOW TABLES LIKE 'system_logs'";
$result = $link->query($check_table);
if ($result) {
    $table_exists = ($result->num_rows > 0);
}

// Create logs table if it doesn't exist and user confirmed
if (!$table_exists) {
    if (isset($_GET['create_table']) && $_GET['create_table'] === 'yes') {
        $create_sql = "CREATE TABLE system_logs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL,
            user_id INT(11) NULL,
            username VARCHAR(255) NULL,
            action VARCHAR(255) NOT NULL,
            description TEXT NULL,
            ip_address VARCHAR(45) NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            module VARCHAR(50) NULL,
            PRIMARY KEY (id),
            INDEX (timestamp),
            INDEX (level),
            INDEX (module)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        if ($link->query($create_sql) === TRUE) {
            $table_exists = true;
            
            // Insert a log about table creation
            $ip = $_SERVER['REMOTE_ADDR'];
            $now = date('Y-m-d H:i:s');
            $insert_log = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            if ($stmt = $link->prepare($insert_log)) {
                $action = "Table Created";
                $description = "System logs table was created";
                $level = "info";
                $module = "system";
                
                $stmt->bind_param("sisssiss", $now, $admin_id, $admin_name, $action, $description, $ip, $level, $module);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $create_error = $link->error;
        }
    } else {
        $create_table = true;
    }
}

// Clear logs if requested
if ($table_exists && isset($_POST['clear_logs']) && isset($_POST['confirm_clear']) && $_POST['confirm_clear'] === 'yes') {
    $clear_before = isset($_POST['clear_before']) ? $_POST['clear_before'] : '';
    $clear_level = isset($_POST['clear_level']) ? $_POST['clear_level'] : '';
    
    $where = [];
    $params = [];
    $types = "";
    
    if (!empty($clear_before)) {
        $where[] = "timestamp < ?";
        $params[] = $clear_before . ' 23:59:59';
        $types .= "s";
    }
    
    if (!empty($clear_level)) {
        $where[] = "level = ?";
        $params[] = $clear_level;
        $types .= "s";
    }
    
    $where_clause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
    
    $clear_sql = "DELETE FROM system_logs" . $where_clause;
    
    if ($stmt = $link->prepare($clear_sql)) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if ($stmt->execute()) {
            $deleted_count = $stmt->affected_rows;
            $success_message = "$deleted_count log records have been deleted.";
            
            // Log the deletion
            $ip = $_SERVER['REMOTE_ADDR'];
            $now = date('Y-m-d H:i:s');
            $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            if ($log_stmt = $link->prepare($log_sql)) {
                $action = "Logs Cleared";
                $description = "$deleted_count logs were deleted by admin";
                $level = "warning";
                $module = "system";
                
                $log_stmt->bind_param("sisssiss", $now, $admin_id, $admin_name, $action, $description, $ip, $level, $module);
                $log_stmt->execute();
                $log_stmt->close();
            }
        } else {
            $error_message = "Error clearing logs: " . $stmt->error;
        }
        
        $stmt->close();
    }
}

// Process filters for log query
$logs = [];
$total_logs = 0;

if ($table_exists) {
    // Build WHERE clause based on filters
    $where = [];
    $params = [];
    $types = "";
    
    // Date range filter
    if (!empty($start_date) && !empty($end_date)) {
        $where[] = "timestamp BETWEEN ? AND ?";
        $params[] = $start_date . ' 00:00:00';
        $params[] = $end_date . ' 23:59:59';
        $types .= "ss";
    }
    
    // Level filter
    if (!empty($level)) {
        $where[] = "level = ?";
        $params[] = $level;
        $types .= "s";
    }
    
    // Module/type filter
    if (!empty($log_type)) {
        $where[] = "module = ?";
        $params[] = $log_type;
        $types .= "s";
    }
    
    // User filter
    if ($user_filter > 0) {
        $where[] = "user_id = ?";
        $params[] = $user_filter;
        $types .= "i";
    }
    
    // Search term
    if (!empty($search)) {
        $where[] = "(action LIKE ? OR description LIKE ? OR username LIKE ? OR ip_address LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ssss";
    }
    
    $where_clause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";
    
    // Count total matching records for pagination
    $count_sql = "SELECT COUNT(*) as total FROM system_logs" . $where_clause;
    
    if ($stmt = $link->prepare($count_sql)) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_logs = $row['total'];
            }
        }
        
        $stmt->close();
    }
    
    // Calculate total pages
    $total_pages = ceil($total_logs / $records_per_page);
    
    // Ensure page is within valid range
    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
    
    // Fetch logs with pagination
    $sql = "SELECT l.*, u.name as user_name
            FROM system_logs l
            LEFT JOIN users u ON l.user_id = u.id
            $where_clause
            ORDER BY l.timestamp DESC
            LIMIT ?, ?";
    
    if ($stmt = $link->prepare($sql)) {
        $params[] = $offset;
        $params[] = $records_per_page;
        $types .= "ii";
        
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        
        $stmt->close();
    }
}

// Get users for filter dropdown
$users = [];
$sql = "SELECT id, name FROM users ORDER BY name";
if ($result = $link->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Get available log types/modules
$log_types = [];
if ($table_exists) {
    $sql = "SELECT DISTINCT module FROM system_logs WHERE module IS NOT NULL ORDER BY module";
    if ($result = $link->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['module'])) {
                $log_types[] = $row['module'];
            }
        }
    }
}

// If no log types found, use default ones
if (empty($log_types)) {
    $log_types = ['system', 'authentication', 'attendance', 'user', 'module', 'settings'];
}

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs | Admin Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        .filters-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .log-level {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .level-info {
            background-color: #cfe2ff;
            color: #084298;
        }
        .level-warning {
            background-color: #fff3cd;
            color: #664d03;
        }
        .level-error {
            background-color: #f8d7da;
            color: #842029;
        }
        .level-debug {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .level-critical {
            background-color: #842029;
            color: #ffffff;
        }
        .log-module {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        .module-system {
            background-color: #e2e3e5;
            color: #41464b;
        }
        .module-authentication {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .module-user {
            background-color: #cfe2ff;
            color: #084298;
        }
        .module-attendance {
            background-color: #f8d7da;
            color: #842029;
        }
        .module-module {
            background-color: #fff3cd;
            color: #664d03;
        }
        .module-settings {
            background-color: #e2e3e5;
            color: #41464b;
        }
        .log-table {
            font-size: 0.875rem;
        }
        .log-table th {
            position: sticky;
            top: 0;
            background-color: #fff;
            z-index: 1;
        }
        .table-responsive {
            max-height: 800px;
        }
        .log-description {
            max-width: 400px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .log-description-full {
            white-space: normal;
            word-break: break-word;
        }
        .pagination {
            justify-content: center;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="#">
            <i class="fas fa-user-shield mr-2"></i>
            IoT Attendance System | Admin
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
                        <i class="fas fa-history"></i> Logs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_settings.php">
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
                            <a class="nav-link active" href="#">
                                <i class="fas fa-history"></i>
                                System Logs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_settings.php">
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

            <main role="main" class="col-md-10 ml-sm-auto col-lg-10 px-4 mt-4">
                <div class="welcome-banner">
                    <div class="row">
                        <div class="col-md-8">
                            <h1 class="h2 mb-1">System Logs</h1>
                            <p class="mb-0">View and manage system activity logs</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if ($create_table): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>System logs table not found!</strong> Would you like to create it?
                        <div class="mt-2">
                            <a href="?create_table=yes" class="btn btn-sm btn-primary">Yes, create table</a>
                            <button type="button" class="btn btn-sm btn-secondary ml-2" data-dismiss="alert">No, dismiss</button>
                        </div>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo $success_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo $error_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if ($table_exists): ?>
                    <div class="filters-card mb-4">
                        <form method="get" action="" id="filterForm">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="start_date">Start Date:</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="end_date">End Date:</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="level">Log Level:</label>
                                        <select class="form-control" id="level" name="level">
                                            <option value="">All Levels</option>
                                            <option value="info" <?php echo $level === 'info' ? 'selected' : ''; ?>>Info</option>
                                            <option value="warning" <?php echo $level === 'warning' ? 'selected' : ''; ?>>Warning</option>
                                            <option value="error" <?php echo $level === 'error' ? 'selected' : ''; ?>>Error</option>
                                            <option value="debug" <?php echo $level === 'debug' ? 'selected' : ''; ?>>Debug</option>
                                            <option value="critical" <?php echo $level === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="type">Log Type:</label>
                                        <select class="form-control" id="type" name="type">
                                            <option value="">All Types</option>
                                            <?php foreach ($log_types as $type): ?>
                                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $log_type === $type ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst(htmlspecialchars($type)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="user_id">User:</label>
                                        <select class="form-control" id="user_id" name="user_id">
                                            <option value="0">All Users</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?php echo $user['id']; ?>" <?php echo $user_filter === $user['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($user['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="search">Search:</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs...">
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-secondary" type="submit">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-5 text-right align-self-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter mr-1"></i> Apply Filters
                                    </button>
                                    <button type="reset" class="btn btn-secondary ml-2" onclick="window.location='admin_logs.php'">
                                        <i class="fas fa-redo mr-1"></i> Reset
                                    </button>
                                    <button type="button" class="btn btn-success ml-2" onclick="exportLogs()">
                                        <i class="fas fa-file-export mr-1"></i> Export
                                    </button>
                                    <button type="button" class="btn btn-danger ml-2" data-toggle="modal" data-target="#clearLogsModal">
                                        <i class="fas fa-trash-alt mr-1"></i> Clear Logs
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-history text-primary mr-2"></i> System Log Records
                                <span class="badge badge-primary ml-2"><?php echo number_format($total_logs); ?> records</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($logs) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 log-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 180px;">Timestamp</th>
                                                <th style="width: 150px;">User</th>
                                                <th style="width: 120px;">Action</th>
                                                <th>Description</th>
                                                <th style="width: 100px;">Level</th>
                                                <th style="width: 110px;">Module</th>
                                                <th style="width: 120px;">IP Address</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                                                    <td>
                                                        <?php if ($log['user_id']): ?>
                                                            <a href="edit_user.php?id=<?php echo $log['user_id']; ?>">
                                                                <?php echo !empty($log['user_name']) ? htmlspecialchars($log['user_name']) : htmlspecialchars($log['username']); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <?php echo !empty($log['username']) ? htmlspecialchars($log['username']) : '<span class="text-muted">System</span>'; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                                    <td>
                                                        <div class="log-description" data-toggle="tooltip" data-placement="top" title="Click to expand">
                                                            <?php echo htmlspecialchars($log['description']); ?>
                                                        </div>
                                                    </td>
                                                    </td>
                                                    <td>
                                                        <span class="log-level level-<?php echo $log['level']; ?>">
                                                            <?php echo ucfirst(htmlspecialchars($log['level'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="log-module module-<?php echo $log['module']; ?>">
                                                            <?php echo ucfirst(htmlspecialchars($log['module'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <div class="card-footer bg-white">
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination mb-0">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=1<?php echo (!empty($level)) ? '&level=' . $level : ''; ?><?php echo (!empty($log_type)) ? '&type=' . $log_type : ''; ?><?php echo ($user_filter > 0) ? '&user_id=' . $user_filter : ''; ?><?php echo (!empty($start_date)) ? '&start_date=' . $start_date : ''; ?><?php echo (!empty($end_date)) ? '&end_date=' . $end_date : ''; ?><?php echo (!empty($search)) ? '&search=' . urlencode($search) : ''; ?>" aria-label="First">
                                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo ($page - 1); ?><?php echo (!empty($level)) ? '&level=' . $level : ''; ?><?php echo (!empty($log_type)) ? '&type=' . $log_type : ''; ?><?php echo ($user_filter > 0) ? '&user_id=' . $user_filter : ''; ?><?php echo (!empty($start_date)) ? '&start_date=' . $start_date : ''; ?><?php echo (!empty($end_date)) ? '&end_date=' . $end_date : ''; ?><?php echo (!empty($search)) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Calculate range of page numbers to display
                                            $range = 2;
                                            $start_page = max(1, $page - $range);
                                            $end_page = min($total_pages, $page + $range);
                                            
                                            // Always show first page
                                            if ($start_page > 1) {
                                                echo '<li class="page-item"><a class="page-link" href="?page=1' . 
                                                    ((!empty($level)) ? '&level=' . $level : '') .
                                                    ((!empty($log_type)) ? '&type=' . $log_type : '') .
                                                    (($user_filter > 0) ? '&user_id=' . $user_filter : '') .
                                                    ((!empty($start_date)) ? '&start_date=' . $start_date : '') .
                                                    ((!empty($end_date)) ? '&end_date=' . $end_date : '') .
                                                    ((!empty($search)) ? '&search=' . urlencode($search) : '') .
                                                    '">1</a></li>';
                                                
                                                if ($start_page > 2) {
                                                    echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                                                }
                                            }
                                            
                                            // Display page numbers
                                            for ($i = $start_page; $i <= $end_page; $i++) {
                                                echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '">
                                                    <a class="page-link" href="?page=' . $i . 
                                                    ((!empty($level)) ? '&level=' . $level : '') .
                                                    ((!empty($log_type)) ? '&type=' . $log_type : '') .
                                                    (($user_filter > 0) ? '&user_id=' . $user_filter : '') .
                                                    ((!empty($start_date)) ? '&start_date=' . $start_date : '') .
                                                    ((!empty($end_date)) ? '&end_date=' . $end_date : '') .
                                                    ((!empty($search)) ? '&search=' . urlencode($search) : '') .
                                                    '">' . $i . '</a></li>';
                                            }
                                            
                                            // Always show last page
                                            if ($end_page < $total_pages) {
                                                if ($end_page < $total_pages - 1) {
                                                    echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                                                }
                                                
                                                echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . 
                                                    ((!empty($level)) ? '&level=' . $level : '') .
                                                    ((!empty($log_type)) ? '&type=' . $log_type : '') .
                                                    (($user_filter > 0) ? '&user_id=' . $user_filter : '') .
                                                    ((!empty($start_date)) ? '&start_date=' . $start_date : '') .
                                                    ((!empty($end_date)) ? '&end_date=' . $end_date : '') .
                                                    ((!empty($search)) ? '&search=' . urlencode($search) : '') .
                                                    '">' . $total_pages . '</a></li>';
                                            }
                                            ?>
                                            
                                            <?php if ($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo ($page + 1); ?><?php echo (!empty($level)) ? '&level=' . $level : ''; ?><?php echo (!empty($log_type)) ? '&type=' . $log_type : ''; ?><?php echo ($user_filter > 0) ? '&user_id=' . $user_filter : ''; ?><?php echo (!empty($start_date)) ? '&start_date=' . $start_date : ''; ?><?php echo (!empty($end_date)) ? '&end_date=' . $end_date : ''; ?><?php echo (!empty($search)) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Next">
                                                        <span aria-hidden="true">&raquo;</span>
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo (!empty($level)) ? '&level=' . $level : ''; ?><?php echo (!empty($log_type)) ? '&type=' . $log_type : ''; ?><?php echo ($user_filter > 0) ? '&user_id=' . $user_filter : ''; ?><?php echo (!empty($start_date)) ? '&start_date=' . $start_date : ''; ?><?php echo (!empty($end_date)) ? '&end_date=' . $end_date : ''; ?><?php echo (!empty($search)) ? '&search=' . urlencode($search) : ''; ?>" aria-label="Last">
                                                        <span aria-hidden="true">&raquo;&raquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="p-4 text-center">
                                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                    <p>No log records found matching your criteria.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif (!$create_table): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-database fa-4x text-muted mb-3"></i>
                            <h4>System Logs Table Created</h4>
                            <p class="text-muted">The system logs table has been successfully created. The system will now start recording activities.</p>
                            <a href="admin_logs.php" class="btn btn-primary mt-3">Refresh Page</a>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- Clear Logs Modal -->
    <div class="modal fade" id="clearLogsModal" tabindex="-1" role="dialog" aria-labelledby="clearLogsModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="clearLogsModalLabel">Clear System Logs</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Warning: This action cannot be undone.
                        </div>
                        
                        <div class="form-group">
                            <label for="clear_level">Clear logs with level:</label>
                            <select class="form-control" id="clear_level" name="clear_level">
                                <option value="">All Levels</option>
                                <option value="info">Info only</option>
                                <option value="debug">Debug only</option>
                                <option value="warning">Warning only</option>
                                <option value="error">Error only</option>
                                <option value="critical">Critical only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="clear_before">Clear logs before date:</label>
                            <input type="date" class="form-control" id="clear_before" name="clear_before" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                            <small class="form-text text-muted">Leave empty to clear all logs.</small>
                        </div>
                        
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="confirm_clear" name="confirm_clear" value="yes" required>
                                <label class="custom-control-label" for="confirm_clear">
                                    I understand that this action cannot be undone.
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="clear_logs" class="btn btn-danger">Clear Logs</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize date pickers
            flatpickr("#start_date", {
                dateFormat: "Y-m-d"
            });
            
            flatpickr("#end_date", {
                dateFormat: "Y-m-d"
            });
            
            flatpickr("#clear_before", {
                dateFormat: "Y-m-d"
            });
            
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();
            
            // Expandable log description
            $('.log-description').click(function() {
                $(this).toggleClass('log-description-full');
            });
        });
        
        // Export logs as CSV
        function exportLogs() {
            // Get table data
            const table = document.querySelector('.log-table');
            if (!table) return;
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    // Get text content, replace quotes and remove HTML
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                
                csv.push(row.join(','));
            }
            
            // Create and download file
            const csvString = csv.join('\n');
            const filename = 'system_logs_<?php echo date('Y-m-d_H-i'); ?>.csv';
            
            const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
            
            // Use FileSaver.js
            saveAs(blob, filename);
        }
    </script>
</body>
</html>