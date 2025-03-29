<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";

$attendance = [];

if ($_SESSION["role"] == "student") {
    $sql = "SELECT a.id, m.name as module_name, a.scan_time, a.status 
            FROM attendance a 
            JOIN modules m ON a.module_id = m.id 
            WHERE a.student_id = ?";

    if ($stmt = $link->prepare($sql)) {
        $stmt->bind_param("i", $param_student_id);
        $param_student_id = $_SESSION["id"];

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $attendance[] = $row;
            }
        }
        $stmt->close();
    }
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <h2>Attendance</h2>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Module</th>
                                    <th>Scan Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance as $record): ?>
                                    <tr>
                                        <td><?php echo $record["id"]; ?></td>
                                        <td><?php echo $record["module_name"]; ?></td>
                                        <td><?php echo $record["scan_time"]; ?></td>
                                        <td><?php echo $record["status"]; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>