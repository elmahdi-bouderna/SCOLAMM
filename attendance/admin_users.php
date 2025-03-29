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

// Handle user actions (add, edit, delete)
$message = '';
$messageType = '';

// Delete user
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $user_id = $_GET['delete'];
    
    // Make sure admin is not deleting themselves
    if ($user_id == $admin_id) {
        $message = "You cannot delete your own account.";
        $messageType = "danger";
    } else {
        // Delete user from the database
        $sql = "DELETE FROM users WHERE id = ?";
        
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $message = "User deleted successfully.";
                $messageType = "success";
            } else {
                $message = "Error deleting user: " . $stmt->error;
                $messageType = "danger";
            }
            
            $stmt->close();
        }
    }
}

// Process bulk actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_action']) && isset($_POST['selected_users'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'];
    
    if (!empty($selected_users)) {
        if ($bulk_action == 'delete') {
            // Prevent deleting own account
            $filtered_users = array_filter($selected_users, function($user_id) use ($admin_id) {
                return $user_id != $admin_id;
            });
            
            if (count($filtered_users) > 0) {
                $ids = implode(',', array_map('intval', $filtered_users));
                $sql = "DELETE FROM users WHERE id IN ($ids)";
                
                if ($link->query($sql)) {
                    $message = count($filtered_users) . " users deleted successfully.";
                    $messageType = "success";
                    
                    if (count($filtered_users) < count($selected_users)) {
                        $message .= " (Your own account was excluded from deletion)";
                    }
                } else {
                    $message = "Error deleting users: " . $link->error;
                    $messageType = "danger";
                }
            } else {
                $message = "No valid users selected for deletion.";
                $messageType = "warning";
            }
        } elseif ($bulk_action == 'change_role') {
            if (isset($_POST['new_role'])) {
                $new_role = $_POST['new_role'];
                if (in_array($new_role, ['student', 'professor', 'admin'])) {
                    $ids = implode(',', array_map('intval', $selected_users));
                    $sql = "UPDATE users SET role = '$new_role' WHERE id IN ($ids)";
                    
                    if ($link->query($sql)) {
                        $message = count($selected_users) . " users updated successfully.";
                        $messageType = "success";
                    } else {
                        $message = "Error updating users: " . $link->error;
                        $messageType = "danger";
                    }
                }
            }
        }
    } else {
        $message = "No users selected.";
        $messageType = "warning";
    }
}

// Filtering and pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 10;
$offset = ($page - 1) * $records_per_page;

$search = isset($_GET['search']) ? $link->real_escape_string($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $link->real_escape_string($_GET['role']) : '';

$where_clause = "1=1";
if (!empty($search)) {
    $where_clause .= " AND (name LIKE '%$search%' OR email LIKE '%$search%' OR rfid_tag LIKE '%$search%')";
}
if (!empty($role_filter)) {
    $where_clause .= " AND role = '$role_filter'";
}

// Count total records
$count_sql = "SELECT COUNT(*) as total FROM users WHERE $where_clause";
$total_records = 0;

if ($result = $link->query($count_sql)) {
    if ($row = $result->fetch_assoc()) {
        $total_records = $row['total'];
    }
    $result->free();
}

$total_pages = ceil($total_records / $records_per_page);

// Fetch users with pagination
$sql = "SELECT * FROM users WHERE $where_clause ORDER BY id DESC LIMIT ?, ?";
$users = [];

if ($stmt = $link->prepare($sql)) {
    $stmt->bind_param("ii", $offset, $records_per_page);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    
    $stmt->close();
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
    <title>User Management</title>
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
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #495057;
        }
        .user-role {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .role-student {
            background-color: #cff4fc;
            color: #055160;
        }
        .role-professor {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .role-admin {
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
                <li class="nav-item active">
                    <a class="nav-link" href="#">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin_modules.php">
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
                            <a class="nav-link active" href="#">
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
                            <h1 class="h2 mb-1">User Management</h1>
                            <p class="mb-0">Add, edit, and manage user accounts</p>
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
                                    <input type="text" class="form-control" placeholder="Search users..." name="search" value="<?php echo htmlspecialchars($search); ?>">
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
                                <label for="roleFilter" class="mr-2">Role:</label>
                                <select id="roleFilter" class="form-control" onchange="filterByRole(this.value)">
                                    <option value="">All Roles</option>
                                    <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Students</option>
                                    <option value="professor" <?php echo $role_filter === 'professor' ? 'selected' : ''; ?>>Professors</option>
                                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Administrators</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="add_user.php" class="btn btn-success">
                                <i class="fas fa-user-plus"></i> Add New User
                            </a>
                            <button type="button" class="btn btn-outline-primary ml-2" data-toggle="modal" data-target="#importModal">
                                <i class="fas fa-file-import"></i> Import
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-users text-primary mr-2"></i> User List (<?php echo $total_records; ?> users)</span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="bulkActionDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                Bulk Actions
                            </button>
                            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="bulkActionDropdown">
                                <button class="dropdown-item" type="button" onclick="setBulkAction('delete')">
                                    <i class="fas fa-trash-alt text-danger mr-2"></i> Delete Selected
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item" type="button" onclick="showRoleChangeModal()">
                                    <i class="fas fa-user-tag text-primary mr-2"></i> Change Role
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if(count($users) > 0): ?>
                            <form id="bulkActionForm" method="post" action="">
                                <input type="hidden" name="bulk_action" id="bulk_action" value="">
                                <input type="hidden" name="new_role" id="new_role" value="">
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
                                                <th>User</th>
                                                <th>Email</th>
                                                <th>Role</th>
                                                <th>RFID Tag</th>
                                                <th>Last Login</th>
                                                <th class="actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($users as $user): ?>
                                                <tr>
                                                    <td>
                                                        <div class="custom-control custom-checkbox">
                                                            <input type="checkbox" class="custom-control-input user-checkbox" id="user-<?php echo $user['id']; ?>" name="selected_users[]" value="<?php echo $user['id']; ?>">
                                                            <label class="custom-control-label" for="user-<?php echo $user['id']; ?>"></label>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="user-avatar mr-2">
                                                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                            </div>
                                                            <span><?php echo htmlspecialchars($user['name']); ?></span>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                    <td>
                                                        <span class="user-role role-<?php echo $user['role']; ?>">
                                                            <?php echo ucfirst($user['role']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if(!empty($user['rfid_tag'])): ?>
                                                            <span class="badge badge-light"><?php echo htmlspecialchars($user['rfid_tag']); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Not assigned</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if($user['last_login']): ?>
                                                            <small><?php echo date('Y-m-d H:i', strtotime($user['last_login'])); ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary btn-action mr-1">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-danger btn-action" 
                                                                onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
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
                                                    <a class="page-link" href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($role_filter) ? '&role=' . urlencode($role_filter) : ''; ?>">
                                                        First
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($role_filter) ? '&role=' . urlencode($role_filter) : ''; ?>">
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
                                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($role_filter) ? '&role=' . urlencode($role_filter) : ''; ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <?php if($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($role_filter) ? '&role=' . urlencode($role_filter) : ''; ?>">
                                                        Next
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($role_filter) ? '&role=' . urlencode($role_filter) : ''; ?>">
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
                                <i class="fas fa-info-circle mr-2"></i> No users found matching your criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
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
                    Are you sure you want to delete user <strong id="deleteUserName"></strong>?
                    <br>
                    <small class="text-danger">This action cannot be undone.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <a href="#" id="deleteUserBtn" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Role Modal -->
    <div class="modal fade" id="changeRoleModal" tabindex="-1" role="dialog" aria-labelledby="changeRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changeRoleModalLabel">Change User Role</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="roleSelect">Select New Role:</label>
                        <select class="form-control" id="roleSelect">
                            <option value="student">Student</option>
                            <option value="professor">Professor</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="changeBulkRole()">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Modal -->
    <div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Import Users</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="importForm" action="import_users.php" method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="csvFile">Select CSV File:</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="csvFile" name="csvFile" accept=".csv">
                                <label class="custom-file-label" for="csvFile">Choose file</label>
                            </div>
                            <small class="form-text text-muted">
                                File must be in CSV format with headers: name,email,role,password,rfid_tag
                            </small>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="headerRow" name="headerRow" checked>
                                <label class="custom-control-label" for="headerRow">First row contains headers</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="defaultRole">Default Role (if not specified):</label>
                            <select