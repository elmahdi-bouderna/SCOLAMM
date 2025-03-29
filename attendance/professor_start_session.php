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

// Process form submission to start a new session
$session_started = false;
$session_id = 0;
$module_id = 0;
$module_name = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["start_session"])) {
    $module_id = trim($_POST["module_id"]);
    $duration = trim($_POST["duration"]); // Duration in minutes
    
    // Check if a session is already running for this module
    $check_sql = "SELECT * FROM teacher_scans 
                 WHERE professor_id = ? AND module_id = ? AND status = 'started' 
                 AND scan_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    
    if ($stmt = $link->prepare($check_sql)) {
        $stmt->bind_param("ii", $professor_id, $module_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error_message = "A session for this module is already running. Please end it before starting a new one.";
        } else {
            // Check if end_time column exists in teacher_scans table
            $column_check_sql = "SHOW COLUMNS FROM teacher_scans LIKE 'end_time'";
            $column_check_result = $link->query($column_check_sql);
            $end_time_exists = ($column_check_result->num_rows > 0);
            
            if ($end_time_exists) {
                $insert_sql = "INSERT INTO teacher_scans (professor_id, module_id, scan_time, status, end_time) 
                              VALUES (?, ?, NOW(), 'started', DATE_ADD(NOW(), INTERVAL ? MINUTE))";
                
                if ($stmt_insert = $link->prepare($insert_sql)) {
                    $stmt_insert->bind_param("iii", $professor_id, $module_id, $duration);
                    
                    if ($stmt_insert->execute()) {
                        $session_id = $stmt_insert->insert_id;
                        $session_started = true;
                    } else {
                        $error_message = "Error starting session. Please try again.";
                    }
                    $stmt_insert->close();
                }
            } else {
                // If end_time column doesn't exist
                $insert_sql = "INSERT INTO teacher_scans (professor_id, module_id, scan_time, status) 
                              VALUES (?, ?, NOW(), 'started')";
                
                if ($stmt_insert = $link->prepare($insert_sql)) {
                    $stmt_insert->bind_param("ii", $professor_id, $module_id);
                    
                    if ($stmt_insert->execute()) {
                        $session_id = $stmt_insert->insert_id;
                        $session_started = true;
                    } else {
                        $error_message = "Error starting session. Please try again.";
                    }
                    $stmt_insert->close();
                }
            }
            
            if ($session_started) {
                // Get module name
                $module_sql = "SELECT name FROM modules WHERE id = ?";
                if ($module_stmt = $link->prepare($module_sql)) {
                    $module_stmt->bind_param("i", $module_id);
                    $module_stmt->execute();
                    $module_result = $module_stmt->get_result();
                    if ($module_row = $module_result->fetch_assoc()) {
                        $module_name = $module_row['name'];
                    }
                    $module_stmt->close();
                }
                
                // Log session start
                $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                           VALUES (NOW(), ?, ?, 'Session Started', ?, ?, 'info', 'attendance')";
                $description = "Started session for module: " . $module_name;
                $ip = $_SERVER['REMOTE_ADDR'];
                
                if ($log_stmt = $link->prepare($log_sql)) {
                    $log_stmt->bind_param("isss", $professor_id, $professor_name, $description, $ip);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            } else {
                $error_message = "Database error. Please try again.";
            }
        }
        $stmt->close();
    }
}

// Fetch professor's modules
$sql_modules = "SELECT m.id, m.name, m.code
               FROM professor_module pm
               JOIN modules m ON pm.module_id = m.id
               WHERE pm.professor_id = ?
               ORDER BY m.name ASC";

$modules = [];
if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $modules = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch today's timetable
$day_of_week = date('l'); // Current day of the week
$current_time = date('H:i:s'); // Current time

$sql_timetable = "SELECT t.id, t.module_id, m.name AS module_name, m.code AS module_code, 
                 t.start_time, t.end_time
                 FROM timetable t
                 JOIN modules m ON t.module_id = m.id
                 WHERE t.professor_id = ? AND t.day_of_week = ?
                 ORDER BY t.start_time ASC";

$today_schedule = [];
if ($stmt = $link->prepare($sql_timetable)) {
    $stmt->bind_param("is", $professor_id, $day_of_week);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $today_schedule = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get current date and time
$currentDateTime = '2025-03-17 22:04:29';

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start Session - Professor Dashboard</title>
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
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #007bff;
            color: white;
            font-weight: 600;
        }
        .module-select-card {
            transition: transform 0.2s;
            cursor: pointer;
        }
        .module-select-card:hover {
            transform: translateY(-5px);
        }
        .module-select-card.selected {
            border: 2px solid #28a745;
            background-color: rgba(40, 167, 69, 0.1);
        }
        .session-progress {
            height: 8px;
            margin: 10px 0;
        }
        .countdown-timer {
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
        }
        .student-list-container {
            max-height: 400px;
            overflow-y: auto;
        }
        .datetime {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .session-info {
            background-color: #e9f7ef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
        .timetable-item {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .timetable-item:hover {
            background-color: #e9ecef;
            transform: translateX(5px);
        }
        .timetable-item.current {
            background-color: #e8f4ff;
            border-left: 4px solid #28a745;
        }
        .timetable-item .module-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        .timetable-item .time {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .quick-start-btn {
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="#">
            <i class="fas fa-graduation-cap mr-2"></i>
            Scolagile
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
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
                        <i class="fas fa-qrcode"></i> Start Session
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
                            <a class="nav-link active" href="professor_start_session.php">
                                <i class="fas fa-qrcode"></i>
                                Start Session
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="professor_attendance_reports.php">
                                <i class="fas fa-chart-bar"></i>
                                Attendance Reports
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
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-qrcode mr-2"></i>Start Attendance Session</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group mr-2">
                            <a href="professor_dashboard.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                            </a>
                            <a href="professor_attendance_reports.php" class="btn btn-sm btn-outline-info">
                                <i class="fas fa-chart-bar mr-1"></i> View Reports
                            </a>
                        </div>
                    </div>
                </div>

                <div class="text-right mb-3">
                    <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                </div>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($session_started): ?>
                    <div id="sessionStartedContainer" class="card session-info pulse-animation">
                        <div class="card-body text-center">
                            <h4><i class="fas fa-check-circle text-success mr-2"></i>Session Started Successfully!</h4>
                            <p class="lead mb-0">Module: <strong><?php echo htmlspecialchars($module_name); ?></strong></p>
                            <p>Session ID: <?php echo $session_id; ?></p>
                            <p>Students can now scan their RFID cards to mark attendance</p>
                            <div class="mt-3">
                                <a href="professor_monitor_session.php?session_id=<?php echo $session_id; ?>" class="btn btn-primary btn-lg">
                                    <i class="fas fa-desktop mr-1"></i> Monitor Session
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <!-- Today's Timetable -->
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-calendar-day mr-2"></i> Today's Schedule (<?php echo $day_of_week; ?>)
                                </div>
                                <div class="card-body">
                                    <?php if (count($today_schedule) > 0): ?>
                                        <div class="mb-3">
                                            <p>You can quickly start a session for one of today's scheduled classes:</p>
                                        </div>
                                        <?php 
                                        foreach ($today_schedule as $schedule): 
                                            $is_current = (strtotime($schedule['start_time']) <= time() && time() <= strtotime($schedule['end_time']));
                                            $class = $is_current ? 'current' : '';
                                        ?>
                                            <div class="timetable-item <?php echo $class; ?>">
                                                <div class="module-name">
                                                    <?php echo htmlspecialchars($schedule['module_name']); ?>
                                                    <?php if(!empty($schedule['module_code'])): ?> 
                                                        (<?php echo htmlspecialchars($schedule['module_code']); ?>)
                                                    <?php endif; ?>
                                                </div>
                                                <div class="time">
                                                    <i class="far fa-clock mr-1"></i>
                                                    <?php echo date('H:i', strtotime($schedule['start_time'])); ?> - 
                                                    <?php echo date('H:i', strtotime($schedule['end_time'])); ?>
                                                </div>
                                                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                                    <input type="hidden" name="module_id" value="<?php echo $schedule['module_id']; ?>">
                                                    <?php
                                                        // Calculate duration in minutes
                                                        $start = new DateTime($schedule['start_time']);
                                                        $end = new DateTime($schedule['end_time']);
                                                        $diff = $start->diff($end);
                                                        $duration = ($diff->h * 60) + $diff->i;
                                                    ?>
                                                    <input type="hidden" name="duration" value="<?php echo $duration; ?>">
                                                    <button type="submit" name="start_session" class="btn btn-sm <?php echo $is_current ? 'btn-success' : 'btn-outline-primary'; ?> quick-start-btn">
                                                        <i class="fas fa-play-circle mr-1"></i> <?php echo $is_current ? 'Start Now' : 'Start Session'; ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle mr-2"></i> You have no scheduled classes for today.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Start Manual Session -->
                        <div class="col-md-5">
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-book mr-2"></i> Start a Custom Session
                                </div>
                                <div class="card-body">
                                    <?php if (count($modules) > 0): ?>
                                        <form id="startSessionForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                            <div class="form-group">
                                                <label for="module_id">Select Module:</label>
                                                <select class="form-control" id="module_id" name="module_id" required>
                                                    <option value="">-- Select a module --</option>
                                                    <?php foreach ($modules as $module): ?>
                                                        <option value="<?php echo $module['id']; ?>">
                                                            <?php echo htmlspecialchars($module['name']); ?>
                                                            <?php if(!empty($module['code'])): ?> 
                                                                (<?php echo htmlspecialchars($module['code']); ?>)
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label for="duration">Session Duration (minutes):</label>
                                                <select class="form-control" id="duration" name="duration" required>
                                                    <option value="45">45 minutes</option>
                                                    <option value="60" selected>60 minutes</option>
                                                    <option value="90">90 minutes</option>
                                                    <option value="120">2 hours</option>
                                                    <option value="180">3 hours</option>
                                                </select>
                                            </div>
                                            <button type="submit" name="start_session" class="btn btn-success btn-lg">
                                                <i class="fas fa-play-circle mr-1"></i> Start Attendance Session
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle mr-2"></i> You don't have any modules assigned to you.
                                        </div>
                                        <a href="professor_modules.php" class="btn btn-primary">
                                            <i class="fas fa-book mr-1"></i> View My Modules
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Information Card -->
                        <div class="col-md-3">
                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-info-circle mr-2"></i> Session Information
                                </div>
                                <div class="card-body">
                                    <h5>About Attendance Sessions</h5>
                                    <p>When you start a session:</p>
                                    <ul>
                                        <li>Students can scan their RFID cards to mark attendance</li>
                                        <li>Real-time updates will show who has attended</li>
                                        <li>Students who don't scan by the end of the session will be marked as absent</li>
                                        <li>You can end the session manually at any time</li>
                                    </ul>
                                    <div class="alert alert-info">
                                        <i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> Make sure the RFID reader is properly connected and visible to students.
                                    </div>
                                </div>
                            </div>
                        </div>
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