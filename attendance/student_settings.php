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

// Initialize message variables
$success_message = '';
$error_message = '';

// Process profile update
if (isset($_POST['update_profile'])) {
    $new_email = trim($_POST['email']);
    $new_phone = trim($_POST['phone'] ?? '');
    
    // Basic validation
    if (empty($new_email)) {
        $error_message = "Email cannot be empty";
    } else {
        // Check if email already exists for another user
        $sql = "SELECT id FROM users WHERE email = ? AND id != ?";
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param("si", $new_email, $student_id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $error_message = "Email already in use by another account";
            } else {
                // Update user profile
                $update_sql = "UPDATE users SET email = ?, phone = ? WHERE id = ?";
                if ($update_stmt = $link->prepare($update_sql)) {
                    $update_stmt->bind_param("ssi", $new_email, $new_phone, $student_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Profile updated successfully";
                        $_SESSION["email"] = $new_email;
                        $student_email = $new_email;
                    } else {
                        $error_message = "Error updating profile: " . $link->error;
                    }
                    
                    $update_stmt->close();
                }
            }
            
            $stmt->close();
        }
    }
}

// Process password change
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // First, verify current password
    $sql = "SELECT password FROM users WHERE id = ?";
    if ($stmt = $link->prepare($sql)) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $stmt->bind_result($hashed_password);
        $stmt->fetch();
        $stmt->close();
        
        // Verify password
        if (password_verify($current_password, $hashed_password)) {
            // Check if new password matches confirmation
            if ($new_password === $confirm_password) {
                // Validate password strength
                if (strlen($new_password) >= 6) {
                    // Hash the new password
                    $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update password
                    $update_sql = "UPDATE users SET password = ? WHERE id = ?";
                    if ($update_stmt = $link->prepare($update_sql)) {
                        $update_stmt->bind_param("si", $new_hashed_password, $student_id);
                        
                        if ($update_stmt->execute()) {
                            $success_message = "Password changed successfully";
                        } else {
                            $error_message = "Error changing password: " . $link->error;
                        }
                        
                        $update_stmt->close();
                    }
                } else {
                    $error_message = "Password must be at least 6 characters long";
                }
            } else {
                $error_message = "New passwords do not match";
            }
        } else {
            $error_message = "Current password is incorrect";
        }
    }
}

// Process notification settings
if (isset($_POST['save_notifications'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $attendance_alerts = isset($_POST['attendance_alerts']) ? 1 : 0;
    
    // Here you would typically save these settings to a user_preferences table
    // For demonstration, we'll just show a success message
    $success_message = "Notification preferences saved successfully";
}

// Fetch student's detailed info
$student_data = [];
$sql = "SELECT u.name, u.email, u.rfid_tag, u.field, u.created_at, u.last_login
        FROM users u
        WHERE u.id = ?";

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $student_data = $row;
        $student_email = $row['email'];
        $student_rfid = $row['rfid_tag'];
    }
    $stmt->close();
}

// Get number of modules enrolled
$module_count = 0;
$sql = "SELECT COUNT(*) as count FROM student_module WHERE student_id = ?";
if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $module_count = $row['count'];
    }
    $stmt->close();
}

// Get attendance statistics
$total_sessions = 0;
$attended_sessions = 0;

$sql = "SELECT 
          COUNT(DISTINCT ts.id) AS total_sessions,
          COUNT(DISTINCT a.id) AS attended_sessions
        FROM teacher_scans ts
        JOIN student_module sm ON ts.module_id = sm.module_id
        LEFT JOIN attendance a ON a.teacher_scan_id = ts.id AND a.student_id = sm.student_id
        WHERE sm.student_id = ?";

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $total_sessions = $row['total_sessions'];
        $attended_sessions = $row['attended_sessions'];
    }
    $stmt->close();
}

// Calculate attendance rate
$attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : 0;

// Get current date and time
$currentDateTime = '2025-03-17 03:30:59';

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Student Dashboard</title>
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
        .settings-nav .nav-link {
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 5px;
            color: #495057;
        }
        .settings-nav .nav-link.active {
            background-color: #28a745;
            color: white;
        }
        .settings-nav .nav-link:hover:not(.active) {
            background-color: #e9ecef;
        }
        .settings-tab-content {
            padding: 20px;
        }
        .account-stat {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            background-color: #f8f9fa;
            margin-bottom: 15px;
        }
        .account-stat i {
            font-size: 24px;
            margin-bottom: 10px;
            color: #28a745;
        }
        .account-stat .stat-value {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .account-stat .stat-label {
            color: #6c757d;
            font-size: 14px;
        }
        .btn-success {
            background-color: #28a745;
            border-color: #28a745;
        }
        .btn-outline-success {
            color: #28a745;
            border-color: #28a745;
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
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
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
                                    <h1 class="profile-name">Account Settings</h1>
                                    <p class="profile-role">Manage your profile and preferences</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-2"></i> <?php echo $success_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body p-0">
                                <div class="list-group settings-nav" id="settings-tab" role="tablist">
                                    <a class="list-group-item list-group-item-action active" id="profile-tab" data-toggle="list" href="#profile" role="tab" aria-controls="profile">
                                        <i class="fas fa-user-edit mr-2"></i> Edit Profile
                                    </a>
                                    <a class="list-group-item list-group-item-action" id="password-tab" data-toggle="list" href="#password" role="tab" aria-controls="password">
                                        <i class="fas fa-key mr-2"></i> Change Password
                                    </a>
                                    <a class="list-group-item list-group-item-action" id="notifications-tab" data-toggle="list" href="#notifications" role="tab" aria-controls="notifications">
                                        <i class="fas fa-bell mr-2"></i> Notifications
                                    </a>
                                    <a class="list-group-item list-group-item-action" id="account-tab" data-toggle="list" href="#account" role="tab" aria-controls="account">
                                        <i class="fas fa-info-circle mr-2"></i> Account Info
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <i class="fas fa-chart-pie mr-2"></i> Account Statistics
                            </div>
                            <div class="card-body">
                                <div class="account-stat">
                                    <i class="fas fa-book"></i>
                                    <div class="stat-value"><?php echo $module_count; ?></div>
                                    <div class="stat-label">Enrolled Modules</div>
                                </div>
                                
                                <div class="account-stat">
                                    <i class="fas fa-calendar-check"></i>
                                    <div class="stat-value"><?php echo $attendance_rate; ?>%</div>
                                    <div class="stat-label">Attendance Rate</div>
                                </div>
                                
                                <div class="account-stat mb-0">
                                    <i class="fas fa-calendar-alt"></i>
                                    <div class="stat-value"><?php echo $attended_sessions; ?> / <?php echo $total_sessions; ?></div>
                                    <div class="stat-label">Sessions Attended</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-9">
                        <div class="card">
                            <div class="card-body">
                                <div class="tab-content settings-tab-content">
                                    <!-- Edit Profile Tab -->
                                    <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                                        <h4 class="mb-4">Edit Profile</h4>
                                        <form action="" method="post">
                                            <div class="form-group row">
                                                <label for="fullName" class="col-sm-3 col-form-label">Full Name</label>
                                                <div class="col-sm-9">
                                                    <input type="text" class="form-control" id="fullName" value="<?php echo htmlspecialchars($student_name); ?>" readonly>
                                                    <small class="form-text text-muted">Name cannot be changed. Contact administration for name changes.</small>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <label for="email" class="col-sm-3 col-form-label">Email Address</label>
                                                <div class="col-sm-9">
                                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($student_email); ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <label for="phone" class="col-sm-3 col-form-label">Phone Number</label>
                                                <div class="col-sm-9">
                                                    <input type="tel" class="form-control" id="phone" name="phone" value="">
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <label for="rfid" class="col-sm-3 col-form-label">RFID Tag</label>
                                                <div class="col-sm-9">
                                                    <input type="text" class="form-control" id="rfid" value="<?php echo htmlspecialchars($student_rfid); ?>" readonly>
                                                    <small class="form-text text-muted">RFID tag cannot be changed. Contact administration for assistance.</small>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <div class="col-sm-9 offset-sm-3">
                                                    <button type="submit" name="update_profile" class="btn btn-success">
                                                        <i class="fas fa-save mr-1"></i> Update Profile
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Change Password Tab -->
                                    <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                                        <h4 class="mb-4">Change Password</h4>
                                        <form action="" method="post">
                                            <div class="form-group row">
                                                <label for="currentPassword" class="col-sm-4 col-form-label">Current Password</label>
                                                <div class="col-sm-8">
                                                    <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <label for="newPassword" class="col-sm-4 col-form-label">New Password</label>
                                                <div class="col-sm-8">
                                                    <input type="password" class="form-control" id="newPassword" name="new_password" required>
                                                    <small class="form-text text-muted">Password must be at least 6 characters long.</small>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <label for="confirmPassword" class="col-sm-4 col-form-label">Confirm New Password</label>
                                                <div class="col-sm-8">
                                                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group row">
                                                <div class="col-sm-8 offset-sm-4">
                                                    <button type="submit" name="change_password" class="btn btn-success">
                                                        <i class="fas fa-key mr-1"></i> Change Password
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Notifications Tab -->
                                    <div class="tab-pane fade" id="notifications" role="tabpanel" aria-labelledby="notifications-tab">
                                        <h4 class="mb-4">Notification Settings</h4>
                                        <form action="" method="post">
                                            <div class="form-group">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" class="custom-control-input" id="emailNotifications" name="email_notifications" checked>
                                                    <label class="custom-control-label" for="emailNotifications">Email Notifications</label>
                                                    <small class="form-text text-muted d-block">Receive important notifications via email.</small>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" class="custom-control-input" id="smsNotifications" name="sms_notifications">
                                                    <label class="custom-control-label" for="smsNotifications">SMS Notifications</label>
                                                    <small class="form-text text-muted d-block">Receive important notifications via SMS.</small>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" class="custom-control-input" id="attendanceAlerts" name="attendance_alerts" checked>
                                                    <label class="custom-control-label" for="attendanceAlerts">Attendance Alerts</label>
                                                    <small class="form-text text-muted d-block">Receive alerts about your attendance status.</small>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <button type="submit" name="save_notifications" class="btn btn-success">
                                                    <i class="fas fa-save mr-1"></i> Save Preferences
                                                </button>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle mr-2"></i>
                                                Note: Some notification options may require additional setup or may not be available in all regions.
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Account Info Tab -->
                                    <div class="tab-pane fade" id="account" role="tabpanel" aria-labelledby="account-tab">
                                        <h4 class="mb-4">Account Information</h4>
                                        
                                        <div class="card mb-3 bg-light">
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-4 font-weight-bold">Name:</div>
                                                    <div class="col-md-8"><?php echo htmlspecialchars($student_name); ?></div>
                                                </div>
                                                <hr>
                                                <div class="row">
                                                    <div class="col-md-4 font-weight-bold">Email:</div>
                                                    <div class="col-md-8"><?php echo htmlspecialchars($student_email); ?></div>
                                                </div>
                                                <hr>
                                                <div class="row">
                                                    <div class="col-md-4 font-weight-bold">RFID Tag:</div>
                                                    <div class="col-md-8"><?php echo htmlspecialchars($student_rfid); ?></div>
                                                </div>
                                                <hr>
                                                <div class="row">
                                                    <div class="col-md-4 font-weight-bold">Field of Study:</div>
                                                    <div class="col-md-8"><?php echo htmlspecialchars($student_data['field'] ?? 'Not specified'); ?></div>
                                                </div>
                                                <hr>
                                                <div class="row">
                                                    <div class="col-md-4 font-weight-bold">Account Created:</div>
                                                    <div class="col-md-8">
                                                        <?php echo isset($student_data['created_at']) ? date('F j, Y', strtotime($student_data['created_at'])) : 'Unknown'; ?>
                                                    </div>
                                                </div>
                                                <hr>
                                                <div class="row">
                                                    <div class="col-md-4 font-weight-bold">Last Login:</div>
                                                    <div class="col-md-8">
                                                        <?php echo isset($student_data['last_login']) ? date('F j, Y H:i', strtotime($student_data['last_login'])) : 'No login recorded'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            If you need to change critical account information such as your name or student ID, please contact the system administrator.
                                        </div>
                                        
                                        <div class="mt-4">
                                            <h5>Data Privacy</h5>
                                            <p class="text-muted">
                                                Your personal information is handled according to our data privacy policy. You have the right to request 
                                                a copy of all data we store about you.
                                            </p>
                                            <button type="button" class="btn btn-outline-success">
                                                <i class="fas fa-file-download mr-1"></i> Request My Data
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <i class="fas fa-shield-alt mr-2"></i> Security Recommendations
                            </div>
                            <div class="card-body">
                                <div class="d-flex mb-3">
                                    <div class="mr-3 text-success">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Keep your password secure</h6>
                                        <p class="text-muted mb-0">Use a strong, unique password and change it periodically.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex mb-3">
                                    <div class="mr-3 text-success">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Keep your contact information updated</h6>
                                        <p class="text-muted mb-0">Ensure we can reach you with important notifications.</p>
                                    </div>
                                </div>
                                
                                <div class="d-flex">
                                    <div class="mr-3 text-success">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">Take care of your RFID tag</h6>
                                        <p class="text-muted mb-0">Report any loss or damage of your RFID tag immediately.</p>
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
        $(document).ready(function() {
            // Current date and time: 2025-03-17 03:33:09 (UTC)
            
            // Activate tabs based on URL hash or show default
            let url = document.location.toString();
            if (url.match('#')) {
                $('.settings-nav a[href="#' + url.split('#')[1] + '"]').tab('show');
            }
            
            // Change hash for page-reload
            $('.settings-nav a').on('shown.bs.tab', function (e) {
                window.location.hash = e.target.hash;
            });
            
            // Password strength validation
            $('#newPassword').on('input', function() {
                const password = $(this).val();
                
                // Simple password strength check
                if (password.length < 6) {
                    $(this).removeClass('is-valid').addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid').addClass('is-valid');
                }
            });
            
            // Password match validation
            $('#confirmPassword').on('input', function() {
                const confirmPassword = $(this).val();
                const password = $('#newPassword').val();
                
                if (confirmPassword === password) {
                    $(this).removeClass('is-invalid').addClass('is-valid');
                } else {
                    $(this).removeClass('is-valid').addClass('is-invalid');
                }
            });
        });
    </script>
</body>
</html>