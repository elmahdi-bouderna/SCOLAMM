<?php
// Include your database configuration
require_once "config.php";

// Set headers to prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/plain');

// Get parameters
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

echo "=== Attendance Verification Tool ===\n";
echo "Current Time: " . date('Y-m-d H:i:s') . "\n";
echo "------------------------\n\n";

if ($session_id) {
    // Fetch session details
    $sql_session = "SELECT ts.*, m.name AS module_name 
                   FROM teacher_scans ts 
                   JOIN modules m ON ts.module_id = m.id 
                   WHERE ts.id = ?";
    
    if ($stmt = $link->prepare($sql_session)) {
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo "Session ID: " . $row['id'] . "\n";
            echo "Module: " . $row['module_name'] . "\n";
            echo "Started: " . $row['scan_time'] . "\n";
            echo "Status: " . $row['status'] . "\n";
            echo "------------------------\n\n";
            
            // Fetch attendance records for this session
            $sql_attendance = "SELECT a.*, u.name, u.rfid_tag 
                               FROM attendance a 
                               JOIN users u ON a.student_id = u.id 
                               WHERE a.teacher_scan_id = ? 
                               ORDER BY a.scan_time ASC";
            
            if ($stmt_att = $link->prepare($sql_attendance)) {
                $stmt_att->bind_param("i", $session_id);
                $stmt_att->execute();
                $result_att = $stmt_att->get_result();
                
                echo "ATTENDANCE RECORDS (" . $result_att->num_rows . " total):\n\n";
                
                if ($result_att->num_rows > 0) {
                    echo str_pad("ID", 5) . " | " . 
                         str_pad("Student", 25) . " | " . 
                         str_pad("RFID Tag", 12) . " | " . 
                         str_pad("Status", 10) . " | " . 
                         "Time\n";
                    echo str_repeat("-", 70) . "\n";
                    
                    while ($att = $result_att->fetch_assoc()) {
                        echo str_pad($att['id'], 5) . " | " . 
                             str_pad($att['name'], 25) . " | " . 
                             str_pad($att['rfid_tag'], 12) . " | " . 
                             str_pad($att['status'], 10) . " | " . 
                             date('H:i:s', strtotime($att['scan_time'])) . "\n";
                    }
                } else {
                    echo "No attendance records found for this session.\n";
                }
                
                $stmt_att->close();
            } else {
                echo "Error preparing attendance query.\n";
            }
            
            echo "\n------------------------\n\n";
            
            // Check for students who haven't marked attendance
            $sql_missing = "SELECT u.id, u.name, u.rfid_tag 
                           FROM student_module sm 
                           JOIN users u ON sm.student_id = u.id 
                           WHERE sm.module_id = ? 
                           AND u.id NOT IN (
                               SELECT student_id FROM attendance WHERE teacher_scan_id = ?
                           )
                           ORDER BY u.name ASC";
            
            if ($stmt_miss = $link->prepare($sql_missing)) {
                $stmt_miss->bind_param("ii", $row['module_id'], $session_id);
                $stmt_miss->execute();
                $result_miss = $stmt_miss->get_result();
                
                echo "STUDENTS WITHOUT ATTENDANCE (" . $result_miss->num_rows . " total):\n\n";
                
                if ($result_miss->num_rows > 0) {
                    echo str_pad("ID", 5) . " | " . 
                         str_pad("Student", 25) . " | " . 
                         "RFID Tag\n";
                    echo str_repeat("-", 50) . "\n";
                    
                    while ($miss = $result_miss->fetch_assoc()) {
                        echo str_pad($miss['id'], 5) . " | " . 
                             str_pad($miss['name'], 25) . " | " . 
                             $miss['rfid_tag'] . "\n";
                    }
                } else {
                    echo "All enrolled students have marked attendance.\n";
                }
                
                $stmt_miss->close();
            }
        } else {
            echo "Session not found with ID: $session_id\n";
        }
        
        $stmt->close();
    } else {
        echo "Error preparing session query.\n";
    }
} else {
    // List recent sessions
    $sql_sessions = "SELECT ts.id, ts.scan_time, ts.status, m.name AS module_name, 
                     COUNT(a.id) AS attendance_count
                     FROM teacher_scans ts 
                     JOIN modules m ON ts.module_id = m.id 
                     LEFT JOIN attendance a ON a.teacher_scan_id = ts.id 
                     GROUP BY ts.id
                     ORDER BY ts.scan_time DESC 
                     LIMIT 10";
    
    $result_sessions = $link->query($sql_sessions);
    
    if ($result_sessions) {
        echo "RECENT SESSIONS:\n\n";
        
        if ($result_sessions->num_rows > 0) {
            echo str_pad("ID", 5) . " | " . 
                 str_pad("Module", 25) . " | " . 
                 str_pad("Status", 10) . " | " . 
                 str_pad("Time", 20) . " | " . 
                 "Students\n";
            echo str_repeat("-", 75) . "\n";
            
            while ($sess = $result_sessions->fetch_assoc()) {
                echo str_pad($sess['id'], 5) . " | " . 
                     str_pad($sess['module_name'], 25) . " | " . 
                     str_pad($sess['status'], 10) . " | " . 
                     str_pad(date('Y-m-d H:i', strtotime($sess['scan_time'])), 20) . " | " . 
                     $sess['attendance_count'] . "\n";
            }
            
            echo "\n";
            echo "To check a specific session, use: check_attendance.php?session_id=X\n";
        } else {
            echo "No sessions found.\n";
        }
    } else {
        echo "Error querying sessions.\n";
    }
}

echo "\n------------------------\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n";

// Close connection
$link->close();
?>