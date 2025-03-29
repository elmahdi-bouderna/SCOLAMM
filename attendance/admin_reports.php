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

// Report type and filters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'overview';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$module_id = isset($_GET['module_id']) ? intval($_GET['module_id']) : 0;
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Initialize reports data arrays
$overview_data = array(
    'total_users' => 0,
    'total_students' => 0,
    'total_professors' => 0,
    'total_modules' => 0,
    'total_attendances' => 0,
    'avg_attendance_rate' => 0,
    'attendance_trend' => array(),
    'module_stats' => array(),
);

$attendance_data = array();
$user_activity_data = array();
$module_engagement_data = array();

// Fetch data based on report type
switch($report_type) {
    case 'overview':
        // Get total counts
        $count_sql = "SELECT 
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM users WHERE role = 'student') AS total_students,
            (SELECT COUNT(*) FROM users WHERE role = 'professor') AS total_professors,
            (SELECT COUNT(*) FROM modules) AS total_modules,
            (SELECT COUNT(*) FROM attendance) AS total_attendances";
            
        if ($result = $link->query($count_sql)) {
            $overview_data = array_merge($overview_data, $result->fetch_assoc());
        }
        
        // Get attendance trend for the last 30 days
        $trend_sql = "SELECT DATE(scan_time) as date, COUNT(*) as count 
                     FROM attendance 
                     WHERE scan_time BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()
                     GROUP BY DATE(scan_time)
                     ORDER BY date";
                     
        if ($result = $link->query($trend_sql)) {
            $dates = array();
            $counts = array();
            
            while ($row = $result->fetch_assoc()) {
                $dates[] = $row['date'];
                $counts[] = $row['count'];
            }
            
            $overview_data['attendance_trend'] = array(
                'dates' => $dates,
                'counts' => $counts
            );
        }
        
        // Get module statistics
        $module_stats_sql = "SELECT m.id, m.name, 
                            (SELECT COUNT(*) FROM student_module WHERE module_id = m.id) as student_count,
                            (SELECT COUNT(*) FROM attendance WHERE module_id = m.id) as attendance_count
                            FROM modules m
                            ORDER BY attendance_count DESC
                            LIMIT 5";
                            
        if ($result = $link->query($module_stats_sql)) {
            while ($row = $result->fetch_assoc()) {
                $overview_data['module_stats'][] = $row;
            }
        }
        
        // Calculate average attendance rate
        if ($overview_data['total_students'] > 0 && count($overview_data['module_stats']) > 0) {
            $total_possible = 0;
            $total_actual = 0;
            
            foreach ($overview_data['module_stats'] as $module) {
                $total_possible += $module['student_count'] * 30; // Assuming 30 sessions per module
                $total_actual += $module['attendance_count'];
            }
            
            $overview_data['avg_attendance_rate'] = ($total_actual / $total_possible) * 100;
        }
        break;
        
    case 'attendance':
        // Get attendance data for specific module or all modules
        $where_clause = "WHERE a.scan_time BETWEEN ? AND ?";
        $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
        $types = "ss";
        
        if ($module_id > 0) {
            $where_clause .= " AND a.module_id = ?";
            $params[] = $module_id;
            $types .= "i";
        }
        
        if ($user_id > 0) {
            $where_clause .= " AND a.student_id = ?";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $sql = "SELECT a.id, a.scan_time, u.name as student_name, m.name as module_name, 
        ts.scan_time as class_start_time, p.name as professor_name
        FROM attendance a
        JOIN users u ON a.student_id = u.id  /* Changed user_id to student_id */
        JOIN modules m ON a.module_id = m.id
        LEFT JOIN teacher_scans ts ON a.teacher_scan_id = ts.id
        LEFT JOIN users p ON ts.professor_id = p.id
        $where_clause
        ORDER BY a.scan_time DESC
        LIMIT 500";

                
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $attendance_data[] = $row;
                }
            }
            
            $stmt->close();
        }
        break;
        
    case 'user_activity':
        // Get user login and activity data
        $where_clause = "WHERE u.last_login BETWEEN ? AND ?";
        $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
        $types = "ss";
        
        if ($user_id > 0) {
            $where_clause .= " AND u.id = ?";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $sql = "SELECT u.id, u.name, u.email, u.role, u.last_login,
                (SELECT COUNT(*) FROM attendance WHERE student_id = u.id) as attendance_count,
                (SELECT MAX(scan_time) FROM attendance WHERE student_id = u.id) as last_attendance
                FROM users u
                $where_clause
                ORDER BY u.last_login DESC";
                
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $user_activity_data[] = $row;
                }
            }
            
            $stmt->close();
        }
        break;
        
    case 'module_engagement':
        // Get module engagement statistics
        $where_clause = "WHERE 1=1";
        $params = array();
        $types = "";
        
        if ($module_id > 0) {
            $where_clause .= " AND m.id = ?";
            $params[] = $module_id;
            $types .= "i";
        }
        
        $sql = "SELECT m.id, m.name, 
        (SELECT COUNT(*) FROM student_module WHERE module_id = m.id) as enrolled_students,
        (SELECT COUNT(*) FROM professor_module WHERE module_id = m.id) as assigned_professors,
        (SELECT COUNT(*) FROM attendance WHERE module_id = m.id AND scan_time BETWEEN ? AND ?) as attendance_count,
        (SELECT COUNT(DISTINCT student_id) FROM attendance WHERE module_id = m.id AND scan_time BETWEEN ? AND ?) as unique_attendees,
        (SELECT COUNT(DISTINCT DATE(scan_time)) FROM attendance WHERE module_id = m.id AND scan_time BETWEEN ? AND ?) as days_with_attendance
        FROM modules m
        $where_clause
        ORDER BY attendance_count DESC";
                
        if ($stmt = $link->prepare($sql)) {
            // Add date parameters
            $date_params = array(
                $start_date . ' 00:00:00', 
                $end_date . ' 23:59:59',
                $start_date . ' 00:00:00', 
                $end_date . ' 23:59:59',
                $start_date . ' 00:00:00', 
                $end_date . ' 23:59:59'
            );
            $all_params = array_merge($date_params, $params);
            $full_types = "ssssss" . $types;
            
            $stmt->bind_param($full_types, ...$all_params);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    // Calculate attendance rate
                    if ($row['enrolled_students'] > 0 && $row['days_with_attendance'] > 0) {
                        $row['attendance_rate'] = ($row['unique_attendees'] / $row['enrolled_students']) * 100;
                    } else {
                        $row['attendance_rate'] = 0;
                    }
                    
                    $module_engagement_data[] = $row;
                }
            }
            
            $stmt->close();
        }
        break;
}

// Get all modules for filter dropdown
$modules = array();
$sql = "SELECT id, name FROM modules ORDER BY name";
if ($result = $link->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
}

// Get users for filter dropdown
$users = array();
$sql = "SELECT id, name, role FROM users ORDER BY name";
if ($result = $link->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
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
    <title>Reports | Admin Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stats-card {
            border-left: 4px solid;
            background-color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            height: 100%;
        }
        .stats-card-students {
            border-left-color: #28a745;
        }
        .stats-card-professors {
            border-left-color: #17a2b8;
        }
        .stats-card-modules {
            border-left-color: #ffc107;
        }
        .stats-card-attendance {
            border-left-color: #dc3545;
        }
        .stats-icon {
            opacity: 0.8;
            font-size: 2rem;
        }
        .stats-icon-students {
            color: #28a745;
        }
        .stats-icon-professors {
            color: #17a2b8;
        }
        .stats-icon-modules {
            color: #ffc107;
        }
        .stats-icon-attendance {
            color: #dc3545;
        }
        .nav-pills .nav-link.active {
            background-color: #007bff;
        }
        .filters-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .attendance-rate {
            font-size: 2rem;
            font-weight: bold;
        }
        .progress {
            height: 10px;
            border-radius: 5px;
        }
        .table th {
            font-weight: 600;
        }
        .module-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .attendance-chart-container {
            position: relative;
            height: 300px;
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
                        <i class="fas fa-chart-bar"></i> Reports
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
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">Reports Dashboard</h1>
                            <p class="mb-0">Generate and analyze attendance reports and statistics</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <ul class="nav nav-pills" id="reportTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $report_type == 'overview' ? 'active' : ''; ?>" 
                                   href="?type=overview">
                                    <i class="fas fa-chart-pie mr-1"></i> Overview
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $report_type == 'attendance' ? 'active' : ''; ?>" 
                                   href="?type=attendance&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                    <i class="fas fa-clipboard-check mr-1"></i> Attendance Reports
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $report_type == 'user_activity' ? 'active' : ''; ?>" 
                                   href="?type=user_activity&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                    <i class="fas fa-user-clock mr-1"></i> User Activity
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $report_type == 'module_engagement' ? 'active' : ''; ?>" 
                                   href="?type=module_engagement&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                    <i class="fas fa-book-reader mr-1"></i> Module Engagement
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($report_type != 'overview'): ?>
                            <!-- Date range and filter controls -->
                            <div class="filters-card mb-4">
                                <form method="get" action="" id="filterForm">
                                    <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                                    
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
                                        
                                        <?php if ($report_type == 'attendance' || $report_type == 'module_engagement'): ?>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="module_id">Module:</label>
                                                    <select class="form-control" id="module_id" name="module_id">
                                                        <option value="0">All Modules</option>
                                                        <?php foreach($modules as $module): ?>
                                                        <option value="<?php echo $module['id']; ?>" <?php echo $module_id == $module['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($module['name']); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($report_type == 'attendance' || $report_type == 'user_activity'): ?>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="user_id">User:</label>
                                                    <select class="form-control" id="user_id" name="user_id">
                                                        <option value="0">All Users</option>
                                                        <?php foreach($users as $user): ?>
                                                        <option value="<?php echo $user['id']; ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($user['name']); ?> (<?php echo ucfirst($user['role']); ?>)
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="col-md-<?php echo ($report_type == 'attendance' || ($report_type == 'user_activity' && $report_type == 'module_engagement')) ? '12' : '3'; ?> text-right align-self-end">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-filter mr-1"></i> Apply Filters
                                            </button>
                                            <button type="button" class="btn btn-success ml-2" onclick="exportReport()">
                                                <i class="fas fa-file-export mr-1"></i> Export
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Report Content -->
                        <?php if ($report_type == 'overview'): ?>
                            <!-- Overview Report -->
                            <div class="row">
                                <div class="col-md-3 mb-4">
                                    <div class="stats-card stats-card-students p-3 d-flex justify-content-between">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Students</h6>
                                            <h3><?php echo number_format($overview_data['total_students']); ?></h3>
                                        </div>
                                        <div class="stats-icon stats-icon-students">
                                            <i class="fas fa-user-graduate"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-4">
                                    <div class="stats-card stats-card-professors p-3 d-flex justify-content-between">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Professors</h6>
                                            <h3><?php echo number_format($overview_data['total_professors']); ?></h3>
                                        </div>
                                        <div class="stats-icon stats-icon-professors">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-4">
                                    <div class="stats-card stats-card-modules p-3 d-flex justify-content-between">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Modules</h6>
                                            <h3><?php echo number_format($overview_data['total_modules']); ?></h3>
                                        </div>
                                        <div class="stats-icon stats-icon-modules">
                                            <i class="fas fa-book"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-4">
                                    <div class="stats-card stats-card-attendance p-3 d-flex justify-content-between">
                                        <div>
                                            <h6 class="text-muted mb-1">Total Attendances</h6>
                                            <h3><?php echo number_format($overview_data['total_attendances']); ?></h3>
                                        </div>
                                        <div class="stats-icon stats-icon-attendance">
                                            <i class="fas fa-clipboard-check"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-8 mb-4">
                                    <div class="card">
                                        <div class="card-header bg-white">
                                            <i class="fas fa-chart-line text-primary mr-2"></i> Attendance Trend (Last 30 Days)
                                        </div>
                                        <div class="card-body">
                                            <div class="attendance-chart-container">
                                                <canvas id="attendanceTrendChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4 mb-4">
                                    <div class="card">
                                        <div class="card-header bg-white">
                                            <i class="fas fa-percentage text-primary mr-2"></i> Average Attendance Rate
                                        </div>
                                        <div class="card-body text-center">
                                            <div class="attendance-rate mb-3"><?php echo number_format($overview_data['avg_attendance_rate'], 1); ?>%</div>
                                            <div class="progress mb-3">
                                                <div class="progress-bar bg-success" role="progressbar" 
                                                     style="width: <?php echo min(100, $overview_data['avg_attendance_rate']); ?>%" 
                                                     aria-valuenow="<?php echo $overview_data['avg_attendance_rate']; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100"></div>
                                            </div>
                                            <div class="text-muted">
                                                Overall attendance rate across all modules
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-header bg-white">
                                    <i class="fas fa-list-alt text-primary mr-2"></i> Module Statistics
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Module Name</th>
                                                    <th>Students</th>
                                                    <th>Attendances</th>
                                                    <th>Avg. Attendance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($overview_data['module_stats']) > 0): ?>
                                                    <?php foreach ($overview_data['module_stats'] as $module): ?>
                                                        <tr>
                                                            <td>
                                                                <a href="edit_module.php?id=<?php echo $module['id']; ?>"><?php echo htmlspecialchars($module['name']); ?></a>
                                                            </td>
                                                            <td><?php echo number_format($module['student_count']); ?></td>
                                                            <td><?php echo number_format($module['attendance_count']); ?></td>
                                                            <td>
                                                                <?php 
                                                                $avg = ($module['student_count'] > 0) 
                                                                    ? ($module['attendance_count'] / ($module['student_count'] * 30)) * 100 
                                                                    : 0;
                                                                ?>
                                                                <div class="progress" style="height: 5px; width: 100px;">
                                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                                        style="width: <?php echo min(100, $avg); ?>%"></div>
                                                                </div>
                                                                <small><?php echo number_format($avg, 1); ?>%</small>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center">No module data available.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        
                        <?php elseif ($report_type == 'attendance'): ?>
                            <!-- Attendance Report -->
                            <div class="card mb-4">
                                <div class="card-header bg-white">
                                    <i class="fas fa-clipboard-check text-primary mr-2"></i> Attendance Records
                                    <span class="badge badge-primary ml-2"><?php echo count($attendance_data); ?> records</span>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0" id="attendanceTable">
                                            <thead>
                                                <tr>
                                                    <th>Date & Time</th>
                                                    <th>Student Name</th>
                                                    <th>Module</th>
                                                    <th>Class Started</th>
                                                    <th>Professor</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($attendance_data) > 0): ?>
                                                    <?php foreach ($attendance_data as $record): ?>
                                                        <tr>
                                                            <td><?php echo date('Y-m-d H:i', strtotime($record['scan_time'])); ?></td>
                                                            <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($record['module_name']); ?></td>
                                                            <td>
                                                                <?php if (!empty($record['class_start_time'])): ?>
                                                                    <?php echo date('Y-m-d H:i', strtotime($record['class_start_time'])); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">N/A</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($record['professor_name'])): ?>
                                                                    <?php echo htmlspecialchars($record['professor_name']); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">N/A</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center">No attendance records found with the selected criteria.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php if (count($attendance_data) > 10): ?>
                                <div class="card-footer bg-white text-center">
                                    <button class="btn btn-sm btn-outline-primary" id="showMoreBtn">Show More</button>
                                </div>
                                <?php endif; ?>
                            </div>
                        
                        <?php elseif ($report_type == 'user_activity'): ?>
                            <!-- User Activity Report -->
                            <div class="card mb-4">
                                <div class="card-header bg-white">
                                    <i class="fas fa-user-clock text-primary mr-2"></i> User Activity Report
                                    <span class="badge badge-primary ml-2"><?php echo count($user_activity_data); ?> users</span>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0" id="userActivityTable">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Role</th>
                                                    <th>Last Login</th>
                                                    <th>Attendances</th>
                                                    <th>Last Attendance</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($user_activity_data) > 0): ?>
                                                    <?php foreach ($user_activity_data as $user): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="d-flex align-items-center">
                                                                    <div class="user-avatar mr-2">
                                                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                                    </div>
                                                                    <?php echo htmlspecialchars($user['name']); ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="badge badge-<?php echo $user['role'] == 'admin' ? 'danger' : ($user['role'] == 'professor' ? 'info' : 'success'); ?>">
                                                                    <?php echo ucfirst($user['role']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($user['last_login'])): ?>
                                                                    <?php echo date('Y-m-d H:i', strtotime($user['last_login'])); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Never</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo number_format($user['attendance_count']); ?></td>
                                                            <td>
                                                                <?php if (!empty($user['last_attendance'])): ?>
                                                                    <?php echo date('Y-m-d H:i', strtotime($user['last_attendance'])); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Never</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="?type=attendance&user_id=<?php echo $user['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-sm btn-outline-info ml-1">
                                                                    <i class="fas fa-clipboard-list"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center">No user activity data found with the selected criteria.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            
                        <?php elseif ($report_type == 'module_engagement'): ?>
                            <!-- Module Engagement Report -->
                            <div class="card mb-4">
                                <div class="card-header bg-white">
                                    <i class="fas fa-book-reader text-primary mr-2"></i> Module Engagement Report
                                    <span class="badge badge-primary ml-2"><?php echo count($module_engagement_data); ?> modules</span>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0" id="moduleEngagementTable">
                                            <thead>
                                                <tr>
                                                    <th>Module Name</th>
                                                    <th>Enrolled Students</th>
                                                    <th>Professors</th>
                                                    <th>Attendance Count</th>
                                                    <th>Unique Attendees</th>
                                                    <th>Days With Activity</th>
                                                    <th>Attendance Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($module_engagement_data) > 0): ?>
                                                    <?php foreach ($module_engagement_data as $module): ?>
                                                        <tr>
                                                            <td>
                                                                <a href="edit_module.php?id=<?php echo $module['id']; ?>"><?php echo htmlspecialchars($module['name']); ?></a>
                                                            </td>
                                                            <td><?php echo number_format($module['enrolled_students']); ?></td>
                                                            <td><?php echo number_format($module['assigned_professors']); ?></td>
                                                            <td><?php echo number_format($module['attendance_count']); ?></td>
                                                            <td><?php echo number_format($module['unique_attendees']); ?></td>
                                                            <td><?php echo number_format($module['days_with_attendance']); ?></td>
                                                            <td>
                                                                <div class="progress" style="height: 5px; width: 100px;">
                                                                    <div class="progress-bar 
                                                                        <?php echo $module['attendance_rate'] < 50 ? 'bg-danger' : ($module['attendance_rate'] < 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                                                        role="progressbar" 
                                                                        style="width: <?php echo min(100, $module['attendance_rate']); ?>%"></div>
                                                                </div>
                                                                <small><?php echo number_format($module['attendance_rate'], 1); ?>%</small>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="7" class="text-center">No module engagement data found with the selected criteria.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tableexport@5.2.0/dist/js/tableexport.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize date pickers
            flatpickr("#start_date", {
                dateFormat: "Y-m-d"
            });
            
            flatpickr("#end_date", {
                dateFormat: "Y-m-d"
            });
            
            // Show more button for attendance records
            $("#showMoreBtn").click(function() {
                $("#attendanceTable tbody tr:hidden").slice(0, 10).show();
                if ($("#attendanceTable tbody tr:hidden").length == 0) {
                    $(this).hide();
                }
            });
            
            // Limit initial display of attendance records
            if ($("#attendanceTable tbody tr").length > 10) {
                $("#attendanceTable tbody tr").slice(10).hide();
            } else {
                $("#showMoreBtn").hide();
            }
            
            <?php if ($report_type == 'overview' && !empty($overview_data['attendance_trend'])): ?>
            // Attendance trend chart
            const ctx = document.getElementById('attendanceTrendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($overview_data['attendance_trend']['dates']); ?>,
                    datasets: [{
                        label: 'Attendances',
                        data: <?php echo json_encode($overview_data['attendance_trend']['counts']); ?>,
                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        pointBackgroundColor: 'rgba(0, 123, 255, 1)',
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
        
        // Export table data
        function exportReport() {
            let table;
            let filename;
            
            switch('<?php echo $report_type; ?>') {
                case 'attendance':
                    table = document.getElementById('attendanceTable');
                    filename = 'attendance_report_<?php echo date('Y-m-d'); ?>';
                    break;
                case 'user_activity':
                    table = document.getElementById('userActivityTable');
                    filename = 'user_activity_report_<?php echo date('Y-m-d'); ?>';
                    break;
                case 'module_engagement':
                    table = document.getElementById('moduleEngagementTable');
                    filename = 'module_engagement_report_<?php echo date('Y-m-d'); ?>';
                    break;
                default:
                    alert('Export not available for this report type');
                    return;
            }
            
            if (table) {
                TableExport(table, {
                    headers: true,
                    footers: true,
                    formats: ['xlsx', 'csv', 'txt'],
                    filename: filename,
                    bootstrap: true,
                    position: 'top',
                    ignoreRows: null,
                    ignoreCols: null,
                    trimWhitespace: true
                });
                
                // Trigger the xlsx button click
                setTimeout(function() {
                    document.querySelector('.xlsx').click();
                }, 100);
            }
        }
    </script>
</body>
</html>