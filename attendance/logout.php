<?php
// Initialize the session
session_start();

// Include config file for database connection
require_once "config.php";

// Check if user is logged in before logging the logout action
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    // Get user info for logging
    $user_id = $_SESSION["id"] ?? null;
    $username = $_SESSION["name"] ?? "Unknown User";
    $user_role = $_SESSION["role"] ?? "unknown";
    
    // Log the logout action
    $action = "User Logout";
    $description = "User logged out successfully. Role: " . $user_role;
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $timestamp = date("Y-m-d H:i:s"); // Current server time
    
    // Insert logout record into system_logs
    $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
                VALUES (NOW(), ?, ?, ?, ?, ?, 'info', 'authentication')";
    
    if($log_stmt = $link->prepare($log_sql)) {
        $log_stmt->bind_param("issss", $user_id, $username, $action, $description, $ip_address);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    // Close database connection
    $link->close();
}

// Unset all session variables
$_SESSION = array();

// If using session cookies, delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Current date and time for display (as requested)
$currentDateTime = '2025-03-17 20:20:29';
$currentUser = 'elmahdi-bouderna';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - Scolagile Attendance System</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-container {
            max-width: 500px;
            width: 100%;
            text-align: center;
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
        }
        .system-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .card-body {
            padding: 30px;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            padding: 12px 30px;
            font-weight: 600;
        }
        .btn-primary:hover {
            background-color: #0069d9;
            border-color: #0062cc;
        }
        .status-text {
            font-size: 1.2rem;
            margin-bottom: 20px;
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
        .success-checkmark {
            color: #28a745;
            font-size: 3.5rem;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container logout-container">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-graduation-cap system-icon"></i>
                <h1 class="h4">Scolagile Attendance System</h1>
                <p class="datetime mb-0"><?php echo $currentDateTime; ?></p>
            </div>
            <div class="card-body">
                <i class="fas fa-check-circle success-checkmark"></i>
                <h2 class="h3 mb-3">Logged Out Successfully</h2>
                <p class="status-text text-muted">You have been successfully logged out of the system.</p>
                <p class="text-muted mb-4">Thank you for using the Scolagile Attendance System.</p>
                <a href="login.php" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt mr-2"></i> Return to Login
                </a>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date("Y"); ?> University | Scolagile Attendance System</p>
            <p>Previous User: <?php echo htmlspecialchars($currentUser); ?></p>
        </div>
    </div>

    <script>
        // Automatic redirect to login page after 5 seconds
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 5000);
    </script>
</body>
</html>