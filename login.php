<?php
//
// login.php
// This page provides a login form and handles user authentication.
// It is no longer a standalone page, but a page that gets included by index.php
//
// The session and database connection are already started and handled by index.php
//
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Verify the password against the stored hash
        if (password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php?page=dashboard");
            exit;
        } else {
            $message = "Invalid password.";
        }
    } else {
        $message = "Invalid username.";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Clinic Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-page-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .login-card {
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
        }
        .logo-section {
            margin-bottom: 30px;
        }
        .logo-section img {
            width: 104px;
            height: 104px;
            object-fit: contain;
            margin-bottom: 10px;
            display: inline-block;
        }
        .logo-section h2 {
            font-size: 2rem;
            margin: 0;
            color: #2c3e50;
        }
        .logo-section p {
            color: #7f8c8d;
            margin: 5px 0 0;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }
        .form-group input:focus {
            outline: none;
            border-color: #3498db;
        }
        .login-button {
            width: 100%;
            padding: 15px;
            background-color: #2ecc71;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .login-button:hover {
            background-color: #27ae60;
            transform: translateY(-2px);
        }
        .alert {
            background-color: #ffebee;
            color: #b71c1c;
            border: 1px solid #ef9a9a;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-page-container">
        <div class="login-card">
            <div class="logo-section">
                <img src="logo.png" alt="La Vita MS Logo">
                <h2>La Vita MS</h2>
                <p>Clinic Management System</p>
            </div>
            <?php if ($message): ?>
                <div class="alert">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <form action="index.php?page=login" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" name="login" class="login-button">Login</button>
            </form>
        </div>
    </div>
</body>
</html>
