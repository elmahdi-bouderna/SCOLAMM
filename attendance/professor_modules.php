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

// For debugging - let's print some information about the current user
$debug = false; // Set to false in production

// Fetch professor's modules
$sql_modules = "SELECT m.id AS module_id, m.name AS module_name, 
                (SELECT COUNT(*) FROM student_module WHERE module_id = m.id) AS student_count,
                (SELECT COUNT(*) FROM teacher_scans WHERE module_id = m.id) AS session_count
                FROM professor_module pm
                JOIN modules m ON pm.module_id = m.id
                WHERE pm.professor_id = ?";

$modules = [];
if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $modules = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// If no modules found, let's check if the professor exists in professor_module table
$professor_module_check = false;
$sql_check = "SELECT COUNT(*) as count FROM professor_module WHERE professor_id = ?";
if ($stmt = $link->prepare($sql_check)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $professor_module_check = ($row['count'] > 0);
    }
    $stmt->close();
}

// If no modules found, let's also get all modules available in the system
$all_modules = [];
$sql_all_modules = "SELECT id, name FROM modules ORDER BY name";
if ($stmt = $link->prepare($sql_all_modules)) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $all_modules = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Handle module assignment
$assignment_message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_module'])) {
    $module_id = $_POST['module_id'];
    
    // Check if already assigned
    $already_assigned = false;
    $check_sql = "SELECT COUNT(*) as count FROM professor_module WHERE professor_id = ? AND module_id = ?";
    if ($stmt = $link->prepare($check_sql)) {
        $stmt->bind_param("ii", $professor_id, $module_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $already_assigned = ($row['count'] > 0);
        }
        $stmt->close();
    }
    
    if ($already_assigned) {
        $assignment_message = '<div class="alert alert-warning">This module is already assigned to you.</div>';
    } else {
        // Assign module to professor
        $assign_sql = "INSERT INTO professor_module (professor_id, module_id) VALUES (?, ?)";
        if ($stmt = $link->prepare($assign_sql)) {
            $stmt->bind_param("ii", $professor_id, $module_id);
            if ($stmt->execute()) {
                $assignment_message = '<div class="alert alert-success">Module successfully assigned.</div>';
                // Refresh the modules list
                if ($stmt = $link->prepare($sql_modules)) {
                    $stmt->bind_param("i", $professor_id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        $modules = $result->fetch_all(MYSQLI_ASSOC);
                    }
                    $stmt->close();
                }
            } else {
                $assignment_message = '<div class="alert alert-danger">Failed to assign module.</div>';
            }
            $stmt->close();
        }
    }
}

// Function to get students enrolled in a module
function getStudents($module_id, $link) {
    $sql = "SELECT u.id AS student_id, u.name AS student_name, u.email, u.rfid_tag,
            (SELECT COUNT(*) FROM attendance WHERE student_id = u.id AND module_id = ? AND status = 'present') AS present_count,
            (SELECT COUNT(DISTINCT DATE(scan_time)) FROM teacher_scans WHERE module_id = ?) AS total_sessions
            FROM student_module sm
            JOIN users u ON sm.student_id = u.id
            WHERE sm.module_id = ? AND u.role = 'student'
            ORDER BY u.name";
    
    $students = [];
    if ($stmt = $link->prepare($sql)) {
        $stmt->bind_param("iii", $module_id, $module_id, $module_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $students = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
    return $students;
}

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

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
            background-color: #007bff;
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
            background-color: #007bff;
            color: white;
            font-weight: 600;
            padding: 15px;
        }
        .module-stats {
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            margin-bottom: 15px;
        }
        .stat-item {
            text-align: center;
        }
        .stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #343a40;
        }
        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .student-list {
            max-height: 300px;
            overflow-y: auto;
            padding: 0;
            margin: 0;
        }
        .student-item {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .student-item:last-child {
            border-bottom: none;
        }
        .student-name {
            font-weight: 500;
        }
        .student-email {
            color: #6c757d;
            font-size: 0.85rem;
        }
        .attendance-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
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
        .progress {
            height: 8px;
            margin-top: 5px;
        }
        .student-attendance {
            width: 120px;
            text-align: right;
        }
        .student-progress {
            width: 100%;
            max-width: 300px;
        }
        .debug-panel {
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #f8d7da;
            background-color: #fff3f5;
            border-radius: 5px;
            color: #721c24;
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
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
                            <a class="nav-link" href="professor_dashboard.php">
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
                            <h1 class="h2 mb-1">My Modules</h1>
                            <p class="mb-0">Manage your course modules and view student attendance records</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>

                <?php if($debug): ?>
                <div class="debug-panel">
                    <h5>Debug Information</h5>
                    <p><strong>Professor ID:</strong> <?php echo $professor_id; ?></p>
                    <p><strong>Professor Name:</strong> <?php echo $professor_name; ?></p>
                    <p><strong>Has Module Assignments:</strong> <?php echo $professor_module_check ? 'Yes' : 'No'; ?></p>
                    <p><strong>Number of Modules Found:</strong> <?php echo count($modules); ?></p>
                    <p><strong>SESSION Data:</strong> <pre><?php print_r($_SESSION); ?></pre></p>
                </div>
                <?php endif; ?>

                <?php echo $assignment_message; ?>
                
                <?php if (count($modules) > 0): ?>
                    <div class="row">
                        <?php foreach ($modules as $module): ?>
                            <div class="col-md-6">
                                <div class="card module-card">
                                    <div class="card-header">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0"><?php echo htmlspecialchars($module['module_name']); ?></h5>
                                            <span class="badge badge-light">
                                                <i class="fas fa-users mr-1"></i> <?php echo $module['student_count']; ?> students
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="module-stats">
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $module['student_count']; ?></div>
                                                <div class="stat-label">Students</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $module['session_count']; ?></div>
                                                <div class="stat-label">Sessions</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value">
                                                    <?php 
                                                    if($module['session_count'] > 0 && $module['student_count'] > 0) {
                                                        echo round(($module['session_count'] * 100) / 
                                                                  ($module['student_count'] * $module['session_count']), 1);
                                                    } else {
                                                        echo "0.0";
                                                    }
                                                    ?>%
                                                </div>
                                                <div class="stat-label">Avg. Attendance</div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between mb-3">
                                            <button class="btn btn-outline-primary" data-toggle="collapse" 
                                                   data-target="#studentList-<?php echo $module['module_id']; ?>">
                                                <i class="fas fa-users mr-1"></i> View Students
                                            </button>
                                            <a href="start_session.php?module_id=<?php echo $module['module_id']; ?>" 
                                               class="btn btn-success">
                                                <i class="fas fa-play-circle mr-1"></i> Start Session
                                            </a>
                                        </div>
                                        
                                        <div class="collapse" id="studentList-<?php echo $module['module_id']; ?>">
                                            <?php 
                                            $students = getStudents($module['module_id'], $link);
                                            if (count($students) > 0): 
                                            ?>
                                                <ul class="list-group student-list">
                                                    <?php foreach ($students as $student): 
                                                        $attendance_rate = $student['total_sessions'] > 0 ? 
                                                            round(($student['present_count'] / $student['total_sessions']) * 100) : 0;
                                                        $badge_color = $attendance_rate >= 75 ? 'success' : 
                                                                      ($attendance_rate >= 50 ? 'warning' : 'danger');
                                                    ?>
                                                        <li class="list-group-item student-item">
                                                            <div>
                                                                <a href="professor_student_attendance.php?student_id=<?php echo $student['student_id']; ?>&module_id=<?php echo $module['module_id']; ?>" class="student-name"><?php echo htmlspecialchars($student['student_name']); ?></a>
                                                                <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                                            </div>
                                                            <div class="student-attendance">
                                                                <span class="badge badge-<?php echo $badge_color; ?>">
                                                                    <?php echo $attendance_rate; ?>%
                                                                </span>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle mr-2"></i> No students enrolled in this module.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i> You don't have any modules assigned yet.
                    </div>
                    
                    <!-- Show module assignment form -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <i class="fas fa-plus-circle mr-2"></i> Assign Module to Yourself
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <div class="form-group">
                                    <label for="moduleSelect">Select Module:</label>
                                    <select class="form-control" id="moduleSelect" name="module_id" required>
                                        <option value="">-- Select Module --</option>
                                        <?php foreach($all_modules as $module): ?>
                                            <option value="<?php echo $module['id']; ?>"><?php echo htmlspecialchars($module['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="assign_module" class="btn btn-primary">Assign Module</button>
                            </form>
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