<?php
require_once "config.php";

$name = $email = $password = $role = $rfid_tag = "";
$name_err = $email_err = $password_err = $role_err = $rfid_tag_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate name
    if (empty(trim($_POST["name"]))) {
        $name_err = "Please enter a name.";
    } else {
        $name = trim($_POST["name"]);
    }

    // Validate email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter an email.";
    } else {
        // Check if email already exists
        $sql = "SELECT id FROM users WHERE email = ?";
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param("s", $param_email);
            $param_email = trim($_POST["email"]);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $email_err = "This email is already taken.";
                } else {
                    $email = trim($_POST["email"]);
                }
            } else {
                echo "Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }

    // Validate password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Validate role
    if (empty(trim($_POST["role"]))) {
        $role_err = "Please select a role.";
    } else {
        $role = trim($_POST["role"]);
    }

    // Validate RFID tag
    if (empty(trim($_POST["rfid_tag"]))) {
        $rfid_tag_err = "Please enter an RFID tag.";
    } else {
        // Check if RFID tag already exists
        $sql = "SELECT id FROM users WHERE rfid_tag = ?";
        if ($stmt = $link->prepare($sql)) {
            $stmt->bind_param("s", $param_rfid_tag);
            $param_rfid_tag = trim($_POST["rfid_tag"]);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $rfid_tag_err = "This RFID tag is already taken.";
                } else {
                    $rfid_tag = trim($_POST["rfid_tag"]);
                }
            } else {
                echo "Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }

    // Check input errors before inserting in database
    if (empty($name_err) && empty($email_err) && empty($password_err) && empty($role_err) && empty($rfid_tag_err)) {
        // Prepare an insert statement
        $sql = "INSERT INTO users (name, email, password, role, rfid_tag) VALUES (?, ?, ?, ?, ?)";

        if ($stmt = $link->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bind_param("sssss", $param_name, $param_email, $param_password, $param_role, $param_rfid_tag);

            // Set parameters
            $param_name = $name;
            $param_email = $email;
            $param_password = password_hash($password, PASSWORD_DEFAULT); // Creates a password hash
            $param_role = $role;
            $param_rfid_tag = $rfid_tag;

            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Redirect to login page
                header("location: login.php");
            } else {
                echo "Something went wrong. Please try again later.";
            }

            // Close statement
            $stmt->close();
        }
    }

    // Close connection
    $link->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h2>Register</h2>
                        <p>Please fill this form to create an account.</p>
                        <form id="register-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                            <div class="form-group">
                                <label>Name</label>
                                <input type="text" name="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $name; ?>">
                                <span class="invalid-feedback"><?php echo $name_err; ?></span>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $email; ?>">
                                <span class="invalid-feedback"><?php echo $email_err; ?></span>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $password; ?>">
                                <span class="invalid-feedback"><?php echo $password_err; ?></span>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role" class="form-control <?php echo (!empty($role_err)) ? 'is-invalid' : ''; ?>">
                                    <option value="">Select Role</option>
                                    <option value="student">Student</option>
                                    <option value="professor">Professor</option>
                                    <option value="admin">Admin</option>
                                </select>
                                <span class="invalid-feedback"><?php echo $role_err; ?></span>
                            </div>
                            <div class="form-group">
                                <label>RFID Tag</label>
                                <div class="input-group">
                                    <input type="text" name="rfid_tag" id="rfid_tag" class="form-control <?php echo (!empty($rfid_tag_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $rfid_tag; ?>">
                                    <div class="input-group-append">
                                        <button type="button" id="scan-button" class="btn btn-primary">Scan</button>
                                    </div>
                                </div>
                                <span class="invalid-feedback"><?php echo $rfid_tag_err; ?></span>
                            </div>
                            <div class="form-group">
                                <input type="submit" class="btn btn-primary" value="Submit">
                            </div>
                            <p>Already have an account? <a href="login.php">Login here</a>.</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            var ws;

            $('#scan-button').click(function() {
                ws = new WebSocket('ws://192.168.1.113:3000/');

                ws.onopen = function() {
                    console.log('WebSocket connection established');
                    ws.send('start_scan'); // Send start_scan command to Arduino
                };

                ws.onmessage = function(event) {
                    var rfidTag = event.data;
                    console.log('RFID Tag received: ' + rfidTag); // Console log for debugging

                    // Only update the field if the message is an RFID tag
                    if (rfidTag && rfidTag !== 'start_scan' && rfidTag !== 'Invalid RFID Tag') {
                        $('#rfid_tag').val(rfidTag);
                    } else if (rfidTag === 'Invalid RFID Tag') {
                        alert('Invalid RFID Tag');
                    }

                    ws.close();
                };

                ws.onclose = function() {
                    console.log('WebSocket connection closed');
                };

                ws.onerror = function(error) {
                    console.log('WebSocket error: ' + error);
                };
            });
        });
    </script>
</body>
</html>