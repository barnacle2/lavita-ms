<?php
//
// settings_users.php
// This page provides a user interface for managing user accounts.
//

// Check if user is logged in and is an admin
$isAdmin = isset($_SESSION['loggedin']) && $_SESSION['role'] === 'admin';

// Completely block staff/cashier from accessing this page
if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
    header('Location: index.php?page=dashboard');
    exit();
}
$blurStyle = $isAdmin ? '' : 'style="filter: blur(5px); pointer-events: none;"';
$accessDenied = !$isAdmin;

//
// Ensure the users table exists before any operations.
// NOTE: For production, you should use password hashing (e.g., password_hash).
// This is for demonstration purposes.
//
$conn->query("
    CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(255) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'staff',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// --- Handle Form Submissions ---
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $role = $_POST['role'];

        if (!empty($username) && !empty($password)) {
            // Check if the username already exists to prevent duplicate key errors
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->bind_param("s", $username);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $message = "Error: A user with that username already exists.";
                $messageType = 'error';
            } else {
                // In a real application, you should hash the password before saving it.
                // $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $username, $hashed_password, $role);
                if ($stmt->execute()) {
                    $message = "User '{$username}' added successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error: Could not add user.";
                    $messageType = 'error';
                }
                $stmt->close();
            }
            $checkStmt->close();
        }
    }

    if (isset($_POST['edit_user'])) {
        $id = $_POST['edit_id'];
        $username = $_POST['edit_username'];
        $role = $_POST['edit_role'];
        $password = $_POST['edit_password'];

        if (!empty($id) && !empty($username)) {
            // Check if the new username already exists for another user
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $checkStmt->bind_param("si", $username, $id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $message = "Error: A user with that username already exists.";
                $messageType = 'error';
            } else {
                if (!empty($password)) {
                    // Update with new password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $username, $hashed_password, $role, $id);
                } else {
                    // Update without changing password
                    $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $username, $role, $id);
                }

                if ($stmt->execute()) {
                    $message = "User '{$username}' updated successfully!";
                    $messageType = 'success';
                } else {
                    $message = "Error: Could not update user.";
                    $messageType = 'error';
                }
                $stmt->close();
            }
            $checkStmt->close();
        }
    }

    if (isset($_POST['delete_user'])) {
        $id = $_POST['delete_id'];
        if (!empty($id)) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $message = "User deleted successfully!";
                $messageType = 'success';
            } else {
                $message = "Error: Could not delete user.";
                $messageType = 'error';
            }
            $stmt->close();
        }
    }
}

// --- Fetch all users from the database ---
$users = [];
$usersResult = $conn->query("SELECT id, username, role, created_at FROM users ORDER BY created_at DESC");
if ($usersResult) {
    while ($row = $usersResult->fetch_assoc()) {
        $users[] = $row;
    }
}
?>

<style>
/* User Management Styles */
.settings-container {
    padding: 20px;
    background-color: #f4f7f9;
    min-height: 100vh;
    position: relative;
}

.access-denied-message {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background-color: rgba(255, 0, 0, 0.8);
    color: white;
    padding: 20px 40px;
    border-radius: 5px;
    z-index: 1000;
    text-align: center;
    font-size: 1.2em;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.blur-content {
    transition: filter 0.3s ease;
}

.user-management-card {
    background-color: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    margin-bottom: 20px;
}

.user-management-card h2 {
    color: #34495e;
    font-size: 24px;
    margin-bottom: 25px;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #555;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 16px;
    transition: border-color 0.3s;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #3498db;
    outline: none;
}

.form-actions {
    display: flex;
    justify-content: flex-start;
    gap: 10px;
    margin-top: 25px;
}

.styled-btn {
    padding: 12px 25px;
    border: none;
    border-radius: 5px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.3s, transform 0.2s;
}

.styled-btn.primary {
    background-color: #3498db;
    color: #fff;
}

.styled-btn.primary:hover {
    background-color: #2980b9;
    transform: translateY(-2px);
}

.styled-btn.secondary {
    background-color: #95a5a6;
    color: #fff;
}

.styled-btn.secondary:hover {
    background-color: #7f8c8d;
    transform: translateY(-2px);
}

.user-list-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.user-list-table th,
.user-list-table td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid #eef1f4;
}

.user-list-table th {
    background-color: #ecf0f1;
    color: #34495e;
    font-weight: 700;
}

.user-list-table tr:hover {
    background-color: #f8f9fa;
}

.user-list-table td .action-btn {
    display: inline-block;
    padding: 8px;
    margin-right: 5px;
    border-radius: 4px;
    color: #fff;
    text-decoration: none;
    transition: background-color 0.3s;
}

.user-list-table td .action-btn.edit {
    background-color: #f39c12;
}

.user-list-table td .action-btn.edit:hover {
    background-color: #e67e22;
}

.user-list-table td .action-btn.delete {
    background-color: #e74c3c;
}

.user-list-table td .action-btn.delete:hover {
    background-color: #c0392b;
}

.message-box.success {
    background-color: #d4edda;
    color: #155724;
    border-color: #c3e6cb;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.message-box.error {
    background-color: #f8d7da;
    color: #721c24;
    border-color: #f5c6cb;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}
/* Dark mode overrides for User Management */
body.theme-dark .settings-container {
    background-color: #020617;
}

body.theme-dark .settings-container .user-management-card {
    background-color: #020617;
    color: #e5e7eb;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.75);
    border: 1px solid #1f2937;
}

body.theme-dark .settings-container .user-management-card h2 {
    color: #e5e7eb;
    border-bottom-color: #1d4ed8;
}

body.theme-dark .settings-container .form-group label {
    color: #e5e7eb;
}

body.theme-dark .settings-container .form-group input,
body.theme-dark .settings-container .form-group select {
    background-color: #020617;
    color: #e5e7eb;
    border-color: #374151;
}

body.theme-dark .settings-container .user-list-table thead tr {
    background-color: #111827 !important;
}

body.theme-dark .settings-container .user-list-table th,
body.theme-dark .settings-container .user-list-table td {
    background-color: #111827 !important;
    color: #e5e7eb !important;
    border-bottom-color: #1f2937 !important;
}

body.theme-dark .settings-container .user-list-table tr:hover {
    background-color: rgba(31, 41, 55, 0.9) !important;
}

body.theme-dark .modal-content {
    background-color: #020617;
    color: #e5e7eb;
    border-color: #1f2937;
}
/* Modal background */
.modal {
    display: none; /* Hidden by default */
    position: fixed; /* Stay in place */
    z-index: 1000; /* Sit on top */
    left: 0;
    top: 0;
    width: 100%; /* Full width */
    height: 100%; /* Full height */
    background-color: rgba(0, 0, 0, 0.4); /* Black w/ opacity */
    justify-content: center;
    align-items: center;
}

/* Modal content box */
.modal-content {
    background-color: #fefefe;
    margin: auto;
    padding: 30px;
    border: 1px solid #888;
    width: 90%; /* Could be more responsive */
    max-width: 500px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    position: relative;
    animation-name: animatetop;
    animation-duration: 0.4s;
}

/* Close button */
.close-btn {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    position: absolute;
    top: 10px;
    right: 20px;
}

.close-btn:hover,
.close-btn:focus {
    color: #000;
    text-decoration: none;
    cursor: pointer;
}

/* Add animation for the modal */
@keyframes animatetop {
    from {
        top: -300px;
        opacity: 0
    }

    to {
        top: 0;
        opacity: 1
    }
}
</style>

<?php if ($accessDenied): ?>
    <div class="access-denied-message">
        <i class="fas fa-ban" style="font-size: 2em; margin-bottom: 10px; color: #fff;"></i>
        <h3>Access Denied</h3>
        <p>You are forbidden to access this section.</p>
        <p>Please contact an administrator if you believe this is an error.</p>
    </div>
<?php endif; ?>

<div class="settings-container">
    <div class="blur-content" <?php echo $blurStyle; ?>>
    
    <?php if (!empty($message)): ?>
        <div class="message-box <?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="user-management-card">
        <h2>Add New User</h2>
        <form action="?page=settings_users" method="POST">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="role">Role:</label>
                <select id="role" name="role">
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" name="add_user" class="styled-btn primary">Add User</button>
            </div>
        </form>
    </div>

    <div class="user-management-card">
        <h2>Existing Users</h2>
        <?php if (!empty($users)): ?>
        <table class="user-list-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['role']); ?></td>
                        <td><?php echo htmlspecialchars(date('F j, Y', strtotime($user['created_at']))); ?></td>
                        <td>
                            <a href="#" class="action-btn edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)"><i class="fas fa-edit"></i></a>
                            <a href="#" class="action-btn delete" onclick="confirmDelete(<?php echo htmlspecialchars($user['id']); ?>)"><i class="fas fa-trash-alt"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p>No users found. Add a new user to get started.</p>
        <?php endif; ?>
    </div>
</div>

<div id="editUserModal" class="modal">
    <div class="modal-content">
        <span class="close-btn">&times;</span>
        <h2>Edit User</h2>
        <form action="?page=settings_users" method="POST">
            <input type="hidden" id="editUserId" name="edit_id">
            <div class="form-group">
                <label for="editUsername">Username:</label>
                <input type="text" id="editUsername" name="edit_username" required>
            </div>
            <div class="form-group">
                <label for="editRole">Role:</label>
                <select id="editRole" name="edit_role">
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label for="editPassword">New Password (optional):</label>
                <input type="password" id="editPassword" name="edit_password">
            </div>
            <div class="form-actions">
                <button type="submit" name="edit_user" class="styled-btn primary">Save Changes</button>
                <button type="button" class="styled-btn secondary" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteUserForm" action="?page=settings_users" method="POST" style="display: none;">
    <input type="hidden" id="deleteUserId" name="delete_id">
    <input type="hidden" name="delete_user" value="1">
</form>

<script>
    function openEditModal(user) {
        document.getElementById('editUserId').value = user.id;
        document.getElementById('editUsername').value = user.username;
        document.getElementById('editRole').value = user.role;
        document.getElementById('editUserModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editUserModal').style.display = 'none';
        document.getElementById('editPassword').value = ''; // Clear password field
    }

    function confirmDelete(userId) {
        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserForm').submit();
        }
    }

    // Modal behavior for closing
    document.querySelector('#editUserModal .close-btn').addEventListener('click', closeEditModal);
    window.addEventListener('click', (event) => {
        if (event.target === document.getElementById('editUserModal')) {
            closeEditModal();
        }
    });
</script>