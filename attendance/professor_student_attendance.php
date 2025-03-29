<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "professor") {
    header("location: login.php");
    exit;
}
require_once "config.php";

$student_id = $_GET['student_id'] ?? 0;
$module_id = $_GET['module_id'] ?? 0;

// Fetch student's name
$sql_student = "SELECT name FROM users WHERE id = ?";
$student_name = '';
if ($stmt = $link->prepare($sql_student)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $stmt->bind_result($student_name);
        $stmt->fetch();
    }
    $stmt->close();
}

// Fetch module name
$sql_module = "SELECT name FROM modules WHERE id = ?";
$module_name = '';
if ($stmt = $link->prepare($sql_module)) {
    $stmt->bind_param("i", $module_id);
    if ($stmt->execute()) {
        $stmt->bind_result($module_name);
        $stmt->fetch();
    }
    $stmt->close();
}

// Fetch all sessions of the module with all necessary fields
$sql_sessions = "SELECT id, DATE(scan_time) as date, scan_time FROM teacher_scans 
                 WHERE module_id = ? 
                 ORDER BY scan_time";
$sessions = [];
if ($stmt = $link->prepare($sql_sessions)) {
    $stmt->bind_param("i", $module_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $sessions = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch student's attendance data for the module
$sql_attendance = "SELECT teacher_scan_id, status FROM attendance 
                   WHERE student_id = ? AND module_id = ?";
$attendance_data = [];
if ($stmt = $link->prepare($sql_attendance)) {
    $stmt->bind_param("ii", $student_id, $module_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $attendance_data[$row['teacher_scan_id']] = $row['status'];
        }
    }
    $stmt->close();
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance for <?php echo htmlspecialchars($student_name); ?> in <?php echo htmlspecialchars($module_name); ?></title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <link href="professor.css" rel="stylesheet">
    <style>
        .present { 
            color: #28a745; 
            font-weight: bold; 
        }
        .absent { 
            color: #dc3545; 
            font-weight: bold; 
        }
        .late { 
            color: #ffc107; 
            font-weight: bold; 
        }
        .table-responsive {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <a class="navbar-brand" href="#">Dashboard</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="professor_dashboard.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="professor_modules.php">My Modules</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">Profile</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">Settings</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Logout</a>
                </li>
            </ul>
            <span class="navbar-text ml-auto">
                Welcome, <?php echo $_SESSION["name"] ?? ''; ?>
            </span>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
                <div class="sidebar-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="professor_dashboard.php">
                                <i class="fas fa-home"></i>
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
                    </ul>
                </div>
            </nav>

            <main role="main" class="col-md-9 ml-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-clipboard-check mr-2"></i>
                        Attendance for <?php echo htmlspecialchars($student_name); ?> in <?php echo htmlspecialchars($module_name); ?>
                    </h1>
                </div>

                <!-- Attendance Summary -->
                <?php
                $totalSessions = count($sessions);
                $presentCount = 0;
                foreach ($attendance_data as $status) {
                    if ($status == 'present') {
                        $presentCount++;
                    }
                }
                $attendancePercentage = $totalSessions > 0 ? ($presentCount / $totalSessions) * 100 : 0;
                ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Attendance Summary</h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="alert alert-info">
                                    <strong>Total Sessions:</strong> <?php echo $totalSessions; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="alert alert-success">
                                    <strong>Present:</strong> <?php echo $presentCount; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="alert alert-danger">
                                    <strong>Absent:</strong> <?php echo $totalSessions - $presentCount; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="alert alert-warning">
                                    <strong>Attendance Rate:</strong> <?php echo number_format($attendancePercentage, 1); ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead class="thead-dark">
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Group sessions by date to show only one entry per day
                            $datesSeen = [];
                            $dateAttendance = [];
                            
                            foreach ($sessions as $session) {
                                $date = $session['date'];
                                $sessionId = $session['id'];
                                
                                if (!isset($datesSeen[$date])) {
                                    $datesSeen[$date] = true;
                                    
                                    // Check if student was present in any session on this date
                                    $status = 'absent';
                                    foreach ($sessions as $checkSession) {
                                        if ($checkSession['date'] == $date && 
                                            isset($attendance_data[$checkSession['id']]) && 
                                            $attendance_data[$checkSession['id']] == 'present') {
                                            $status = 'present';
                                            break;
                                        }
                                    }
                                    
                                    $dateAttendance[$date] = [
                                        'time' => date('H:i:s', strtotime($session['scan_time'])),
                                        'status' => $status
                                    ];
                                }
                            }
                            
                            foreach ($dateAttendance as $date => $info):
                                $statusClass = $info['status'];
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($date); ?></td>
                                    <td><?php echo htmlspecialchars($info['time']); ?></td>
                                    <td class="<?php echo $statusClass; ?>">
                                        <?php if ($info['status'] == 'present'): ?>
                                            <i class="fas fa-check-circle mr-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle mr-1"></i>
                                        <?php endif; ?>
                                        <?php echo ucfirst(htmlspecialchars($info['status'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($dateAttendance)): ?>
                                <tr>
                                    <td colspan="3" class="text-center">No attendance records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>