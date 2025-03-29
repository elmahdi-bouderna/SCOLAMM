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

// Initialize variables
$message = '';
$messageType = '';
$backupFiles = [];
$backupPath = 'backups/';
$currentDateTime = '2025-03-17 02:50:08'; // Use the specified current time

// Create backup directory if it doesn't exist
if (!file_exists($backupPath)) {
    if (!mkdir($backupPath, 0755, true)) {
        $message = "Failed to create backup directory. Please check file permissions.";
        $messageType = "danger";
    } else {
        // Create .htaccess to prevent direct access
        $htaccess = $backupPath . '.htaccess';
        if (!file_exists($htaccess)) {
            $content = "deny from all\n";
            file_put_contents($htaccess, $content);
        }
    }
}

// Check if .htaccess exists in the root directory
if (!file_exists('.htaccess')) {
    $content = "<IfModule mod_rewrite.c>\n";
    $content .= "RewriteEngine On\n";
    $content .= "RewriteRule ^backups/.*$ - [F,L]\n";
    $content .= "</IfModule>\n";
    file_put_contents('.htaccess', $content);
}

// Load backup files
if (is_dir($backupPath)) {
    $files = scandir($backupPath);
    foreach ($files as $file) {
        if (preg_match('/^backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql$/', $file, $matches)) {
            $timestamp = str_replace(['_', '-'], [' ', ':'], $matches[1]);
            $size = filesize($backupPath . $file);
            $backupFiles[] = [
                'filename' => $file,
                'timestamp' => $timestamp,
                'size' => $size,
                'size_formatted' => formatFileSize($size)
            ];
        }
    }
    
    // Sort backups by timestamp (newest first)
    usort($backupFiles, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
}

// Process backup creation
if (isset($_POST['create_backup'])) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "backup_" . $timestamp . ".sql";
    $filepath = $backupPath . $filename;
    
    // Get tables
    $tables = [];
    $result = $link->query("SHOW TABLES");
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    // Start output buffering
    ob_start();
    
    // Add header information
    echo "-- IoT Attendance System Database Backup\n";
    echo "-- Created: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Server: " . $link->host_info . "\n";
    echo "-- Database: " . DB_NAME . "\n\n";
    
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+00:00\";\n\n";
    
    // Process each table
    foreach ($tables as $table) {
        // Get create table statement
        $result = $link->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch_row();
        
        echo "-- Table structure for table `$table`\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $row[1] . ";\n\n";
        
        // Get data
        $result = $link->query("SELECT * FROM `$table`");
        $numFields = $result->field_count;
        $numRows = $result->num_rows;
        
        echo "-- Dumping data for table `$table` ($numRows rows)\n";
        
        if ($numRows > 0) {
            // Get field names
            $fields = [];
            while ($fieldInfo = $result->fetch_field()) {
                $fields[] = $fieldInfo->name;
            }
            
            // Reset pointer
            $result->data_seek(0);
            
            // Get rows
            while ($row = $result->fetch_assoc()) {
                $values = [];
                foreach ($row as $value) {
                    if (is_null($value)) {
                        $values[] = "NULL";
                    } else {
                        $values[] = "'" . $link->real_escape_string($value) . "'";
                    }
                }
                
                echo "INSERT INTO `$table` (`" . implode("`, `", $fields) . "`) VALUES (" . implode(", ", $values) . ");\n";
            }
        }
        
        echo "\n";
    }
    
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    
    // Get the output buffer and save to file
    $output = ob_get_clean();
    
    if (file_put_contents($filepath, $output) !== false) {
        $message = "Backup created successfully: $filename";
        $messageType = "success";
        
        // Log the action
        logAction("Backup Created", "Created database backup: $filename");
        
        // Refresh backup files list
        $backupFiles = [];
        $files = scandir($backupPath);
        foreach ($files as $file) {
            if (preg_match('/^backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql$/', $file, $matches)) {
                $timestamp = str_replace(['_', '-'], [' ', ':'], $matches[1]);
                $size = filesize($backupPath . $file);
                $backupFiles[] = [
                    'filename' => $file,
                    'timestamp' => $timestamp,
                    'size' => $size,
                    'size_formatted' => formatFileSize($size)
                ];
            }
        }
        
        // Sort backups by timestamp (newest first)
        usort($backupFiles, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
    } else {
        $message = "Failed to create backup. Please check file permissions.";
        $messageType = "danger";
    }
}

// Process backup deletion
if (isset($_POST['delete_backup']) && isset($_POST['filename'])) {
    $filename = $_POST['filename'];
    $filepath = $backupPath . $filename;
    
    // Security check - make sure it's a backup file
    if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename) && file_exists($filepath)) {
        if (unlink($filepath)) {
            $message = "Backup deleted successfully: $filename";
            $messageType = "success";
            
            // Log the action
            logAction("Backup Deleted", "Deleted database backup: $filename");
            
            // Remove from array
            foreach ($backupFiles as $key => $backup) {
                if ($backup['filename'] === $filename) {
                    unset($backupFiles[$key]);
                    break;
                }
            }
        } else {
            $message = "Failed to delete backup. Please check file permissions.";
            $messageType = "danger";
        }
    } else {
        $message = "Invalid backup file specified.";
        $messageType = "danger";
    }
}

// Process backup restore
if (isset($_POST['restore_backup']) && isset($_POST['filename'])) {
    $filename = $_POST['filename'];
    $filepath = $backupPath . $filename;
    
    // Security check - make sure it's a backup file
    if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename) && file_exists($filepath)) {
        // Create a temporary backup before restoration
        $timestamp = date('Y-m-d_H-i-s');
        $tempFilename = "backup_before_restore_" . $timestamp . ".sql";
        $tempFilepath = $backupPath . $tempFilename;
        
        // Create pre-restore backup (similar to create_backup code)
        $tables = [];
        $result = $link->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        ob_start();
        
        echo "-- IoT Attendance System Database Backup (Pre-Restore)\n";
        echo "-- Created: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Server: " . $link->host_info . "\n";
        echo "-- Database: " . DB_NAME . "\n\n";
        
        echo "SET FOREIGN_KEY_CHECKS=0;\n";
        echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        echo "SET time_zone = \"+00:00\";\n\n";
        
        foreach ($tables as $table) {
            $result = $link->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch_row();
            
            echo "-- Table structure for table `$table`\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            echo $row[1] . ";\n\n";
            
            $result = $link->query("SELECT * FROM `$table`");
            $numFields = $result->field_count;
            $numRows = $result->num_rows;
            
            echo "-- Dumping data for table `$table` ($numRows rows)\n";
            
            if ($numRows > 0) {
                $fields = [];
                while ($fieldInfo = $result->fetch_field()) {
                    $fields[] = $fieldInfo->name;
                }
                
                $result->data_seek(0);
                
                while ($row = $result->fetch_assoc()) {
                    $values = [];
                    foreach ($row as $value) {
                        if (is_null($value)) {
                            $values[] = "NULL";
                        } else {
                            $values[] = "'" . $link->real_escape_string($value) . "'";
                        }
                    }
                    
                    echo "INSERT INTO `$table` (`" . implode("`, `", $fields) . "`) VALUES (" . implode(", ", $values) . ");\n";
                }
            }
            
            echo "\n";
        }
        
        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        
        $output = ob_get_clean();
        file_put_contents($tempFilepath, $output);
        
        // Now restore from backup file
        $sql = file_get_contents($filepath);
        $queries = explode(';', $sql);
        
        // Start transaction
        $link->begin_transaction();
        
        try {
            $link->query("SET FOREIGN_KEY_CHECKS=0");
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    $result = $link->query($query);
                    if (!$result) {
                        throw new Exception($link->error);
                    }
                }
            }
            
            $link->query("SET FOREIGN_KEY_CHECKS=1");
            
            // Commit transaction
            $link->commit();
            
            $message = "Database restored successfully from backup: $filename";
            $messageType = "success";
            
            // Log the action
            logAction("Backup Restored", "Restored database from backup: $filename");
            
        } catch (Exception $e) {
            // Rollback transaction
            $link->rollback();
            
            $message = "Error restoring database: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = "Invalid backup file specified.";
        $messageType = "danger";
    }
}

// Process backup download
if (isset($_GET['download']) && !empty($_GET['file'])) {
    $filename = $_GET['file'];
    $filepath = $backupPath . $filename;
    
    // Security check - make sure it's a backup file
    if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename) && file_exists($filepath)) {
        // Log the download
        logAction("Backup Downloaded", "Downloaded database backup: $filename");
        
        // Download the file
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        $message = "Invalid backup file specified for download.";
        $messageType = "danger";
    }
}

// Get system info for backup statistics
$systemInfo = [
    'database_size' => 0,
    'num_tables' => 0,
    'num_rows' => 0,
    'last_backup' => 'Never',
    'next_scheduled' => 'Not scheduled'
];

// Get database size
$result = $link->query("SELECT SUM(data_length + index_length) as size FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
if ($row = $result->fetch_assoc()) {
    $systemInfo['database_size'] = $row['size'];
}

// Count tables
$result = $link->query("SHOW TABLES");
$systemInfo['num_tables'] = $result->num_rows;

// Count total rows across all tables
$result = $link->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $table = $row[0];
    $count = $link->query("SELECT COUNT(*) as count FROM `$table`");
    $countRow = $count->fetch_assoc();
    $systemInfo['num_rows'] += $countRow['count'];
}

// Get last backup time
if (!empty($backupFiles)) {
    $systemInfo['last_backup'] = $backupFiles[0]['timestamp'];
}

// Helper function to format file size
function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Helper function to log actions to system_logs table if it exists
function logAction($action, $description)
{
    global $link, $admin_id, $admin_name;
    
    // Check if system_logs table exists
    $result = $link->query("SHOW TABLES LIKE 'system_logs'");
    if ($result->num_rows == 0) {
        return; // Table doesn't exist, skip logging
    }
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = date('Y-m-d H:i:s');
    $level = "info";
    $module = "backup";
    
    $sql = "INSERT INTO system_logs (timestamp, user_id, username, action, description, ip_address, level, module) 
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    if ($stmt = $link->prepare($sql)) {
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
    <title>Backup & Restore | Admin Dashboard</title>
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
        .backup-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .backup-item {
            border-left: 4px solid transparent;
            transition: all 0.2s;
        }
        .backup-item:hover {
            background-color: #f8f9fa;
            border-left-color: #007bff;
        }
        .backup-timestamp {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .backup-size {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .stats-card {
            height: 100%;
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.7;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .backup-actions {
            margin-top: auto;
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
                <li class="nav-item">
                    <a class="nav-link" href="admin_modules.php">
                        <i class="fas fa-book"></i> Modules
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_reports.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_logs.php">
                        <i class="fas fa-history"></i> Logs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_settings.php">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
                <li class="nav-item active">
                    <a class="nav-link" href="#">
                        <i class="fas fa-database"></i> Backup
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
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">Database Backup & Restore</h1>
                            <p class="mb-0">Manage database backups and restore previous versions</p>
                        </div>
                        <div class="col-md-4 text-md-right">
                            <p class="datetime mb-0"><?php echo htmlspecialchars($currentDateTime); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php if ($messageType === 'success'): ?>
                            <i class="fas fa-check-circle mr-2"></i>
                        <?php elseif ($messageType === 'danger'): ?>
                            <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php else: ?>
                            <i class="fas fa-info-circle mr-2"></i>
                        <?php endif; ?>
                        <?php echo $message; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-database text-primary mr-2"></i> Backup Archives
                                </div>
                                <form method="post" action="">
                                    <button type="submit" name="create_backup" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus mr-1"></i> Create New Backup
                                    </button>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <?php if (count($backupFiles) > 0): ?>
                                <div class="backup-list">
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($backupFiles as $backup): ?>
                                        <div class="list-group-item backup-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h5 class="mb-1"><?php echo htmlspecialchars($backup['filename']); ?></h5>
                                                    <p class="mb-1 backup-timestamp">
                                                        <i class="far fa-clock mr-1"></i>
                                                        <?php echo htmlspecialchars($backup['timestamp']); ?>
                                                    </p>
                                                    <span class="badge badge-light backup-size">
                                                        <i class="fas fa-file mr-1"></i>
                                                        <?php echo $backup['size_formatted']; ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <a href="?download=1&file=<?php echo urlencode($backup['filename']); ?>" class="btn btn-sm btn-outline-primary mr-1" title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-success mr-1" title="Restore" 
                                                            data-toggle="modal" 
                                                            data-target="#restoreModal" 
                                                            data-filename="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                        <i class="fas fa-undo-alt"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                                            data-toggle="modal" 
                                                            data-target="#deleteModal" 
                                                            data-filename="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="p-4 text-center">
                                    <i class="fas fa-database fa-3x text-muted mb-3"></i>
                                    <p class="mb-0">No backup files found.</p>
                                    <p>Click "Create New Backup" to make your first database backup.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <i class="fas fa-chart-pie text-primary mr-2"></i> Backup Statistics
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <div class="stat-icon text-primary mb-2">
                                        <i class="fas fa-server"></i>
                                        </div>
                                    <div class="stat-value"><?php echo formatFileSize($systemInfo['database_size']); ?></div>
                                    <div class="stat-label">Database Size</div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-6 text-center mb-4">
                                        <div class="stat-icon text-info">
                                            <i class="fas fa-table"></i>
                                        </div>
                                        <div class="stat-value"><?php echo number_format($systemInfo['num_tables']); ?></div>
                                        <div class="stat-label">Tables</div>
                                    </div>
                                    <div class="col-6 text-center mb-4">
                                        <div class="stat-icon text-success">
                                            <i class="fas fa-list"></i>
                                        </div>
                                        <div class="stat-value"><?php echo number_format($systemInfo['num_rows']); ?></div>
                                        <div class="stat-label">Total Records</div>
                                    </div>
                                </div>
                                
                                <div class="text-center mb-4">
                                    <div class="stat-icon text-warning">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="stat-value" style="font-size: 1.2rem;">
                                        <?php echo $systemInfo['last_backup'] !== 'Never' ? date('Y-m-d H:i', strtotime($systemInfo['last_backup'])) : 'Never'; ?>
                                    </div>
                                    <div class="stat-label">Last Backup</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-white">
                                <i class="fas fa-question-circle text-primary mr-2"></i> Backup Information
                            </div>
                            <div class="card-body">
                                <p>Backups are stored in the <code>backups/</code> directory and are protected from direct access.</p>
                                <p>Each backup contains:</p>
                                <ul>
                                    <li>Table structures</li>
                                    <li>All data records</li>
                                    <li>Database configuration</li>
                                </ul>
                                
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Important:</strong> Restoring a backup will overwrite your current database.
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Always download a copy of your backups and store them in a safe location.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Restore Confirmation Modal -->
    <div class="modal fade" id="restoreModal" tabindex="-1" role="dialog" aria-labelledby="restoreModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="restoreModalLabel">Confirm Restore</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Are you sure you want to restore from this backup?
                    </div>
                    <p>This will overwrite your current database with the data from the selected backup file:</p>
                    <p class="font-weight-bold" id="restoreFilename"></p>
                    <p>A temporary backup of your current database will be created before restoration.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form method="post" action="">
                        <input type="hidden" name="filename" id="restoreFileInput">
                        <button type="submit" name="restore_backup" class="btn btn-warning">
                            <i class="fas fa-undo-alt mr-1"></i> Yes, Restore This Backup
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-trash-alt mr-2"></i>
                        Are you sure you want to delete this backup?
                    </div>
                    <p>This action cannot be undone. The following backup file will be permanently deleted:</p>
                    <p class="font-weight-bold" id="deleteFilename"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form method="post" action="">
                        <input type="hidden" name="filename" id="deleteFileInput">
                        <button type="submit" name="delete_backup" class="btn btn-danger">
                            <i class="fas fa-trash-alt mr-1"></i> Yes, Delete This Backup
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Current date and time: 2025-03-17 02:52:32
            // Current user: <?php echo htmlspecialchars($admin_name); ?> (<?php echo htmlspecialchars($_SESSION["role"]); ?>)
            
            // Update restore modal
            $('#restoreModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var filename = button.data('filename');
                $('#restoreFilename').text(filename);
                $('#restoreFileInput').val(filename);
            });
            
            // Update delete modal
            $('#deleteModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var filename = button.data('filename');
                $('#deleteFilename').text(filename);
                $('#deleteFileInput').val(filename);
            });
        });
    </script>
</body>
</html>
                