<?php
// Initialize the session
session_start();

// Check if user is already logged in
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    // Redirect based on user role
    switch($_SESSION["role"]) {
        case "student":
            header("location: student_dashboard.php");
            break;
        case "professor":
            header("location: professor_dashboard.php");
            break;
        case "admin":
            header("location: admin_dashboard.php");
            break;
        default:
            header("location: index.php");
    }
    exit;
}

// Include config file
require_once "config.php";

// Fetch system settings
$system_name = "Scolagile Attendance System";
$institution_name = "University";
$logo_url = "";
$max_login_attempts = 5;
$lockout_time = 30;

$sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('system_name', 'institution_name', 'logo_url', 'max_login_attempts', 'lockout_time', 'maintenance_mode')";
if($stmt = $link->prepare($sql)) {
    if($stmt->execute()) {
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()) {
            switch($row['setting_key']) {
                case 'system_name':
                    $system_name = $row['setting_value'];
                    break;
                case 'institution_name':
                    $institution_name = $row['setting_value'];
                    break;
                case 'logo_url':
                    $logo_url = $row['setting_value'];
                    break;
                case 'max_login_attempts':
                    $max_login_attempts = (int)$row['setting_value'];
                    break;
                case 'lockout_time':
                    $lockout_time = (int)$row['setting_value'];
                    break;
                case 'maintenance_mode':
                    $maintenance_mode = (bool)$row['setting_value'];
                    if($maintenance_mode && !isset($_GET['maintenance_override'])) {
                        // Display maintenance page
                        header("location: maintenance.php");
                        exit;
                    }
                    break;
            }
        }
    }
    $stmt->close();
}

// Check if RFID login is being used
$rfid_login = false;
$rfid_err = "";

// Process direct RFID tag submission
if(isset($_POST['rfid_tag']) && !empty($_POST['rfid_tag'])) {
    $rfid_login = true;
    $rfid_tag = trim($_POST['rfid_tag']);
    
    // Prepare a select statement to find user by RFID tag
    $sql = "SELECT id, name, email, password, role, active, last_login FROM users WHERE rfid_tag = ? AND active = 1";
    
    if($stmt = $link->prepare($sql)) {
        // Bind variables to the prepared statement as parameters
        $stmt->bind_param("s", $rfid_tag);
        
        // Attempt to execute the prepared statement
        if($stmt->execute()) {
            // Store result
            $stmt->store_result();
            
            // Check if RFID tag exists
            if($stmt->num_rows == 1) {                    
                // Bind result variables
                $stmt->bind_result($id, $name, $email, $hashed_password, $role, $active, $last_login);
                if($stmt->fetch()) {
                    // Valid RFID, start a new session
                    session_regenerate_id(true);
                    
                    // Store data in session variables
                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $id;
                    $_SESSION["name"] = $name;
                    $_SESSION["email"] = $email;
                    $_SESSION["role"] = $role;
                    
                    // Reset login attempts
                    unset($_SESSION["login_attempts"]);
                    unset($_SESSION["last_attempt_time"]);
                    
                    // Update last login time
                    $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                    if($update_stmt = $link->prepare($update_sql)) {
                        $update_stmt->bind_param("i", $id);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                    
                    // Log the successful login
                    $action = "RFID Login";
                    $description = "Successful login via RFID";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                                VALUES (NOW(), ?, ?, ?, ?, ?, 'info', 'authentication')";
                    if($log_stmt = $link->prepare($log_sql)) {
                        $log_stmt->bind_param("issss", $id, $name, $action, $description, $ip);
                        $log_stmt->execute();
                        $log_stmt->close();
                    }
                    
                    // Redirect user based on role
                    switch($role) {
                        case "student":
                            header("location: student_dashboard.php");
                            break;
                        case "professor":
                            header("location: professor_dashboard.php");
                            break;
                        case "admin":
                            header("location: admin_dashboard.php");
                            break;
                        default:
                            header("location: index.php");
                    }
                    exit;
                }
            } else {
                $rfid_err = "Invalid RFID tag.";
                
                // Log failed login attempt
                $action = "Failed RFID Login";
                $description = "Failed login attempt with RFID tag: " . $rfid_tag;
                $ip = $_SERVER['REMOTE_ADDR'];
                $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                            VALUES (NOW(), NULL, ?, ?, ?, ?, 'warning', 'authentication')";
                if($log_stmt = $link->prepare($log_sql)) {
                    $log_stmt->bind_param("ssss", $rfid_tag, $action, $description, $ip);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            }
        } else {
            $rfid_err = "Oops! Something went wrong. Please try again later.";
        }
        
        // Close statement
        $stmt->close();
    }
}

// Define variables and initialize with empty values
$email = $password = "";
$email_err = $password_err = $login_err = "";
$attempts_remaining = $max_login_attempts;

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST" && !$rfid_login) {
    
    // Check if the user has exceeded max login attempts
    if(isset($_SESSION["login_attempts"]) && $_SESSION["login_attempts"] >= $max_login_attempts) {
        if(time() - $_SESSION["last_attempt_time"] < $lockout_time * 60) {
            $login_err = "Too many failed login attempts. Please try again after " . $lockout_time . " minutes.";
        } else {
            // Reset attempts after lockout period
            $_SESSION["login_attempts"] = 0;
        }
    }

    if(empty($login_err)) {
        // Validate email
        if(empty(trim($_POST["email"]))){
            $email_err = "Please enter your email.";
        } else {
            $email = trim($_POST["email"]);
        }
        
        // Validate password
        if(empty(trim($_POST["password"]))){
            $password_err = "Please enter your password.";
        } else{
            $password = trim($_POST["password"]);
        }
        
        // Check input errors before checking in database
        if(empty($email_err) && empty($password_err)) {
            // Prepare a select statement
            $sql = "SELECT id, name, email, password, role, active, last_login FROM users WHERE email = ?";
            
            if($stmt = $link->prepare($sql)) {
                // Bind variables to the prepared statement as parameters
                $stmt->bind_param("s", $param_email);
                
                // Set parameters
                $param_email = $email;
                
                // Attempt to execute the prepared statement
                if($stmt->execute()) {
                    // Store result
                    $stmt->store_result();
                    
                    // Check if email exists, if yes then verify password
                    if($stmt->num_rows == 1) {                    
                        // Bind result variables
                        $stmt->bind_result($id, $name, $email, $hashed_password, $role, $active, $last_login);
                        if($stmt->fetch()) {
                            // Check if account is active
                            if($active != 1) {
                                $login_err = "Your account is not active. Please contact the administrator.";
                                // Track login attempts
                                if(!isset($_SESSION["login_attempts"])) {
                                    $_SESSION["login_attempts"] = 1;
                                } else {
                                    $_SESSION["login_attempts"]++;
                                }
                                $_SESSION["last_attempt_time"] = time();
                                $attempts_remaining = $max_login_attempts - $_SESSION["login_attempts"];
                            }
                            elseif(password_verify($password, $hashed_password)) {
                                // Password is correct, start a new session
                                session_regenerate_id(true);
                                
                                // Store data in session variables
                                $_SESSION["loggedin"] = true;
                                $_SESSION["id"] = $id;
                                $_SESSION["name"] = $name;
                                $_SESSION["email"] = $email;
                                $_SESSION["role"] = $role;
                                
                                // Reset login attempts
                                unset($_SESSION["login_attempts"]);
                                unset($_SESSION["last_attempt_time"]);
                                
                                // Update last login time
                                $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                                if($update_stmt = $link->prepare($update_sql)) {
                                    $update_stmt->bind_param("i", $id);
                                    $update_stmt->execute();
                                    $update_stmt->close();
                                }
                                
                                // Log the successful login
                                $action = "User Login";
                                $description = "Successful login";
                                $ip = $_SERVER['REMOTE_ADDR'];
                                $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                                            VALUES (NOW(), ?, ?, ?, ?, ?, 'info', 'authentication')";
                                if($log_stmt = $link->prepare($log_sql)) {
                                    $log_stmt->bind_param("issss", $id, $name, $action, $description, $ip);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                }
                                
                                // Redirect user based on role
                                switch($role) {
                                    case "student":
                                        header("location: student_dashboard.php");
                                        break;
                                    case "professor":
                                        header("location: professor_dashboard.php");
                                        break;
                                    case "admin":
                                        header("location: admin_dashboard.php");
                                        break;
                                    default:
                                        header("location: index.php");
                                }
                                exit;
                            } else {
                                // Password is not valid
                                $login_err = "Invalid email or password.";
                                
                                // Track login attempts
                                if(!isset($_SESSION["login_attempts"])) {
                                    $_SESSION["login_attempts"] = 1;
                                } else {
                                    $_SESSION["login_attempts"]++;
                                }
                                $_SESSION["last_attempt_time"] = time();
                                $attempts_remaining = $max_login_attempts - $_SESSION["login_attempts"];
                                
                                // Log failed login attempt
                                $action = "Failed Login";
                                $description = "Failed login attempt for email: " . $email;
                                $ip = $_SERVER['REMOTE_ADDR'];
                                $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                                            VALUES (NOW(), NULL, ?, ?, ?, ?, 'warning', 'authentication')";
                                if($log_stmt = $link->prepare($log_sql)) {
                                    $log_stmt->bind_param("ssss", $email, $action, $description, $ip);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                }
                            }
                        }
                    } else {
                        // Email doesn't exist
                        $login_err = "Invalid email or password.";
                        
                        // Track login attempts for security
                        if(!isset($_SESSION["login_attempts"])) {
                            $_SESSION["login_attempts"] = 1;
                        } else {
                            $_SESSION["login_attempts"]++;
                        }
                        $_SESSION["last_attempt_time"] = time();
                        $attempts_remaining = $max_login_attempts - $_SESSION["login_attempts"];
                    }
                } else {
                    $login_err = "Oops! Something went wrong. Please try again later.";
                }
                
                // Close statement
                $stmt->close();
            }
        }
    }
    // Close connection
    $link->close();
}

// Current date and time - UPDATED
$currentDateTime = '2025-03-17 21:07:58';
$currentUser = 'elmahdi-bouderna';

// WebSocket server details
$websocket_host = "192.168.1.113";
$websocket_port = 3000;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($system_name); ?></title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .login-container {
            max-width: 960px;
            width: 100%;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-bottom: none;
            padding: 25px;
            text-align: center;
        }
        .system-name {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .institution-name {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        .welcome-msg {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .login-form {
            padding: 30px;
        }
        .input-group-text {
            background-color: #f8f9fa;
            border-right: none;
        }
        .form-control {
            border-left: none;
            padding: 12px;
            height: auto;
        }
        .form-control:focus {
            box-shadow: none;
            border-color: #ced4da;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            padding: 12px;
            font-weight: 600;
        }
        .btn-primary:hover {
            background-color: #0069d9;
            border-color: #0062cc;
        }
        .alert {
            border-radius: 5px;
        }
        .forgot-password {
            text-align: right;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        .login-image {
            background: url('https://source.unsplash.com/random/600x800?university,campus') center/cover no-repeat;
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .datetime {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.7);
        }
        .footer {
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 20px;
        }
        .rfid-scan {
            background-color: #e9f7ef;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
        }
        .btn-scan {
            background-color: #27ae60;
            border-color: #27ae60;
        }
        .btn-scan:hover {
            background-color: #219653;
            border-color: #219653;
        }
        .invalid-feedback {
            font-size: 0.85rem;
        }
        
        /* Mobile specific styles */
        @media (max-width: 767.98px) {
            .card {
                margin-bottom: 0;
            }
            .login-container {
                padding: 0;
                max-width: 100%;
            }
            .system-name {
                font-size: 1.5rem;
            }
            .institution-name {
                font-size: 1rem;
            }
            .login-form {
                padding: 20px;
            }
            .card-header {
                padding: 20px 15px;
            }
            body {
                padding: 10px;
            }
            .btn {
                padding: 12px;
                font-size: 1rem;
            }
            .welcome-msg {
                font-size: 1.3rem;
            }
            .mobile-heading {
                display: block;
            }
            .desktop-heading {
                display: none;
            }
        }
        
        /* Desktop specific styles */
        @media (min-width: 768px) {
            .mobile-heading {
                display: none;
            }
            .desktop-heading {
                display: block;
            }
            .login-image {
                min-height: 550px;
            }
        }
        
        /* Animation for the form */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animated-form {
            animation: fadeIn 0.8s ease-out;
        }
        
        /* Touch-friendly adjustments */
        @media (hover: none) and (pointer: coarse) {
            .form-control, .btn, .input-group-text {
                font-size: 16px;
            }
            .form-control {
                height: 46px;
            }
            .btn {
                min-height: 46px;
            }
        }
        
        /* RFID scanning styles */
        .rfid-status {
            text-align: center;
            margin-top: 10px;
        }
        .status-connecting {
            color: #ffc107;
        }
        .status-connected {
            color: #28a745;
        }
        .status-disconnected {
            color: #dc3545;
        }
        .rfid-animation {
            width: 100px;
            height: 100px;
            margin: 0 auto;
            position: relative;
        }
        .rfid-animation .outer-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 2px solid #28a745;
            position: absolute;
            opacity: 0.3;
            animation: pulse 2s infinite;
        }
        .rfid-animation .middle-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 2px solid #28a745;
            position: absolute;
            top: 15px;
            left: 15px;
            opacity: 0.5;
            animation: pulse 2s infinite;
            animation-delay: 0.3s;
        }
        .rfid-animation .inner-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid #28a745;
            position: absolute;
            top: 30px;
            left: 30px;
            opacity: 0.7;
            animation: pulse 2s infinite;
            animation-delay: 0.6s;
        }
        @keyframes pulse {
            0% { transform: scale(0.9); opacity: 0.7; }
            50% { transform: scale(1); opacity: 0.4; }
            100% { transform: scale(0.9); opacity: 0.7; }
        }
    </style>
</head>
<body>
    <div class="container-fluid login-container">
        <div class="card">
            <div class="row no-gutters">
                <!-- Mobile Header - Shows only on mobile -->
                <div class="col-12 d-md-none">
                    <div class="card-header mobile-heading">
                        <?php if (!empty($logo_url)): ?>
                            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="mb-3" style="max-height: 60px;">
                        <?php else: ?>
                            <i class="fas fa-graduation-cap fa-3x mb-3"></i>
                        <?php endif; ?>
                        <h1 class="system-name"><?php echo htmlspecialchars($system_name); ?></h1>
                        <p class="institution-name"><?php echo htmlspecialchars($institution_name); ?></p>
                        <p class="datetime mt-3"><?php echo $currentDateTime; ?></p>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <!-- Desktop Header - Shows only on desktop -->
                    <div class="card-header desktop-heading">
                        <?php if (!empty($logo_url)): ?>
                            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="mb-3" style="max-height: 80px;">
                        <?php else: ?>
                            <i class="fas fa-graduation-cap fa-3x mb-3"></i>
                        <?php endif; ?>
                        <h1 class="system-name"><?php echo htmlspecialchars($system_name); ?></h1>
                        <p class="institution-name"><?php echo htmlspecialchars($institution_name); ?></p>
                        <p class="datetime mt-3"><?php echo $currentDateTime; ?></p>
                    </div>
                    
                    <div class="login-form animated-form">
                        <h2 class="welcome-msg">Welcome Back</h2>
                        
                        <?php 
                        if(!empty($login_err)){
                            echo '<div class="alert alert-danger" role="alert">' . $login_err . '</div>';
                            if($attempts_remaining > 0){
                                echo '<div class="alert alert-warning" role="alert">Attempts remaining: ' . $attempts_remaining . '</div>';
                            }
                        }
                        
                        if(!empty($rfid_err)){
                            echo '<div class="alert alert-danger" role="alert">' . $rfid_err . '</div>';
                        }
                        ?>

                        <form id="loginForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                            <div class="form-group">
                                <label for="email">Email</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    </div>
                                    <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $email; ?>" placeholder="Enter your email" autocomplete="email">
                                    <?php if (!empty($email_err)): ?>
                                        <div class="invalid-feedback"><?php echo $email_err; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    </div>
                                    <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" placeholder="Enter your password" autocomplete="current-password">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="fa fa-eye-slash" id="eye-icon"></i>
                                        </button>
                                    </div>
                                    <?php if (!empty($password_err)): ?>
                                        <div class="invalid-feedback"><?php echo $password_err; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="rememberMe">
                                    <label class="custom-control-label" for="rememberMe">Remember me</label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary btn-block">
                                    <i class="fas fa-sign-in-alt mr-2"></i> Login
                                </button>
                            </div>
                        </form>
                        
                        <!-- Separate form for RFID login -->
                        <form id="rfidForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" style="display:none;">
                            <input type="hidden" name="rfid_tag" id="rfidTagInput">
                        </form>
                        
                        <div class="forgot-password">
                            <a href="forgot_password.php">Forgot your password?</a>
                        </div>
                        
                        <div class="rfid-scan">
                            <div class="text-center">
                                <i class="fas fa-id-card fa-2x mb-2 text-success"></i>
                                <h5>Quick Login with RFID</h5>
                                <p class="text-muted mb-2">Place your RFID card on the scanner</p>
                                
                                <button class="btn btn-scan" id="scanButton">
                                    <i class="fas fa-wifi mr-1"></i> Scan Card
                                </button>
                                
                                <div id="scanningArea" style="display: none;">
                                    <div class="rfid-animation mt-3">
                                        <div class="outer-circle"></div>
                                        <div class="middle-circle"></div>
                                        <div class="inner-circle"></div>
                                    </div>
                                    <p class="mt-2 mb-0">Scanning... Please place your card on the reader.</p>
                                    <small class="text-muted">The scanner is active for 15 seconds</small>
                                </div>
                                
                                <div id="rfidStatus" class="rfid-status mt-2">
                                    <p><span id="statusIcon" class="status-disconnected"><i class="fas fa-plug"></i></span> 
                                    <span id="statusMessage">Disconnected</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 d-none d-md-block">
                    <div class="login-image"></div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($institution_name); ?> | <?php echo htmlspecialchars($system_name); ?></p>
            <p>Current User: <?php echo htmlspecialchars($currentUser); ?></p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle password visibility
            const togglePassword = document.getElementById('togglePassword');
            const password = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (togglePassword) {
                togglePassword.addEventListener('click', function() {
                    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                    password.setAttribute('type', type);
                    eyeIcon.className = type === 'password' ? 'fa fa-eye-slash' : 'fa fa-eye';
                });
            }
            
            // Apply focus styles for better UX
            const inputs = document.querySelectorAll('.form-control');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentNode.style.border = '1px solid #007bff';
                });
                
                input.addEventListener('blur', function() {                     this.parentNode.style.border = '';
                });
            });
            
            // WebSocket functionality for RFID scanning
            let socket = null;
            let scanTimeout = null;
            let isScanning = false;
            const statusIcon = document.getElementById('statusIcon');
            const statusMessage = document.getElementById('statusMessage');
            const scanButton = document.getElementById('scanButton');
            const scanningArea = document.getElementById('scanningArea');
            const rfidForm = document.getElementById('rfidForm');
            const rfidTagInput = document.getElementById('rfidTagInput');
            
            // Setup WebSocket connection
            function connectWebSocket() {
                // Update status to connecting
                if (statusIcon) statusIcon.className = 'status-connecting';
                if (statusIcon) statusIcon.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                if (statusMessage) statusMessage.textContent = 'Connecting...';
                
                try {
                    // Create WebSocket connection
                    socket = new WebSocket('ws://<?php echo $websocket_host; ?>:<?php echo $websocket_port; ?>');
                    
                    // Connection opened
                    socket.addEventListener('open', function(event) {
                        console.log('Connected to WebSocket server');
                        if (statusIcon) statusIcon.className = 'status-connected';
                        if (statusIcon) statusIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
                        if (statusMessage) statusMessage.textContent = 'Connected to Scanner';
                        if (scanButton) scanButton.disabled = false;
                    });
                    
                    // Connection closed
                    socket.addEventListener('close', function(event) {
                        console.log('Disconnected from WebSocket server');
                        if (statusIcon) statusIcon.className = 'status-disconnected';
                        if (statusIcon) statusIcon.innerHTML = '<i class="fas fa-times-circle"></i>';
                        if (statusMessage) statusMessage.textContent = 'Disconnected';
                        if (!isScanning && scanButton) scanButton.disabled = false;
                        if (scanningArea) scanningArea.style.display = 'none';
                        
                        // Try to reconnect after 5 seconds
                        setTimeout(connectWebSocket, 5000);
                    });
                    
                    // Connection error
                    socket.addEventListener('error', function(event) {
                        console.error('WebSocket error:', event);
                        if (statusIcon) statusIcon.className = 'status-disconnected';
                        if (statusIcon) statusIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                        if (statusMessage) statusMessage.textContent = 'Connection Error';
                        if (scanButton) scanButton.disabled = true;
                    });
                    
                    // Listen for messages - CRITICAL PART THAT WAS FIXED
                    socket.addEventListener('message', function(event) {
                        console.log('Raw server message:', event.data);
                        
                        // Check if this is an RFID tag (hexadecimal format)
                        // This directly processes the tag from your Arduino
                        const data = event.data;
                        if (data && /^[a-f0-9]+$/.test(data) && data.length >= 6) {
                            console.log('Valid RFID tag detected:', data);
                            
                            // Set the RFID tag value in the hidden form
                            if (rfidTagInput) rfidTagInput.value = data;
                            
                            // Update UI to show success
                            if (scanButton) {
                                scanButton.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Card Detected';
                                scanButton.className = 'btn btn-success';
                            }
                            
                            // Hide scanning animation
                            if (scanningArea) scanningArea.style.display = 'none';
                            isScanning = false;
                            
                            // Submit the RFID form
                            setTimeout(function() {
                                if (rfidForm) rfidForm.submit();
                            }, 1000);
                            
                            // Clear any timeouts
                            if (scanTimeout) {
                                clearTimeout(scanTimeout);
                                scanTimeout = null;
                            }
                        }
                        // Handle error messages from server
                        else if (data === 'Invalid RFID Tag' || data.includes('No class') || data.includes('Internal server error')) {
                            console.log('Server reported issue:', data);
                            if (scanButton) {
                                scanButton.innerHTML = '<i class="fas fa-times-circle mr-1"></i> Invalid Card';
                                scanButton.className = 'btn btn-danger';
                            }
                            
                            // Hide scanning animation
                            if (scanningArea) scanningArea.style.display = 'none';
                            isScanning = false;
                            
                            // Reset button after a delay
                            setTimeout(function() {
                                if (scanButton) {
                                    scanButton.innerHTML = '<i class="fas fa-wifi mr-1"></i> Scan Card';
                                    scanButton.className = 'btn btn-scan';
                                    scanButton.disabled = false;
                                }
                            }, 3000);
                            
                            // Clear any timeouts
                            if (scanTimeout) {
                                clearTimeout(scanTimeout);
                                scanTimeout = null;
                            }
                        }
                    });
                } catch (error) {
                    console.error('WebSocket connection error:', error);
                    if (statusIcon) statusIcon.className = 'status-disconnected';
                    if (statusIcon) statusIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    if (statusMessage) statusMessage.textContent = 'Connection Error';
                }
            }
            
            // Start RFID scanning process
            if (scanButton) {
                scanButton.addEventListener('click', function() {
                    // If socket isn't connected, connect first
                    if (!socket || socket.readyState !== WebSocket.OPEN) {
                        connectWebSocket();
                        
                        // Show connecting status
                        scanButton.disabled = true;
                        scanButton.innerHTML = '<i class="fas fa-plug mr-1"></i> Connecting...';
                        
                        // Try to start scanning after a delay
                        setTimeout(function() {
                            if (socket && socket.readyState === WebSocket.OPEN) {
                                startScanning();
                            } else {
                                // Still not connected
                                scanButton.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> Cannot Connect';
                                setTimeout(function() {
                                    scanButton.innerHTML = '<i class="fas fa-wifi mr-1"></i> Scan Card';
                                    scanButton.disabled = false;
                                }, 2000);
                            }
                        }, 2000);
                    } else {
                        startScanning();
                    }
                });
            }
            
            function startScanning() {
                // Ensure elements exist
                if (!scanButton || !scanningArea) return;
                
                // Update UI
                scanButton.disabled = true;
                scanButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Scanning...';
                isScanning = true;
                scanningArea.style.display = 'block';
                
                // Send command to server
                if (socket && socket.readyState === WebSocket.OPEN) {
                    console.log('Sending start_scan command to server');
                    socket.send('start_scan');
                    
                    // Set timeout for scan
                    scanTimeout = setTimeout(function() {
                        console.log('Scan timed out');
                        scanButton.innerHTML = '<i class="fas fa-wifi mr-1"></i> Scan Card';
                        scanButton.disabled = false;
                        scanningArea.style.display = 'none';
                        if (statusMessage) statusMessage.textContent = 'Scan timed out. Try again.';
                        isScanning = false;
                    }, 15000);
                } else {
                    // Not connected
                    scanButton.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> Not Connected';
                    setTimeout(function() {
                        scanButton.innerHTML = '<i class="fas fa-wifi mr-1"></i> Scan Card';
                        scanButton.disabled = false;
                        isScanning = false;
                    }, 2000);
                    scanningArea.style.display = 'none';
                }
            }
            
            // Connect to WebSocket when page loads
            setTimeout(connectWebSocket, 1000);
            
            // Update timestamp
            const datetimeElements = document.querySelectorAll('.datetime');
            datetimeElements.forEach(element => {
                element.textContent = "<?php echo $currentDateTime; ?>";
            });
        });
    </script>
</body>
</html>
                    