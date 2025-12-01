<?php
//
// dashboard.php
// This page provides a modern, functional dashboard with key metrics and graphs,
// focusing exclusively on TODAY's data for immediate operational insight.
//
// The $conn variable is available from the main index.php file.

// --- Set Date Range to Today Only ---
// Note: We are using 2025-11-16 based on the provided sample data for testing.
// For a live system, use: $today = date('Y-m-d');
$today = date('Y-m-d'); // This is the correct line for a live system
// If you want to force the data from the SQL dump, use: $today = '2025-11-16';

$startOfDay = $today . ' 00:00:00'; // Start of today
$endOfDay = $today . ' 23:59:59';   // End of today

// Display for the header (Today's Date)
$displayDate = date('F j, Y', strtotime($today));

// --- 1. Patients Metrics ---
// Get count of new patients registered today
// NOTE: Assuming 'date_registered' in the 'patients' table is a DATETIME field,
// if it is a DATE field, you can simplify the WHERE clause.
$newPatientsQuery = "SELECT COUNT(*) AS new_patients_today FROM patients ";
$newPatientsQuery .= "WHERE date_registered >= ? AND date_registered <= ?";
$stmt = $conn->prepare($newPatientsQuery);
$stmt->bind_param('ss', $startOfDay, $endOfDay);
$stmt->execute();
$patientsResult = $stmt->get_result();
$newPatientsToday = $patientsResult->fetch_assoc()['new_patients_today'] ?? 0;
$stmt->close();

// --- 2. Inventory Metrics (UPDATED LOGIC) ---
// Get total items in stock (SUM of stock_quantity)
$totalStockQuery = "SELECT SUM(stock_quantity) AS total_items FROM inventory";
$totalStockResult = $conn->query($totalStockQuery);
$totalItemsInStock = $totalStockResult->fetch_assoc()['total_items'] ?? 0;

// --- UNIT-SPECIFIC LOW STOCK THRESHOLDS (Copied from inventory.php) ---
// This block is necessary to make the dashboard counts accurate to the inventory page logic.
$low_stock_thresholds = [
    'box' => 5,
    'boxes' => 5,
    'bottle' => 3,
    'bottles' => 3,
    'pcs' => 10,
    'pieces' => 10, 
    'pack' => 5,
    'packs' => 5,
];
$default_threshold = 10; // Default threshold for unlisted unit types

// --- Calculate Low Stock and Critical Stock using unit-specific thresholds ---
// Fetch all inventory items and apply the same logic used on the Inventory page,
// including any per-item custom low_stock_threshold values.
$inventoryCheckResult = $conn->query("SELECT item_name, stock_quantity, unit_type, low_stock_threshold FROM inventory");
$lowStockItemsCount = 0; // Count of low stock items based on unit-specific thresholds
$criticalItemsCount = 0; // Count of items below the fixed critical threshold
$criticalThreshold = 10; 
$lowStockItems = [];      // Detailed list for the dashboard card

if ($inventoryCheckResult) {
    while ($row = $inventoryCheckResult->fetch_assoc()) {
        $unit_type_lower = strtolower($row['unit_type']);

        // 1. Low Stock Check (Unit-Specific + optional per-item override)
        // Prefer per-item custom threshold if set, otherwise fall back to unit/default
        if (isset($row['low_stock_threshold']) && $row['low_stock_threshold'] !== null && $row['low_stock_threshold'] !== '') {
            $low_stock_threshold = (int)$row['low_stock_threshold'];
        } else {
            $low_stock_threshold = $low_stock_thresholds[$unit_type_lower] ?? $default_threshold;
        }
        if ($row['stock_quantity'] <= $low_stock_threshold) {
            $lowStockItemsCount++;
            $lowStockItems[] = [
                'item_name' => $row['item_name'],
                'stock_quantity' => $row['stock_quantity']
            ];
        }

        // 2. Critical Stock Check (Fixed <10 for the Critical Card)
        if ($row['stock_quantity'] < $criticalThreshold) {
            $criticalItemsCount++;
        }
    }
}


// --- 3. Revenue / Billing Summary ---
// Today's Earnings (Total amount from transactions today)
// NOTE: Using 'transaction_date' as the filter field.
$earningsQuery = "SELECT SUM(total_amount) AS todays_earnings FROM transactions ";
$earningsQuery .= "WHERE transaction_date >= ? AND transaction_date <= ?";
$stmt = $conn->prepare($earningsQuery);
$stmt->bind_param('ss', $startOfDay, $endOfDay);
$stmt->execute();
$earningsResult = $stmt->get_result();
$todaysEarnings = $earningsResult->fetch_assoc()['todays_earnings'] ?? 0;
$stmt->close();


// --- 4. Services Rendered Today ---
// Total number of service/consultation transactions today
// NOTE: We rely on a separate `transaction_services` table which is not in the dump,
// so we will count unique transaction IDs from the `transactions` table that occurred today.
// A more accurate count relies on the `transaction_services` table existing.
$serviceTxnCountQuery = "SELECT COUNT(DISTINCT transaction_id) AS total_service_txns FROM transactions
                         WHERE transaction_date >= ? AND transaction_date <= ?";
$stmt = $conn->prepare($serviceTxnCountQuery);
$stmt->bind_param('ss', $startOfDay, $endOfDay);
$stmt->execute();
$txnResult = $stmt->get_result();
$serviceTxnCount = $txnResult->fetch_assoc()['total_service_txns'] ?? 0;
$stmt->close();

// Top service rendered today (Logic must be adjusted as `services` is a comma-separated string)
// This is a complex calculation without a linking table (`transaction_services`).
// We will parse the `services` column for transactions today to find the top service.
$allServicesToday = [];
$serviceParseQuery = "SELECT services FROM transactions WHERE transaction_date >= ? AND transaction_date <= ?";
$stmt = $conn->prepare($serviceParseQuery);
$stmt->bind_param('ss', $startOfDay, $endOfDay);
$stmt->execute();
$parseResult = $stmt->get_result();
while ($row = $parseResult->fetch_assoc()) {
    $servicesArray = explode(', ', $row['services']);
    foreach ($servicesArray as $service) {
        $service = trim($service);
        if (!empty($service)) {
            $allServicesToday[$service] = ($allServicesToday[$service] ?? 0) + 1;
        }
    }
}
$stmt->close();


$topServiceName = 'N/A';
if (!empty($allServicesToday)) {
    arsort($allServicesToday);
    $topServiceName = array_key_first($allServicesToday);
}


// Note: $lowStockItems is now built together with the unit-specific low stock logic above,
// so the dashboard list exactly matches the configured thresholds.

// Get the 5 most recently added patients for TODAY only
$recentPatientsStmt = $conn->prepare("SELECT fullname, date_registered FROM patients WHERE date_registered >= ? AND date_registered <= ? ORDER BY date_registered DESC LIMIT 5");
$recentPatientsStmt->bind_param('ss', $startOfDay, $endOfDay);
$recentPatientsStmt->execute();
$recentPatientsResult = $recentPatientsStmt->get_result();
$recentPatients = [];
if ($recentPatientsResult) {
    while ($row = $recentPatientsResult->fetch_assoc()) {
        $recentPatients[] = $row;
    }
}
if (isset($recentPatientsStmt)) { $recentPatientsStmt->close(); }


// --- Chart Data (Today's Focus) ---

// Sales by Service Today (Using the count derived above for the chart)
$serviceLabels = array_keys($allServicesToday);
$serviceSalesData = array_values($allServicesToday);


// Billing for Services Today (Top 5 by revenue)
$serviceBillingData = [];
$serviceBillingLabels = [];

// Use transaction_services + services joined to transactions filtered to today
$svcBillQuery = $conn->prepare("SELECT s.service_name, SUM(ts.price_at_transaction) AS total_revenue
                                FROM transactions t
                                JOIN transaction_services ts ON t.transaction_id = ts.transaction_id
                                JOIN services s ON ts.service_id = s.id
                                WHERE t.transaction_date >= ? AND t.transaction_date <= ?
                                GROUP BY s.service_name");
$svcBillQuery->bind_param('ss', $startOfDay, $endOfDay);
$svcBillQuery->execute();
$svcBillResult = $svcBillQuery->get_result();

if ($svcBillResult) {
    while ($row = $svcBillResult->fetch_assoc()) {
        $name = $row['service_name'] ?? 'Unknown Service';
        $total = (float)($row['total_revenue'] ?? 0);
        $serviceBillingData[$name] = $total;
    }
}
$svcBillQuery->close();

arsort($serviceBillingData); // Sort by highest revenue first
$serviceBillingData = array_slice($serviceBillingData, 0, 5, true); // Top 5 services
$serviceBillingLabels = array_keys($serviceBillingData);
$serviceBillingValues = array_values($serviceBillingData);

?>
<style>
/* Redesigned Dashboard Styles */
:root {
    --card-bg: #ffffff;
    --card-border: #e9edf2;
    --text-muted: #6b7280;
    --text-strong: #111827;
    --shadow-soft: 0 8px 24px rgba(0,0,0,0.06);
}

.content-header h1 {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-strong);
    letter-spacing: .2px;
}

/* KPI cards grid */
.dashboard-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.metric-card {
    position: relative;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    border: 1px solid var(--card-border);
    border-radius: 12px;
    padding: 18px;
    box-shadow: var(--shadow-soft);
    transition: transform .15s ease, box-shadow .15s ease;
    display: grid;
    grid-template-rows: auto auto;
    row-gap: 8px;
}
.metric-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.08);} 

.metric-icon {
    width: 44px; height: 44px; display: grid; place-items: center;
    border-radius: 10px; color: #fff; font-size: 20px;
    background: linear-gradient(135deg, #4f46e5, #06b6d4);
}
.metric-card.low-stock .metric-icon { background: linear-gradient(135deg, #f59e0b, #fbbf24);} 
.metric-card.critical .metric-icon { background: linear-gradient(135deg, #ef4444, #f87171);} 

.metric-card h4 { margin: 0; font-size: 12px; text-transform: uppercase; color: var(--text-muted); letter-spacing: .6px; }
.metric-value {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-strong);
}

.metric-number {
    font-weight: 800;
    font-size: clamp(20px, 2.4vw, 28px);
    line-height: 1;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Panels grid */
.dashboard-container { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 992px){ .dashboard-container { grid-template-columns: 1fr; } }

.dashboard-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 12px;
    padding: 18px;
    box-shadow: var(--shadow-soft);
}
.dashboard-card h3 { margin: 0 0 10px 0; font-size: 15px; color: var(--text-strong); border-bottom: 1px solid #eef2f6; padding-bottom: 8px; }

.chart-container { height: 300px; width: 100%; margin-top: 10px; }

.dashboard-list { list-style: none; margin: 0; padding: 0; }
.dashboard-list li { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px dashed #eef2f6; }
.dashboard-list li:last-child { border-bottom: none; }
.stock-item { font-weight: 600; color: var(--text-strong); }
.stock-quantity { color: #ef4444; font-weight: 700; }

/* Color accents for numeric values in KPI cards */
.metric-card[style*="1cc88a"] .metric-icon { background: linear-gradient(135deg, #10b981, #34d399); }
.metric-card[style*="36b9cc"] .metric-icon { background: linear-gradient(135deg, #06b6d4, #22d3ee); }
.metric-card[style*="f6c23e"] .metric-icon { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
</style>
<div class="content-header">
    <h1>Overview - <?php echo $displayDate; ?></h1>
</div>

<div class="dashboard-metrics">
    <div class="metric-card">
        <h4>New Patients Today</h4>
        <div class="metric-value">
            <i class="fas fa-user-plus metric-icon"></i>
            <span class="metric-number"><?php echo $newPatientsToday; ?></span>
        </div>
    </div>
    
    <div class="metric-card" style="border-left-color: #1cc88a;">
        <h4>Today's Earnings (₱)</h4>
        <div class="metric-value" style="color: #1cc88a;">
            <i class="fas fa-money-bill-wave metric-icon" style="color: #eef2f6;"></i>
            <span class="metric-number"><?php echo number_format($todaysEarnings, 2); ?></span>
        </div>
    </div>
    
    <div class="metric-card" style="border-left-color: #36b9cc;">
        <h4>Total Transactions Today</h4>
        <div class="metric-value" style="color: #36b9cc;">
            <i class="fas fa-handshake metric-icon" style="color: #eef2f6;"></i>
            <span class="metric-number"><?php echo $serviceTxnCount; ?></span>
        </div>
        <small class="text-muted" style="margin-top: 5px;">Top Service: <strong><?php echo htmlspecialchars($topServiceName); ?></strong></small>
    </div>

    <div class="metric-card" style="border-left-color: #f6c23e;">
        <h4>Total Stock Items</h4>
        <div class="metric-value" style="color: #f6c23e;">
            <i class="fas fa-boxes metric-icon" style="color: #eef2f6;"></i>
            <span class="metric-number"><?php echo $totalItemsInStock; ?></span>
        </div>
    </div>
    
    <div class="metric-card low-stock">
        <h4>Low Stock Items (Unit-Specific)</h4>
        <div class="metric-value">
            <i class="fas fa-exclamation-triangle metric-icon"></i>
            <span class="metric-number"><?php echo $lowStockItemsCount; ?></span>
        </div>
    </div>

    <div class="metric-card critical">
        <h4>Critical Stock (<10 Units)</h4>
        <div class="metric-value">
            <i class="fas fa-skull-crossbones metric-icon"></i>
            <span class="metric-number"><?php echo $criticalItemsCount; ?></span>
        </div>
    </div>
</div>

<div class="dashboard-container">
    <div class="dashboard-card">
        <h3>Today's Service Frequency</h3>
        <?php if (!empty($serviceLabels)): ?>
        <div class="chart-container">
            <canvas id="serviceSalesChart"></canvas>
        </div>
        <?php else: ?>
            <p>No services recorded today.</p>
        <?php endif; ?>
    </div>
    
    <div class="dashboard-card">
        <h3>Billing for Services (Top 5)</h3>
        <?php if (!empty($serviceBillingValues)): ?>
        <div class="chart-container">
            <canvas id="inventorySalesChart"></canvas>
        </div>
        <?php else: ?>
            <p>No service billings recorded today.</p>
        <?php endif; ?>
    </div>
    
    <div class="dashboard-card">
        <h3>Low Stock Inventory</h3>
        <ul class="dashboard-list">
            <?php if (empty($lowStockItems)): ?>
                <li>All stock levels are healthy!</li>
            <?php else: ?>
                <?php foreach ($lowStockItems as $item): ?>
                    <li>
                        <span class="stock-item"><?php echo htmlspecialchars($item['item_name']); ?></span>
                        <span class="stock-quantity"><?php echo htmlspecialchars($item['stock_quantity']); ?> left</span>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
    
    <div class="dashboard-card dashboard-section recent-patients-list">
        <h3>Recent Patients</h3>
        <?php if (!empty($recentPatients)): ?>
        <ul>
            <?php foreach ($recentPatients as $patient): ?>
                <li>
                    <strong><?php echo htmlspecialchars($patient['fullname']); ?></strong>
                    <br>
                    <small>Registered on: <?php echo date('M d, Y', strtotime($patient['date_registered'])); ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <p>No recent patients to display.</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// No date picker logic needed for a "Today" dashboard
</script>
<script>
    // --- Sales by Service Chart (Today) ---
    const serviceLabels = <?php echo json_encode($serviceLabels); ?>;
    const serviceSalesData = <?php echo json_encode($serviceSalesData); ?>;

    if (serviceLabels.length > 0) {
        const serviceSalesCtx = document.getElementById('serviceSalesChart').getContext('2d');
        new Chart(serviceSalesCtx, {
            type: 'pie',
            data: {
                labels: serviceLabels,
                datasets: [{
                    label: 'Service Frequency',
                    data: serviceSalesData,
                    backgroundColor: [
                        '#2ecc71',
                        '#3498db',
                        '#e74c3c',
                        '#f1c40f',
                        '#9b59b6',
                        '#1abc9c',
                        '#95a5a6'
                    ],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
            }
        });
    }
    
    // --- Billing for Services Chart (Today's Top 5) ---
    const inventorySalesLabels = <?php echo json_encode($serviceBillingLabels); ?>;
    const inventorySalesValues = <?php echo json_encode($serviceBillingValues); ?>;
    
    if (inventorySalesLabels.length > 0) {
        const inventorySalesCtx = document.getElementById('inventorySalesChart').getContext('2d');
        new Chart(inventorySalesCtx, {
            type: 'bar',
            data: {
                labels: inventorySalesLabels,
                datasets: [{
                    label: 'Billing by Service (₱)',
                    data: inventorySalesValues,
                    backgroundColor: 'rgba(52, 152, 219, 0.7)',
                    borderColor: 'rgba(52, 152, 219, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y', // Makes it a horizontal bar chart
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
</script>