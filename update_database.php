<?php
//
// update_database.php
// This script adds the sex column to the patients table
//

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "clinic_ms";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Add sex column to patients table
$sql = "ALTER TABLE `patients` ADD COLUMN `sex` ENUM('Male', 'Female', 'Prefer not to say') DEFAULT 'Prefer not to say' AFTER `date_of_birth`";

if ($conn->query($sql) === TRUE) {
    echo "✅ Successfully added sex column to patients table!<br>";
    echo "The column has been added with the following options:<br>";
    echo "- Male<br>";
    echo "- Female<br>";
    echo "- Prefer not to say (default)<br><br>";
    echo "You can now use the Patients section with the new sex field.";
} else {
    echo "❌ Error adding sex column: " . $conn->error;
}

$conn->close();
?>
