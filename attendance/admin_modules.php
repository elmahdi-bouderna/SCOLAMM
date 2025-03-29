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

// Handle module actions (add, edit, delete)
$message = '';
$messageType = '';

// Delete module
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $module_id = $_GET['delete'];
    
    // Check if module can be deleted (no associated records)
    $check_sql = "SELECT 
                   (SELECT COUNT(*) FROM student_module WHERE module_id = ?) AS student_count,
                   (SELECT COUNT(*) FROM professor_module WHERE module_id = ?) AS professor_count,
                   (SELECT COUNT(*) FROM attendance WHERE module_id = ?) AS attendance_count,
                   (SELECT COUNT(*) FROM teacher_scans WHERE module_id = ?) AS scan_count";
    
    if ($stmt = $link->prepare($check_sql)) {
        $stmt->bind_param("iiii", $module_id, $module_id, $module_id, $module_id);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $total_associated = $row['student_count'] + $row['professor_count'] + $row['attendance_count'] + $row['scan_count'];
            
            if ($total_associated > 0) {
                $message = "Cannot delete module as it has associated data. Please remove all associations first.";
                $messageType = "danger";
            } else {
                // Delete module from the database
                $sql = "DELETE FROM modules WHERE id = ?";
                
                if ($stmt = $link->prepare($sql)) {
                    $stmt->bind_param("i", $module_id);
                    
                    if ($stmt->execute()) {
                        $message = "Module deleted successfully.";
                        $messageType = "success";
                    } else {
                        $message = "Error deleting module: " . $stmt->error;
                        $messageType = "danger";
                    }
                    
                    $stmt->close();
                }
            }
        }
    }
}

// Process bulk actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action']) && isset($_POST['selected_modules'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_modules = $_POST['selected_modules'];
    
    if (!empty($selected_modules)) {
        if ($bulk_action == 'delete') {
            $ids = implode(',', array_map('intval', $selected_modules));
            
            // Check if modules can be deleted (no associated records)
            $check_sql = "SELECT m.id, m.name,
                         (SELECT COUNT(*) FROM student_module WHERE module_id = m.id) +
                         (SELECT COUNT(*) FROM professor_module WHERE module_id = m.id) +
                         (SELECT COUNT(*) FROM attendance WHERE module_id = m.id) +
                         (SELECT COUNT(*) FROM teacher_scans WHERE module_id = m.id) AS total_associated
                         FROM modules m
                         WHERE m.id IN ($ids)
                         HAVING total_associated > 0";
            
            $result = $link->query($check_sql);
            $has_associations = false;
            $association_names = [];
            
            if ($result->num_rows > 0) {
                $has_associations = true;
                while($row = $result->fetch_assoc()) {
                    $association_names[] = $row['name'];
                }
            }
            
            if ($has_associations) {
                $message = "Cannot delete the following modules as they have associated data: " . implode(", ", $association_names);
                $messageType = "danger";
            } else {
                $sql = "DELETE FROM modules WHERE id IN ($ids)";
                
                if ($link->query($sql)) {
                    $message = count($selected_modules) . " modules deleted successfully.";
                    $messageType = "success";
                } else {
                    $message = "Error deleting modules: " . $link->error;
                    $messageType = "danger";
                }
            }
        }
    } else {
        $message = "No modules selected.";
        $messageType = "warning";
    }
}

// Add new module
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_module'])) {
    $name = trim($_POST["name"]);
    $code = trim($_POST["code"]);
    $description = trim($_POST["description"]);
    $semester = trim($_POST["semester"]);
    
    // Check if module name already exists
    $check_sql = "SELECT id FROM modules WHERE name = ? OR code = ?";
    if ($stmt = $link->prepare($check_sql)) {
        $stmt->bind_param("ss", $name, $code);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $message = "A module with this name or code already exists.";
            $messageType = "danger";
        } else {
            // Define SQL dynamically based on available columns
            $check_code = "SHOW COLUMNS FROM modules LIKE 'code'";
            $check_desc = "SHOW COLUMNS FROM modules LIKE 'description'";
            $check_sem = "SHOW COLUMNS FROM modules LIKE 'semester'";

            $has_code = $link->query($check_code)->num_rows > 0;
            $has_desc = $link->query($check_desc)->num_rows > 0;
            $has_sem = $link->query($check_sem)->num_rows > 0;

            $columns = ["name"];
            $values = ["?"];
            $params = [$name];
            $types = "s";

            if ($has_code) {
                $columns[] = "code";
                $values[] = "?";
                $params[] = $code;
                $types .= "s";
            }

            if ($has_desc) {
                $columns[] = "description";
                $values[] = "?";
                $params[] = $description;
                $types .= "s";
            }

            if ($has_sem && !empty($semester)) {
                $columns[] = "semester";
                $values[] = "?";
                $params[] = $semester;
                $types .= "s";
            }

            $sql = "INSERT INTO modules (" . implode(",", $columns) . ") VALUES (" . implode(",", $values) . ")";

            if ($stmt = $link->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                
                if ($stmt->execute()) {
                    $message = "New module added successfully.";
                    $messageType = "success";
                } else {
                    $message = "Error adding module: " . $stmt->error;
                    $messageType = "danger";
                }
                
                $stmt->close();
            }
        }
    }
}

// Filtering and pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

$search = isset($_GET['search']) ? $link->real_escape_string($_GET['search']) : '';
$semester_filter = isset($_GET['semester']) ? $link->real_escape_string($_GET['semester']) : '';

$where_clause = "1=1";
if (!empty($search)) {
    $where_clause .= " AND (name LIKE '%$search%' OR code LIKE '%$search%' OR description LIKE '%$search%')";
}
if (!empty($semester_filter) && $column_exists) {
    $where_clause .= " AND semester = '$semester_filter'";
}

// Count total records
$count_sql = "SELECT COUNT(*) as total FROM modules WHERE $where_clause";
$total_records = 0;

if ($result = $link->query($count_sql)) {
    if ($row = $result->fetch_assoc()) {
        $total_records = $row['total'];
    }
    $result->free();
}

$total_pages = ceil($total_records / $records_per_page);

// Fetch modules with pagination
$sql = "SELECT m.*, 
        (SELECT COUNT(*) FROM student_module WHERE module_id = m.id) AS student_count,
        (SELECT COUNT(*) FROM professor_module WHERE module_id = m.id) AS professor_count
        FROM modules m 
        WHERE $where_clause 
        ORDER BY m.name ASC
        LIMIT ?, ?";
$modules = [];

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("ii", $offset, $records_per_page);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $modules[] = $row;
        }
    }
    
    $stmt->close();
}

// Get available semesters for filter
$semesters = [];

// Check if the semester column exists
$check_column = "SHOW COLUMNS FROM modules LIKE 'semester'";
$column_exists = $link->query($check_column)->num_rows > 0;

if ($column_exists) {
    $semester_sql = "SELECT DISTINCT semester FROM modules ORDER BY semester";
    if ($result = $link->query($semester_sql)) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['semester'])) {
                $semesters[] = $row['semester'];
            }
        }
        $result->free();
    }
} else {
    // Add default semesters if column doesn't exist
    $semesters = ['1', '2'];
}

// Get current date and time
$currentDateTime = date('Y-m-d H:i:s');

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Management</title>
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
        .module-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-semester-1 {
            background-color: #cff4fc;
            color: #055160;
        }
        .badge-semester-2 {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .badge-semester-3 {
            background-color: #f8d7da;
            color: #842029;
        }
        .actions-column {
            width: 130px;
        }
        .btn-action {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .filters-card {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .page-link {
            color: #007bff;
        }
        .page-item.active .page-link {
            background-color: #007bff;
            border-color: #007bff;
        }
        .checkbox-column {
            width: 40px;
        }
        .module-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #495057;
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
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
                            <a class="nav-link" href="admin_users.php">
                                <i class="fas fa-users"></i>
                                User Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">Module Management</h1>
                            <p class="mb-0">Add, edit, and manage course modules</p>
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
                
                <div class="filters-card mb-4">
                    <div class="row">
                        <div class="col-md-4">
                            <form method="get" action="" class="form-inline">
                                <div class="input-group w-100">
                                    <input type="text" class="form-control" placeholder="Search modules..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-0">
                                <label for="semesterFilter" class="mr-2">Semester:</label>
                                <select id="semesterFilter" class="form-control" onchange="filterBySemester(this.value)">
                                    <option value="">All Semesters</option>
                                    <?php foreach($semesters as $semester): ?>
                                        <option value="<?php echo htmlspecialchars($semester); ?>" <?php echo $semester_filter === $semester ? 'selected' : ''; ?>>
                                            Semester <?php echo htmlspecialchars($semester); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4 text-right">
                            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addModuleModal">
                                <i class="fas fa-plus-circle"></i> Add New Module
                            </button>
                            <button type="button" class="btn btn-outline-primary ml-2" data-toggle="modal" data-target="#importModal">
                                <i class="fas fa-file-import"></i> Import
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-book text-primary mr-2"></i> Module List (<?php echo $total_records; ?> modules)</span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="bulkActionDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                Bulk Actions
                            </button>
                            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="bulkActionDropdown">
                                <button class="dropdown-item" type="button" onclick="setBulkAction('delete')">
                                    <i class="fas fa-trash-alt text-danger mr-2"></i> Delete Selected
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if(count($modules) > 0): ?>
                            <form id="bulkActionForm" method="post" action="">
                                <input type="hidden" name="bulk_action" id="bulk_action" value="">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th class="checkbox-column">
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input" id="selectAll">
                                                        <label class="custom-control-label" for="selectAll"></label>
                                                    </div>
                                                </th>
                                                <th>Module</th>
                                                <th>Code</th>
                                                <th>Description</th>
                                                <th>Semester</th>
                                                <th>Enrollments</th>
                                                <th class="actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($modules as $module): ?>
                                                <tr>
                                                    <td>
                                                        <div class="custom-control custom-checkbox">
                                                            <input type="checkbox" class="custom-control-input module-checkbox" id="module-<?php echo $module['id']; ?>" name="selected_modules[]" value="<?php echo $module['id']; ?>">
                                                            <label class="custom-control-label" for="module-<?php echo $module['id']; ?>"></label>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="module-icon mr-2">
                                                                <i class="fas fa-book"></i>
                                                            </div>
                                                            <span><?php echo htmlspecialchars($module['name']); ?></span>
                                                        </div>
                                                    </td>
                                                    <td><?php echo isset($module['code']) ? htmlspecialchars($module['code']) : 'N/A'; ?></td>
                                                    <td>
                                                        <?php 
                                                        $description = isset($module['description']) ? $module['description'] : '';
                                                        if (strlen($description) > 50) {
                                                            echo htmlspecialchars(substr($description, 0, 50) . '...');
                                                        } else {
                                                            echo htmlspecialchars($description);
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php if(!empty($module['semester'])): ?>
                                                            <span class="module-badge badge-semester-<?php echo $module['semester']; ?>">
                                                                Semester <?php echo htmlspecialchars($module['semester']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not assigned</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div data-toggle="tooltip" title="<?php echo $module['student_count']; ?> students, <?php echo $module['professor_count']; ?> professors">
                                                            <span class="badge badge-primary"><?php echo $module['student_count']; ?> <i class="fas fa-user-graduate"></i></span>
                                                            <span class="badge badge-info ml-1"><?php echo $module['professor_count']; ?> <i class="fas fa-chalkboard-teacher"></i></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <a href="edit_module.php?id=<?php echo $module['id']; ?>" class="btn btn-sm btn-outline-primary btn-action mr-1">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-danger btn-action" 
                                                                onclick="confirmDelete(<?php echo $module['id']; ?>, '<?php echo htmlspecialchars($module['name']); ?>')">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                            
                            <!-- Pagination -->
                            <?php if($total_pages > 1): ?>
                                <div class="card-footer bg-white">
                                    <nav>
                                        <ul class="pagination justify-content-center mb-0">
                                            <?php if($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($semester_filter) ? '&semester=' . urlencode($semester_filter) : ''; ?>">
                                                        First
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($semester_filter) ? '&semester=' . urlencode($semester_filter) : ''; ?>">
                                                        Previous
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <?php
                                            $start_page = max(1, $page - 2);
                                            $end_page = min($total_pages, $start_page + 4);
                                            
                                            if($end_page - $start_page < 4) {
                                                $start_page = max(1, $end_page - 4);
                                            }
                                            
                                            for($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($semester_filter) ? '&semester=' . urlencode($semester_filter) : ''; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <?php if($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($semester_filter) ? '&semester=' . urlencode($semester_filter) : ''; ?>">
                                                        Next
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($semester_filter) ? '&semester=' . urlencode($semester_filter) : ''; ?>">
                                                        Last
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info m-3 mb-0">
                                <i class="fas fa-info-circle mr-2"></i> No modules found matching your criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Module Modal -->
    <div class="modal fade" id="addModuleModal" tabindex="-1" role="dialog" aria-labelledby="addModuleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addModuleModalLabel">Add New Module</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="add_module" value="1">
                        <div class="form-group">
                            <label for="name">Module Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="code">Module Code</label>
                            <input type="text" class="form-control" id="code" name="code" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="semester">Semester</label>
                            <select class="form-control" id="semester" name="semester">
                                <option value="">Select Semester</option>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                                <option value="3">Semester 3</option>
                                <option value="4">Semester 4</option>
                                <option value="5">Semester 5</option>
                                <option value="6">Semester 6</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Module</button>
                    </div>
                </form>
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
                    Are you sure you want to delete module <strong id="deleteModuleName"></strong>?
                    <br>
                    <small class="text-danger">This action cannot be undone.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <a href="#" id="deleteModuleBtn" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Modal -->
    <div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Import Modules</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="importForm" action="import_modules.php" method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="csvFile">Select CSV File:</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="csvFile" name="csvFile" accept=".csv">
                                <label class="custom-file-label" for="csvFile">Choose file</label>
                            </div>
                            <small class="form-text text-muted">
                                File must be in CSV format with headers: name,code,description,semester
                            </small>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="headerRow" name="headerRow" checked>
                                <label class="custom-control-label" for="headerRow">First row contains headers</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="duplicateHandling">If duplicate modules found:</label>
                            <select class="form-control" id="duplicateHandling" name="duplicate_handling">
                                <option value="skip">Skip (do not import duplicates)</option>
                                <option value="update">Update existing modules</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('importForm').submit();">Import</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();
            
            // Handle select all checkbox
            $("#selectAll").change(function() {
                $(".module-checkbox").prop('checked', $(this).prop('checked'));
            });
            
            // Update select all checkbox when individual checkboxes change
            $(".module-checkbox").change(function() {
                if ($(".module-checkbox:checked").length === $(".module-checkbox").length) {
                    $("#selectAll").prop('checked', true);
                } else {
                    $("#selectAll").prop('checked', false);
                }
            });
            
            // Show selected file name in custom file input
            $(".custom-file-input").on("change", function() {
                var fileName = $(this).val().split("\\").pop();
                $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
            });
        });
        
        // Filter by semester
        function filterBySemester(semester) {
            window.location.href = 'admin_modules.php?semester=' + semester + '<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>';
        }
        
        // Set bulk action
        function setBulkAction(action) {
            if ($(".module-checkbox:checked").length === 0) {
                alert("Please select at least one module to perform this action.");
                return;
            }
            
            if (action === 'delete') {
                if (confirm("Are you sure you want to delete the selected modules? This action cannot be undone.")) {
                    document.getElementById('bulk_action').value = action;
                    document.getElementById('bulkActionForm').submit();
                }
            } else {
                document.getElementById('bulk_action').value = action;
                document.getElementById('bulkActionForm').submit();
            }
        }
        
        // Confirm delete for individual module
        function confirmDelete(moduleId, moduleName) {
            document.getElementById('deleteModuleName').textContent = moduleName;
            document.getElementById('deleteModuleBtn').href = 'admin_modules.php?delete=' + moduleId;
            $('#deleteModal').modal('show');
        }
    </script>
</body>
</html>
                        