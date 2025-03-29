<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "student") {
    header("location: login.html");
    exit;
}
require_once "config.php";

// Fetch student's info
$student_id = $_SESSION["id"];
$student_name = $_SESSION["name"];

// Fetch student's modules with attendance stats
$sql_modules = "SELECT 
                m.id AS module_id, 
                m.name AS module_name,
                (SELECT COUNT(DISTINCT DATE(ts.scan_time)) FROM teacher_scans ts WHERE ts.module_id = m.id) AS total_sessions,
                (SELECT COUNT(DISTINCT DATE(a.scan_time)) FROM attendance a WHERE a.student_id = ? AND a.module_id = m.id) AS attended_sessions,
                (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM professor_module pm 
                 JOIN users u ON pm.professor_id = u.id 
                 WHERE pm.module_id = m.id) AS professors
                FROM student_module sm
                JOIN modules m ON sm.module_id = m.id
                WHERE sm.student_id = ?";

$modules = [];
if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("ii", $student_id, $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $modules = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get detailed attendance for selected module
$module_detail = null;
$attendance_records = [];
$module_professors = [];

if (isset($_GET['module_id']) && is_numeric($_GET['module_id'])) {
    $selected_module_id = $_GET['module_id'];
    
    // Get module details
    $sql_module = "SELECT m.id, m.name FROM modules m
                   JOIN student_module sm ON m.id = sm.module_id
                   WHERE m.id = ? AND sm.student_id = ?";
    
    if ($stmt = $link->prepare($sql_module)) {
        $stmt->bind_param("ii", $selected_module_id, $student_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $module_detail = $result->fetch_assoc();
        }
        $stmt->close();
    }
    
    // Get detailed attendance records
    $sql_attendance = "SELECT DATE(ts.scan_time) as date, 
                      CASE WHEN a.id IS NULL THEN 'absent' ELSE a.status END as status
                      FROM teacher_scans ts
                      LEFT JOIN attendance a ON ts.id = a.teacher_scan_id AND a.student_id = ?
                      WHERE ts.module_id = ?
                      GROUP BY DATE(ts.scan_time)
                      ORDER BY ts.scan_time DESC";
    
    if ($stmt = $link->prepare($sql_attendance)) {
        $stmt->bind_param("ii", $student_id, $selected_module_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $attendance_records = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
    
    // Get professors teaching this module
    $sql_professors = "SELECT u.name AS professor_name, u.email
                      FROM professor_module pm
                      JOIN users u ON pm.professor_id = u.id
                      WHERE pm.module_id = ?";
    
    if ($stmt = $link->prepare($sql_professors)) {
        $stmt->bind_param("i", $selected_module_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $module_professors = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
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
    <title>My Modules</title>
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
        .module-card {
            border: none;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        .module-card:hover {
            transform: translateY(-5px);
        }
        .module-card .card-header {
            background-color: #28a745;
            color: white;
            font-weight: 600;
            padding: 15px;
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
            border-radius: 5px;
        }
        .status-present {
            color: #28a745;
            font-weight: bold;
        }
        .status-absent {
            color: #dc3545;
            font-weight: bold;
        }
        .status-late {
            color: #ffc107;
            font-weight: bold;
        }
        .module-details {
            margin-top: 20px;
        }
        .module-details .card-header {
            background-color: #17a2b8;
        }
        .professor-item {
            margin-bottom: 10px;
            padding: 10px;
            border-radius: 5px;
            background-color: #f8f9fa;
        }
        .professor-name {
            font-weight: 500;
        }
        .professor-email {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .back-btn {
            color: #28a745;
        }
        .attendance-tabs {
            margin-top: 20px;
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
                <li class="nav-item">
                    <a class="nav-link" href="student_dashboard.php">
                        <i class="fas fa-home"></i> Home
                    </a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="#">
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
                            <a class="nav-link" href="student_dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">My Modules</h1>
                            <p class="mb-0">View your enrolled modules and attendance records</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>

                <?php if (isset($_GET['module_id']) && $module_detail): ?>
                    <!-- Module Detail View -->
                    <div class="module-details">
                        <div class="mb-3">
                            <a href="student_modules.php" class="back-btn">
                                <i class="fas fa-arrow-left"></i> Back to All Modules
                            </a>
                        </div>
                        
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-book-open mr-2"></i>
                                        <?php echo htmlspecialchars($module_detail['name']); ?>
                                    </h5>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6>Professors:</h6>
                                <div class="row mb-4">
                                    <?php if (count($module_professors) > 0): ?>
                                        <?php foreach ($module_professors as $professor): ?>
                                            <div class="col-md-6">
                                                <div class="professor-item">
                                                    <div class="professor-name">
                                                        <i class="fas fa-user-tie mr-2"></i>
                                                        <?php echo htmlspecialchars($professor['professor_name']); ?>
                                                    </div>
                                                    <div class="professor-email">
                                                        <i class="fas fa-envelope mr-2"></i>
                                                        <?php echo htmlspecialchars($professor['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="col-12">
                                            <div class="alert alert-info">No professors assigned to this module.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <h6>Attendance Records:</h6>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($attendance_records) > 0): ?>
                                                <?php foreach ($attendance_records as $record): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($record['date']); ?></td>
                                                        <td class="status-<?php echo $record['status']; ?>">
                                                            <?php 
                                                            if ($record['status'] == 'present') {
                                                                echo '<i class="fas fa-check-circle mr-1"></i>';
                                                            } else if ($record['status'] == 'absent') {
                                                                echo '<i class="fas fa-times-circle mr-1"></i>';
                                                            } else {
                                                                echo '<i class="fas fa-exclamation-circle mr-1"></i>';
                                                            }
                                                            echo ucfirst($record['status']);
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="2" class="text-center">No attendance records found.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Modules List View -->
                    <div class="row">
                        <?php if (count($modules) > 0): ?>
                            <?php foreach ($modules as $module): 
                                $attendance_rate = $module['total_sessions'] > 0 ? 
                                    round(($module['attended_sessions'] / $module['total_sessions']) * 100) : 0;
                                $progress_color = $attendance_rate >= 75 ? 'success' : 
                                    ($attendance_rate >= 50 ? 'warning' : 'danger');
                            ?>
                                <div class="col-md-6">
                                    <div class="card module-card">
                                        <div class="card-header">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5 class="mb-0"><?php echo htmlspecialchars($module['module_name']); ?></h5>
                                                <span class="badge badge-light">
                                                    <i class="fas fa-user-tie mr-1"></i> <?php echo $module['professors'] ? htmlspecialchars($module['professors']) : 'No professors assigned'; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between mb-2">
                                                <div>
                                                    <p class="mb-0"><strong>Attendance Rate:</strong></p>
                                                </div>
                                                <div>
                                                    <span class="text-<?php echo $progress_color; ?> font-weight-bold">
                                                        <?php echo $attendance_rate; ?>%
                                                    </span>
                                                    <small class="text-muted ml-2">
                                                        (<?php echo $module['attended_sessions']; ?>/<?php echo $module['total_sessions']; ?> sessions)
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="progress mb-3">
                                                <div class="progress-bar bg-<?php echo $progress_color; ?>" role="progressbar" 
                                                     style="width: <?php echo $attendance_rate; ?>%" 
                                                     aria-valuenow="<?php echo $attendance_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <a href="student_modules.php?module_id=<?php echo $module['module_id']; ?>" class="btn btn-outline-success">
                                                    <i class="fas fa-eye mr-1"></i> View Details
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i> You are not enrolled in any modules yet.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>