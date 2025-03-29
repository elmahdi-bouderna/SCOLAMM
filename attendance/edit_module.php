<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "admin") {
    header("location: login.html");
    exit;
}
require_once "config.php";

// Fetch admin's info
$admin_id = $_SESSION["id"];
$admin_name = $_SESSION["name"];

// Check if module ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("location: admin_modules.php");
    exit;
}

$module_id = intval($_GET['id']);
$message = '';
$messageType = '';

// Check for column existence
$has_code = $link->query("SHOW COLUMNS FROM modules LIKE 'code'")->num_rows > 0;
$has_description = $link->query("SHOW COLUMNS FROM modules LIKE 'description'")->num_rows > 0;
$has_semester = $link->query("SHOW COLUMNS FROM modules LIKE 'semester'")->num_rows > 0;

// Fetch module data
$sql = "SELECT * FROM modules WHERE id = ?";
$module = null;

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $module_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $module = $result->fetch_assoc();
        } else {
            // No module found with the given ID
            header("location: admin_modules.php");
            exit;
        }
    }
    
    $stmt->close();
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate and sanitize input
    $name = trim($_POST["name"]);
    $code = $has_code ? trim($_POST["code"]) : "";
    $description = $has_description ? trim($_POST["description"]) : "";
    $semester = $has_semester ? trim($_POST["semester"]) : "";
    
    // Check if name is not empty
    if (empty($name)) {
        $message = "Please enter a module name.";
        $messageType = "danger";
    } else {
        // Check if another module with the same code exists (except this one)
        if ($has_code && !empty($code)) {
            $check_sql = "SELECT id FROM modules WHERE code = ? AND id != ?";
            if ($check_stmt = $link->prepare($check_sql)) {
                $check_stmt->bind_param("si", $code, $module_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $message = "A module with this code already exists.";
                    $messageType = "danger";
                    $check_stmt->close();
                    goto end_processing; // Skip the update process
                }
                
                $check_stmt->close();
            }
        }
        
        // Prepare dynamic update SQL
        $updates = ["name = ?"];
        $params = [$name];
        $types = "s";
        
        if ($has_code) {
            $updates[] = "code = ?";
            $params[] = $code;
            $types .= "s";
        }
        
        if ($has_description) {
            $updates[] = "description = ?";
            $params[] = $description;
            $types .= "s";
        }
        
        if ($has_semester) {
            $updates[] = "semester = ?";
            $params[] = $semester;
            $types .= "s";
        }
        
        // Add module id as the last parameter
        $params[] = $module_id;
        $types .= "i";
        
        $update_sql = "UPDATE modules SET " . implode(", ", $updates) . " WHERE id = ?";
        
        if ($stmt = $link->prepare($update_sql)) {
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $message = "Module updated successfully.";
                $messageType = "success";
                
                // Refresh module data
                if ($refresh_stmt = $link->prepare($sql)) {
                    $refresh_stmt->bind_param("i", $module_id);
                    
                    if ($refresh_stmt->execute()) {
                        $result = $refresh_stmt->get_result();
                        
                        if ($result->num_rows == 1) {
                            $module = $result->fetch_assoc();
                        }
                    }
                    
                    $refresh_stmt->close();
                }
            } else {
                $message = "Error updating module: " . $stmt->error;
                $messageType = "danger";
            }
            
            $stmt->close();
        }
    }
    
    end_processing:
}

// Fetch enrolled students
$students = [];
$sql_students = "SELECT u.id, u.name, u.email
                FROM users u
                JOIN student_module sm ON u.id = sm.student_id
                WHERE sm.module_id = ? AND u.role = 'student'
                ORDER BY u.name";

if ($stmt = $link->prepare($sql_students)) {
    $stmt->bind_param("i", $module_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
    }
    
    $stmt->close();
}

// Fetch assigned professors
$professors = [];
$sql_professors = "SELECT u.id, u.name, u.email
                   FROM users u
                   JOIN professor_module pm ON u.id = pm.professor_id
                   WHERE pm.module_id = ? AND u.role = 'professor'
                   ORDER BY u.name";

if ($stmt = $link->prepare($sql_professors)) {
    $stmt->bind_param("i", $module_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $professors[] = $row;
        }
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
    <title>Edit Module</title>
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
            background-color: #212529;
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            margin-bottom: 5px;
        }
        .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
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
            font-weight: 600;
            padding: 15px;
        }
        .module-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-semester-1 {
            background-color: #cff4fc;
            color: #055160;
        }
        .badge-semester-2 {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .badge-semester-3 {
            background-color: #f8d7da;
            color: #842029;
        }
        .module-icon {
            width: 80px;
            height: 80px;
            border-radius: 10px;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            color: #495057;
            margin: 0 auto 20px;
        }
        .user-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }
        .user-item:last-child {
            border-bottom: none;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: #495057;
            margin-right: 15px;
        }
        .nav-pills .nav-link.active {
            background-color: #007bff;
        }
        .user-list {
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <a class="navbar-brand" href="#">
            <i class="fas fa-user-shield mr-2"></i>
            IoT Attendance System | Admin
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="admin_dashboard.php">
                        <i class="fas fa-home"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_users.php">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="admin_modules.php">
                        <i class="fas fa-book"></i> Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_settings.php">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
            <span class="navbar-text ml-auto">
                <i class="fas fa-user-circle mr-1"></i> Welcome, <?php echo htmlspecialchars($admin_name); ?>
            </span>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <nav id="sidebar" class="col-md-2 d-none d-md-block sidebar">
                <div class="sidebar-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="admin_dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_users.php">
                                <i class="fas fa-users"></i>
                                User Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="admin_modules.php">
                                <i class="fas fa-book"></i>
                                Module Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_reports.php">
                                <i class="fas fa-chart-bar"></i>
                                Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_logs.php">
                                <i class="fas fa-history"></i>
                                System Logs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_settings.php">
                                <i class="fas fa-cog"></i>
                                System Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_backup.php">
                                <i class="fas fa-database"></i>
                                Backup & Restore
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
                            <h1 class="h2 mb-1">Edit Module</h1>
                            <p class="mb-0">Modify module information, enrollments and assignments</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <div class="module-icon">
                                    <i class="fas fa-book"></i>
                                </div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($module['name']); ?></h5>
                                <?php if($has_code && !empty($module['code'])): ?>
                                <div class="text-muted mb-2"><?php echo htmlspecialchars($module['code']); ?></div>
                                <?php endif; ?>
                                
                                <?php if($has_semester && !empty($module['semester'])): ?>
                                <div class="module-badge badge-semester-<?php echo $module['semester']; ?>">
                                    Semester <?php echo htmlspecialchars($module['semester']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <hr>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <div>
                                        <div class="font-weight-bold mb-1">Students</div>
                                        <h4><?php echo count($students); ?></h4>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold mb-1">Professors</div>
                                        <h4><?php echo count($professors); ?></h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3">
                            <a href="admin_modules.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Modules
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-md-9">
                        <div class="card">
                            <div class="card-header bg-white">
                                <ul class="nav nav-pills" id="moduleTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="general-tab" data-toggle="pill" href="#general" role="tab" aria-controls="general" aria-selected="true">
                                            <i class="fas fa-info-circle mr-1"></i> General
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="students-tab" data-toggle="pill" href="#students" role="tab" aria-controls="students" aria-selected="false">
                                            <i class="fas fa-user-graduate mr-1"></i> Students
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="professors-tab" data-toggle="pill" href="#professors" role="tab" aria-controls="professors" aria-selected="false">
                                            <i class="fas fa-chalkboard-teacher mr-1"></i> Professors
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="moduleTabsContent">
                                    <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                                        <form method="post" action="">
                                            <div class="form-group row">
                                                <label for="name" class="col-md-3 col-form-label">Module Name</label>
                                                <div class="col-md-9">
                                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($module['name']); ?>" required>
                                                </div>
                                            </div>
                                            
                                            <?php if($has_code): ?>
                                            <div class="form-group row">
                                                <label for="code" class="col-md-3 col-form-label">Module Code</label>
                                                <div class="col-md-9">
                                                    <input type="text" class="form-control" id="code" name="code" value="<?php echo isset($module['code']) ? htmlspecialchars($module['code']) : ''; ?>">
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($has_description): ?>
                                            <div class="form-group row">
                                                <label for="description" class="col-md-3 col-form-label">Description</label>
                                                <div class="col-md-9">
                                                    <textarea class="form-control" id="description" name="description" rows="4"><?php echo isset($module['description']) ? htmlspecialchars($module['description']) : ''; ?></textarea>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if($has_semester): ?>
                                            <div class="form-group row">
                                                <label for="semester" class="col-md-3 col-form-label">Semester</label>
                                                <div class="col-md-9">
                                                    <select class="form-control" id="semester" name="semester">
                                                        <option value="">Select Semester</option>
                                                        <?php for($i = 1; $i <= 6; $i++): ?>
                                                        <option value="<?php echo $i; ?>" <?php echo (isset($module['semester']) && $module['semester'] == $i) ? 'selected' : ''; ?>>
                                                            Semester <?php echo $i; ?>
                                                        </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="text-right">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save mr-1"></i> Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <div class="tab-pane fade" id="students" role="tabpanel" aria-labelledby="students-tab">
                                        <div class="mb-3">
                                            <h5 class="mb-3">Enrolled Students</h5>
                                            <div class="d-flex justify-content-between mb-3">
                                                <div class="input-group" style="width: 250px;">
                                                    <input type="text" class="form-control" id="studentSearch" placeholder="Search students...">
                                                    <div class="input-group-append">
                                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                    </div>
                                                </div>
                                                <a href="manage_module_students.php?id=<?php echo $module_id; ?>" class="btn btn-primary">
                                                    <i class="fas fa-user-plus mr-1"></i> Manage Students
                                                </a>
                                            </div>
                                            
                                            <div class="card">
                                                <div class="user-list">
                                                    <?php if (count($students) > 0): ?>
                                                        <?php foreach ($students as $student): ?>
                                                            <div class="user-item">
                                                                <div class="user-avatar">
                                                                    <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                                                </div>
                                                                <div>
                                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($student['name']); ?></div>
                                                                    <div class="text-muted small"><?php echo htmlspecialchars($student['email']); ?></div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="alert alert-info m-3">
                                                            <i class="fas fa-info-circle mr-2"></i> No students enrolled in this module.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="tab-pane fade" id="professors" role="tabpanel" aria-labelledby="professors-tab">
                                        <div class="mb-3">
                                            <h5 class="mb-3">Assigned Professors</h5>
                                            <div class="d-flex justify-content-between mb-3">
                                                <div class="input-group" style="width: 250px;">
                                                    <input type="text" class="form-control" id="professorSearch" placeholder="Search professors...">
                                                    <div class="input-group-append">
                                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                    </div>
                                                </div>
                                                <a href="manage_module_professors.php?id=<?php echo $module_id; ?>" class="btn btn-primary">
                                                    <i class="fas fa-user-plus mr-1"></i> Manage Professors
                                                </a>
                                            </div>
                                            
                                            <div class="card">
                                                <div class="user-list">
                                                    <?php if (count($professors) > 0): ?>
                                                        <?php foreach ($professors as $professor): ?>
                                                            <div class="user-item">
                                                                <div class="user-avatar">
                                                                    <?php echo strtoupper(substr($professor['name'], 0, 1)); ?>
                                                                </div>
                                                                <div>
                                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($professor['name']); ?></div>
                                                                    <div class="text-muted small"><?php echo htmlspecialchars($professor['email']); ?></div>
                                                                </div>
                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="alert alert-info m-3">
                                                            <i class="fas fa-info-circle mr-2"></i> No professors assigned to this module.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
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
        // Student search functionality
        $(document).ready(function() {
            $("#studentSearch").on("keyup", function() {
                var value = $(this).val().toLowerCase();
                $("#students .user-item").filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                });
            });
            
            // Professor search functionality
            $("#professorSearch").on("keyup", function() {
                var value = $(this).val().toLowerCase();
                $("#professors .user-item").filter(function() {
                    $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                });
            });
        });
    </script>
</body>
</html>