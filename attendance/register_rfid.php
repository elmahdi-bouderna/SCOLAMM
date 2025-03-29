<?php
require_once "config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $rfid_tag = trim($_POST["rfid_tag"]);

    // Check if RFID tag already exists
    $sql = "SELECT id FROM users WHERE rfid_tag = ?";
    if ($stmt = $link->prepare($sql)) {
        $stmt->bind_param("s", $param_rfid_tag);
        $param_rfid_tag = $rfid_tag;
        if ($stmt->execute()) {
            $stmt->store_result();
            if ($stmt->num_rows == 0) {
                // RFID tag does not exist, insert new record
                $sql_insert = "INSERT INTO users (rfid_tag) VALUES (?)";
                if ($stmt_insert = $link->prepare($sql_insert)) {
                    $stmt_insert->bind_param("s", $param_rfid_tag);
                    $param_rfid_tag = $rfid_tag;
                    if ($stmt_insert->execute()) {
                        echo "RFID tag registered successfully.";
                    } else {
                        echo "Error: Could not register RFID tag.";
                    }
                    $stmt_insert->close();
                }
            } else {
                echo "RFID tag already exists.";
            }
        } else {
            echo "Error: Could not execute query.";
        }
        $stmt->close();
    }
    $link->close();
} else {
    echo "Invalid request method.";
}
?>