<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "professor") {
    header("location: login.html");
    exit;
}
require_once "config.php";

// Fetch professor's info
$professor_id = $_SESSION["id"];
$professor_name = $_SESSION["name"];

// Selected module filter (optional)
$selected_module = isset($_GET['module_id']) ? $_GET['module_id'] : 'all';
$module_filter = $selected_module !== 'all' ? "AND m.id = " . intval($selected_module) : "";

// Date range filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$date_filter = " AND DATE(ts.scan_time) BETWEEN '$start_date' AND '$end_date'";

// Get all modules taught by the professor
$sql_modules = "SELECT m.id, m.name 
                FROM professor_module pm 
                JOIN modules m ON pm.module_id = m.id 
                WHERE pm.professor_id = ?
                ORDER BY m.name";

$modules = [];
if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $modules = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get overall module attendance statistics
$sql_module_stats = "SELECT 
                    m.id AS module_id,
                    m.name AS module_name,
                    COUNT(DISTINCT ts.id) AS total_sessions,
                    COUNT(DISTINCT sm.student_id) AS total_students,
                    SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS attended_count,
                    COUNT(DISTINCT ts.id) * COUNT(DISTINCT sm.student_id) AS possible_attendance
                FROM professor_module pm
                JOIN modules m ON pm.module_id = m.id
                LEFT JOIN teacher_scans ts ON ts.module_id = m.id
                LEFT JOIN student_module sm ON sm.module_id = m.id
                LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
                WHERE pm.professor_id = ? $module_filter $date_filter
                GROUP BY m.id, m.name
                ORDER BY m.name";

$module_stats = [];
if ($stmt = $link->prepare($sql_module_stats)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $module_stats = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get session-by-session attendance for the selected module
$session_attendance = [];
if ($selected_module !== 'all') {
    $sql_session_attendance = "SELECT 
                             DATE(ts.scan_time) AS session_date,
                             COUNT(DISTINCT sm.student_id) AS total_students,
                             SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS attended_count
                             FROM teacher_scans ts
                             JOIN student_module sm ON sm.module_id = ts.module_id
                             LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
                             WHERE ts.module_id = ? $date_filter
                             GROUP BY DATE(ts.scan_time)
                             ORDER BY session_date DESC";
    
    if ($stmt = $link->prepare($sql_session_attendance)) {
        $stmt->bind_param("i", $selected_module);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $session_attendance = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
}

// Get student attendance for the selected module
$student_attendance = [];
if ($selected_module !== 'all') {
    $sql_student_attendance = "SELECT 
                              u.id AS student_id,
                              u.name AS student_name,
                              u.email,
                              COUNT(DISTINCT ts.id) AS total_sessions,
                              SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS attended_sessions
                              FROM student_module sm
                              JOIN users u ON sm.student_id = u.id
                              JOIN teacher_scans ts ON ts.module_id = sm.module_id
                              LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = u.id
                              WHERE sm.module_id = ? $date_filter
                              GROUP BY u.id, u.name, u.email
                              ORDER BY u.name";
    
    if ($stmt = $link->prepare($sql_student_attendance)) {
        $stmt->bind_param("i", $selected_module);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $student_attendance = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
}

// Calculate overall attendance statistics
$total_sessions = 0;
$total_students = 0;
$total_attendance = 0;
$total_possible = 0;

foreach ($module_stats as $module) {
    $total_sessions += $module['total_sessions'];
    $total_students += $module['total_students'];
    $total_attendance += $module['attended_count'];
    $total_possible += $module['possible_attendance'];
}

$overall_rate = $total_possible > 0 ? round(($total_attendance / $total_possible) * 100, 1) : 0;

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $module_id = intval($_GET['module_id'] ?? 0);
    $module_name = "attendance_data";
    
    // Get module name if specific module selected
    if ($module_id > 0) {
        foreach ($modules as $m) {
            if ($m['id'] == $module_id) {
                $module_name = strtolower(str_replace(' ', '_', $m['name']));
                break;
            }
        }
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $module_name . '_attendance_' . date('Y-m-d') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, array('Student ID', 'Student Name', 'Email', 'Sessions', 'Present', 'Absent', 'Attendance Rate (%)'));
    
    // Write data
    foreach ($student_attendance as $student) {
        $attendance_rate = $student['total_sessions'] > 0 ? 
            round(($student['attended_sessions'] / $student['total_sessions']) * 100, 1) : 0;
        
        fputcsv($output, array(
            $student['student_id'],
            $student['student_name'],
            $student['email'],
            $student['total_sessions'],
            $student['attended_sessions'],
            $student['total_sessions'] - $student['attended_sessions'],
            $attendance_rate
        ));
    }
    
    // Close the output stream and exit
    fclose($output);
    exit;
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports</title>
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
            background-color: #007bff;
            color: white;
            font-weight: 600;
            padding: 15px;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            background-color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
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
        .progress {
            height: 10px;
            margin-top: 5px;
            margin-bottom: 10px;
        }
        .module-row {
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: all 0.2s ease;
        }
        .module-row:hover {
            background-color: #f8f9fa;
        }
        .module-row:last-child {
            border-bottom: none;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        .attendance-filter {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .status-good {
            color: #28a745;
        }
        .status-warning {
            color: #ffc107;
        }
        .status-danger {
            color: #dc3545;
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
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
                    <a class="nav-link" href="professor_dashboard.php">
                        <i class="fas fa-home"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="professor_modules.php">
                        <i class="fas fa-book"></i> My Modules
                    </a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="professor_attendance_reports.php">
                        <i class="fas fa-chart-bar"></i> Reports
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
                            <a class="nav-link" href="professor_dashboard.php">
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
                            <a class="nav-link active" href="professor_attendance_reports.php">
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
                            <h1 class="h2 mb-1">Attendance Reports</h1>
                            <p class="mb-0">Analyze student attendance data across your modules</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="attendance-filter">
                    <form method="get" action="" class="form-row align-items-end">
                        <div class="col-md-3 mb-2">
                            <label for="module_select">Module:</label>
                            <select class="form-control" id="module_select" name="module_id">
                                <option value="all" <?php echo $selected_module === 'all' ? 'selected' : ''; ?>>All Modules</option>
                                <?php foreach ($modules as $module): ?>
                                    <option value="<?php echo $module['id']; ?>" <?php echo $selected_module == $module['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($module['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label for="start_date">From:</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3 mb-2">
                            <label for="end_date">To:</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary mr-2">
                                <i class="fas fa-filter mr-1"></i> Apply Filters
                            </button>
                            <?php if ($selected_module !== 'all'): ?>
                                <a href="?export=csv&module_id=<?php echo $selected_module; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-download mr-1"></i> Export CSV
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <i class="fas fa-book"></i>
                            <div class="stat-value"><?php echo count($modules); ?></div>
                            <div class="stat-label">Total Modules</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <div class="stat-value"><?php echo $total_sessions; ?></div>
                            <div class="stat-label">Total Sessions</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <i class="fas fa-users"></i>
                            <div class="stat-value"><?php echo $total_students; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <i class="fas fa-chart-pie"></i>
                            <div class="stat-value"><?php echo $overall_rate; ?>%</div>
                            <div class="stat-label">Overall Attendance Rate</div>
                            <div class="progress">
                                <?php
                                $progress_class = $overall_rate >= 75 ? 'bg-success' : ($overall_rate >= 50 ? 'bg-warning' : 'bg-danger');
                                ?>
                                <div class="progress-bar <?php echo $progress_class; ?>" role="progressbar" style="width: <?php echo $overall_rate; ?>%" 
                                     aria-valuenow="<?php echo $overall_rate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if($selected_module === 'all'): ?>
                    <!-- Module Overview -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-list mr-2"></i> Module Overview</span>
                        </div>
                        <div class="card-body p-0">
                            <?php if(count($module_stats) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Module Name</th>
                                                <th class="text-center">Sessions</th>
                                                <th class="text-center">Students</th>
                                                <th class="text-center">Attendance</th>
                                                <th class="text-center">Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($module_stats as $module): 
                                                $rate = $module['possible_attendance'] > 0 ? 
                                                    round(($module['attended_count'] / $module['possible_attendance']) * 100, 1) : 0;
                                                
                                                $status_class = $rate >= 75 ? 'status-good' : ($rate >= 50 ? 'status-warning' : 'status-danger');
                                            ?>
                                                <tr>
                                                    <td>
                                                        <a href="?module_id=<?php echo $module['module_id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                                                            <?php echo htmlspecialchars($module['module_name']); ?>
                                                        </a>
                                                    </td>
                                                    <td class="text-center"><?php echo $module['total_sessions']; ?></td>
                                                    <td class="text-center"><?php echo $module['total_students']; ?></td>
                                                    <td class="text-center"><?php echo $module['attended_count']; ?> / <?php echo $module['possible_attendance']; ?></td>
                                                    <td class="text-center <?php echo $status_class; ?> font-weight-bold">
                                                        <?php echo $rate; ?>%
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info m-3">
                                    <i class="fas fa-info-circle mr-2"></i> No attendance data found for the selected period.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Attendance Comparison Chart -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-chart-bar mr-2"></i> Attendance Comparison
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="moduleComparisonChart"></canvas>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Module Detail View -->
                    <?php $module_name = ""; 
                    foreach($modules as $m) {
                        if($m['id'] == $selected_module) {
                            $module_name = $m['name'];
                            break;
                        }
                    }
                    ?>
                    
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-info-circle mr-2"></i> Module Details: <?php echo htmlspecialchars($module_name); ?>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5>Session Attendance</h5>
                                    <div class="chart-container">
                                        <canvas id="sessionAttendanceChart"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h5>Student Statistics</h5>
                                    <div class="chart-container">
                                        <canvas id="studentStatisticsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Session Detail Table -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-calendar-day mr-2"></i> Sessions
                        </div>
                        <div class="card-body p-0">
                            <?php if(count($session_attendance) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th class="text-center">Students Present</th>
                                                <th class="text-center">Students Absent</th>
                                                <th class="text-center">Attendance Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($session_attendance as $session): 
                                                $attendance_rate = $session['total_students'] > 0 ? 
                                                    round(($session['attended_count'] / $session['total_students']) * 100, 1) : 0;
                                                $status_class = $attendance_rate >= 75 ? 'status-good' : 
                                                    ($attendance_rate >= 50 ? 'status-warning' : 'status-danger');
                                            ?>
                                                <tr>
                                                    <td><?php echo $session['session_date']; ?></td>
                                                    <td class="text-center"><?php echo $session['attended_count']; ?></td>
                                                    <td class="text-center"><?php echo $session['total_students'] - $session['attended_count']; ?></td>
                                                    <td class="text-center <?php echo $status_class; ?> font-weight-bold">
                                                        <?php echo $attendance_rate; ?>%
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info m-3">
                                    <i class="fas fa-info-circle mr-2"></i> No sessions found for the selected period.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Student Detail Table -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-users mr-2"></i> Student Attendance</span>
                            <div>
                                <input type="text" id="studentSearch" class="form-control form-control-sm" placeholder="Search students...">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if(count($student_attendance) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="studentTable">
                                        <thead>
                                            <tr>
                                                <th>Student Name</th>
                                                <th>Email</th>
                                                <th class="text-center">Sessions</th>
                                                <th class="text-center">Present</th>
                                                <th class="text-center">Absent</th>
                                                <th class="text-center">Rate</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($student_attendance as $student): 
                                                $attendance_rate = $student['total_sessions'] > 0 ? 
                                                    round(($student['attended_sessions'] / $student['total_sessions']) * 100, 1) : 0;
                                                $status_class = $attendance_rate >= 75 ? 'status-good' : 
                                                    ($attendance_rate >= 50 ? 'status-warning' : 'status-danger');
                                            ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                                    <td class="text-center"><?php echo $student['total_sessions']; ?></td>
                                                    <td class="text-center"><?php echo $student['attended_sessions']; ?></td>
                                                    <td class="text-center"><?php echo $student['total_sessions'] - $student['attended_sessions']; ?></td>
                                                    <td class="text-center <?php echo $status_class; ?> font-weight-bold">
                                                        <?php echo $attendance_rate; ?>%
                                                    </td>
                                                    <td class="text-center">
    <a href="professor_student_attendance.php?student_id=<?php echo $student['student_id']; ?>&module_id=<?php echo $selected_module; ?>" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-eye"></i> Details
    </a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="alert alert-info m-3">
    <i class="fas fa-info-circle mr-2"></i> No students found enrolled in this module.
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</main>
</div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
// Student search functionality
$(document).ready(function() {
    $("#studentSearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#studentTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});

// Create charts based on available data
<?php if($selected_module === 'all' && count($module_stats) > 0): ?>
// Module comparison chart
const moduleComparisonCtx = document.getElementById('moduleComparisonChart').getContext('2d');
const moduleComparisonChart = new Chart(moduleComparisonCtx, {
    type: 'bar',
    data: {
        labels: [
            <?php foreach($module_stats as $module): ?>
                '<?php echo addslashes($module['module_name']); ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: 'Attendance Rate (%)',
            data: [
                <?php 
                foreach($module_stats as $module): 
                    $rate = $module['possible_attendance'] > 0 ? 
                        round(($module['attended_count'] / $module['possible_attendance']) * 100, 1) : 0;
                    echo $rate . ',';
                endforeach; 
                ?>
            ],
            backgroundColor: 'rgba(111, 66, 193, 0.7)',
            borderColor: '#6f42c1',
            borderWidth: 1
        }]
    },
    options: {
        scales: {
            yAxes: [{
                ticks: {
                    beginAtZero: true,
                    max: 100
                },
                scaleLabel: {
                    display: true,
                    labelString: 'Attendance Rate (%)'
                }
            }]
        },
        responsive: true,
        maintainAspectRatio: false,
        legend: {
            position: 'top'
        }
    }
});
<?php endif; ?>

<?php if($selected_module !== 'all'): ?>
// Session attendance chart
const sessionAttendanceCtx = document.getElementById('sessionAttendanceChart').getContext('2d');
const sessionAttendanceChart = new Chart(sessionAttendanceCtx, {
    type: 'line',
    data: {
        labels: [
            <?php foreach($session_attendance as $session): ?>
                '<?php echo $session['session_date']; ?>',
            <?php endforeach; ?>
        ],
        datasets: [{
            label: 'Attendance Rate (%)',
            data: [
                <?php 
                foreach($session_attendance as $session): 
                    $rate = $session['total_students'] > 0 ? 
                        round(($session['attended_count'] / $session['total_students']) * 100, 1) : 0;
                    echo $rate . ',';
                endforeach; 
                ?>
            ],
            backgroundColor: 'rgba(111, 66, 193, 0.1)',
            borderColor: '#6f42c1',
            borderWidth: 2,
            pointBackgroundColor: '#6f42c1',
            fill: true
        }]
    },
    options: {
        scales: {
            yAxes: [{
                ticks: {
                    beginAtZero: true,
                    max: 100
                }
            }]
        },
        responsive: true,
        maintainAspectRatio: false
    }
});

// Student statistics chart
const studentData = {
    labels: ['Excellent (>75%)', 'Good (50-75%)', 'Poor (<50%)'],
    datasets: [{
        data: [
            <?php 
            $excellent = 0;
            $good = 0;
            $poor = 0;
            
            foreach($student_attendance as $student) {
                $rate = $student['total_sessions'] > 0 ? 
                    round(($student['attended_sessions'] / $student['total_sessions']) * 100, 1) : 0;
                
                if($rate >= 75) $excellent++;
                else if($rate >= 50) $good++;
                else $poor++;
            }
            
            echo $excellent . ',' . $good . ',' . $poor;
            ?>
        ],
        backgroundColor: ['#28a745', '#ffc107', '#dc3545']
    }]
};

const studentStatisticsCtx = document.getElementById('studentStatisticsChart').getContext('2d');
const studentStatisticsChart = new Chart(studentStatisticsCtx, {
    type: 'doughnut',
    data: studentData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        legend: {
            position: 'right'
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>