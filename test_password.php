<?php
// Script to test if your password and hash are correct.
// Make sure to replace the placeholder values.

// Replace this with the plain text password you are trying to use.
$plainTextPassword = 'cashier123';

// Replace this with the hashed password from your database.
$hashedPasswordFromDb = '$2y$10$1h9M1D9o2O9l5J7k8v8L5K1N0t5E4a6G7B2F8H2C1B8V4E8D7T';

if (password_verify($plainTextPassword, $hashedPasswordFromDb)) {
    echo "Success! The password and hash match. The issue is not with the hash itself.";
} else {
    echo "Failure! The password and hash do NOT match. This could mean the password is wrong, or the hash was created with an incompatible method.";
}
?>
