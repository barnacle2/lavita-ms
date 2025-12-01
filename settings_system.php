<?php
//
// settings_system.php
// This page provides a user interface for managing system-wide settings.
//

// Handle form submission for changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_system'])) {
    $taxRate = $_POST['tax_rate'];
    $defaultTheme = $_POST['theme_color'];

    // Upsert logic for system settings
    $settingsToSave = [
        'system_tax_rate' => $taxRate,
        'system_theme_color' => $defaultTheme
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

    echo "<div class='alert alert-success'>System Settings saved successfully!</div>";
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

<div class="settings-container">
    <p>Configure system-wide settings here, such as tax rates and default themes.</p>

    <div class="card">
        <div class="card-header">System Settings</div>
        <div class="card-body">
            <form action="?page=settings_system" method="POST">
                <div class="form-group">
                    <label for="tax-rate">Default Tax Rate (%):</label>
                    <input type="number" id="tax-rate" name="tax_rate" value="<?php echo htmlspecialchars($settings['system_tax_rate'] ?? '0'); ?>" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label for="theme-color">Application Theme:</label>
                    <select id="theme-color" name="theme_color">
                        <option value="default" <?php echo ($settings['system_theme_color'] ?? '') == 'default' ? 'selected' : ''; ?>>Default</option>
                        <option value="dark" <?php echo ($settings['system_theme_color'] ?? '') == 'dark' ? 'selected' : ''; ?>>Dark Mode</option>
                    </select>
                </div>
                <button type="submit" name="save_system" class="btn btn-primary" style="border-radius:999px; padding:10px 26px; font-size:0.95rem; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-save"></i>
                    <span>Save System Settings</span>
                </button>
            </form>
        </div>
    </div>
</div>
