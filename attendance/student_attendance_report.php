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

// Calculate overall attendance statistics
$sql_overall = "SELECT 
                COUNT(DISTINCT ts.id) AS total_sessions,
                SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS attended_sessions
              FROM teacher_scans ts
              JOIN student_module sm ON ts.module_id = sm.module_id
              LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
              WHERE sm.student_id = ?";

$total_sessions = 0;
$attended_sessions = 0;

if ($stmt = $link->prepare($sql_overall)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $total_sessions = $row['total_sessions'] ?? 0;
        $attended_sessions = $row['attended_sessions'] ?? 0;
    }
    $stmt->close();
}

// Calculate attendance rate
$attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : 0;

// Fetch module-wise attendance
$sql_modules = "SELECT 
                m.id AS module_id, 
                m.name AS module_name,
                COUNT(DISTINCT ts.id) AS total_sessions,
                SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS attended_sessions
                FROM student_module sm
                JOIN modules m ON sm.module_id = m.id
                JOIN teacher_scans ts ON ts.module_id = m.id
                LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
                WHERE sm.student_id = ?
                GROUP BY m.id, m.name";

$modules_attendance = [];
if ($stmt = $link->prepare($sql_modules)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $modules_attendance = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch monthly attendance trends (last 6 months)
$sql_monthly = "SELECT 
                DATE_FORMAT(ts.scan_time, '%Y-%m') AS month,
                COUNT(DISTINCT ts.id) AS total_sessions,
                SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS attended_sessions
                FROM teacher_scans ts
                JOIN student_module sm ON ts.module_id = sm.module_id
                LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
                WHERE sm.student_id = ? AND ts.scan_time >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(ts.scan_time, '%Y-%m')
                ORDER BY month";

$monthly_trends = [];
if ($stmt = $link->prepare($sql_monthly)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $monthly_trends = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Fetch detailed attendance history
$sql_history = "SELECT 
               ts.scan_time,
               m.name AS module_name,
               CASE WHEN a.id IS NOT NULL THEN 'present' ELSE 'absent' END AS status
               FROM teacher_scans ts
               JOIN modules m ON ts.module_id = m.id
               JOIN student_module sm ON sm.module_id = m.id
               LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
               WHERE sm.student_id = ?
               ORDER BY ts.scan_time DESC
               LIMIT 50";

$attendance_history = [];
if ($stmt = $link->prepare($sql_history)) {
    $stmt->bind_param("i", $student_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $attendance_history = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

// Process for export if requested
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Get all attendance records for export
    $sql_export = "SELECT 
                  ts.scan_time,
                  m.name AS module_name,
                  CASE WHEN a.id IS NOT NULL THEN 'present' ELSE 'absent' END AS status
                  FROM teacher_scans ts
                  JOIN modules m ON ts.module_id = m.id
                  JOIN student_module sm ON sm.module_id = m.id
                  LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
                  WHERE sm.student_id = ?
                  ORDER BY ts.scan_time DESC";
    
    $export_data = [];
    if ($stmt = $link->prepare($sql_export)) {
        $stmt->bind_param("i", $student_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $export_data = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
    
    // Generate and download CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . $student_id . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Date', 'Module', 'Status'));
    
    foreach ($export_data as $row) {
        fputcsv($output, array(
            $row['scan_time'],
            $row['module_name'],
            $row['status']
        ));
    }
    
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
    <title>Attendance Report</title>
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
            background-color: #17a2b8;
            color: white;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .welcome-banner {
            background: linear-gradient(135deg, #17a2b8, #138496);
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
            background-color: #17a2b8;
            color: white;
            font-weight: 600;
            border-radius: 10px 10px 0 0;
        }
        .status-present {
            color: #28a745;
            font-weight: bold;
        }
        .status-absent {
            color: #dc3545;
            font-weight: bold;
        }
        .gauge-container {
            position: relative;
            height: 150px;
            margin: 0 auto;
            width: 200px;
        }
        .gauge-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 15px solid #e9ecef;
            position: absolute;
            top: 0;
            left: 25px;
        }
        .gauge-fill {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            clip: rect(0px, 150px, 150px, 75px);
            position: absolute;
            top: 0;
            left: 25px;
        }
        .gauge-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            font-weight: bold;
        }
        .progress {
            height: 10px;
            margin-top: 5px;
            margin-bottom: 10px;
        }
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        .module-row {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .module-row:last-child {
            border-bottom: none;
        }
        .attendance-filters {
            margin-bottom: 20px;
        }
        .custom-select-sm {
            border-radius: 20px;
        }
        .export-btn {
            border-radius: 20px;
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
                        <i class="fas fa-chart-bar"></i> Attendance Report
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
                            <a class="nav-link" href="student_modules.php">
                                <i class="fas fa-book"></i>
                                My Modules
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">Attendance Report</h1>
                            <p class="mb-0">View and analyze your attendance statistics</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><i class="fas fa-analytics mr-2"></i> Summary Statistics</span>
                                    <a href="?export=csv" class="btn btn-sm btn-light export-btn">
                                        <i class="fas fa-download mr-1"></i> Export as CSV
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 text-center">
                                        <div class="gauge-container">
                                            <div class="gauge-circle"></div>
                                            <div class="gauge-value"><?php echo $attendance_rate; ?>%</div>
                                        </div>
                                        <h5 class="mt-3">Overall Attendance Rate</h5>
                                        <p class="text-muted"><?php echo $attended_sessions; ?> out of <?php echo $total_sessions; ?> sessions</p>
                                    </div>
                                    <div class="col-md-8">
                                        <h5>Attendance Status</h5>
                                        <div class="chart-container">
                                            <canvas id="attendanceChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-book mr-2"></i> Module Attendance
                            </div>
                            <div class="card-body">
                                <?php if(count($modules_attendance) > 0): ?>
                                    <?php foreach($modules_attendance as $module): 
                                        $module_rate = $module['total_sessions'] > 0 ? 
                                            round(($module['attended_sessions'] / $module['total_sessions']) * 100) : 0;
                                        $progress_color = $module_rate >= 75 ? 'success' : 
                                            ($module_rate >= 50 ? 'warning' : 'danger');
                                    ?>
                                        <div class="module-row">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6><?php echo htmlspecialchars($module['module_name']); ?></h6>
                                                <span class="badge badge-<?php echo $progress_color; ?>"><?php echo $module_rate; ?>%</span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted">
                                                    <?php echo $module['attended_sessions']; ?> / <?php echo $module['total_sessions']; ?> sessions
                                                </small>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar bg-<?php echo $progress_color; ?>" role="progressbar" 
                                                     style="width: <?php echo $module_rate; ?>%" 
                                                     aria-valuenow="<?php echo $module_rate; ?>" aria-valuemin="0" aria-valuemax="100">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle mr-2"></i> No modules found or no attendance data available.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <i class="fas fa-chart-line mr-2"></i> Monthly Trends
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="monthlyTrendChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="fas fa-history mr-2"></i> Attendance History
                            </div>
                            <div class="card-body">
                                <div class="attendance-filters mb-3">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <select class="custom-select custom-select-sm" id="filterStatus">
                                                <option value="all">All Status</option>
                                                <option value="present">Present</option>
                                                <option value="absent">Absent</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <select class="custom-select custom-select-sm" id="filterModule">
                                                <option value="all">All Modules</option>
                                                <?php foreach($modules_attendance as $module): ?>
                                                    <option value="<?php echo htmlspecialchars($module['module_name']); ?>"><?php echo htmlspecialchars($module['module_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" placeholder="Search..." id="searchHistory">
                                                <div class="input-group-append">
                                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                    <table class="table table-hover table-sm" id="historyTable">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Module</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(count($attendance_history) > 0): ?>
                                                <?php foreach($attendance_history as $record): ?>
                                                    <tr class="attendance-row" 
                                                        data-status="<?php echo $record['status']; ?>"
                                                        data-module="<?php echo htmlspecialchars($record['module_name']); ?>">
                                                        <td><?php echo date('Y-m-d', strtotime($record['scan_time'])); ?></td>
                                                        <td><?php echo date('H:i', strtotime($record['scan_time'])); ?></td>
                                                        <td><?php echo htmlspecialchars($record['module_name']); ?></td>
                                                        <td class="status-<?php echo $record['status']; ?>">
                                                            <?php 
                                                            if($record['status'] == 'present') {
                                                                echo '<i class="fas fa-check-circle mr-1"></i>';
                                                            } else {
                                                                echo '<i class="fas fa-times-circle mr-1"></i>';
                                                            }
                                                            echo ucfirst($record['status']);
                                                            ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No attendance history found.</td>
                                                </tr>
                                            <?php endif; ?>
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

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // Attendance status pie chart
        const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
        const attendanceChart = new Chart(attendanceCtx, {
            type: 'pie',
            data: {
                labels: ['Present', 'Absent'],
                datasets: [{
                    data: [<?php echo $attended_sessions; ?>, <?php echo $total_sessions - $attended_sessions; ?>],
                    backgroundColor: [
                        '#28a745',
                        '#dc3545'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: {
                    position: 'right'
                }
            }
        });
        
        // Monthly trends chart
        const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        const monthlyChart = new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: [
                    <?php foreach($monthly_trends as $month): ?>
                        '<?php echo date("M Y", strtotime($month['month'] . "-01")); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Attendance Rate (%)',
                    data: [
                        <?php foreach($monthly_trends as $month): 
                            $rate = $month['total_sessions'] > 0 ? 
                                round(($month['attended_sessions'] / $month['total_sessions']) * 100) : 0;
                            echo $rate . ',';
                        endforeach; ?>
                    ],
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#17a2b8',
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true,
                            max: 100
                        }
                    }]
                }
            }
        });
        
        // Filter and search functionality
        $(document).ready(function() {
            $("#filterStatus, #filterModule").change(filterTable);
            $("#searchHistory").keyup(filterTable);
            
            function filterTable() {
                const statusFilter = $("#filterStatus").val();
                const moduleFilter = $("#filterModule").val();
                const searchText = $("#searchHistory").val().toLowerCase();
                
                $(".attendance-row").each(function() {
                    const status = $(this).data("status");
                    const module = $(this).data("module");
                    const rowText = $(this).text().toLowerCase();
                    
                    const statusMatch = statusFilter === "all" || status === statusFilter;
                    const moduleMatch = moduleFilter === "all" || module === moduleFilter;
                    const textMatch = rowText.includes(searchText);
                    
                    if (statusMatch && moduleMatch && textMatch) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            }
        });
    </script>
</body>
</html>