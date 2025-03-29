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

// Check if session ID is provided
if (!isset($_GET["session_id"])) {
    header("location: professor_start_session.php");
    exit;
}

$session_id = intval($_GET["session_id"]);
$session_info = null;
$module_info = null;
$students_enrolled = [];
$students_present = [];

// Fetch session information
$sql_session = "SELECT ts.*, m.name AS module_name, m.code AS module_code 
               FROM teacher_scans ts 
               JOIN modules m ON ts.module_id = m.id 
               WHERE ts.id = ? AND ts.professor_id = ?";

if ($stmt = $link->prepare($sql_session)) {
    $stmt->bind_param("ii", $session_id, $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $session_info = $row;
            
            // Calculate end time if not present in database
            if (!isset($session_info['end_time'])) {
                // Default to 1 hour session if end_time is not in database
                $session_info['end_time'] = date('Y-m-d H:i:s', strtotime($session_info['scan_time'] . ' +1 hour'));
            }
            
            // Calculate remaining time
            $end_time = strtotime($session_info['end_time']);
            $current_time = time();
            $remaining_seconds = max(0, $end_time - $current_time);
            $remaining_minutes = floor($remaining_seconds / 60);
            $remaining_seconds_display = $remaining_seconds % 60;
            
            // Calculate progress percentage
            $start_time = strtotime($session_info['scan_time']);
            $total_duration = $end_time - $start_time;
            $elapsed = $current_time - $start_time;
            $progress = min(100, ($elapsed / $total_duration) * 100);
            
            $session_info['remaining_minutes'] = $remaining_minutes;
            $session_info['remaining_seconds'] = $remaining_seconds_display;
            $session_info['progress'] = $progress;
            $session_info['is_ended'] = ($session_info['status'] == 'ended');
        } else {
            // Session not found or doesn't belong to this professor
            header("location: professor_start_session.php");
            exit;
        }
    }
    $stmt->close();
}

// Fetch students enrolled in the module - FIXED QUERY
$sql_students = "SELECT u.id, u.name, u.email 
                FROM student_module sm 
                JOIN users u ON sm.student_id = u.id 
                WHERE sm.module_id = ? 
                ORDER BY u.name ASC";

if ($stmt = $link->prepare($sql_students)) {
    $stmt->bind_param("i", $session_info['module_id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $students_enrolled = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch students who have already marked attendance
$sql_present = "SELECT a.student_id, a.scan_time, a.status, u.name 
               FROM attendance a 
               JOIN users u ON a.student_id = u.id 
               WHERE a.teacher_scan_id = ? 
               ORDER BY a.scan_time ASC";

$present_ids = [];
if ($stmt = $link->prepare($sql_present)) {
    $stmt->bind_param("i", $session_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $students_present = $result->fetch_all(MYSQLI_ASSOC);
        
        // Create an array of present student IDs for easy checking
        foreach ($students_present as $student) {
            $present_ids[$student['student_id']] = $student['status'];
        }
    }
    $stmt->close();
}

// Handle session ending
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["end_session"])) {
    // Check if end_time column exists in teacher_scans table
    $column_check_sql = "SHOW COLUMNS FROM teacher_scans LIKE 'end_time'";
    $column_check_result = $link->query($column_check_sql);
    $end_time_exists = ($column_check_result->num_rows > 0);
    
    if ($end_time_exists) {
        $end_sql = "UPDATE teacher_scans SET status = 'ended', end_time = NOW() WHERE id = ?";
    } else {
        $end_sql = "UPDATE teacher_scans SET status = 'ended' WHERE id = ?";
    }
    
    if ($stmt = $link->prepare($end_sql)) {
        $stmt->bind_param("i", $session_id);
        
        if ($stmt->execute()) {
            // Mark absent students
            $absent_sql = "INSERT INTO attendance (student_id, module_id, scan_time, teacher_scan_id, status) 
                          SELECT sm.student_id, ?, NOW(), ?, 'absent' 
                          FROM student_module sm 
                          WHERE sm.module_id = ? 
                          AND sm.student_id NOT IN (
                              SELECT a.student_id FROM attendance a WHERE a.teacher_scan_id = ?
                          )";
            
            if ($stmt_absent = $link->prepare($absent_sql)) {
                $stmt_absent->bind_param("iiii", $session_info['module_id'], $session_id, 
                                      $session_info['module_id'], $session_id);
                $stmt_absent->execute();
                $stmt_absent->close();
            }
            
            // Update session info
            $session_info['status'] = 'ended';
            $session_info['is_ended'] = true;
            
            // Log session end
            $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                       VALUES (NOW(), ?, ?, 'Session Ended', ?, ?, 'info', 'attendance')";
            $description = "Ended session for module: " . $session_info['module_name'];
            $ip = $_SERVER['REMOTE_ADDR'];
            
            if ($log_stmt = $link->prepare($log_sql)) {
                $log_stmt->bind_param("isss", $professor_id, $professor_name, $description, $ip);
                $log_stmt->execute();
                $log_stmt->close();
            }
        }
        $stmt->close();
    }
}

// Get current date and time
$currentDateTime = '2025-03-17 21:46:45';

// Calculate attendance statistics
$total_students = count($students_enrolled);
$present_count = count($students_present);
$absent_count = $total_students - $present_count;
$attendance_rate = ($total_students > 0) ? ($present_count / $total_students) * 100 : 0;

// Close database connection
$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor Session - Professor Dashboard</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
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
        .session-info-card {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
        }
        .countdown-timer {
            font-size: 2.5rem;
            font-weight: bold;
            text-align: center;
            color: #fff;
        }
        .session-progress {
            height: 8px;
            margin: 10px 0;
        }
        .student-list-container {
            max-height: 500px;
            overflow-y: auto;
        }
        .student-present {
            background-color: rgba(40, 167, 69, 0.1);
            animation: fadeIn 1s;
        }
        .student-absent {
            background-color: rgba(220, 53, 69, 0.1);
        }
        .status-badge {
            font-size: 0.85rem;
        }
        .datetime {
            color: white;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .attendance-stat {
            font-size: 1.8rem;
            font-weight: bold;
            text-align: center;
        }
        .attendance-label {
            font-size: 0.9rem;
            text-align: center;
            color: #6c757d;
        }
        .session-ended-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(52, 58, 64, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            z-index: 10;
        }
        @keyframes fadeIn {
            from { opacity: 0; background-color: rgba(40, 167, 69, 0.3); }
            to { opacity: 1; background-color: rgba(40, 167, 69, 0.1); }
        }
        .live-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background-color: #dc3545;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }
        .card-session-ended {
            background: linear-gradient(135deg, #6c757d, #343a40);
        }
        .filter-controls {
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 15px;
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
                    <a class="nav-link" href="professor_start_session.php">
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
                    <h1 class="h2"><i class="fas fa-desktop mr-2"></i>Attendance Monitor</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group mr-2">
                            <a href="professor_start_session.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Sessions
                            </a>
                            <a href="professor_dashboard.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-tachometer-alt mr-1"></i> Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Session Information Card -->
                <div class="card <?php echo $session_info['is_ended'] ? 'card-session-ended' : 'session-info-card'; ?> mb-4">
                    <?php if ($session_info['is_ended']): ?>
                        <div class="session-ended-overlay">
                            <h2><i class="fas fa-check-circle mr-2"></i>Session Ended</h2>
                            <p class="lead">Attendance records have been finalized</p>
                            <a href="professor_attendance_reports.php?session_id=<?php echo $session_id; ?>" class="btn btn-light btn-lg mt-2">
                                <i class="fas fa-chart-bar mr-1"></i> View Report
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h3><?php echo htmlspecialchars($session_info['module_name']); ?> 
                                <?php if(!empty($session_info['module_code'])): ?>
                                    (<?php echo htmlspecialchars($session_info['module_code']); ?>)
                                <?php endif; ?>
                                </h3>
                                <p>
                                    <span class="badge badge-light">Session ID: <?php echo $session_id; ?></span>
                                    <span class="badge badge-light">Started: <?php echo date('H:i', strtotime($session_info['scan_time'])); ?></span>
                                    <?php if (!$session_info['is_ended']): ?>
                                        <span class="badge badge-light">Ends: <?php echo date('H:i', strtotime($session_info['end_time'])); ?></span>
                                        <span class="badge badge-success ml-2">
                                            <span class="live-indicator"></span> LIVE
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-light">Ended: <?php echo date('H:i', strtotime($session_info['end_time'])); ?></span>
                                        <span class="badge badge-secondary ml-2">ENDED</span>
                                    <?php endif; ?>
                                </p>
                                
                                <?php if (!$session_info['is_ended']): ?>
                                    <div class="progress session-progress">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" 
                                             style="width: <?php echo $session_info['progress']; ?>%" aria-valuenow="<?php echo $session_info['progress']; ?>" 
                                             aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <p class="datetime mb-0"><?php echo $currentDateTime; ?></p>
                                    </div>
                                    <div class="col-md-6 text-md-right">
                                        <?php if (!$session_info['is_ended']): ?>
                                            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?session_id=' . $session_id); ?>" 
                                                  onsubmit="return confirm('Are you sure you want to end this session? All remaining students will be marked as absent.');">
                                                <button type="submit" name="end_session" class="btn btn-danger">
                                                    <i class="fas fa-stop-circle mr-1"></i> End Session
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <?php if (!$session_info['is_ended']): ?>
                                    <h4>Session Ends In:</h4>
                                    <div id="countdown" class="countdown-timer">
                                        <?php echo sprintf('%02d:%02d', $session_info['remaining_minutes'], $session_info['remaining_seconds']); ?>
                                    </div>
                                    <small class="text-light">Minutes : Seconds</small>
                                <?php else: ?>
                                    <h4>Session Completed</h4>
                                    <div class="countdown-timer">00:00</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Stats and List -->
                <div class="row">
                    <!-- Statistics -->
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-chart-pie mr-2"></i> Attendance Stats
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="attendance-stat"><?php echo $total_students; ?></div>
                                        <div class="attendance-label">Total Students</div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="attendance-stat" id="presentCount"><?php echo $present_count; ?></div>
                                        <div class="attendance-label">Present</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="attendance-stat" id="absentCount"><?php echo $absent_count; ?></div>
                                        <div class="attendance-label">Absent</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="attendance-stat" id="attendanceRate"><?php echo round($attendance_rate); ?>%</div>
                                        <div class="attendance-label">Rate</div>
                                    </div>
                                </div>

                                <hr>
                                
                                <div class="text-center">
                                    <a href="professor_attendance_reports.php?session_id=<?php echo $session_id; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-chart-bar mr-1"></i> Detailed Report
                                    </a>
                                </div>
                            </div>
                        </div>

                        <?php if (!$session_info['is_ended']): ?>
                        <div class="card mt-3">
                            <div class="card-header">
                                <i class="fas fa-bullhorn mr-2"></i> Instructions
                            </div>
                            <div class="card-body">
                                <p>Students can mark their attendance by scanning their RFID cards at the reader.</p>
                                <p>The list will update automatically as students scan their cards.</p>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-1"></i> Students who don't scan their cards will be automatically marked as absent when the session ends.
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Student List -->
                    <div class="col-md-9">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-users mr-2"></i> Student Attendance
                                        <?php if (!$session_info['is_ended']): ?>
                                            <span class="badge badge-success ml-2">
                                                <span class="live-indicator"></span> Live Updates
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <input type="text" id="studentSearch" class="form-control form-control-sm" placeholder="Search students...">
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="filter-controls border-bottom px-3 py-2">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary active" data-filter="all">All Students</button>
                                        <button class="btn btn-outline-success" data-filter="present">Present</button>
                                        <button class="btn btn-outline-danger" data-filter="absent">Absent</button>
                                    </div>
                                </div>
                                <div class="student-list-container">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Student ID</th>
                                                <th>Name</th>
                                                <th>Status</th>
                                                <th>Time</th>
                                            </tr>
                                        </thead>
                                        <tbody id="studentListBody">
                                            <?php $count = 1; ?>
                                            <?php foreach ($students_enrolled as $student): ?>
                                                <?php 
                                                    $status = isset($present_ids[$student['id']]) ? $present_ids[$student['id']] : 'absent';
                                                    $rowClass = $status === 'present' ? 'student-present' : ($session_info['is_ended'] ? 'student-absent' : '');
                                                    $scan_time = '';
                                                    
                                                    foreach ($students_present as $present) {
                                                        if ($present['student_id'] == $student['id']) {
                                                            $scan_time = date('H:i:s', strtotime($present['scan_time']));
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                <tr class="<?php echo $rowClass; ?>" data-status="<?php echo $status; ?>" data-student-id="<?php echo $student['id']; ?>">
                                                    <td><?php echo $count++; ?></td>
                                                    <td><?php echo htmlspecialchars($student['id']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                                    <td>
                                                        <?php if ($status === 'present'): ?>
                                                            <span class="badge badge-success status-badge"><i class="fas fa-check-circle mr-1"></i>Present</span>
                                                        <?php elseif ($status === 'late'): ?>
                                                            <span class="badge badge-warning status-badge"><i class="fas fa-clock mr-1"></i>Late</span>
                                                        <?php else: ?>
                                                            <?php if ($session_info['is_ended']): ?>
                                                                <span class="badge badge-danger status-badge"><i class="fas fa-times-circle mr-1"></i>Absent</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-secondary status-badge"><i class="fas fa-hourglass-half mr-1"></i>Pending</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $scan_time; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
    // WebSocket for real-time updates
    let socket = null;
    const sessionId = <?php echo $session_id; ?>;
    const isSessionEnded = <?php echo $session_info['is_ended'] ? 'true' : 'false'; ?>;
    let remainingTime = <?php echo $session_info['remaining_minutes'] * 60 + $session_info['remaining_seconds']; ?>;
    
    // Only setup WebSocket if session is not ended
    if (!isSessionEnded) {
        setupWebSocket();
        
        // Setup countdown timer
        const countdownTimer = setInterval(updateCountdown, 1000);
    }
    
    function setupWebSocket() {
        console.log("Setting up WebSocket connection...");
        
        // Use current host with WebSocket port
        const host = window.location.hostname || 'localhost';
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsUrl = `${protocol}//${host}:3000`;
        
        console.log(`Connecting to WebSocket server at ${wsUrl}`);
        socket = new WebSocket(wsUrl);
        
        socket.onopen = function(event) {
            console.log('Connected to WebSocket server successfully');
            
            // Send registration message to identify this client
            const registrationMsg = {
                type: 'register',
                session_id: sessionId,
                client_type: 'professor'
            };
            socket.send(JSON.stringify(registrationMsg));
            console.log('Sent registration message:', registrationMsg);
            
            // Show connected status
            showToast('Connected to attendance server', 'success');
        };
        
        socket.onmessage = function(event) {
            console.log('Message received from server:', event.data);
            
            try {
                const data = JSON.parse(event.data);
                
                if (data.type === 'attendance_update') {
                    console.log('Attendance update received:', data);
                    
                    // Update the student row in the table
                    updateStudentStatus(data.student_id, data.status, data.time);
                    
                    // Update attendance statistics
                    updateAttendanceStats();
                    
                    // Show notification
                    showToast(`${data.student_name} marked as ${data.status}`, 'success');
                    
                    // Play a sound
                    playNotificationSound();
                } 
                else if (data.type === 'session_ended') {
                    console.log('Session ended notification received');
                    showToast('Session has ended. Reloading page...', 'info');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                }
            } catch (error) {
                console.error('Error processing WebSocket message:', error);
            }
        };
        
        socket.onerror = function(error) {
            console.error('WebSocket error:', error);
            showToast('Connection error with attendance server', 'danger');
        };
        
        socket.onclose = function(event) {
            console.log('WebSocket connection closed. Reconnecting in 3 seconds...');
            showToast('Connection to attendance server lost. Reconnecting...', 'warning');
            
            // Attempt to reconnect after a delay
            setTimeout(setupWebSocket, 3000);
        };
    }
    
    function updateStudentStatus(studentId, status, time) {
        console.log(`Updating student ${studentId} status to ${status}`);
        
        // Find the student row
        const row = document.querySelector(`tr[data-student-id="${studentId}"]`);
        if (!row) {
            console.error(`Could not find row for student ID ${studentId}`);
            return;
        }
        
        // Update row styling
        if (status === 'present') {
            row.classList.remove('student-absent', 'student-late');
            row.classList.add('student-present');
        } else if (status === 'late') {
            row.classList.remove('student-absent', 'student-present');
            row.classList.add('student-late');
        }
        
        row.setAttribute('data-status', status);
        
        // Update status badge
        const statusCell = row.cells[3];
        if (statusCell) {
            let statusHtml = '';
            
            if (status === 'present') {
                statusHtml = '<span class="badge badge-success status-badge"><i class="fas fa-check-circle mr-1"></i>Present</span>';
            } else if (status === 'late') {
                statusHtml = '<span class="badge badge-warning status-badge"><i class="fas fa-clock mr-1"></i>Late</span>';
            } else if (status === 'absent') {
                statusHtml = '<span class="badge badge-danger status-badge"><i class="fas fa-times-circle mr-1"></i>Absent</span>';
            }
            
            statusCell.innerHTML = statusHtml;
        }
        
        // Update time
        const timeCell = row.cells[4];
        if (timeCell) {
            timeCell.textContent = time;
        }
        
        // Add highlight animation
        row.style.animation = 'none'; // Reset animation
        setTimeout(() => {
            row.style.animation = 'highlight-row 2s';
        }, 10);
        
        // Scroll to row if not visible
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    function updateAttendanceStats() {
        const totalStudents = <?php echo $total_students; ?>;
        const presentRows = document.querySelectorAll('tr[data-status="present"], tr[data-status="late"]').length;
        const absentRows = totalStudents - presentRows;
        const attendanceRate = totalStudents > 0 ? Math.round((presentRows / totalStudents) * 100) : 0;
        
        // Update the stats display
        document.getElementById('presentCount').textContent = presentRows;
        document.getElementById('absentCount').textContent = absentRows;
        document.getElementById('attendanceRate').textContent = attendanceRate + '%';
    }
    
    function showToast(message, type = 'info') {
        // Create toast container if it doesn't exist
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        // Create a unique ID for this toast
        const toastId = 'toast-' + Date.now();
        
        // Create the toast element
        const toastHtml = `
            <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-${type} text-white">
                    <i class="fas fa-bell me-2"></i>
                    <strong class="me-auto">Attendance Update</strong>
                    <small>${new Date().toLocaleTimeString()}</small>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        
        // Add the toast to the container
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        
        // Get the toast element and show it
        const toastElement = document.getElementById(toastId);
        const bsToast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: 5000
        });
        bsToast.show();
        
        // Remove the toast after it's hidden
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    function playNotificationSound() {
        try {
            // Create an audio element if it doesn't exist
            let audio = document.getElementById('notification-sound');
            if (!audio) {
                audio = document.createElement('audio');
                audio.id = 'notification-sound';
                audio.src = 'data:audio/mp3;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA/+M4wAAAAAAAAAAAAEluZm8AAAAPAAAAAwAAAbAAuLi4uLi4uLi4uLi4uLi4uLi4uLjIyMjIyMjIyMjIyMjIyMjIyMjIyMjI5OTk5OTk5OTk5OTk5OTk5OTk5OTk5P////////////////////////////////8AAAAATGF2YzU4LjEzAAAAAAAAAAAAAAAAJAYHg/+M4wD/AAAGkAAAABEjgOB4HgGB4HgGAAaB4Hg/y4HgGB4HgGB4HgeB4HgAAD+B4HgGB4HgeB4HgeAABh+B4HgGB4HgeB4HgeD4Pg+D4Pg+D4Pg+D4Pg+D4Pg+D4Pg+D4Pg+D4Pg+D4P/+M4wGP5JJK0AAgOB4HgGB4HgeB4HgeB4HgeD/Lg+H/l//+XB/lwfggAAABhgGAYBQDAKAgCAMG/7/////////////////////////////////////////////9///8=';
                audio.preload = 'auto';
                document.body.appendChild(audio);
            }
            audio.play();
        } catch (e) {
            console.error('Could not play notification sound:', e);
        }
    }
    
    function updateCountdown() {
        if (remainingTime <= 0) {
            clearInterval(countdownTimer);
            document.getElementById('countdown').innerHTML = '00:00';
            showToast('Session time has ended', 'warning');
            setTimeout(() => {
                location.reload();
            }, 3000);
            return;
        }
        
        remainingTime--;
        const minutes = Math.floor(remainingTime / 60);
        const seconds = remainingTime % 60;
        document.getElementById('countdown').innerHTML = 
            String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    }
    
    // Add highlight animation style
    const styleElement = document.createElement('style');
    styleElement.textContent = `
        @keyframes highlight-row {
            0% { background-color: #c8e6c9; }
            70% { background-color: #c8e6c9; }
            100% { background-color: transparent; }
        }
        .student-present {
            background-color: rgba(40, 167, 69, 0.1);
        }
        .student-late {
            background-color: rgba(255, 193, 7, 0.1);
        }
        .student-absent {
            background-color: rgba(220, 53, 69, 0.1);
        }
        #toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
    `;
    document.head.appendChild(styleElement);
    
    // Document ready handler
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Page loaded, setting up real-time monitoring...');
        
        // Ensure all student rows have the proper data-status attribute
        document.querySelectorAll('#studentListBody tr').forEach(row => {
            const statusCell = row.cells[3];
            if (statusCell) {
                const statusText = statusCell.textContent.trim().toLowerCase();
                if (statusText.includes('present')) {
                    row.setAttribute('data-status', 'present');
                    row.classList.add('student-present');
                } else if (statusText.includes('late')) {
                    row.setAttribute('data-status', 'late');
                    row.classList.add('student-late');
                } else if (statusText.includes('absent')) {
                    row.setAttribute('data-status', 'absent');
                    row.classList.add('student-absent');
                } else {
                    row.setAttribute('data-status', 'pending');
                }
            }
        });
    });
</script>
</body>
</html>