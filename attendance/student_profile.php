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
$student_email = $_SESSION["email"] ?? '';
$student_rfid = $_SESSION["rfid_tag"] ?? '';

// Fetch detailed student information
$sql = "SELECT u.name, u.email, u.rfid_tag, u.field, u.created_at, u.last_login, u.active
        FROM users u 
        WHERE u.id = ?";

$student_data = [];
if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $student_data = $row;
    }
    $stmt->close();
}

// Get enrolled modules
$modules = [];
$sql_modules = "SELECT m.id, m.name, m.code, m.description
                FROM modules m
                JOIN student_module sm ON m.id = sm.module_id
                WHERE sm.student_id = ?";

if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
    $stmt->close();
}

// Count total enrolled modules
$module_count = count($modules);

// Get attendance statistics
$attendance_stats = [];
$sql_stats = "SELECT 
                m.id,
                m.name,
                COUNT(DISTINCT ts.id) AS total_sessions,
                COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN a.teacher_scan_id END) AS attended_sessions
              FROM modules m
              JOIN student_module sm ON m.id = sm.module_id
              LEFT JOIN teacher_scans ts ON ts.module_id = m.id
              LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = ?
              WHERE sm.student_id = ?
              GROUP BY m.id
              ORDER BY m.name";

if ($stmt = $link->prepare($sql_stats)) {
    $stmt->bind_param("ii", $student_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $attendance_stats[$row['id']] = $row;
    }
    $stmt->close();
}

// Calculate overall attendance
$total_sessions = 0;
$attended_sessions = 0;

foreach ($attendance_stats as $stat) {
    $total_sessions += $stat['total_sessions'];
    $attended_sessions += $stat['attended_sessions'];
}

$overall_attendance = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : 0;

// Recent attendance records
$recent_attendance = [];
$sql_recent = "SELECT 
                a.id, 
                a.scan_time, 
                a.status, 
                m.name AS module_name,
                ts.scan_time AS class_start_time
               FROM attendance a
               JOIN modules m ON a.module_id = m.id
               LEFT JOIN teacher_scans ts ON a.teacher_scan_id = ts.id
               WHERE a.student_id = ?
               ORDER BY a.scan_time DESC
               LIMIT 5";

if ($stmt = $link->prepare($sql_recent)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_attendance[] = $row;
    }
    $stmt->close();
}

// Get current date and time
$currentDateTime = '2025-03-17 03:36:07';

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Student Dashboard</title>
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
        .large-profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background-color: #28a745;
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 48px;
            margin: 0 auto;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
        .info-value {
            font-weight: 400;
        }
        .attendance-badge {
            font-size: 0.9rem;
            padding: 0.5rem 0.75rem;
        }
        .progress {
            height: 10px;
            margin-bottom: 10px;
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
        .module-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .module-card:hover {
            transform: translateY(-5px);
        }
        .module-code {
            font-size: 0.85rem;
            color: #6c757d;
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
                <li class="nav-item">
                    <a class="nav-link" href="student_modules.php">
                        <i class="fas fa-book"></i> My Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="student_schedule.php">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </a>
                </li>
                <li class="nav-item active">
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
                        <li class="nav-item active">
                            <a class="nav-link" href="#">
                                <i class="fas fa-user"></i>
                                My Profile
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
                                    <h1 class="profile-name">My Profile</h1>
                                    <p class="profile-role">View your personal information and stats</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <!-- Profile Card -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-id-card mr-2"></i> Personal Information
                            </div>
                            <div class="card-body text-center">
                                <div class="large-profile-avatar">
                                    <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                                </div>
                                
                                <h4><?php echo htmlspecialchars($student_name); ?></h4>
                                <p class="text-muted mb-4">Student</p>
                                
                                <div class="text-left">
                                    <div class="mb-3">
                                        <span class="info-label">Email:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($student_email); ?></span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <span class="info-label">RFID Tag:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($student_rfid); ?></span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <span class="info-label">Field of Study:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($student_data['field'] ?? 'Not specified'); ?></span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <span class="info-label">Joined:</span>
                                        <span class="info-value">
                                            <?php echo isset($student_data['created_at']) ? date('F j, Y', strtotime($student_data['created_at'])) : 'Unknown'; ?>
                                        </span>
                                    </div>
                                    
                                    <div>
                                        <span class="info-label">Status:</span>
                                        <span class="badge badge-success">Active Student</span>
                                    </div>
                                </div>
                                
                                <div class="mt-4 text-center">
                                    <a href="student_settings.php" class="btn btn-success">
                                        <i class="fas fa-edit mr-1"></i> Edit Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Overall Attendance Card -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-chart-pie mr-2"></i> Overall Attendance
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <h1 class="display-4 mb-0"><?php echo $overall_attendance; ?>%</h1>
                                    <p class="text-muted">Attendance Rate</p>
                                </div>
                                
                                <div class="progress">
                                    <?php 
                                        $progress_color = $overall_attendance >= 75 ? 'bg-success' : ($overall_attendance >= 50 ? 'bg-warning' : 'bg-danger');
                                    ?>
                                    <div class="progress-bar <?php echo $progress_color; ?>" role="progressbar" 
                                         style="width: <?php echo $overall_attendance; ?>%" 
                                         aria-valuenow="<?php echo $overall_attendance; ?>" aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <div>
                                        <h5 class="mb-0"><?php echo $attended_sessions; ?></h5>
                                        <small class="text-muted">Attended</small>
                                    </div>
                                    <div>
                                        <h5 class="mb-0"><?php echo $total_sessions; ?></h5>
                                        <small class="text-muted">Total Sessions</small>
                                    </div>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <a href="student_attendance_report.php" class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-chart-bar mr-1"></i> View Full Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <!-- Module Attendance -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-book-reader mr-2"></i> Module Attendance
                            </div>
                            <div class="card-body">
                                <?php if (!empty($attendance_stats)): ?>
                                    <?php foreach ($attendance_stats as $stat): 
                                        $module_rate = $stat['total_sessions'] > 0 ? 
                                            round(($stat['attended_sessions'] / $stat['total_sessions']) * 100) : 0;
                                        
                                        $badge_class = $module_rate >= 75 ? 'badge-success' : 
                                            ($module_rate >= 50 ? 'badge-warning' : 'badge-danger');
                                    ?>
                                    <div class="mb-4">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h5 class="mb-0"><?php echo htmlspecialchars($stat['name']); ?></h5>
                                            <span class="badge attendance-badge <?php echo $badge_class; ?>">
                                                <?php echo $module_rate; ?>%
                                            </span>
                                        </div>
                                        
                                        <div class="progress">
                                            <?php 
                                                $progress_color = $module_rate >= 75 ? 'bg-success' : 
                                                    ($module_rate >= 50 ? 'bg-warning' : 'bg-danger');
                                            ?>
                                            <div class="progress-bar <?php echo $progress_color; ?>" role="progressbar" 
                                                 style="width: <?php echo $module_rate; ?>%" 
                                                 aria-valuenow="<?php echo $module_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between">
                                            <small class="text-muted">
                                                <?php echo $stat['attended_sessions']; ?> of <?php echo $stat['total_sessions']; ?> sessions attended
                                            </small>
                                            
                                            <?php if ($module_rate < 75): ?>
                                            <small class="text-danger">
                                                <i class="fas fa-exclamation-triangle mr-1"></i> Below 75% attendance
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-book fa-3x text-muted mb-3"></i>
                                        <h5>No Module Data Available</h5>
                                        <p class="text-muted">You are not enrolled in any modules yet or no attendance has been recorded.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Recent Attendance -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-history mr-2"></i> Recent Attendance Activity
                            </div>
                            <div class="card-body">
                                <?php if (!empty($recent_attendance)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date & Time</th>
                                                    <th>Module</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_attendance as $record): ?>
                                                <tr>
                                                    <td><?php echo date('d M Y, H:i', strtotime($record['scan_time'])); ?></td>
                                                    <td><?php echo htmlspecialchars($record['module_name']); ?></td>
                                                    <td class="status-<?php echo $record['status']; ?>">
                                                        <?php 
                                                        if ($record['status'] == 'present') {
                                                            echo '<i class="fas fa-check-circle mr-1"></i> Present';
                                                        } elseif ($record['status'] == 'absent') {
                                                            echo '<i class="fas fa-times-circle mr-1"></i> Absent';
                                                        } else {
                                                            echo '<i class="fas fa-exclamation-circle mr-1"></i> Late';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                                        <h5>No Recent Activity</h5>
                                        <p class="text-muted">Your attendance activities will appear here.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Enrolled Modules -->
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-book mr-2"></i> Enrolled Modules
                            </div>
                            <div class="card-body">
                                <?php if (!empty($modules)): ?>
                                    <div class="row">
                                        <?php foreach ($modules as $module): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card module-card h-100">
                                                <div class="card-body">
                                                    <h5 class="card-title"><?php echo htmlspecialchars($module['name']); ?></h5>
                                                    <h6 class="card-subtitle mb-2 text-muted module-code"><?php echo htmlspecialchars($module['code'] ?? 'No Code'); ?></h6>
                                                    <p class="card-text small">
                                                        <?php echo htmlspecialchars($module['description'] ?? 'No description available.'); ?>
                                                    </p>
                                                    <a href="student_module_detail.php?id=<?php echo $module['id']; ?>" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-info-circle mr-1"></i> Module Details
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                                        <h5>No Modules Enrolled</h5>
                                        <p class="text-muted">You are not currently enrolled in any modules.</p>
                                    </div>
                                <?php endif; ?>
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
            // Current date and time: 2025-03-17 03:36:07 (UTC)
        });
    </script>
</body>
</html>