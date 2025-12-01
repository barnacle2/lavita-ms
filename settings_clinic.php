<?php
//
// settings_clinic.php
// This page provides a user interface for managing the clinic's general details.
//

// Handle form submission for changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_general'])) {
    $clinicName = $_POST['clinic_name'];
    $address = $_POST['address'];
    $phoneNumber = $_POST['phone_number'];
    $email = $_POST['email'];
    $businessHours = $_POST['business_hours'];

    $settingsToSave = [
        'clinic_name' => $clinicName,
        'clinic_address' => $address,
        'clinic_phone_number' => $phoneNumber,
        'clinic_email' => $email,
        'clinic_business_hours' => $businessHours
    ];

    // Ensure settings table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS `settings` (
            `setting_name` VARCHAR(255) NOT NULL PRIMARY KEY,
            `setting_value` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    foreach ($settingsToSave as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        $stmt->execute();
        $stmt->close();
    }

    echo "<div class='alert alert-success'>Clinic Details saved successfully!</div>";
}

// Fetch current settings for display.
$settings = [];
$settingsResult = $conn->query("SELECT setting_name, setting_value FROM settings");
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['setting_name']] = $row['setting_value'];
    }
}
?>
<style>
    .settings-container {
        padding: 20px;
        background-color: #f4f7f9;
        min-height: 100vh;
    }

    .settings-card {
        background-color: #fff;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        max-width: 800px;
        margin: 0 auto;
    }

    .settings-card h2 {
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

    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 16px;
        transition: border-color 0.3s;
        box-sizing: border-box; /* Ensures padding doesn't affect width */
    }

    .form-group input:focus,
    .form-group textarea:focus {
        border-color: #3498db;
        outline: none;
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
    /* Dark mode overrides for Clinic Details */
    body.theme-dark .settings-container {
        background-color: #020617;
    }

    body.theme-dark .settings-container .settings-card {
        background-color: #020617;
        color: #e5e7eb;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.75);
        border: 1px solid #1f2937;
    }

    body.theme-dark .settings-container .settings-card h2 {
        color: #e5e7eb;
        border-bottom-color: #1d4ed8;
    }

    body.theme-dark .settings-container .form-group label {
        color: #e5e7eb;
    }

    body.theme-dark .settings-container .form-group input[type="text"],
    body.theme-dark .settings-container .form-group input[type="email"],
    body.theme-dark .settings-container .form-group textarea {
        background-color: #020617;
        color: #e5e7eb;
        border-color: #374151;
    }
</style>
<div class="settings-container">


    <div class="settings-card">
        <h2>General Details</h2>
        <form action="?page=settings_clinic" method="POST">
            <div class="form-group">
                <label for="clinic-name">Clinic Name:</label>
                <input type="text" id="clinic-name" name="clinic_name" value="<?php echo htmlspecialchars($settings['clinic_name'] ?? ''); ?>" placeholder="Enter clinic name">
            </div>

            <div class="form-group">
                <label for="address">Clinic Address:</label>
                <textarea id="address" name="address" rows="3" placeholder="Enter clinic address"><?php echo htmlspecialchars($settings['clinic_address'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label for="phone-number">Phone Number:</label>
                <input type="text" id="phone-number" name="phone_number" value="<?php echo htmlspecialchars($settings['clinic_phone_number'] ?? ''); ?>" placeholder="Enter phone number">
            </div>

            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($settings['clinic_email'] ?? ''); ?>" placeholder="Enter email address">
            </div>

            <div class="form-group">
                <label for="business-hours">Business Hours:</label>
                <input type="text" id="business-hours" name="business_hours" value="<?php echo htmlspecialchars($settings['clinic_business_hours'] ?? ''); ?>" placeholder="e.g., Mon-Fri, 9am-5pm">
            </div>

            <button type="submit" name="save_general" class="styled-btn primary">Save Changes</button>
        </form>
    </div>
</div>
