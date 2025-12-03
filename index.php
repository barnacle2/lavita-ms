<?php
//
// index.php
// This is the main PHP file for the clinic management system.
// It includes the HTML structure and handles a basic page routing system.
// The database connection is handled here.
//
session_start();
// Start output buffering so included pages can send headers safely (e.g., redirects)
ob_start();

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

// Determine application theme from settings table (default vs dark mode)
$appTheme = 'default';
$themeResult = $conn->query("SELECT setting_value FROM settings WHERE setting_name='system_theme_color' LIMIT 1");
if ($themeResult && $themeResult->num_rows > 0) {
    $themeRow = $themeResult->fetch_assoc();
    $appTheme = $themeRow['setting_value'] ?: 'default';
}
$themeClass = ($appTheme === 'dark') ? 'theme-dark' : 'theme-default';

// Get the current page from the URL. Defaults to 'dashboard'.
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Check if the user is logged in
// We only redirect to login if the user is not logged in AND the current page is NOT 'login'
// This allows the login form to be processed when it's submitted.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    if ($page != 'login') {
        $page = 'login';
    }
}

// Simple router to include the correct page based on the URL parameter.
// This is now outside the HTML structure to allow for a full page login screen.
$pageFile = $page . '.php';

// Map page to human-friendly section title for the top header
$sectionTitles = [
    'dashboard' => 'Dashboard',
    'patients' => 'Patient Management',
    'services' => 'Service and Package Management',
    'transactions' => 'Billing Management',
    'inventory' => 'Inventory Management',
    'expenses' => 'Expense Management',
    'analytics' => 'Analytics & Reports',
    'settings_clinic' => 'Clinic Details',
    'settings_users' => 'User Management',
    'settings_system' => 'System Configuration',
];
$activeTitle = $sectionTitles[$page] ?? ucwords(str_replace('_', ' ', $page));

if ($page == 'login' && file_exists($pageFile)) {
    // If the page is login, just include the login file and exit
    include($pageFile);
    exit;
}

// Early API passthroughs before sending any HTML
// Allow patients transaction JSON endpoint to return clean JSON without layout
if ($page === 'patients' && isset($_GET['patient_txn'])) {
    if (file_exists($pageFile)) {
        include($pageFile);
        exit;
    }
}

// Rest of the application HTML
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Vita MS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="script.js" defer></script>
</head>
<style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #2ecc71;
        --background-color: #ecf0f1;
        --card-background: #ffffff;
        --text-color: #34495e;
        --border-color: #e0e0e0;
    }

    .dashboard-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }

    .dashboard-card {
        background-color: var(--card-background);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        border: 1px solid var(--border-color);
        height: 100%;
        display: flex;
        flex-direction: column;
    }

    .dashboard-card h3 {
        margin-top: 0;
        color: var(--primary-color);
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 10px;
        margin-bottom: 15px;
    }

    .dashboard-metrics {
        display: flex;
        justify-content: space-around;
        gap: 20px;
        margin-top: 20px;
    }

    .metric-card {
        flex: 1;
        background-color: var(--card-background);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        text-align: center;
        border-left: 5px solid var(--secondary-color);
    }

    .metric-card h4 {
        margin: 0 0 10px 0;
        color: var(--text-color);
        font-size: 1em;
    }

    .metric-card .metric-value {
        font-size: 2.5em;
        font-weight: bold;
        color: var(--secondary-color);
    }

    .dashboard-list {
        list-style: none;
        padding: 0;
        margin: 0;
        overflow-y: auto;
        /* Make the list scrollable if it gets too long */
        flex-grow: 1;
    }

    .dashboard-list li {
        padding: 10px 0;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .dashboard-list li:last-child {
        border-bottom: none;
    }

    .dashboard-list .patient-name {
        font-weight: bold;
        color: var(--primary-color);
    }

    .dashboard-list .date {
        font-size: 0.9em;
        color: #777;
    }

    .dashboard-list .stock-item {
        color: var(--text-color);
    }

    .dashboard-list .stock-quantity {
        font-weight: bold;
        color: var(--accent-color);
    }

    /* Style for charts to ensure they fill the card */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
        margin-top: 50px;
        /* Pushes the chart to the bottom of the card */
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .dashboard-container {
            grid-template-columns: 1fr;
        }

        .dashboard-metrics {
            flex-direction: column;
        }
    }
</style>

<body class="<?php echo htmlspecialchars($themeClass); ?>">
    <div class="app-container">

        <aside class="sidebar">
            <div class="logo-section" style="display: flex; align-items: center; padding: 15px 20px;">
                <img src="logo.png" alt="La Vita MS Logo" style="height: 40px; margin-right: 10px;">
                <h2 style="margin: 0; font-size: 1.2rem;">La Vita MS</h2>
            </div>
            <nav class="main-nav">
                <ul>
                    <li class="<?php echo ($page == 'dashboard') ? 'active' : ''; ?>">
                        <a href="?page=dashboard">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>

                    <li class="<?php echo ($page == 'patients') ? 'active' : ''; ?>">
                        <a href="?page=patients">
                            <i class="fas fa-user-injured"></i> Patients
                        </a>
                    </li>

                    <li class="<?php echo ($page == 'services') ? 'active' : ''; ?>">
                        <a href="?page=services">
                            <i class="fas fa-hand-holding-medical"></i> Services
                        </a>
                    </li>
                    <li class="<?php echo ($page == 'transactions') ? 'active' : ''; ?>">
                        <a href="?page=transactions">
                            <i class="fas fa-file-invoice-dollar"></i> Billing
                        </a>
                    </li>
                    <li class="<?php echo ($page == 'inventory') ? 'active' : ''; ?>">
                        <a href="?page=inventory">
                            <i class="fas fa-boxes"></i> Inventory
                        </a>
                    </li>
                    <li class="<?php echo ($page == 'expenses') ? 'active' : ''; ?>">
                        <a href="?page=expenses">
                            <i class="fas fa-receipt"></i> Expenses
                        </a>
                    </li>
                    <li class="<?php echo ($page == 'analytics') ? 'active' : ''; ?>">
                        <a href="?page=analytics">
                            <i class="fas fa-chart-line"></i> Reports
                        </a>
                    </li>
                    <li class="nav-header">SETTINGS</li>
                    <li class="<?php echo ($page == 'settings_clinic') ? 'active' : ''; ?>">
                        <a href="?page=settings_clinic">
                            <i class="fas fa-hospital"></i> Clinic Details
                        </a>
                    </li>
                    <li class="<?php echo ($page == 'settings_users') ? 'active' : ''; ?>">
                        <a href="?page=settings_users">
                            <i class="fas fa-user-cog"></i> User Management
                        </a>
                    </li>
                    <li class="<?php echo ($page == 'settings_system') ? 'active' : ''; ?>">
                        <a href="?page=settings_system">
                            <i class="fas fa-cogs"></i> System Configuration
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <div class="main-content-wrapper">
            <header class="top-header"
                style="display:flex; align-items:center; justify-content:space-between; padding: 10px 15px; border-bottom:1px solid var(--border-color); background:#fff;">
                <div class="left-section" style="font-weight:700; font-size:1.1rem; color: #000;">
                    <?php echo htmlspecialchars($activeTitle); ?>
                </div>
                <div class="right-section">
                    <div class="user-profile" id="userProfileDropdown"
                        style="position: relative; display: inline-block; cursor: pointer; padding: 10px 15px;">
                        <div style="display: flex; align-items: center;">
                            <i class="fas fa-user-circle" style="margin-right: 8px;"></i>
                            <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            <i class="fas fa-caret-down" style="margin-left: 5px;"></i>
                        </div>
                        <div id="userDropdown"
                            style="display: none; position: absolute; left: 0; top: 100%; background: white; min-width: 180px; box-shadow: 0px 4px 8px rgba(0,0,0,0.1); z-index: 1000; border-radius: 4px; margin-top: 5px; border: 1px solid #e0e0e0;">
                            <a href="logout.php"
                                style="display: flex; align-items: center; padding: 10px 15px; color: #333; text-decoration: none; transition: background-color 0.2s;"
                                onmouseover="this.style.backgroundColor='#f8f9fa'"
                                onmouseout="this.style.backgroundColor='white'">
                                <i class="fas fa-sign-out-alt"
                                    style="margin-right: 8px; width: 20px; text-align: center;"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                    <script>
                        document.getElementById('userProfileDropdown').addEventListener('click', function () {
                            var dropdown = document.getElementById('userDropdown');
                            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
                        });

                        // Close the dropdown when clicking outside
                        document.addEventListener('click', function (event) {
                            var dropdown = document.getElementById('userDropdown');
                            var userProfile = document.getElementById('userProfileDropdown');
                            if (!userProfile.contains(event.target)) {
                                dropdown.style.display = 'none';
                            }
                        });
                    </script>
                </div>
            </header>

            <main class="main-content">
                <?php
                // Simple router to include the correct page based on the URL parameter.
                $pageFile = $page . '.php';
                if (file_exists($pageFile)) {
                    include($pageFile);
                } else {
                    echo "<h1>Page not found!</h1>";
                }
                ?>
            </main>
        </div>

    </div>
</body>

</html>

<?php
// Close the database connection when the script finishes.
$conn->close();
// Flush output buffer
ob_end_flush();
?>