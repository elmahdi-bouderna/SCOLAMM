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

// Get system statistics
// Total users count
$sql_users = "SELECT 
              COUNT(*) AS total_users,
              SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS student_count,
              SUM(CASE WHEN role = 'professor' THEN 1 ELSE 0 END) AS professor_count
              FROM users";
$total_users = 0;
$student_count = 0;
$professor_count = 0;

if ($result = $link->query($sql_users)) {
    if ($row = $result->fetch_assoc()) {
        $total_users = $row['total_users'];
        $student_count = $row['student_count'];
        $professor_count = $row['professor_count'];
    }
    $result->free();
}

// Total modules count
$sql_modules = "SELECT COUNT(*) AS total_modules FROM modules";
$total_modules = 0;

if ($result = $link->query($sql_modules)) {
    if ($row = $result->fetch_assoc()) {
        $total_modules = $row['total_modules'];
    }
    $result->free();
}

// Total attendance records count
$sql_attendance = "SELECT COUNT(*) AS total_attendance FROM attendance";
$total_attendance = 0;

if ($result = $link->query($sql_attendance)) {
    if ($row = $result->fetch_assoc()) {
        $total_attendance = $row['total_attendance'];
    }
    $result->free();
}

// Total sessions count
$sql_sessions = "SELECT COUNT(*) AS total_sessions FROM teacher_scans";
$total_sessions = 0;

if ($result = $link->query($sql_sessions)) {
    if ($row = $result->fetch_assoc()) {
        $total_sessions = $row['total_sessions'];
    }
    $result->free();
}

// Recent activities
$sql_recent = "SELECT a.scan_time, u.name AS student_name, m.name AS module_name, a.status
               FROM attendance a
               JOIN users u ON a.student_id = u.id
               JOIN modules m ON a.module_id = m.id
               ORDER BY a.scan_time DESC
               LIMIT 10";
$recent_activities = [];

if ($result = $link->query($sql_recent)) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
    $result->free();
}

// Recent user registrations
$sql_new_users = "SELECT id, name, email, role, last_login FROM users ORDER BY id DESC LIMIT 5";
$new_users = [];

if ($result = $link->query($sql_new_users)) {
    while ($row = $result->fetch_assoc()) {
        $new_users[] = $row;
    }
    $result->free();
}

// Module statistics
$sql_module_stats = "SELECT m.name AS module_name, 
                    (SELECT COUNT(*) FROM student_module WHERE module_id = m.id) AS student_count,
                    (SELECT COUNT(*) FROM teacher_scans WHERE module_id = m.id) AS session_count
                    FROM modules m
                    ORDER BY student_count DESC
                    LIMIT 5";
$module_stats = [];

if ($result = $link->query($sql_module_stats)) {
    while ($row = $result->fetch_assoc()) {
        $module_stats[] = $row;
    }
    $result->free();
}

// System logs (fictitious for this example - you would need to implement a logging system)
$system_logs = [
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'action' => 'System backup',
        'status' => 'Success',
        'details' => 'Automated daily backup completed'
    ],
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-3 hour')),
        'action' => 'User login',
        'status' => 'Success',
        'details' => 'Administrator login from 192.168.1.1'
    ],
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-5 hour')),
        'action' => 'Database optimization',
        'status' => 'Success',
        'details' => 'Scheduled optimization completed'
    ],
    [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 day')),
        'action' => 'System update',
        'status' => 'Success',
        'details' => 'Updated to version 1.2.3'
    ]
];

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
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
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #343a40;
            margin-bottom: 5px;
        }
        .stat-card .stat-label {
            font-size: 1rem;
            color: #6c757d;
        }
        .chart-container {
            position: relative;
            height: 250px;
            margin-bottom: 20px;
        }
        .status-success {
            color: #28a745;
        }
        .status-warning {
            color: #ffc107;
        }
        .status-danger {
            color: #dc3545;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .bg-primary-soft {
            background-color: #cfe2ff;
        }
        .bg-success-soft {
            background-color: #d1e7dd;
        }
        .bg-warning-soft {
            background-color: #fff3cd;
        }
        .bg-danger-soft {
            background-color: #f8d7da;
        }
        .bg-info-soft {
            background-color: #cff4fc;
        }
        .activity-timeline {
            position: relative;
            padding-left: 30px;
        }
        .activity-timeline:before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: #e9ecef;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 15px;
        }
        .timeline-item:before {
            content: '';
            position: absolute;
            left: -21px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #007bff;
        }
        .timeline-item.success:before {
            background-color: #28a745;
        }
        .timeline-item.warning:before {
            background-color: #ffc107;
        }
        .timeline-item.danger:before {
            background-color: #dc3545;
        }
        .timeline-header {
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="#">
            <i class="fas fa-user-shield mr-2"></i>
            Scolagile | Admin
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item active">
                    <a class="nav-link" href="#">
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
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">Admin Dashboard</h1>
                            <p class="mb-0">Welcome to the administrator control panel</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card bg-primary-soft">
                            <i class="fas fa-users text-primary"></i>
                            <div class="stat-value"><?php echo $total_users; ?></div>
                            <div class="stat-label">Total Users</div>
                            <small class="text-muted"><?php echo $student_count; ?> students, <?php echo $professor_count; ?> professors</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-success-soft">
                            <i class="fas fa-book text-success"></i>
                            <div class="stat-value"><?php echo $total_modules; ?></div>
                            <div class="stat-label">Total Modules</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-info-soft">
                            <i class="fas fa-calendar-check text-info"></i>
                            <div class="stat-value"><?php echo $total_sessions; ?></div>
                            <div class="stat-label">Total Sessions</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-warning-soft">
                            <i class="fas fa-clipboard-check text-warning"></i>
                            <div class="stat-value"><?php echo $total_attendance; ?></div>
                            <div class="stat-label">Attendance Records</div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-chart-bar text-primary mr-2"></i> System Overview</span>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="weekBtn">Week</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary active" id="monthBtn">Month</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="yearBtn">Year</button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="systemActivityChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-history text-primary mr-2"></i> Recent Activities
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Student</th>
                                                <th>Module</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($recent_activities) > 0): ?>
                                                <?php foreach($recent_activities as $activity): ?>
                                                    <tr>
                                                        <td><?php echo date('d M H:i', strtotime($activity['scan_time'])); ?></td>
                                                        <td><?php echo htmlspecialchars($activity['student_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($activity['module_name']); ?></td>
                                                        <td>
                                                            <?php if($activity['status'] == 'present'): ?>
                                                                <span class="badge badge-success">Present</span>
                                                            <?php elseif($activity['status'] == 'absent'): ?>
                                                                <span class="badge badge-danger">Absent</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-warning">Late</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No recent activities found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center py-2">
                                    <a href="admin_activities.php" class="btn btn-sm btn-outline-primary">View All Activities</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-user-plus text-primary mr-2"></i> Recent User Registrations
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php if(count($new_users) > 0): ?>
                                        <?php foreach($new_users as $user): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($user['name']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                                                    </div>
                                                    <span class="badge badge-<?php echo $user['role'] == 'student' ? 'info' : 'primary'; ?>">
                                                        <?php echo ucfirst($user['role']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="list-group-item">
                                            <div class="text-center">No recent registrations.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-center py-2">
                                    <a href="admin_users.php" class="btn btn-sm btn-outline-primary">Manage Users</a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-book text-primary mr-2"></i> Top Modules
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php if(count($module_stats) > 0): ?>
                                        <?php foreach($module_stats as $module): ?>
                                            <div class="list-group-item">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($module['module_name']); ?></h6>
                                                <div class="d-flex justify-content-between">
                                                    <small><i class="fas fa-users text-info mr-1"></i> <?php echo $module['student_count']; ?> students</small>
                                                    <small><i class="fas fa-calendar-check text-success mr-1"></i> <?php echo $module['session_count']; ?> sessions</small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="list-group-item">
                                            <div class="text-center">No module data available.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-center py-2">
                                    <a href="admin_modules.php" class="btn btn-sm btn-outline-primary">Manage Modules</a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-shield-alt text-primary mr-2"></i> System Logs
                            </div>
                            <div class="card-body">
                                <div class="activity-timeline">
                                    <?php foreach($system_logs as $index => $log): 
                                        $statusClass = 'primary';
                                        if($log['status'] == 'Success') $statusClass = 'success';
                                        elseif($log['status'] == 'Warning') $statusClass = 'warning';
                                        elseif($log['status'] == 'Error') $statusClass = 'danger';
                                    ?>
                                        <div class="timeline-item <?php echo strtolower($log['status']); ?>">
                                            <div class="timeline-header">
                                                <?php echo $log['timestamp']; ?>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <div><?php echo htmlspecialchars($log['action']); ?></div>
                                                <span class="badge badge-<?php echo $statusClass; ?>">
                                                    <?php echo htmlspecialchars($log['status']); ?>
                                                </span>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($log['details']); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="admin_logs.php" class="btn btn-sm btn-outline-primary">View All Logs</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-users text-primary mr-2"></i> User Distribution
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="userDistributionChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-check-circle text-primary mr-2"></i> Attendance Rate
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="attendanceRateChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Quick Actions</h5>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <a href="admin_users.php?action=add" class="btn btn-outline-primary btn-block py-3">
                                    <i class="fas fa-user-plus mb-2 d-block" style="font-size: 2rem;"></i>
                                    Add New User
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="admin_modules.php?action=add" class="btn btn-outline-success btn-block py-3">
                                    <i class="fas fa-book-medical mb-2 d-block" style="font-size: 2rem;"></i>
                                    Create Module
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="admin_reports.php" class="btn btn-outline-info btn-block py-3">
                                    <i class="fas fa-chart-pie mb-2 d-block" style="font-size: 2rem;"></i>
                                    Generate Reports
                                </a>
                            </div>
                            <div class="col-md-3 mb-3">
                                <a href="admin_backup.php" class="btn btn-outline-secondary btn-block py-3">
                                    <i class="fas fa-database mb-2 d-block" style="font-size: 2rem;"></i>
                                    Backup System
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // Sample chart data
        const ctx1 = document.getElementById('systemActivityChart').getContext('2d');
        const systemActivityChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Sessions',
                    data: [12, 19, 15, 11, 16, 18, 22, 25, 23, 19, 21, <?php echo $total_sessions; ?>],
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    borderColor: 'rgba(0, 123, 255, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#007bff'
                }, {
                    label: 'Attendance Records',
                    data: [42, 55, 49, 60, 72, 78, 74, 80, 85, 78, 82, <?php echo $total_attendance; ?>],
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#28a745'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true
                        }
                    }]
                }
            }
        });
        
        // User Distribution Chart
        const ctx2 = document.getElementById('userDistributionChart').getContext('2d');
        const userDistributionChart = new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Students', 'Professors', 'Admins'],
                datasets: [{
                    data: [<?php echo $student_count; ?>, <?php echo $professor_count; ?>, <?php echo $total_users - $student_count - $professor_count; ?>],
                    backgroundColor: ['#007bff', '#28a745', '#dc3545'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    position: 'right'
                }
            }
        });
        
        // Attendance Rate Chart
        const ctx3 = document.getElementById('attendanceRateChart').getContext('2d');
        const attendanceRateChart = new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: ['IoT', 'Génie Informatique', 'Web Dev', 'AI', 'Mobile Dev'],
                datasets: [{
                    label: 'Attendance Rate (%)',
                    data: [85, 78, 90, 72, 63],
                    backgroundColor: ['#007bff', '#28a745', '#17a2b8', '#6f42c1', '#fd7e14'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            max: 100
                        }
                    }]
                }
            }
        });
        
        // Time period selection for system activity chart
        document.getElementById('weekBtn').addEventListener('click', function() {
            updateActiveBtn(this);
            updateChartData(systemActivityChart, [5, 7, 8, 6, 4, 9, 7], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);
        });
        
        document.getElementById('monthBtn').addEventListener('click', function() {
            updateActiveBtn(this);
            // Current data is already for month, no need to update
        });
        
        document.getElementById('yearBtn').addEventListener('click', function() {
            updateActiveBtn(this);
            // Data is already showing yearly trend
        });
        
        function updateActiveBtn(btn) {
            document.querySelectorAll('.btn-group .btn').forEach(button => {
                button.classList.remove('active');
            });
            btn.classList.add('active');
        }
        
        function updateChartData(chart, newData, newLabels) {
            if (newLabels) {
                chart.data.labels = newLabels;
            }
            
            chart.data.datasets.forEach(dataset => {
                if (dataset.label === 'Sessions') {
                    dataset.data = newData;
                } else if (dataset.label === 'Attendance Records') {
                    dataset.data = newData.map(value => value * 3 + Math.floor(Math.random() * 10)); // Some derived data
                }
            });
            
            chart.update();
        }
    </script>
</body>
</html>