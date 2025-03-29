<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "professor") {
    header("location: login.php");
    exit;
}
require_once "config.php";

// Fetch professor's info
$professor_id = $_SESSION["id"];
$professor_name = $_SESSION["name"];

// Fetch professor's modules count
$sql_modules_count = "SELECT COUNT(*) as module_count FROM professor_module WHERE professor_id = ?";
$module_count = 0;
if ($stmt = $link->prepare($sql_modules_count)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $module_count = $row['module_count'];
    }
    $stmt->close();
}

// Fetch total students across all modules taught by the professor
$sql_students_count = "SELECT COUNT(DISTINCT sm.student_id) as student_count 
                      FROM professor_module pm
                      JOIN student_module sm ON pm.module_id = sm.module_id
                      WHERE pm.professor_id = ?";
$student_count = 0;
if ($stmt = $link->prepare($sql_students_count)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $student_count = $row['student_count'];
    }
    $stmt->close();
}

// Fetch total teacher scans (sessions) by this professor
$sql_sessions_count = "SELECT COUNT(*) as session_count FROM teacher_scans WHERE professor_id = ?";
$session_count = 0;
if ($stmt = $link->prepare($sql_sessions_count)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $session_count = $row['session_count'];
    }
    $stmt->close();
}

// Fetch recent attendance
$sql_recent = "SELECT a.scan_time, u.name AS student_name, m.name AS module_name, a.status
              FROM attendance a
              JOIN users u ON a.student_id = u.id
              JOIN modules m ON a.module_id = m.id
              JOIN teacher_scans ts ON a.teacher_scan_id = ts.id
              WHERE ts.professor_id = ?
              ORDER BY a.scan_time DESC LIMIT 5";
$recent_attendances = [];
if ($stmt = $link->prepare($sql_recent)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $recent_attendances = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch upcoming sessions from timetable
$day_of_week = date('l'); // Current day of the week
$current_time = date('H:i:s'); // Current time

$sql_upcoming = "SELECT m.name AS module_name, t.start_time, t.end_time, t.day_of_week
                FROM timetable t
                JOIN modules m ON t.module_id = m.id
                WHERE t.professor_id = ? 
                AND (t.day_of_week = ? AND t.start_time > ?)
                ORDER BY t.start_time ASC
                LIMIT 3";
$upcoming_sessions = [];
if ($stmt = $link->prepare($sql_upcoming)) {
    $stmt->bind_param("iss", $professor_id, $day_of_week, $current_time);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $upcoming_sessions = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
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
    <title>Professor Dashboard</title>
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
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }
        .sidebar .nav-link {
            color: #495057;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            margin-bottom: 5px;
        }
        .sidebar .nav-link:hover {
            background-color: #e9ecef;
        }
        .sidebar .nav-link.active {
            background-color: #007bff;
            color: white;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            border: none;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background-color: #007bff;
            color: white;
            font-weight: 600;
            padding: 15px;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
        }
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: #007bff;
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
        .table-responsive {
            margin-top: 15px;
        }
        .status-present {
            color: #28a745;
            font-weight: 600;
        }
        .status-absent {
            color: #dc3545;
            font-weight: 600;
        }
        .status-late {
            color: #ffc107;
            font-weight: 600;
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
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="#">
            <i class="fas fa-graduation-cap mr-2"></i>
            Scolagile
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
                    <a class="nav-link" href="professor_modules.php">
                        <i class="fas fa-book"></i> My Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="fas fa-user"></i> Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
            <span class="navbar-text ml-auto">
                <i class="fas fa-user-circle mr-1"></i> Welcome, <?php echo htmlspecialchars($professor_name); ?>
            </span>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <nav id="sidebar" class="col-md-2 d-none d-md-block sidebar">
                <div class="sidebar-sticky">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="professor_modules.php">
                                <i class="fas fa-book"></i>
                                My Modules
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="professor_attendance_reports.php">
                                <i class="fas fa-chart-bar"></i>
                                Attendance Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="professor_schedule.php">
                                <i class="fas fa-calendar-alt"></i>
                                Schedule
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="professor_settings.php">
                                <i class="fas fa-cog"></i>
                                Settings
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
                            <h1 class="h2 mb-1">Welcome, <?php echo htmlspecialchars($professor_name); ?>!</h1>
                            <p class="mb-0">Here's an overview of your teaching activities and attendance records.</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <i class="fas fa-book"></i>
                            <div class="stat-value"><?php echo $module_count; ?></div>
                            <div class="stat-label">Active Modules</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <i class="fas fa-users"></i>
                            <div class="stat-value"><?php echo $student_count; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <i class="fas fa-clipboard-check"></i>
                            <div class="stat-value"><?php echo $session_count; ?></div>
                            <div class="stat-label">Total Sessions</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-history mr-2"></i> Recent Attendance
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Module</th>
                                                <th>Time</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($recent_attendances) > 0): ?>
                                                <?php foreach($recent_attendances as $attendance): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($attendance['student_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($attendance['module_name']); ?></td>
                                                        <td><?php echo date('d M H:i', strtotime($attendance['scan_time'])); ?></td>
                                                        <td class="status-<?php echo $attendance['status']; ?>">
                                                            <?php 
                                                            if($attendance['status'] == 'present') {
                                                                echo '<i class="fas fa-check-circle mr-1"></i>';
                                                            } else if($attendance['status'] == 'absent') {
                                                                echo '<i class="fas fa-times-circle mr-1"></i>';
                                                            } else {
                                                                echo '<i class="fas fa-exclamation-circle mr-1"></i>';
                                                            }
                                                            echo ucfirst($attendance['status']);
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No recent attendance records found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-calendar-day mr-2"></i> Upcoming Sessions
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Module</th>
                                                <th>Day</th>
                                                <th>Start Time</th>
                                                <th>End Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($upcoming_sessions) > 0): ?>
                                                <?php foreach($upcoming_sessions as $session): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($session['module_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($session['day_of_week']); ?></td>
                                                        <td><?php echo date('H:i', strtotime($session['start_time'])); ?></td>
                                                        <td><?php echo date('H:i', strtotime($session['end_time'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No upcoming sessions scheduled for today.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-right mt-2">
                                    <a href="#" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-calendar-alt mr-1"></i> View Full Schedule
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <i class="fas fa-tasks mr-2"></i> Quick Actions
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <a href="professor_modules.php" class="btn btn-primary btn-block">
                                            <i class="fas fa-book mr-1"></i> View Modules
                                        </a>
                                    </div>
                                    <div class="col-6 mb-3">
                                    <a href="professor_start_session.php" class="btn btn-success btn-block">
                                        <i class="fas fa-qrcode mr-1"></i> Start Session
                                    </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="#" class="btn btn-info btn-block">
                                            <i class="fas fa-chart-pie mr-1"></i> View Reports
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="#" class="btn btn-secondary btn-block">
                                            <i class="fas fa-user-edit mr-1"></i> Edit Profile
                                        </a>
                                    </div>
                                </div>
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
</body>
</html>