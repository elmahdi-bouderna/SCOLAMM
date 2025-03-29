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

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("location: admin_users.php");
    exit;
}

$user_id = intval($_GET['id']);
$message = '';
$messageType = '';

// Fetch user data
$sql = "SELECT * FROM users WHERE id = ?";
$user = null;

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
        } else {
            // No user found with the given ID
            header("location: admin_users.php");
            exit;
        }
    }
    
    $stmt->close();
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate and sanitize input
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $role = trim($_POST["role"]);
    $rfid_tag = trim($_POST["rfid_tag"]);
    $active = isset($_POST["active"]) ? 1 : 0;
    
    // Check if name is not empty
    if (empty($name)) {
        $message = "Please enter a name.";
        $messageType = "danger";
    } else {
        // Prepare update SQL
        $update_sql = "UPDATE users SET name = ?, email = ?, role = ?, rfid_tag = ?, active = ? WHERE id = ?";
        $params = array($name, $email, $role, $rfid_tag, $active, $user_id);
        $types = "ssssii";
        
        // Check if password should be updated
        if (!empty($_POST["password"])) {
            $password = password_hash($_POST["password"], PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET name = ?, email = ?, role = ?, rfid_tag = ?, active = ?, password = ? WHERE id = ?";
            $params[] = $password;
            $params[] = $user_id;
            $types = "ssssiis";
            array_pop($params);  // Remove the last user_id we added (to fix the order)
        }
        
        if ($stmt = $link->prepare($update_sql)) {
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $message = "User updated successfully.";
                $messageType = "success";
                
                // Close this statement before creating a new one
                $stmt->close();
                
                // Refresh user data - use a NEW variable name for this statement
                if ($refresh_stmt = $link->prepare($sql)) {
                    $refresh_stmt->bind_param("i", $user_id);
                    
                    if ($refresh_stmt->execute()) {
                        $result = $refresh_stmt->get_result();
                        
                        if ($result->num_rows == 1) {
                            $user = $result->fetch_assoc();
                        }
                    }
                    
                    $refresh_stmt->close(); // Close the new statement
                }
            } else {
                $message = "Error updating user: " . $stmt->error;
                $messageType = "danger";
                $stmt->close(); // Close statement in error case
            }
        }
    }
}

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

// Get module enrollments for students
$modules = [];
if ($user && $user['role'] == 'student') {
    $sql_modules = "SELECT m.id, m.name, 
                  CASE WHEN sm.student_id IS NOT NULL THEN 1 ELSE 0 END AS enrolled
                  FROM modules m
                  LEFT JOIN student_module sm ON m.id = sm.module_id AND sm.student_id = ?
                  ORDER BY m.name";
    
    if ($stmt = $link->prepare($sql_modules)) {
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $modules[] = $row;
            }
        }
        
        $stmt->close();
    }
}

// Get module assignments for professors
$prof_modules = [];
if ($user && $user['role'] == 'professor') {
    $sql_modules = "SELECT m.id, m.name, 
                  CASE WHEN pm.professor_id IS NOT NULL THEN 1 ELSE 0 END AS assigned
                  FROM modules m
                  LEFT JOIN professor_module pm ON m.id = pm.module_id AND pm.professor_id = ?
                  ORDER BY m.name";
    
    if ($stmt = $link->prepare($sql_modules)) {
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $prof_modules[] = $row;
            }
        }
        
        $stmt->close();
    }
}

// Process module enrollments
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_modules'])) {
    if ($user['role'] == 'student') {
        // First, remove all current enrollments
        $delete_sql = "DELETE FROM student_module WHERE student_id = ?";
        if ($stmt = $link->prepare($delete_sql)) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Then, add selected enrollments
        if (isset($_POST['modules']) && is_array($_POST['modules'])) {
            $insert_sql = "INSERT INTO student_module (student_id, module_id) VALUES (?, ?)";
            if ($stmt = $link->prepare($insert_sql)) {
                foreach ($_POST['modules'] as $module_id) {
                    $stmt->bind_param("ii", $user_id, $module_id);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }
        
        $message = "Module enrollments updated successfully.";
        $messageType = "success";
        
        // Refresh modules data
        $modules = [];
        if ($stmt = $link->prepare($sql_modules)) {
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $modules[] = $row;
                }
            }
            
            $stmt->close();
        }
    } elseif ($user['role'] == 'professor') {
        // First, remove all current assignments
        $delete_sql = "DELETE FROM professor_module WHERE professor_id = ?";
        if ($stmt = $link->prepare($delete_sql)) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Then, add selected assignments
        if (isset($_POST['modules']) && is_array($_POST['modules'])) {
            $insert_sql = "INSERT INTO professor_module (professor_id, module_id) VALUES (?, ?)";
            if ($stmt = $link->prepare($insert_sql)) {
                foreach ($_POST['modules'] as $module_id) {
                    $stmt->bind_param("ii", $user_id, $module_id);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }
        
        $message = "Module assignments updated successfully.";
        $messageType = "success";
        
        // Refresh modules data
        $prof_modules = [];
        if ($stmt = $link->prepare($sql_modules)) {
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $prof_modules[] = $row;
                }
            }
            
            $stmt->close();
        }
    }
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
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
        .user-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: bold;
            color: #495057;
            margin: 0 auto 20px;
        }
        .role-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .role-student {
            background-color: #cff4fc;
            color: #055160;
        }
        .role-professor {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .role-admin {
            background-color: #f8d7da;
            color: #842029;
        }
        .nav-pills .nav-link.active {
            background-color: #007bff;
        }
        .module-list {
            max-height: 300px;
            overflow-y: auto;
        }
        .module-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .module-item:last-child {
            border-bottom: none;
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
                <li class="nav-item active">
                    <a class="nav-link" href="admin_users.php">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
                <li class="nav-item">
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
                            <a class="nav-link active" href="admin_users.php">
                                <i class="fas fa-users"></i>
                                User Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_modules.php">
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
                            <h1 class="h2 mb-1">Edit User</h1>
                            <p class="mb-0">Modify user information and settings</p>
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
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                </div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($user['name']); ?></h5>
                                <div class="role-badge role-<?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </div>
                                <div class="text-muted mb-3"><?php echo htmlspecialchars($user['email']); ?></div>
                                <div class="mb-3">
                                    <span class="badge badge-light">
                                        <i class="fas fa-id-card mr-1"></i> 
                                        <?php echo !empty($user['rfid_tag']) ? htmlspecialchars($user['rfid_tag']) : 'No RFID Tag'; ?>
                                    </span>
                                </div>
                                <div class="mb-3">
                                    <span class="badge badge-<?php echo $user['active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $user['active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <hr>
                                <div class="text-muted small">
                                    <div class="mb-1">
                                        <i class="fas fa-clock mr-1"></i> 
                                        Last Login: <?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?>
                                    </div>
                                    <div>
                                        <i class="fas fa-user-plus mr-1"></i> 
                                        Created: <?php echo date('Y-m-d', strtotime($user['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3">
                            <a href="admin_users.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Users
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-md-9">
                        <div class="card">
                            <div class="card-header bg-white">
                                <ul class="nav nav-pills" id="userTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="general-tab" data-toggle="pill" href="#general" role="tab" aria-controls="general" aria-selected="true">
                                            <i class="fas fa-user mr-1"></i> General
                                        </a>
                                    </li>
                                    <?php if ($user['role'] == 'student' || $user['role'] == 'professor'): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" id="modules-tab" data-toggle="pill" href="#modules" role="tab" aria-controls="modules" aria-selected="false">
                                            <i class="fas fa-book mr-1"></i> Modules
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a class="nav-link" id="password-tab" data-toggle="pill" href="#password" role="tab" aria-controls="password" aria-selected="false">
                                            <i class="fas fa-key mr-1"></i> Password
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="userTabsContent">
                                    <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                                        <form method="post" action="">
                                            <div class="form-group row">
                                                <label for="name" class="col-md-3 col-form-label">Full Name</label>
                                                <div class="col-md-9">
                                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="form-group row">
                                                <label for="email" class="col-md-3 col-form-label">Email Address</label>
                                                <div class="col-md-9">
                                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="form-group row">
                                                <label for="role" class="col-md-3 col-form-label">User Role</label>
                                                <div class="col-md-9">
                                                    <select class="form-control" id="role" name="role">
                                                        <option value="student" <?php echo $user['role'] == 'student' ? 'selected' : ''; ?>>Student</option>
                                                        <option value="professor" <?php echo $user['role'] == 'professor' ? 'selected' : ''; ?>>Professor</option>
                                                        <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group row">
                                                <label for="rfid_tag" class="col-md-3 col-form-label">RFID Tag</label>
                                                <div class="col-md-9">
                                                    <input type="text" class="form-control" id="rfid_tag" name="rfid_tag" value="<?php echo htmlspecialchars($user['rfid_tag']); ?>">
                                                    <small class="form-text text-muted">Leave blank if no RFID tag is assigned</small>
                                                </div>
                                            </div>
                                            <div class="form-group row">
                                                <div class="col-md-3">Account Status</div>
                                                <div class="col-md-9">
                                                <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input" id="active" name="active" <?php echo (isset($user['active']) && $user['active']) ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="active">Active</label>
                                            </div>
                                                    <small class="form-text text-muted">Inactive users cannot log in to the system</small>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save mr-1"></i> Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <?php if ($user['role'] == 'student'): ?>
                                    <div class="tab-pane fade" id="modules" role="tabpanel" aria-labelledby="modules-tab">
                                        <form method="post" action="">
                                            <input type="hidden" name="update_modules" value="1">
                                            <div class="mb-3">
                                                <h5 class="mb-3">Module Enrollments</h5>
                                                
                                                <div class="mb-3">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <input type="text" class="form-control" id="moduleSearch" placeholder="Search modules...">
                                                        </div>
                                                        <div class="col-auto">
                                                            <div class="custom-control custom-switch">
                                                                <input type="checkbox" class="custom-control-input" id="toggleAllModules">
                                                                <label class="custom-control-label" for="toggleAllModules">Select All</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="module-list">
                                                    <?php if (count($modules) > 0): ?>
                                                        <?php foreach ($modules as $module): ?>
                                                            <div class="module-item">
                                                                <div class="custom-control custom-checkbox">
                                                                    <input type="checkbox" class="custom-control-input module-checkbox" 
                                                                        id="module-<?php echo $module['id']; ?>" 
                                                                        name="modules[]" 
                                                                        value="<?php echo $module['id']; ?>"
                                                                        <?php echo $module['enrolled'] ? 'checked' : ''; ?>>
                                                                    <label class="custom-control-label" for="module-<?php echo $module['id']; ?>">
                                                                        <?php echo htmlspecialchars($module['name']); ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="alert alert-info">
                                                            <i class="fas fa-info-circle mr-2"></i> No modules available.
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save mr-1"></i> Update Enrollments
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    <?php elseif ($user['role'] == 'professor'): ?>
                                    <div class="tab-pane fade" id="modules" role="tabpanel" aria-labelledby="modules-tab">
                                        <form method="post" action="">
                                            <input type="hidden" name="update_modules" value="1">
                                            <div class="mb-3">
                                                <h5 class="mb-3">Module Assignments</h5>
                                                
                                                <div class="mb-3">
                                                    <div class="row align-items-center">
                                                        <div class="col">
                                                            <input type="text" class="form-control" id="moduleSearch" placeholder="Search modules...">
                                                        </div>
                                                        <div class="col-auto">
                                                            <div class="custom-control custom-switch">
                                                                <input type="checkbox" class="custom-control-input" id="toggleAllModules">
                                                                <label class="custom-control-label" for="toggleAllModules">Select All</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="module-list">
                                                    <?php if (count($prof_modules) > 0): ?>
                                                        <?php foreach ($prof_modules as $module): ?>
                                                            <div class="module-item">
                                                                <div class="custom-control custom-checkbox">
                                                                    <input type="checkbox" class="custom-control-input module-checkbox" 
                                                                        id="module-<?php echo $module['id']; ?>" 
                                                                        name="modules[]" 
                                                                        value="<?php echo $module['id']; ?>"
                                                                        <?php echo $module['assigned'] ? 'checked' : ''; ?>>
                                                                    <label class="custom-control-label" for="module-<?php echo $module['id']; ?>">
                                                                        <?php echo htmlspecialchars($module['name']); ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="alert alert-info">
                                                            <i class="fas fa-info-circle mr-2"></i> No modules available.
                                                        </div>
                                      
                                                                                                        <?php endif; ?>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                    <div class="text-right">
                                                                                                        <button type="submit" class="btn btn-primary">
                                                                                                            <i class="fas fa-save mr-1"></i> Update Assignments
                                                                                                        </button>
                                                                                                    </div>
                                                                                                </form>
                                                                                            </div>
                                                                                            <?php endif; ?>
                                                                                            
                                                                                            <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                                                                                                <form method="post" action="" id="passwordForm">
                                                                                                    <div class="form-group row">
                                                                                                        <label for="password" class="col-md-3 col-form-label">New Password</label>
                                                                                                        <div class="col-md-9">
                                                                                                            <input type="password" class="form-control" id="password" name="password">
                                                                                                            <small class="form-text text-muted">Leave blank to keep current password</small>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                    <div class="form-group row">
                                                                                                        <label for="confirm_password" class="col-md-3 col-form-label">Confirm Password</label>
                                                                                                        <div class="col-md-9">
                                                                                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                                                                                        </div>
                                                                                                    </div>
                                                                                                    <div class="form-group row">
                                                                                                        <div class="col-md-9 offset-md-3">
                                                                                                            <div class="alert alert-info">
                                                                                                                <i class="fas fa-info-circle mr-2"></i> Password should be at least 8 characters.
                                                                                                            </div>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                    <div class="text-right">
                                                                                                        <button type="button" onclick="validatePassword()" class="btn btn-primary">
                                                                                                            <i class="fas fa-key mr-1"></i> Change Password
                                                                                                        </button>
                                                                                                    </div>
                                                                                                </form>
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
                                                                // Module search functionality
                                                                $(document).ready(function() {
                                                                    $("#moduleSearch").on("keyup", function() {
                                                                        var value = $(this).val().toLowerCase();
                                                                        $(".module-item").filter(function() {
                                                                            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
                                                                        });
                                                                    });
                                                        
                                                                    // Toggle all modules
                                                                    $("#toggleAllModules").change(function() {
                                                                        $(".module-checkbox").prop('checked', $(this).prop('checked'));
                                                                    });
                                                        
                                                                    // Update toggle all checkbox state based on module checkboxes
                                                                    $(".module-checkbox").change(function() {
                                                                        if ($(".module-checkbox:checked").length === $(".module-checkbox").length) {
                                                                            $("#toggleAllModules").prop('checked', true);
                                                                        } else {
                                                                            $("#toggleAllModules").prop('checked', false);
                                                                        }
                                                                    });
                                                                });
                                                        
                                                                // Password validation
                                                                function validatePassword() {
                                                                    const password = document.getElementById('password').value;
                                                                    const confirmPassword = document.getElementById('confirm_password').value;
                                                        
                                                                    if (password !== '') {
                                                                        if (password.length < 8) {
                                                                            alert('Password should be at least 8 characters long.');
                                                                            return;
                                                                        }
                                                        
                                                                        if (password !== confirmPassword) {
                                                                            alert('Passwords do not match.');
                                                                            return;
                                                                        }
                                                                    }
                                                        
                                                                    document.getElementById('passwordForm').submit();
                                                                }
                                                            </script>
                                                        </body>
                                                        </html>