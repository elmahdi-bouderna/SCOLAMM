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

// Get current date and time in UTC
$currentDateTime = '2025-03-17 17:27:56';
$today = date('Y-m-d');
$currentDay = date('l', strtotime($today));

// Determine the view mode (day, week, month)
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'week';

// Calculate date ranges based on view mode
$start_date = $today;
$end_date = $today;

switch($view_mode) {
    case 'day':
        $start_date = $today;
        $end_date = $today;
        break;
        
    case 'week':
        // Find the start of the week (Monday)
        $dayOfWeek = date('N', strtotime($today));
        $start_date = date('Y-m-d', strtotime("-" . ($dayOfWeek - 1) . " days", strtotime($today)));
        $end_date = date('Y-m-d', strtotime("+" . (7 - $dayOfWeek) . " days", strtotime($today)));
        break;
        
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
}

// Get professor's modules
$modules = [];
$sql = "SELECT m.id, m.name, m.code, m.description 
        FROM modules m
        JOIN professor_module pm ON m.id = pm.module_id
        WHERE pm.professor_id = ?
        ORDER BY m.name";
        
if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $modules[$row['id']] = $row;
            $modules[$row['id']]['schedule'] = [];
            // Initialize student count
            $modules[$row['id']]['student_count'] = 0;
        }
    }
    $stmt->close();
}

// Get student count for each module
if (!empty($modules)) {
    $module_ids = array_keys($modules);
    $placeholders = str_repeat('?,', count($module_ids) - 1) . '?';
    
    $sql = "SELECT module_id, COUNT(student_id) as student_count 
            FROM student_module 
            WHERE module_id IN ($placeholders) 
            GROUP BY module_id";
    
    if ($stmt = $link->prepare($sql)) {
        $types = str_repeat('i', count($module_ids));
        $stmt->bind_param($types, ...$module_ids);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (isset($modules[$row['module_id']])) {
                    $modules[$row['module_id']]['student_count'] = $row['student_count'];
                }
            }
        }
        $stmt->close();
    }
}

// Get schedule for professor's modules using the timetable table
$schedule = [];
$schedule_by_date = [];
$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Query for recurring schedule (weekly pattern)
$sql = "SELECT t.id, t.module_id, t.day_of_week, t.start_time, t.end_time, 
               'Classroom' as location, m.name as module_name, m.code as module_code
        FROM timetable t
        JOIN modules m ON t.module_id = m.id
        WHERE t.professor_id = ? 
        ORDER BY FIELD(t.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), 
                t.start_time";
                 
if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $professor_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Skip entries with empty day_of_week
            if (empty($row['day_of_week'])) {
                continue;
            }
            
            // Store in schedule array by day
            if (!isset($schedule[$row['day_of_week']])) {
                $schedule[$row['day_of_week']] = [];
            }
            $schedule[$row['day_of_week']][] = $row;
            
            // Store in modules array
            if (isset($modules[$row['module_id']])) {
                $modules[$row['module_id']]['schedule'][] = $row;
            }
            
            // For date-based view, calculate specific dates in the selected range
            $curr_date = clone new DateTime($start_date);
            $end_datetime = new DateTime($end_date);
            
            while ($curr_date <= $end_datetime) {
                $curr_day = $curr_date->format('l');
                
                if ($curr_day === $row['day_of_week']) {
                    $date_key = $curr_date->format('Y-m-d');
                    
                    if (!isset($schedule_by_date[$date_key])) {
                        $schedule_by_date[$date_key] = [];
                    }
                    
                    // Create a copy of the row with the specific date
                    $date_row = $row;
                    $date_row['date'] = $date_key;
                    $schedule_by_date[$date_key][] = $date_row;
                }
                
                $curr_date->modify('+1 day');
            }
        }
    }
    
    $stmt->close();
}

// Get attendance statistics for classes
$attendance_stats = [];
$sql = "SELECT ts.id as scan_id, ts.module_id, DATE(ts.scan_time) as class_date, 
               COUNT(DISTINCT a.student_id) as attended_count
        FROM teacher_scans ts
        LEFT JOIN attendance a ON a.teacher_scan_id = ts.id
        WHERE ts.professor_id = ? AND DATE(ts.scan_time) BETWEEN ? AND ?
        GROUP BY ts.id, ts.module_id, DATE(ts.scan_time)";
        
if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("iss", $professor_id, $start_date, $end_date);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $key = $row['module_id'] . '_' . $row['class_date'];
            $attendance_stats[$key] = $row;
        }
    }
    
    $stmt->close();
}

// Check for upcoming classes today
$upcoming_classes = [];
$current_time = date('H:i:s', strtotime($currentDateTime));

if (isset($schedule[$currentDay])) {
    foreach ($schedule[$currentDay] as $class) {
        if ($class['start_time'] > $current_time) {
            $upcoming_classes[] = $class;
        }
        
        if (count($upcoming_classes) >= 3) {
            break;  // Limit to 3 upcoming classes
        }
    }
}

// Close connection
$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule | Professor Dashboard</title>
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
        .schedule-day {
            background-color: #ffffff;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }
        .schedule-day-header {
            background-color: #e9ecef;
            padding: 10px 15px;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
        }
        .schedule-item {
            padding: 15px;
            border-left: 4px solid transparent;
            margin-bottom: 10px;
            background-color: white;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }
        .schedule-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .schedule-item h5 {
            margin-bottom: 5px;
        }
        .schedule-time {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .schedule-location {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .schedule-professor {
            font-size: 0.875rem;
        }
        .module-code {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 600;
        }
        .current-class {
            border-left-color: #007bff;
        }
        .upcoming-class {
            border-left-color: #17a2b8;
        }
        .past-class {
            border-left-color: #6c757d;
            opacity: 0.85;
        }
        .attendance-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .attendance-good {
            background-color: #28a745;
        }
        .attendance-medium {
            background-color: #ffc107;
        }
        .attendance-poor {
            background-color: #dc3545;
        }
        .view-toggle .btn {
            border-radius: 20px;
        }
        .today-marker {
            background-color: rgba(0, 123, 255, 0.1);
            border-left: 3px solid #007bff;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
        }
        .calendar-time-col {
            grid-column: 1;
        }
        .calendar-day {
            background-color: white;
            border-radius: 8px;
            height: 100%;
            overflow: hidden;
        }
        .calendar-day-header {
            background-color: #e9ecef;
            padding: 8px;
            text-align: center;
            font-weight: 600;
        }
        .calendar-day-body {
            padding: 10px;
            height: calc(100% - 40px);
        }
        .calendar-time-slot {
            display: grid;
            grid-template-columns: 80px repeat(5, 1fr);
            height: 60px;
            border-bottom: 1px solid #f0f0f0;
        }
        .calendar-time-label {
            font-size: 0.75rem;
            color: #6c757d;
            align-self: start;
            padding-top: 5px;
        }
        .calendar-event {
            background-color: #cfe2ff;
            border-left: 3px solid #0d6efd;
            border-radius: 5px;
            padding: 5px;
            font-size: 0.75rem;
            margin: 2px 0;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .module-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .module-item {
            padding: 10px 15px;
            border-left: 4px solid #007bff;
            margin-bottom: 10px;
            background-color: white;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .module-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
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
            background-color: #007bff;
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
        .qr-code-container {
            background-color: #fff;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 2px dashed #dee2e6;
            margin-bottom: 20px;
        }
        .qr-code-placeholder {
            width: 150px;
            height: 150px;
            background-color: #f8f9fa;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            color: #6c757d;
        }
        .student-count {
            position: absolute;
            top: 5px;
            right: 8px;
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 0px 8px;
            font-size: 0.7rem;
            font-weight: bold;
            color: #007bff;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-outline-primary {
            color: #007bff;
            border-color: #007bff;
        }
        .quick-action-btn {
            transition: all 0.3s;
        }
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
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
                    <a class="nav-link" href="#">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="professor_attendance.php">
                        <i class="fas fa-clipboard-check"></i> Attendance
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="professor_profile.php">
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
                <i class="fas fa-user-circle mr-1"></i> Welcome, Prof. <?php echo htmlspecialchars($professor_name); ?>
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
                            <a class="nav-link" href="professor_attendance_reports.php">
                                <i class="fas fa-chart-bar"></i>
                                Attendance Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="professor_schedule.php">
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
                            <div class="profile-info">
                                <div class="profile-avatar">
                                    <?php echo strtoupper(substr($professor_name, 0, 1)); ?>
                                </div>
                                <div class="profile-details">
                                    <h1 class="profile-name">Teaching Schedule</h1>
                                    <p class="profile-role">Manage your class schedule and attendance</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-8">
                        <!-- View Toggles -->
                        <div class="card mb-4">
                            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                                <div class="view-toggle btn-group">
                                    <a href="?view=day" class="btn btn-sm <?php echo $view_mode == 'day' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                        <i class="fas fa-calendar-day mr-1"></i> Day
                                    </a>
                                    <a href="?view=week" class="btn btn-sm <?php echo $view_mode == 'week' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                        <i class="fas fa-calendar-week mr-1"></i> Week
                                    </a>
                                    <a href="?view=month" class="btn btn-sm <?php echo $view_mode == 'month' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                        <i class="fas fa-calendar-alt mr-1"></i> Month
                                    </a>
                                </div>
                                
                                <div class="date-range">
                                    <span class="badge badge-light">
                                        <i class="far fa-calendar-alt mr-1"></i>
                                        <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($view_mode == 'week'): ?>
                            <!-- Weekly View -->
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-calendar-week mr-2"></i> Weekly Schedule
                                </div>
                                <div class="card-body">
                                    <?php foreach ($weekdays as $day): ?>
                                        <div class="schedule-day <?php echo $day == $currentDay ? 'today-marker' : ''; ?>">
                                            <div class="schedule-day-header d-flex justify-content-between">
                                                <span><?php echo $day; ?></span>
                                                <?php 
                                                    $day_date = '';
                                                    $curr_date = clone new DateTime($start_date);
                                                    $end_datetime = new DateTime($end_date);
                                                    
                                                    while ($curr_date <= $end_datetime) {
                                                        if ($curr_date->format('l') === $day) {
                                                            $day_date = $curr_date->format('d M Y');
                                                            break;
                                                        }
                                                        $curr_date->modify('+1 day');
                                                    }
                                                ?>
                                                <span class="text-muted small"><?php echo $day_date; ?></span>
                                            </div>
                                            
                                            <?php if (isset($schedule[$day]) && !empty($schedule[$day])): ?>
                                                <div class="schedule-items p-2">
                                                    <?php foreach ($schedule[$day] as $class): ?>
                                                        <?php
                                                            // Determine if class is current, upcoming, or past
                                                            $class_status = 'past-class';
                                                            
                                                            if ($day == $currentDay) {
                                                                $current_time = date('H:i:s', strtotime($currentDateTime));
                                                                
                                                                if ($class['start_time'] <= $current_time && $class['end_time'] >= $current_time) {
                                                                    $class_status = 'current-class';
                                                                } elseif ($class['start_time'] > $current_time) {
                                                                    $class_status = 'upcoming-class';
                                                                }
                                                            } elseif (array_search($day, $weekdays) > array_search($currentDay, $weekdays)) {
                                                                $class_status = 'upcoming-class';
                                                            }
                                                            
                                                            // Format times
                                                            $start_time = date('h:i A', strtotime($class['start_time']));
                                                            $end_time = date('h:i A', strtotime($class['end_time']));
                                                            
                                                            // Get student count for this module
                                                            $student_count = isset($modules[$class['module_id']]) ? $modules[$class['module_id']]['student_count'] : 0;
                                                        ?>
                                                        <div class="schedule-item <?php echo $class_status; ?> position-relative">
                                                            <div class="d-flex justify-content-between mb-2">
                                                                <span class="module-code"><?php echo htmlspecialchars($class['module_code'] ?? 'No Code'); ?></span>
                                                                <span class="schedule-time">
                                                                    <i class="far fa-clock mr-1"></i><?php echo $start_time; ?> - <?php echo $end_time; ?>
                                                                </span>
                                                            </div>
                                                            <h5><?php echo htmlspecialchars($class['module_name']); ?></h5>
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <p class="schedule-location mb-1">
                                                                        <i class="fas fa-map-marker-alt mr-1"></i>
                                                                        <?php echo htmlspecialchars($class['location'] ?? 'Classroom'); ?>
                                                                    </p>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <p class="schedule-professor mb-1">
                                                                        <i class="fas fa-users mr-1"></i>
                                                                        <?php echo $student_count; ?> Students
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            
                                                            <?php if ($class_status == 'current-class' || $class_status == 'upcoming-class'): ?>
                                                            <div class="mt-2">
                                                                <a href="professor_scan.php?module=<?php echo $class['module_id']; ?>" class="btn btn-sm btn-primary">
                                                                    <i class="fas fa-qrcode mr-1"></i> Start Attendance
                                                                </a>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <span class="student-count">
                                                                <i class="fas fa-user-graduate mr-1"></i> <?php echo $student_count; ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="p-4 text-center text-muted">
                                                    <i class="fas fa-calendar-times mb-2"></i><br>
                                                    No classes scheduled
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        
                        <?php elseif ($view_mode == 'day'): ?>
                            <!-- Daily View -->
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-calendar-day mr-2"></i> Daily Schedule: <?php echo date('l, d F Y', strtotime($today)); ?>
                                </div>
                                <div class="card-body">
                                    <?php if (isset($schedule[$currentDay]) && !empty($schedule[$currentDay])): ?>
                                        <div class="schedule-items p-2">
                                            <?php foreach ($schedule[$currentDay] as $class): ?>
                                                <?php
                                                    // Determine if class is current, upcoming, or past
                                                    $class_status = 'past-class';
                                                    $current_time = date('H:i:s', strtotime('2025-03-17 17:30:18'));
                                                    
                                                    if ($class['start_time'] <= $current_time && $class['end_time'] >= $current_time) {
                                                        $class_status = 'current-class';
                                                    } elseif ($class['start_time'] > $current_time) {
                                                        $class_status = 'upcoming-class';
                                                    }
                                                    
                                                    // Format times
                                                    $start_time = date('h:i A', strtotime($class['start_time']));
                                                    $end_time = date('h:i A', strtotime($class['end_time']));
                                                    
                                                    // Get attendance stats if available
                                                    $attendance_key = $class['module_id'] . '_' . $today;
                                                    $attended_count = 0;
                                                    if (isset($attendance_stats[$attendance_key])) {
                                                        $attended_count = $attendance_stats[$attendance_key]['attended_count'];
                                                    }
                                                    
                                                    // Get student count for this module
                                                    $student_count = isset($modules[$class['module_id']]) ? $modules[$class['module_id']]['student_count'] : 0;
                                                    
                                                    // Calculate attendance percentage
                                                    $attendance_percent = $student_count > 0 ? round(($attended_count / $student_count) * 100) : 0;
                                                ?>
                                                <div class="schedule-item <?php echo $class_status; ?> position-relative">
                                                    <div class="d-flex justify-content-between mb-2">
                                                        <span class="module-code"><?php echo htmlspecialchars($class['module_code'] ?? 'No Code'); ?></span>
                                                        <span class="schedule-time">
                                                            <i class="far fa-clock mr-1"></i><?php echo $start_time; ?> - <?php echo $end_time; ?>
                                                        </span>
                                                    </div>
                                                    <h5><?php echo htmlspecialchars($class['module_name']); ?></h5>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <p class="schedule-location mb-1">
                                                                <i class="fas fa-map-marker-alt mr-1"></i>
                                                                <?php echo htmlspecialchars($class['location'] ?? 'Classroom'); ?>
                                                            </p>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <p class="schedule-professor mb-1">
                                                                <i class="fas fa-users mr-1"></i>
                                                                <?php echo $student_count; ?> Students
                                                            </p>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if (isset($attendance_stats[$attendance_key])): ?>
                                                    <div class="mt-2">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span>
                                                                <i class="fas fa-clipboard-check mr-1"></i>
                                                                Attendance: <?php echo $attended_count; ?> / <?php echo $student_count; ?> students
                                                                (<?php echo $attendance_percent; ?>%)
                                                            </span>
                                                            <div>
                                                                <a href="professor_attendance_details.php?scan_id=<?php echo $attendance_stats[$attendance_key]['scan_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                                    <i class="fas fa-eye mr-1"></i> View Details
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php elseif ($class_status == 'current-class' || $class_status == 'upcoming-class'): ?>
                                                    <div class="mt-2">
                                                        <a href="professor_scan.php?module=<?php echo $class['module_id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-qrcode mr-1"></i> Start Attendance
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <span class="student-count">
                                                        <i class="fas fa-user-graduate mr-1"></i> <?php echo $student_count; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="p-5 text-center text-muted">
                                            <i class="fas fa-calendar-times fa-3x mb-3"></i>
                                            <h4>No Classes Today</h4>
                                            <p>You don't have any classes scheduled for today.</p>
                                            <a href="?view=week" class="btn btn-primary mt-2">View Weekly Schedule</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <!-- Monthly View -->
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-calendar-alt mr-2"></i> Monthly Schedule: <?php echo date('F Y', strtotime($start_date)); ?>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Mon</th>
                                                    <th>Tue</th>
                                                    <th>Wed</th>
                                                    <th>Thu</th>
                                                    <th>Fri</th>
                                                    <th>Sat</th>
                                                    <th>Sun</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                // Generate monthly calendar
                                                $firstDay = date('Y-m-01', strtotime($start_date));
                                                $lastDay = date('Y-m-t', strtotime($start_date));
                                                
                                                $firstDayOfWeek = date('N', strtotime($firstDay));
                                                $lastDayOfMonth = date('j', strtotime($lastDay));
                                                
                                                $weeks = ceil(($lastDayOfMonth + $firstDayOfWeek - 1) / 7);
                                                
                                                $day = 1 - $firstDayOfWeek + 1; // Start with the correct offset
                                                
                                                for ($week = 0; $week < $weeks; $week++) {
                                                    echo '<tr>';
                                                    for ($dayOfWeek = 1; $dayOfWeek <= 7; $dayOfWeek++) {
                                                        if ($day > 0 && $day <= $lastDayOfMonth) {
                                                            $date = date('Y-m-') . sprintf('%02d', $day);
                                                            $isToday = $date === $today;
                                                            $cellClass = $isToday ? 'bg-light' : '';
                                                            
                                                            echo '<td class="' . $cellClass . '" style="height: 100px; width: 14%; vertical-align: top;">';
                                                            echo '<div class="d-flex justify-content-between align-items-center mb-1">';
                                                            echo '<span class="' . ($isToday ? 'font-weight-bold text-primary' : '') . '">' . $day . '</span>';
                                                            
                                                            // Count classes for this day
                                                            $classCount = isset($schedule_by_date[$date]) ? count($schedule_by_date[$date]) : 0;
                                                            if ($classCount > 0) {
                                                                echo '<span class="badge badge-primary badge-pill">' . $classCount . '</span>';
                                                            }
                                                            
                                                            echo '</div>';
                                                            
                                                            // Display class items for this day
                                                            if (isset($schedule_by_date[$date])) {
                                                                foreach ($schedule_by_date[$date] as $class) {
                                                                    $start_time = date('H:i', strtotime($class['start_time']));
                                                                    
                                                                    // Check attendance status
                                                                    $attendance_key = $class['module_id'] . '_' . $date;
                                                                    $attended_count = 0;
                                                                    $has_attendance = false;
                                                                    
                                                                    if (isset($attendance_stats[$attendance_key])) {
                                                                        $has_attendance = true;
                                                                        $attended_count = $attendance_stats[$attendance_key]['attended_count'];
                                                                    }
                                                                    
                                                                    $student_count = isset($modules[$class['module_id']]) ? $modules[$class['module_id']]['student_count'] : 0;
                                                                    $attendance_percent = $student_count > 0 ? round(($attended_count / $student_count) * 100) : 0;
                                                                    
                                                                    $status_class = '';
                                                                    if ($has_attendance) {
                                                                        if ($attendance_percent >= 75) {
                                                                            $status_class = 'attended';
                                                                        } elseif ($attendance_percent >= 50) {
                                                                            $status_class = 'late';
                                                                        } else {
                                                                            $status_class = 'absent';
                                                                        }
                                                                    }
                                                                    
                                                                    echo '<div class="calendar-event ' . $status_class . '" title="' . htmlspecialchars($class['module_name']) . ' - ' . $student_count . ' students">';
                                                                    echo $start_time . ' ' . htmlspecialchars($class['module_code'] ?? 'No Code');
                                                                    echo '</div>';
                                                                }
                                                            }
                                                            
                                                            echo '</td>';
                                                        } else {
                                                            echo '<td class="bg-light"></td>';
                                                        }
                                                        $day++;
                                                    }
                                                    echo '</tr>';
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="attendance-indicator attendance-good mr-2"></div>
                                            <small>Good Attendance (>75%)</small>
                                            <div class="attendance-indicator attendance-medium mx-2"></div>
                                            <small>Medium Attendance (50-75%)</small>
                                            <div class="attendance-indicator attendance-poor mx-2"></div>
                                            <small>Poor Attendance (<50%)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Today's Classes -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-clock mr-2"></i> Today's Classes
                            </div>
                            <div class="card-body">
                                <?php if (isset($schedule[$currentDay]) && !empty($schedule[$currentDay])): ?>
                                    <div class="d-flex justify-content-between mb-3">
                                        <h6 class="mb-0"><?php echo $currentDay; ?>, <?php echo date('d F Y', strtotime($today)); ?></h6>
                                        <span class="badge badge-light"><?php echo count($schedule[$currentDay]); ?> classes</span>
                                    </div>
                                    
                                    <?php foreach ($schedule[$currentDay] as $class): ?>
                                        <?php
                                            // Determine status
                                            $class_status = 'past-class';
                                            $status_text = 'Completed';
                                            $status_icon = 'fas fa-check-circle text-secondary';
                                            
                                            $current_time = date('H:i:s', strtotime('2025-03-17 17:30:18'));
                                            
                                            if ($class['start_time'] <= $current_time && $class['end_time'] >= $current_time) {
                                                $class_status = 'current-class';
                                                $status_text = 'In Progress';
                                                $status_icon = 'fas fa-circle text-primary';
                                            } elseif ($class['start_time'] > $current_time) {
                                                $class_status = 'upcoming-class';
                                                $status_text = 'Upcoming';
                                                $status_icon = 'far fa-clock text-info';
                                            }
                                            
                                            // Get attendance stats if available
                                            $attendance_key = $class['module_id'] . '_' . $today;
                                            $attendance_text = '';
                                            
                                            if (isset($attendance_stats[$attendance_key])) {
                                                $attended_count = $attendance_stats[$attendance_key]['attended_count'];
                                                $student_count = isset($modules[$class['module_id']]) ? $modules[$class['module_id']]['student_count'] : 0;
                                                $attendance_percent = $student_count > 0 ? round(($attended_count / $student_count) * 100) : 0;
                                                
                                                if ($attendance_percent >= 75) {
                                                    $attendance_text = '<span class="badge badge-success">' . $attendance_percent . '%</span>';
                                                } elseif ($attendance_percent >= 50) {
                                                    $attendance_text = '<span class="badge badge-warning">' . $attendance_percent . '%</span>';
                                                } else {
                                                    $attendance_text = '<span class="badge badge-danger">' . $attendance_percent . '%</span>';
                                                }
                                            }
                                            
                                            // Format times
                                            $start_time = date('h:i A', strtotime($class['start_time']));
                                            $end_time = date('h:i A', strtotime($class['end_time']));
                                        ?>
                                        <div class="schedule-item <?php echo $class_status; ?> mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="schedule-time"><?php echo $start_time; ?> - <?php echo $end_time; ?></span>
                                                <span>
                                                    <i class="<?php echo $status_icon; ?> mr-1"></i>
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($class['module_name']); ?></h6>
                                            <div class="d-flex justify-content-between">
                                                <small><?php echo htmlspecialchars($class['location'] ?? 'Classroom'); ?></small>
                                                <?php echo $attendance_text; ?>
                                            </div>
                                            
                                            <?php if ($class_status == 'current-class' || $class_status == 'upcoming-class'): ?>
                                            <div class="mt-2">
                                                <a href="professor_scan.php?module=<?php echo $class['module_id']; ?>" class="btn btn-sm btn-block btn-primary quick-action-btn">
                                                    <i class="fas fa-qrcode mr-1"></i> Start Attendance
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar-times fa-2x text-muted mb-2"></i>
                                        <p class="mb-0">No classes scheduled for today</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Quick Attendance -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-qrcode mr-2"></i> Quick Attendance
                            </div>
                            <div class="card-body">
                                <div class="qr-code-container">
                                    <div class="qr-code-placeholder">
                                        <i class="fas fa-qrcode"></i>
                                    </div>
                                    <p class="mt-3 mb-1">Generate a QR code for attendance</p>
                                    <small class="text-muted">Select a module below</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="moduleSelect"><i class="fas fa-book mr-1"></i> Select Module:</label>
                                    <select class="form-control" id="moduleSelect">
                                        <option value="">-- Select a module --</option>
                                        <?php foreach ($modules as $module): ?>
                                        <option value="<?php echo $module['id']; ?>"><?php echo htmlspecialchars($module['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <button id="generateQRBtn" class="btn btn-primary btn-block quick-action-btn">
                                    <i class="fas fa-qrcode mr-1"></i> Generate QR Code
                                </button>
                            </div>
                        </div>
                        
                        <!-- My Modules -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-book mr-2"></i> My Modules
                            </div>
                            <div class="card-body p-0">
                                <div class="module-list">
                                    <?php if (count($modules) > 0): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($modules as $module): ?>
                                                <a href="professor_module_detail.php?id=<?php echo $module['id']; ?>" class="list-group-item list-group-item-action">
                                                    <div class="d-flex justify-content-between">
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($module['name']); ?></h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($module['code'] ?? 'No Code'); ?></small>
                                                    </div>
                                                    <div class="d-flex justify-content-between">
                                                        <small class="text-muted">
                                                            <i class="fas fa-user-graduate mr-1"></i> <?php echo $module['student_count']; ?> students
                                                        </small>
                                                        <small class="text-muted">
                                                            <i class="fas fa-calendar-alt mr-1"></i> <?php echo count($module['schedule']); ?> sessions/week
                                                        </small>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="p-4 text-center text-muted">
                                            <p>No modules assigned yet.</p>
                                        </div>
                                    <?php endif; ?>
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
    <script>
        $(document).ready(function() {
            // Current date and time: 2025-03-17 17:30:18 (UTC)
            
            // Highlight today's schedule
            $('.schedule-day.today-marker').find('.schedule-day-header').addClass('font-weight-bold');
            
            // Detailed view for calendar events
            $('.calendar-event').tooltip();
            
            // Generate QR Code functionality
            $('#generateQRBtn').click(function() {
                const moduleId = $('#moduleSelect').val();
                if (moduleId) {
                    window.location.href = 'professor_scan.php?module=' + moduleId;
                } else {
                    alert('Please select a module first');
                }
            });
            
            // Add hover effects for quick action buttons
            $('.quick-action-btn').hover(
                function() {
                    $(this).addClass('shadow');
                },
                function() {
                    $(this).removeClass('shadow');
                }
            );
        });
    </script>
</body>
</html>