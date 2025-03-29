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

// Fetch professor details
$professor_data = [];
$sql = "SELECT name, email, rfid_tag, field, created_at, last_login FROM users WHERE id = ?";
if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $professor_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $professor_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Fetch system settings
$settings = [];
$sql_settings = "SELECT setting_key, setting_value, setting_group, display_name, input_type, input_options 
                FROM settings 
                WHERE setting_group IN ('general', 'attendance', 'email')
                ORDER BY setting_group, display_name";
if ($stmt = $link->prepare($sql_settings)) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_group']][] = $row;
        }
    }
    $stmt->close();
}

// Handle profile update
$update_success = false;
$update_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_profile"])) {
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $field = trim($_POST["field"]);
    
    // Validate inputs
    if (empty($name)) {
        $update_error = "Please enter your name.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $update_error = "Please enter a valid email address.";
    } else {
        // Check if email already exists for another user
        $sql_check = "SELECT id FROM users WHERE email = ? AND id != ?";
        if ($stmt = $link->prepare($sql_check)) {
            $stmt->bind_param("si", $email, $professor_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $update_error = "This email is already taken.";
            } else {
                // Update profile
                $sql_update = "UPDATE users SET name = ?, email = ?, field = ? WHERE id = ?";
                if ($stmt_update = $link->prepare($sql_update)) {
                    $stmt_update->bind_param("sssi", $name, $email, $field, $professor_id);
                    if ($stmt_update->execute()) {
                        $update_success = true;
                        $_SESSION["name"] = $name;
                        
                        // Update local data
                        $professor_data["name"] = $name;
                        $professor_data["email"] = $email;
                        $professor_data["field"] = $field;
                        
                        // Log the action
                        $action = "Profile Update";
                        $description = "Professor updated their profile information";
                        $ip = $_SERVER['REMOTE_ADDR'];
                        $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                                    VALUES (NOW(), ?, ?, ?, ?, ?, 'info', 'account')";
                        if ($log_stmt = $link->prepare($log_sql)) {
                            $log_stmt->bind_param("issss", $professor_id, $professor_name, $action, $description, $ip);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                    } else {
                        $update_error = "Something went wrong. Please try again later.";
                    }
                    $stmt_update->close();
                }
            }
            $stmt->close();
        }
    }
}

// Handle password update
$password_success = false;
$password_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_password"])) {
    $current_password = $_POST["current_password"];
    $new_password = $_POST["new_password"];
    $confirm_password = $_POST["confirm_password"];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $password_error = "Please fill in all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 8) {
        $password_error = "Password must be at least 8 characters long.";
    } else {
        // Verify current password
        $sql = "SELECT password FROM users WHERE id = ?";
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param("i", $professor_id);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($hashed_password);
                    $stmt->fetch();
                    
                    if (password_verify($current_password, $hashed_password)) {
                        // Update password
                        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_sql = "UPDATE users SET password = ? WHERE id = ?";
                        if ($update_stmt = $link->prepare($update_sql)) {
                            $update_stmt->bind_param("si", $new_hashed_password, $professor_id);
                            if ($update_stmt->execute()) {
                                $password_success = true;
                                
                                // Log the action
                                $action = "Password Change";
                                $description = "Professor changed their account password";
                                $ip = $_SERVER['REMOTE_ADDR'];
                                $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                                            VALUES (NOW(), ?, ?, ?, ?, ?, 'info', 'security')";
                                if ($log_stmt = $link->prepare($log_sql)) {
                                    $log_stmt->bind_param("issss", $professor_id, $professor_name, $action, $description, $ip);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                }
                            } else {
                                $password_error = "Something went wrong. Please try again later.";
                            }
                            $update_stmt->close();
                        }
                    } else {
                        $password_error = "Current password is incorrect.";
                    }
                }
            }
            $stmt->close();
        }
    }
}

// Get current date and time
$currentDateTime = '2025-03-17 17:39:34';

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Professor Dashboard</title>
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
        .settings-nav .nav-link {
            padding: 1rem;
            color: #495057;
            border-radius: 0;
            border-left: 3px solid transparent;
        }
        .settings-nav .nav-link.active {
            background-color: #f8f9fa;
            border-left-color: #007bff;
            color: #007bff;
            font-weight: 600;
        }
        .settings-nav .nav-link:hover:not(.active) {
            background-color: #f1f1f1;
            border-left-color: #6c757d;
        }
        .settings-nav .nav-link i {
            width: 25px;
            text-align: center;
            margin-right: 10px;
        }
        .settings-content {
            padding: 20px;
            background-color: #ffffff;
            border-radius: 0 0 10px 10px;
        }
        .form-group label {
            font-weight: 600;
            color: #495057;
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
        .settings-section {
            margin-bottom: 30px;
        }
        .settings-section-title {
            font-weight: 600;
            color: #343a40;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .list-group-item.active {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-outline-primary {
            color: #007bff;
            border-color: #007bff;
        }
        .btn-outline-primary:hover {
            background-color: #007bff;
            color: white;
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
                <li class="nav-item">
                    <a class="nav-link" href="professor_schedule.php">
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
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
                            <a class="nav-link" href="professor_schedule.php">
                                <i class="fas fa-calendar-alt"></i>
                                Schedule
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="professor_settings.php">
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
                                    <h1 class="profile-name">Account Settings</h1>
                                    <p class="profile-role">Manage your account preferences and settings</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body p-0">
                                <div class="row no-gutters">
                                    <div class="col-md-3 border-right">
                                        <div class="settings-nav list-group list-group-flush">
                                            <a class="list-group-item list-group-item-action active" id="profile-tab" data-toggle="tab" href="#profile" role="tab">
                                                <i class="fas fa-user-circle"></i> Edit Profile
                                            </a>
                                            <a class="list-group-item list-group-item-action" id="security-tab" data-toggle="tab" href="#security" role="tab">
                                                <i class="fas fa-lock"></i> Change Password
                                            </a>
                                            <a class="list-group-item list-group-item-action" id="notifications-tab" data-toggle="tab" href="#notifications" role="tab">
                                                <i class="fas fa-bell"></i> Notifications
                                            </a>
                                            <a class="list-group-item list-group-item-action" id="attendance-tab" data-toggle="tab" href="#attendance" role="tab">
                                                <i class="fas fa-clipboard-check"></i> Attendance Settings
                                            </a>
                                            <a class="list-group-item list-group-item-action" id="account-tab" data-toggle="tab" href="#account" role="tab">
                                                <i class="fas fa-info-circle"></i> Account Information
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-9">
                                        <div class="tab-content settings-content">
                                            <!-- Edit Profile Tab -->
                                            <div class="tab-pane fade show active" id="profile" role="tabpanel">
                                                <h4 class="settings-section-title">Profile Information</h4>
                                                
                                                <?php if ($update_success): ?>
                                                    <div class="alert alert-success" role="alert">
                                                        <i class="fas fa-check-circle mr-2"></i> Your profile has been updated successfully.
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($update_error): ?>
                                                    <div class="alert alert-danger" role="alert">
                                                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $update_error; ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                                    <div class="form-group">
                                                        <label for="name">Full Name</label>
                                                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($professor_data['name'] ?? ''); ?>" required>
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label for="email">Email Address</label>
                                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($professor_data['email'] ?? ''); ?>" required>
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label for="field">Field/Department</label>
                                                        <input type="text" class="form-control" id="field" name="field" value="<?php echo htmlspecialchars($professor_data['field'] ?? ''); ?>">
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label for="rfidTag">RFID Tag</label>
                                                        <input type="text" class="form-control" id="rfidTag" value="<?php echo htmlspecialchars($professor_data['rfid_tag'] ?? ''); ?>" readonly>
                                                        <small class="form-text text-muted">Contact the administrator if you need to change your RFID tag.</small>
                                                    </div>
                                                    
                                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                                        <i class="fas fa-save mr-1"></i> Save Changes
                                                    </button>
                                                </form>
                                            </div>
                                            
                                            <!-- Change Password Tab -->
                                            <div class="tab-pane fade" id="security" role="tabpanel">
                                                <h4 class="settings-section-title">Change Password</h4>
                                                
                                                <?php if ($password_success): ?>
                                                    <div class="alert alert-success" role="alert">
                                                        <i class="fas fa-check-circle mr-2"></i> Your password has been changed successfully.
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($password_error): ?>
                                                    <div class="alert alert-danger" role="alert">
                                                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $password_error; ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                                    <div class="form-group">
                                                        <label for="currentPassword">Current Password</label>
                                                        <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label for="newPassword">New Password</label>
                                                        <input type="password" class="form-control" id="newPassword" name="new_password" required>
                                                        <small class="form-text text-muted">Password must be at least 8 characters long.</small>
                                                    </div>
                                                    
                                                    <div class="form-group">
                                                        <label for="confirmPassword">Confirm New Password</label>
                                                        <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                                                    </div>
                                                    
                                                    <button type="submit" name="update_password" class="btn btn-primary">
                                                        <i class="fas fa-key mr-1"></i> Change Password
                                                    </button>
                                                </form>
                                                
                                                <hr>
                                                <div class="mt-4">
                                                    <h5>Security Tips</h5>
                                                    <ul class="text-muted">
                                                        <li>Use a strong password with at least 8 characters</li>
                                                        <li>Include uppercase letters, lowercase letters, numbers, and symbols</li>
                                                        <li>Avoid using the same password across multiple sites</li>
                                                        <li>Change your password periodically for better security</li>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <!-- Notifications Tab -->
                                            <div class="tab-pane fade" id="notifications" role="tabpanel">
                                                <h4 class="settings-section-title">Notification Settings</h4>
                                                
                                                <form>
                                                    <div class="settings-section">
                                                        <h5 class="mb-3">Email Notifications</h5>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="notifyAttendance" checked>
                                                            <label class="custom-control-label" for="notifyAttendance">Attendance Updates</label>
                                                            <small class="form-text text-muted">Receive email notifications about attendance records.</small>
                                                        </div>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="notifySchedule" checked>
                                                            <label class="custom-control-label" for="notifySchedule">Schedule Changes</label>
                                                            <small class="form-text text-muted">Receive email notifications about schedule changes.</small>
                                                        </div>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="notifySystem">
                                                            <label class="custom-control-label" for="notifySystem">System Updates</label>
                                                            <small class="form-text text-muted">Receive email notifications about system updates and maintenance.</small>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="settings-section">
                                                        <h5 class="mb-3">App Notifications</h5>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="notifyAppAttendance" checked>
                                                            <label class="custom-control-label" for="notifyAppAttendance">Attendance Alerts</label>
                                                        </div>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="notifyAppSchedule" checked>
                                                            <label class="custom-control-label" for="notifyAppSchedule">Class Reminders</label>
                                                        </div>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fas fa-save mr-1"></i> Save Preferences
                                                    </button>
                                                </form>
                                            </div>
                                            
                                            <!-- Attendance Settings Tab -->
                                            <div class="tab-pane fade" id="attendance" role="tabpanel">
                                                <h4 class="settings-section-title">Attendance Configuration</h4>
                                                
                                                <form>
                                                    <div class="settings-section">
                                                        <div class="form-group">
                                                            <label for="lateThreshold">Late Arrival Threshold (minutes)</label>
                                                            <input type="number" class="form-control" id="lateThreshold" value="10" min="0" max="60">
                                                            <small class="form-text text-muted">Students arriving after this many minutes will be marked as late.</small>
                                                        </div>
                                                        
                                                        <div class="form-group">
                                                            <label for="absentThreshold">Absent Threshold (minutes)</label>
                                                            <input type="number" class="form-control" id="absentThreshold" value="30" min="0" max="120">
                                                            <small class="form-text text-muted">Students arriving after this many minutes will be marked as absent.</small>
                                                        </div>
                                                        
                                                        <div class="form-group">
                                                            <label for="verificationMethod">Attendance Verification Method</label>
                                                            <select class="form-control" id="verificationMethod">
                                                            <option value="qrcode" selected>QR Code</option>
                                                                <option value="nfc">NFC Tag</option>
                                                                <option value="beacon">Bluetooth Beacon</option>
                                                                <option value="facial">Facial Recognition</option>
                                                                <option value="manual">Manual Entry</option>
                                                            </select>
                                                            <small class="form-text text-muted">Method used to verify student attendance.</small>
                                                        </div>
                                                        
                                                        <div class="form-group">
                                                            <label for="gracePeriod">Grace Period (minutes)</label>
                                                            <input type="number" class="form-control" id="gracePeriod" value="15" min="0" max="60">
                                                            <small class="form-text text-muted">Time window for students to mark attendance after class starts.</small>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="settings-section">
                                                        <h5 class="mb-3">QR Code Settings</h5>
                                                        
                                                        <div class="form-group">
                                                            <label for="qrValidTime">QR Code Validity Period (minutes)</label>
                                                            <input type="number" class="form-control" id="qrValidTime" value="5" min="1" max="30">
                                                            <small class="form-text text-muted">How long each generated QR code remains valid.</small>
                                                        </div>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="qrAutoRefresh" checked>
                                                            <label class="custom-control-label" for="qrAutoRefresh">Auto-refresh QR Code</label>
                                                            <small class="form-text text-muted">Automatically generate new QR codes after expiration.</small>
                                                        </div>
                                                        
                                                        <div class="form-group custom-control custom-switch">
                                                            <input type="checkbox" class="custom-control-input" id="locationVerification">
                                                            <label class="custom-control-label" for="locationVerification">Enable Location Verification</label>
                                                            <small class="form-text text-muted">Verify that students are physically present in the classroom.</small>
                                                        </div>
                                                    </div>
                                                    
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fas fa-save mr-1"></i> Save Settings
                                                    </button>
                                                </form>
                                            </div>
                                            
                                            <!-- Account Information Tab -->
                                            <div class="tab-pane fade" id="account" role="tabpanel">
                                                <h4 class="settings-section-title">Account Information</h4>
                                                
                                                <div class="row mb-4">
                                                    <div class="col-md-6">
                                                        <div class="card bg-light">
                                                            <div class="card-body">
                                                                <h5 class="card-title">Account Details</h5>
                                                                <hr>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">Account Type:</strong>
                                                                    <span class="badge badge-primary">Professor</span>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">Account Created:</strong>
                                                                    <?php echo isset($professor_data['created_at']) ? date('F j, Y', strtotime($professor_data['created_at'])) : 'N/A'; ?>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">Last Login:</strong>
                                                                    <?php echo isset($professor_data['last_login']) ? date('F j, Y H:i', strtotime($professor_data['last_login'])) : 'N/A'; ?>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">Account Status:</strong>
                                                                    <span class="badge badge-success">Active</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="col-md-6">
                                                        <div class="card bg-light">
                                                            <div class="card-body">
                                                                <h5 class="card-title">System Information</h5>
                                                                <hr>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">System Name:</strong>
                                                                    <?php 
                                                                    $system_name = '';
                                                                    foreach($settings['general'] as $setting) {
                                                                        if($setting['setting_key'] == 'system_name') {
                                                                            $system_name = $setting['setting_value'];
                                                                            break;
                                                                        }
                                                                    }
                                                                    echo htmlspecialchars($system_name ?: 'Scolagile Attendance System'); 
                                                                    ?>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">Current Time (UTC):</strong>
                                                                    <?php echo htmlspecialchars($currentDateTime); ?>
                                                                </div>
                                                                
                                                                <div class="mb-3">
                                                                    <strong class="d-block">System Timezone:</strong>
                                                                    <?php 
                                                                    $timezone = '';
                                                                    foreach($settings['general'] as $setting) {
                                                                        if($setting['setting_key'] == 'timezone') {
                                                                            $timezone = $setting['setting_value'];
                                                                            break;
                                                                        }
                                                                    }
                                                                    echo htmlspecialchars($timezone ?: 'UTC'); 
                                                                    ?>
                                                                </div>
                                                                
                                                                <div>
                                                                    <strong class="d-block">Session Timeout:</strong>
                                                                    <?php 
                                                                    $session_timeout = '';
                                                                    foreach($settings['general'] as $setting) {
                                                                        if($setting['setting_key'] == 'session_timeout') {
                                                                            $session_timeout = $setting['setting_value'];
                                                                            break;
                                                                        }
                                                                    }
                                                                    echo htmlspecialchars($session_timeout ?: '120'); 
                                                                    ?> minutes
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="alert alert-info">
                                                    <i class="fas fa-info-circle mr-2"></i>
                                                    Need help or have questions about your account? Please contact the system administrator.
                                                </div>
                                                
                                                <div class="text-right">
                                                    <button type="button" class="btn btn-outline-secondary" data-toggle="modal" data-target="#exportDataModal">
                                                        <i class="fas fa-download mr-1"></i> Export My Data
                                                    </button>
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
    
    <!-- Export Data Modal -->
    <div class="modal fade" id="exportDataModal" tabindex="-1" role="dialog" aria-labelledby="exportDataModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="exportDataModalLabel">Export Your Data</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Select the data you would like to export:</p>
                    
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="exportProfile" checked>
                            <label class="custom-control-label" for="exportProfile">Profile Information</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="exportModules" checked>
                            <label class="custom-control-label" for="exportModules">Modules & Teaching Schedule</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="exportAttendance" checked>
                            <label class="custom-control-label" for="exportAttendance">Attendance Records</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="exportFormat">Export Format</label>
                        <select class="form-control" id="exportFormat">
                            <option value="csv">CSV</option>
                            <option value="xlsx">Excel (XLSX)</option>
                            <option value="pdf">PDF Document</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle mr-1"></i>
                        The export process may take a few moments depending on the amount of data.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary">
                        <i class="fas fa-download mr-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Handle active tab from URL hash if present
            let hash = window.location.hash;
            if (hash) {
                $('.settings-nav a[href="' + hash + '"]').tab('show');
            }
            
            // Update URL hash when tabs are clicked
            $('.settings-nav a').on('click', function (e) {
                window.location.hash = $(this).attr('href');
            });
            
            // Toggle visibility of form fields based on settings
            $('#verificationMethod').change(function() {
                if ($(this).val() === 'qrcode') {
                    $('#qrSettings').show();
                } else {
                    $('#qrSettings').hide();
                }
            });
            
            // Enable tooltips
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
</body>
</html>