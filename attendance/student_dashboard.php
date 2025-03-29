<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "student") {
    header("location: login.php");
    exit;
}
require_once "config.php";

// Fetch student's info
$student_id = $_SESSION["id"];
$student_name = $_SESSION["name"];

// Fetch student's modules
$sql_modules = "SELECT m.id AS module_id, m.name AS module_name
                FROM student_module sm
                JOIN modules m ON sm.module_id = m.id
                WHERE sm.student_id = ?";

$modules = [];
if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $modules = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Count total enrolled modules
$module_count = count($modules);

// Calculate overall attendance statistics
$sql_attendance = "SELECT 
                    COUNT(DISTINCT ts.id) AS total_sessions,
                    COUNT(DISTINCT a.id) AS attended_sessions
                  FROM teacher_scans ts
                  JOIN student_module sm ON ts.module_id = sm.module_id
                  LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
                  WHERE sm.student_id = ?";

$total_sessions = 0;
$attended_sessions = 0;

if ($stmt = $link->prepare($sql_attendance)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $total_sessions = $row['total_sessions'];
        $attended_sessions = $row['attended_sessions'];
    }
    $stmt->close();
}

// Calculate attendance rate
$attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : 0;

// Fetch recent attendance records
$sql_recent = "SELECT a.scan_time, m.name AS module_name, a.status
               FROM attendance a
               JOIN modules m ON a.module_id = m.id
               WHERE a.student_id = ?
               ORDER BY a.scan_time DESC
               LIMIT 5";

$recent_attendance = [];
if ($stmt = $link->prepare($sql_recent)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $recent_attendance = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch upcoming sessions from timetable
$day_of_week = date('l'); // Current day of week
$current_time = date('H:i:s'); // Current time

$sql_upcoming = "SELECT m.name AS module_name, t.start_time, t.end_time, t.day_of_week, 
                 u.name AS professor_name
                 FROM timetable t
                 JOIN modules m ON t.module_id = m.id
                 JOIN users u ON t.professor_id = u.id
                 JOIN student_module sm ON t.module_id = sm.module_id
                 WHERE sm.student_id = ? 
                 AND ((t.day_of_week = ? AND t.start_time > ?) 
                      OR t.day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'))
                 ORDER BY FIELD(t.day_of_week, ?, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                          t.start_time ASC
                 LIMIT 5";

$upcoming_sessions = [];
if ($stmt = $link->prepare($sql_upcoming)) {
    $stmt->bind_param("isss", $student_id, $day_of_week, $current_time, $day_of_week);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $upcoming_sessions = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch detailed module attendance
$sql_module_attendance = "SELECT 
                          m.id AS module_id,
                          m.name AS module_name,
                          (SELECT COUNT(DISTINCT ts.id) FROM teacher_scans ts WHERE ts.module_id = m.id) AS total_sessions,
                          (SELECT COUNT(DISTINCT a.teacher_scan_id) FROM attendance a WHERE a.module_id = m.id AND a.student_id = sm.student_id) AS attended_sessions
                        FROM student_module sm
                        JOIN modules m ON sm.module_id = m.id
                        WHERE sm.student_id = ?";

$module_attendance = [];
if ($stmt = $link->prepare($sql_module_attendance)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $module_attendance = $result->fetch_all(MYSQLI_ASSOC);
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
    <title>Student Dashboard</title>
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
            background-color: #28a745;
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
            background-color: #28a745;
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
            color: #28a745;
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
            background: linear-gradient(135deg, #28a745, #20883a);
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
        .progress {
            height: 10px;
            margin-top: 10px;
        }
        .module-attendance {
            margin-bottom: 15px;
        }
        .qr-code-placeholder {
            width: 100%;
            height: 160px;
            background-color: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #6c757d;
        }
        .attendance-chart-card {
            height: 100%;
        }
        .profile-info {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .profile-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #28a745;
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            margin-right: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .profile-details {
            flex-grow: 1;
        }
        .profile-name {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 0;
        }
        .profile-role {
            color: rgba(255, 255, 255, 0.8);
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
                    <a class="nav-link" href="student_modules.php">
                        <i class="fas fa-book"></i> My Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="student_profile.php">
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
                <i class="fas fa-user-circle mr-1"></i> Welcome, <?php echo htmlspecialchars($student_name); ?>
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
                            <a class="nav-link" href="student_modules.php">
                                <i class="fas fa-book"></i>
                                My Modules
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="student_attendance_report.php">
                                <i class="fas fa-chart-bar"></i>
                                Attendance Report
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="student_schedule.php">
                                <i class="fas fa-calendar-alt"></i>
                                Schedule
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="student_settings.php">
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
                            <div class="profile-info">
                                <div class="profile-avatar">
                                    <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                                </div>
                                <div class="profile-details">
                                    <h1 class="profile-name"><?php echo htmlspecialchars($student_name); ?></h1>
                                    <p class="profile-role">Student</p>
                                </div>
                            </div>
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
                            <div class="stat-label">Enrolled Modules</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <i class="fas fa-calendar-check"></i>
                            <div class="stat-value"><?php echo $attended_sessions; ?>/<?php echo $total_sessions; ?></div>
                            <div class="stat-label">Sessions Attended</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card">
                            <i class="fas fa-chart-pie"></i>
                            <div class="stat-value"><?php echo $attendance_rate; ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                            <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $attendance_rate; ?>%" 
                                    aria-valuenow="<?php echo $attendance_rate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-book mr-2"></i> My Modules - Attendance Summary
                            </div>
                            <div class="card-body">
                                <?php foreach ($module_attendance as $module): 
                                    $module_rate = $module['total_sessions'] > 0 ? 
                                        round(($module['attended_sessions'] / $module['total_sessions']) * 100) : 0;
                                    $progress_color = $module_rate >= 75 ? 'success' : 
                                        ($module_rate >= 50 ? 'warning' : 'danger');
                                ?>
                                <div class="module-attendance">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6><?php echo htmlspecialchars($module['module_name']); ?></h6>
                                        </div>
                                        <div>
                                            <small><?php echo $module['attended_sessions']; ?> / <?php echo $module['total_sessions']; ?> sessions</small>
                                        </div>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-<?php echo $progress_color; ?>" role="progressbar" 
                                             style="width: <?php echo $module_rate; ?>%" 
                                             aria-valuenow="<?php echo $module_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                        </div>
                                    </div>
                                    <small class="text-<?php echo $progress_color; ?> font-weight-bold"><?php echo $module_rate; ?>%</small>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (count($module_attendance) === 0): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i> You are not enrolled in any modules yet.
                                </div>
                                <?php endif; ?>
                                
                                <div class="text-right mt-3">
                                    <a href="student_modules.php" class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-eye mr-1"></i> View All Modules
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <i class="fas fa-history mr-2"></i> Recent Attendance
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Module</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($recent_attendance) > 0): ?>
                                                <?php foreach ($recent_attendance as $attendance): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($attendance['module_name']); ?></td>
                                                    <td><?php echo date('d M H:i', strtotime($attendance['scan_time'])); ?></td>
                                                    <td class="status-<?php echo $attendance['status']; ?>">
                                                        <?php 
                                                        if ($attendance['status'] == 'present') {
                                                            echo '<i class="fas fa-check-circle mr-1"></i>';
                                                        } else if ($attendance['status'] == 'absent') {
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
                                                    <td colspan="3" class="text-center">No recent attendance records found.</td>
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
                                                <th>Time</th>
                                                <th>Professor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($upcoming_sessions) > 0): ?>
                                                <?php foreach ($upcoming_sessions as $session): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($session['module_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($session['day_of_week']); ?></td>
                                                    <td><?php echo date('H:i', strtotime($session['start_time'])) . ' - ' . 
                                                               date('H:i', strtotime($session['end_time'])); ?></td>
                                                    <td><?php echo htmlspecialchars($session['professor_name']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No upcoming sessions scheduled.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <i class="fas fa-qrcode mr-2"></i> Quick Attendance
                            </div>
                            <div class="card-body">
                                <div class="alert alert-success">
                                    <i class="fas fa-info-circle mr-2"></i> 
                                    Scan the QR code using the IoT device or click the button below to mark your attendance.
                                </div>
                                
                                <div class="text-center mb-3">
                                    <div class="qr-code-placeholder">
                                        <div>
                                            <i class="fas fa-qrcode fa-3x mb-2"></i><br>
                                            Your RFID Tag: <strong><?php echo htmlspecialchars($_SESSION["rfid_tag"] ?? 'Not Available'); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-center">
                                    <button class="btn btn-success btn-lg">
                                        <i class="fas fa-check-circle mr-1"></i> Mark Attendance
                                    </button>
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