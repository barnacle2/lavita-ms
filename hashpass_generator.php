<?php
// Script to generate a password hash for a new user.
// Replace 'your_new_password' with the password you want to use.
$passwordToHash = 'cashier123';

// Generate a secure hash
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

echo "The new hashed password is: <br><br>";
echo htmlspecialchars($hashedPassword);
?>
