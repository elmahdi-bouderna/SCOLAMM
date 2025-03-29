<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["role"] != "admin") {
    header("location: login.html");
    exit;
}
require_once "config.php";

$message = '';
$messageType = '';

// Check if the table already exists
$tableExists = false;
$result = $link->query("SHOW TABLES LIKE 'class_schedule'");
if ($result) {
    $tableExists = ($result->num_rows > 0);
}

if (!$tableExists) {
    // Create class_schedule table
    $sql = "CREATE TABLE class_schedule (
        id INT(11) NOT NULL AUTO_INCREMENT,
        module_id INT(11) NOT NULL,
        day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        location VARCHAR(255) NOT NULL,
        professor_id INT(11) NOT NULL,
        recurring TINYINT(1) DEFAULT 1,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX (module_id),
        INDEX (professor_id),
        INDEX (day_of_week),
        FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
        FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    if ($link->query($sql) === TRUE) {
        $message .= "Class schedule table created successfully.<br>";
        $messageType = 'success';
        
        // Add some sample data
        $sampleData = "INSERT INTO class_schedule (module_id, day_of_week, start_time, end_time, location, professor_id) VALUES
            ((SELECT id FROM modules LIMIT 1), 'Monday', '09:00:00', '11:00:00', 'Room A101', (SELECT id FROM users WHERE role = 'professor' LIMIT 1)),
            ((SELECT id FROM modules LIMIT 1), 'Wednesday', '14:00:00', '16:00:00', 'Room B205', (SELECT id FROM users WHERE role = 'professor' LIMIT 1)),
            ((SELECT id FROM modules LIMIT 1 OFFSET 1), 'Tuesday', '10:30:00', '12:30:00', 'Room C310', (SELECT id FROM users WHERE role = 'professor' LIMIT 1)),
            ((SELECT id FROM modules LIMIT 1 OFFSET 1), 'Thursday', '15:45:00', '17:45:00', 'Lab 2', (SELECT id FROM users WHERE role = 'professor' LIMIT 1))";
            
        try {
            if ($link->query($sampleData) === TRUE) {
                $message .= "Sample schedule data added.<br>";
            } else {
                $message .= "Note: Could not add sample data (this is normal if you don't have modules or professors yet).<br>";
            }
        } catch (Exception $e) {
            $message .= "Note: Could not add sample data. You'll need to add schedules manually.<br>";
        }
    } else {
        $message = "Error creating class schedule table: " . $link->error;
        $messageType = 'danger';
    }
} else {
    $message = "The class_schedule table already exists.";
    $messageType = 'info';
}

// Log the action if system_logs table exists
$result = $link->query("SHOW TABLES LIKE 'system_logs'");
if ($result && $result->num_rows > 0) {
    $admin_id = $_SESSION["id"];
    $admin_name = $_SESSION["name"];
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = date('Y-m-d H:i:s');
    $action = "Table Created";
    $description = "Created class_schedule table";
    $level = "info";
    $module = "system";
    
    $log_sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    if ($stmt = $link->prepare($log_sql)) {
        $stmt->bind_param("sisssiss", $now, $admin_id, $admin_name, $action, $description, $ip, $level, $module);
        $stmt->execute();
        $stmt->close();
    }
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Schedule Tables | Admin</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            padding-top: 50px;
        }
        .container {
            max-width: 800px;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background-color: #007bff;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-calendar-alt mr-2"></i> Create Schedule Tables
            </div>
            <div class="card-body">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?>" role="alert">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <div class="text-center mt-3">
                    <p>The class schedule table is necessary for displaying student schedules.</p>
                    <div class="mt-4">
                        <a href="admin_modules.php" class="btn btn-primary">
                            <i class="fas fa-book mr-1"></i> Go to Module Management
                        </a>
                        <a href="student_schedule.php" class="btn btn-success ml-2">
                            <i class="fas fa-calendar-alt mr-1"></i> View Student Schedule
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>