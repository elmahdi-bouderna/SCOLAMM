<?php
require_once "config.php";

// Function to add test data
function addTestData($link) {
    // Check if there's already attendance data
    $check = $link->query("SELECT COUNT(*) AS count FROM attendance");
    $row = $check->fetch_assoc();
    
    if ($row['count'] > 0) {
        return "Test data already exists. Found " . $row['count'] . " attendance records.";
    }
    
    // Get some students
    $students = [];
    $result = $link->query("SELECT id FROM users WHERE role = 'student' LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        $students[] = $row['id'];
    }
    
    // If no students found, return
    if (empty($students)) {
        return "Error: No students found in the database.";
    }
    
    // Get some modules
    $modules = [];
    $result = $link->query("SELECT id FROM modules LIMIT 3");
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row['id'];
    }
    
    // If no modules found, return
    if (empty($modules)) {
        return "Error: No modules found in the database.";
    }
    
    // Check if attendance table exists, if not create it
    $link->query("
        CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            module_id INT NOT NULL,
            date DATETIME NOT NULL,
            status ENUM('present', 'absent', 'late') NOT NULL DEFAULT 'present',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id),
            FOREIGN KEY (module_id) REFERENCES modules(id)
        )
    ");
    
    // Check if student_module table exists, if not create it
    $link->query("
        CREATE TABLE IF NOT EXISTS student_module (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            module_id INT NOT NULL,
            UNIQUE KEY student_module_unique (student_id, module_id),
            FOREIGN KEY (student_id) REFERENCES users(id),
            FOREIGN KEY (module_id) REFERENCES modules(id)
        )
    ");
    
    // Check if professor_module table exists, if not create it
    $link->query("
        CREATE TABLE IF NOT EXISTS professor_module (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professor_id INT NOT NULL,
            module_id INT NOT NULL,
            UNIQUE KEY professor_module_unique (professor_id, module_id),
            FOREIGN KEY (professor_id) REFERENCES users(id),
            FOREIGN KEY (module_id) REFERENCES modules(id)
        )
    ");
    
    // Add student-module enrollments
    foreach ($students as $student_id) {
        foreach ($modules as $module_id) {
            // Check if enrollment already exists
            $check = $link->query("SELECT COUNT(*) as count FROM student_module 
                                  WHERE student_id = $student_id AND module_id = $module_id");
            $row = $check->fetch_assoc();
            
            if ($row['count'] == 0) {
                $link->query("INSERT INTO student_module (student_id, module_id) 
                             VALUES ($student_id, $module_id)");
            }
        }
    }
    
    // Add attendance data for the last 30 days
    $statuses = ['present', 'present', 'present', 'present', 'absent', 'late']; // Weighted distribution
    $count = 0;
    
    // Use the specified date as the reference point (March 17, 2025)
    $reference_date = "2025-03-17 02:02:40";
    
    for ($i = 0; $i < 30; $i++) {
        $date = date('Y-m-d H:i:s', strtotime($reference_date . " -$i days"));
        
        foreach ($students as $student_id) {
            foreach ($modules as $module_id) {
                // Randomize presence (70% present, 15% absent, 15% late)
                $status = $statuses[array_rand($statuses)];
                
                // Only add attendance record with 80% probability each day
                if (rand(1, 100) <= 80) {
                    $notes = "";
                    if ($status == 'late') {
                        $notes = "Arrived " . rand(5, 30) . " minutes late";
                    } elseif ($status == 'absent') {
                        $notes = rand(0, 1) ? "No excuse provided" : "Medical excuse";
                    }
                    
                    $stmt = $link->prepare("INSERT INTO attendance 
                                 (student_id, module_id, date, status, notes) 
                                 VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("iisss", $student_id, $module_id, $date, $status, $notes);
                    $result = $stmt->execute();
                    if ($result) {
                        $count++;
                    } else {
                        echo "Error: " . $stmt->error . "<br>";
                    }
                }
            }
        }
    }
    
    return "Successfully added $count attendance records for testing.";
}

// Add HTML wrapper for better presentation
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insert Test Attendance Data</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header">
                <h4>Insert Test Attendance Data</h4>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <?php echo addTestData($link); ?>
                </div>
                <a href="admin_reports.php" class="btn btn-primary">Go to Reports</a>
            </div>
        </div>
    </div>
</body>
</html>