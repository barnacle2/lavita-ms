<?php
//
// print_report.php
// This file generates a printable analytics report sheet.
//
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

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

// Get report parameters
$reportType = $_GET['type'] ?? 'monthly';
$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate = $_GET['end'] ?? date('Y-m-t');

// Format display dates
$displayStartDate = date('F d, Y', strtotime($startDate));
$displayEndDate = date('F d, Y', strtotime($endDate));

// Get patient statistics
$patientQuery = "SELECT 
    COUNT(*) as total_patients,
    COUNT(CASE WHEN DATE(date_registered) BETWEEN ? AND ? THEN 1 END) as new_patients
    FROM patients";
$stmt = $conn->prepare($patientQuery);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$patientResult = $stmt->get_result();
$patientStats = $patientResult->fetch_assoc();
$stmt->close();

// Get transaction statistics
$transactionQuery = "SELECT 
    COUNT(*) as total_transactions,
    SUM(total_amount) as total_revenue,
    AVG(total_amount) as average_transaction
    FROM transactions 
    WHERE transaction_date BETWEEN ? AND ?";
$stmt = $conn->prepare($transactionQuery);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$transactionResult = $stmt->get_result();
$transactionStats = $transactionResult->fetch_assoc();
$stmt->close();

// Get service performance (individual only)
$serviceQuery = "SELECT 
    s.service_name,
    COUNT(ts.service_id) as service_count,
    SUM(ts.price_at_transaction) as service_revenue
    FROM transaction_services ts
    JOIN services s ON ts.service_id = s.id
    JOIN transactions t ON ts.transaction_id = t.transaction_id
    WHERE t.transaction_date BETWEEN ? AND ? AND s.service_type='individual'
    GROUP BY s.service_name
    ORDER BY service_revenue DESC";
$stmt = $conn->prepare($serviceQuery);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$serviceResult = $stmt->get_result();
$serviceStats = [];
while ($row = $serviceResult->fetch_assoc()) {
    $serviceStats[] = $row;
}
$stmt->close();

// Get package services performance
$pkgQuery = "SELECT 
    s.service_name,
    COUNT(ts.service_id) as service_count,
    SUM(ts.price_at_transaction) as service_revenue
    FROM transaction_services ts
    JOIN services s ON ts.service_id = s.id
    JOIN transactions t ON ts.transaction_id = t.transaction_id
    WHERE t.transaction_date BETWEEN ? AND ? AND s.service_type='package'
    GROUP BY s.service_name
    ORDER BY service_revenue DESC";
$stmt = $conn->prepare($pkgQuery);
$stmt->bind_param('ss', $startDate, $endDate);
$stmt->execute();
$pkgResult = $stmt->get_result();
$packageServiceStats = [];
while ($row = $pkgResult->fetch_assoc()) {
    $packageServiceStats[] = $row;
}
$stmt->close();

// Build "Services Only" usage stats (counts for individual services,
// including when they are part of package services)
$serviceOnlyStats = [];
try {
    // Start with direct individual services from $serviceStats
    $serviceUsage = [];
    foreach ($serviceStats as $svcRow) {
        $name = (string)$svcRow['service_name'];
        $cnt  = (int)($svcRow['service_count'] ?? 0);
        if (!isset($serviceUsage[$name])) {
            $serviceUsage[$name] = 0;
        }
        $serviceUsage[$name] += $cnt;
    }

    // Map of all services (needed to resolve package contents)
    $allServices = [];
    $allSvcRes = $conn->query("SELECT id, service_name, service_type, description FROM services");
    if ($allSvcRes) {
        while ($r = $allSvcRes->fetch_assoc()) {
            $allServices[(int)$r['id']] = $r;
        }
        $allSvcRes->close();
    }

    // Pre-parse package -> inner individual services
    $packageContents = [];
    foreach ($allServices as $sid => $svc) {
        if (($svc['service_type'] ?? '') !== 'package') {
            continue;
        }
        $ids = [];
        if (!empty($svc['description']) && preg_match('/Package contents:\\s*([0-9, ]+)/', $svc['description'], $m)) {
            $ids = array_filter(array_map('intval', explode(',', $m[1])));
        }
        if ($ids) {
            $packageContents[$sid] = $ids;
        }
    }

    // Count how many times each package was used in transactions
    $pkgUsageQuery = "SELECT ts.service_id, COUNT(*) AS use_count
                      FROM transaction_services ts
                      JOIN services s ON ts.service_id = s.id
                      JOIN transactions t ON ts.transaction_id = t.transaction_id
                      WHERE t.transaction_date BETWEEN ? AND ?
                        AND s.service_type = 'package'
                      GROUP BY ts.service_id";
    $stmt = $conn->prepare($pkgUsageQuery);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $pkgUsageRes = $stmt->get_result();
        while ($row = $pkgUsageRes->fetch_assoc()) {
            $pkgId = (int)$row['service_id'];
            $uses  = (int)$row['use_count'];
            if (!isset($packageContents[$pkgId])) {
                continue;
            }
            foreach ($packageContents[$pkgId] as $innerId) {
                if (!isset($allServices[$innerId])) {
                    continue;
                }
                $innerSvc = $allServices[$innerId];
                if (($innerSvc['service_type'] ?? '') !== 'individual') {
                    continue;
                }
                $name = (string)$innerSvc['service_name'];
                if (!isset($serviceUsage[$name])) {
                    $serviceUsage[$name] = 0;
                }
                $serviceUsage[$name] += $uses;
            }
        }
        $stmt->close();
    }

    // Convert to array and sort by total count desc
    foreach ($serviceUsage as $name => $cnt) {
        $serviceOnlyStats[] = [
            'service_name' => $name,
            'total_count'  => $cnt,
        ];
    }
    usort($serviceOnlyStats, function ($a, $b) {
        $c = ($b['total_count'] <=> $a['total_count']);
        if ($c !== 0) return $c;
        return strcmp($a['service_name'], $b['service_name']);
    });

} catch (Exception $e) {
    $serviceOnlyStats = [];
}

// Get expenses for the period
$expensesQuery = "SELECT * FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ? ORDER BY expense_date DESC";
$expenses = [];
$totalExpenses = 0;
$stmt = $conn->prepare($expensesQuery);
if ($stmt) {
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $expensesResult = $stmt->get_result();
    if ($expensesResult) {
        while ($row = $expensesResult->fetch_assoc()) {
            $expenses[] = $row;
            $totalExpenses += (float)$row['amount'];
        }
    } else {
        // Log error if any
        error_log("Error in expenses query: " . $conn->error);
    }
    $stmt->close();
}

// Get inventory sales - include medicines dispensed and service-bound inventory (including packages)
$inventoryStats = [];
try {
    // Build maps
    $invMap = [];
    $invRes = $conn->query("SELECT id, item_name, unit_price FROM inventory");
    while ($r = $invRes->fetch_assoc()) { $invMap[(int)$r['id']] = ['name'=>$r['item_name'], 'price'=>(float)($r['unit_price'] ?? 0)]; }
    if ($invRes) { $invRes->close(); }

    $svcMap = [];
    $svcRes = $conn->query("SELECT id, service_type, inventory_id, quantity_needed, description FROM services");
    while ($r = $svcRes->fetch_assoc()) { $svcMap[(int)$r['id']] = $r; }
    if ($svcRes) { $svcRes->close(); }

    $aggByName = [];

    // 1) medicine_given JSON
    $inventoryQuery = "SELECT medicine_given FROM transactions 
                      WHERE transaction_date BETWEEN ? AND ? 
                      AND medicine_given IS NOT NULL 
                      AND medicine_given != '[]' 
                      AND medicine_given != ''";
    $stmt = $conn->prepare($inventoryQuery);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $inventoryResult = $stmt->get_result();
    while ($row = $inventoryResult->fetch_assoc()) {
        $medicines = json_decode($row['medicine_given'], true);
        if ($medicines && is_array($medicines)) {
            foreach ($medicines as $item) {
                if (isset($item['name']) && isset($item['quantity'])) {
                    $name = (string)$item['name'];
                    $qty = floatval($item['quantity']);
                    $rev = isset($item['total']) ? floatval($item['total']) : 0.0;
                    if (!isset($aggByName[$name])) { $aggByName[$name] = ['times'=>0,'qty'=>0,'rev'=>0]; }
                    $aggByName[$name]['times'] += 1;
                    $aggByName[$name]['qty'] += $qty;
                    $aggByName[$name]['rev'] += $rev;
                }
            }
        }
    }
    $stmt->close();

    // 2) service-bound items including packages
    $stmt = $conn->prepare("SELECT ts.service_id FROM transaction_services ts JOIN transactions t ON ts.transaction_id=t.transaction_id WHERE t.transaction_date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $tsRes = $stmt->get_result();
    while ($r = $tsRes->fetch_assoc()) {
        $sid = (int)$r['service_id'];
        if (!isset($svcMap[$sid])) continue;
        $svc = $svcMap[$sid];
        if ($svc['service_type'] === 'individual') {
            $invId = (int)($svc['inventory_id'] ?? 0);
            $qtyNeed = (float)($svc['quantity_needed'] ?? 0);
            if ($invId && $qtyNeed > 0 && isset($invMap[$invId])) {
                $name = $invMap[$invId]['name'];
                if (!isset($aggByName[$name])) { $aggByName[$name] = ['times'=>0,'qty'=>0,'rev'=>0]; }
                $aggByName[$name]['times'] += 1;
                $aggByName[$name]['qty'] += $qtyNeed;
                $aggByName[$name]['rev'] += ($invMap[$invId]['price'] * $qtyNeed);
            }
        } elseif ($svc['service_type'] === 'package') {
            $ids = [];
            if (!empty($svc['description']) && preg_match('/Package contents:\\s*([0-9, ]+)/', $svc['description'], $m)) {
                $ids = array_filter(array_map('intval', explode(',', $m[1])));
            }
            foreach ($ids as $innerId) {
                if (!isset($svcMap[$innerId])) continue;
                $inner = $svcMap[$innerId];
                if ($inner['service_type'] === 'individual') {
                    $invId = (int)($inner['inventory_id'] ?? 0);
                    $qtyNeed = (float)($inner['quantity_needed'] ?? 0);
                    if ($invId && $qtyNeed > 0 && isset($invMap[$invId])) {
                        $name = $invMap[$invId]['name'];
                        if (!isset($aggByName[$name])) { $aggByName[$name] = ['times'=>0,'qty'=>0,'rev'=>0]; }
                        $aggByName[$name]['times'] += 1;
                        $aggByName[$name]['qty'] += $qtyNeed;
                        $aggByName[$name]['rev'] += ($invMap[$invId]['price'] * $qtyNeed);
                    }
                }
            }
        }
    }
    $stmt->close();

    foreach ($aggByName as $itemName => $data) {
        $inventoryStats[] = [
            'item_name' => $itemName,
            'times_sold' => $data['times'],
            'total_quantity_sold' => $data['qty'],
            'total_revenue' => $data['rev']
        ];
    }
    usort($inventoryStats, function($a, $b) {
        $q = $b['total_quantity_sold'] <=> $a['total_quantity_sold'];
        return $q !== 0 ? $q : ($b['total_revenue'] <=> $a['total_revenue']);
    });

} catch (Exception $e) {
    $inventoryStats = [];
}

// Close the database connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($reportType); ?> Analytics Report</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f9;
            color: #333;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            overflow-y: auto;
        }
        .report-container {
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            min-height: auto;
            overflow: visible;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0 0;
            color: #7f8c8d;
        }
        .clinic-logo {
            margin-bottom: 15px;
        }
        .clinic-logo img {
            height: 60px;
            width: auto;
        }
        .report-info {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .report-info h2 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            font-size: 18px;
        }
        .report-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 14px;
        }
        .report-details div {
            padding: 5px 0;
        }
        .report-details label {
            font-weight: bold;
            color: #555;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            border-radius: 4px;
        }
        .stat-card h4 {
            font-size: 12px;
            margin: 0 0 8px 0;
            color: #555;
            font-weight: bold;
        }
        .stat-card .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .stat-card .stat-label {
            font-size: 10px;
            color: #7f8c8d;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            font-size: 16px;
            margin: 0 0 15px 0;
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            font-weight: bold;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .data-table th, .data-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .data-table th {
            background-color: #ecf0f1;
            color: #2c3e50;
            font-weight: bold;
        }
        .data-table tr:nth-child(even) td {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            padding: 20px;
            border-top: 2px solid #eee;
            background-color: #f9f9f9;
            border-radius: 4px;
            clear: both;
        }
        .footer-content {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .footer-section h4 {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
            margin: 0 0 10px 0;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        .footer-section p {
            font-size: 11px;
            color: #555;
            margin: 5px 0;
            line-height: 1.4;
        }
        .footer-divider {
            height: 1px;
            background-color: #ddd;
            margin: 15px 0;
        }
        .footer-disclaimer {
            text-align: center;
            font-size: 10px;
            color: #7f8c8d;
            font-style: italic;
        }
        .footer-disclaimer p {
            margin: 5px 0;
        }
        .print-button {
            display: block;
            margin: 20px auto;
            padding: 12px 24px;
            font-size: 16px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            position: relative;
            z-index: 10;
        }
        .print-button:hover {
            background-color: #2980b9;
        }

        /* Print-specific styles */
        @media print {
            body {
                background-color: #fff;
                overflow: visible;
            }
            .report-container {
                box-shadow: none;
                border: 1px solid #ccc;
                margin: 0;
                padding: 20px;
                overflow: visible;
            }
            .print-button {
                display: none;
            }
        }
        
        /* Ensure content is scrollable */
        html {
            height: 100%;
            overflow-y: auto;
        }
        
        /* Make sure all sections are visible */
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        /* Ensure footer is always visible */
        .footer {
            margin-top: 30px;
            padding: 20px;
            border-top: 2px solid #eee;
            background-color: #f9f9f9;
            border-radius: 4px;
            clear: both;
            position: relative;
        }
    </style>
</head>
<body>
    <div class="report-container">
        <div class="header">
            <div class="clinic-logo">
                <img src="logo.png" alt="La Vita MS Logo">
            </div>
            <h1>LA VITA MS</h1>
            <p>Clinic Management System</p>
            <p>Diagnostics, Medicine & Medical Supplies, Inc.</p>
        </div>

        <div class="report-info">
            <h2><?php echo ucfirst($reportType); ?> Analytics Report</h2>
            <div class="report-details">
                <div>
                    <label>Report Period:</label> <?php echo $displayStartDate; ?> to <?php echo $displayEndDate; ?>
                </div>
                <div>
                    <label>Report Type:</label> <?php echo ucfirst($reportType); ?> Report
                </div>
                <div>
                    <label>Generated on:</label> <?php echo date('F d, Y \a\t g:i A'); ?>
                </div>
                <div>
                    <label>Generated by:</label> <?php echo htmlspecialchars($_SESSION['username']); ?>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Revenue</h4>
                <div class="stat-value">₱<?php echo number_format($transactionStats['total_revenue'] ?? 0, 2); ?></div>
                <div class="stat-label">Period Revenue</div>
            </div>
            <div class="stat-card">
                <h4>Total Transactions</h4>
                <div class="stat-value"><?php echo number_format($transactionStats['total_transactions'] ?? 0); ?></div>
                <div class="stat-label">Transactions</div>
            </div>
            <div class="stat-card">
                <h4>Average Transaction</h4>
                <div class="stat-value">₱<?php echo number_format($transactionStats['average_transaction'] ?? 0, 2); ?></div>
                <div class="stat-label">Per Transaction</div>
            </div>
            <div class="stat-card">
                <h4>New Patients</h4>
                <div class="stat-value"><?php echo number_format($patientStats['new_patients'] ?? 0); ?></div>
                <div class="stat-label">This Period</div>
            </div>
        </div>

        <div class="section">
            <h3>Invidual Services Performance</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Count</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $serviceTotalCount = 0;
                    $serviceTotalRevenue = 0;
                    
                    if (!empty($serviceStats)): ?>
                        <?php foreach ($serviceStats as $service): 
                            $serviceTotalCount += (int)$service['service_count'];
                            $serviceTotalRevenue += (float)($service['service_revenue'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                <td><?php echo number_format($service['service_count']); ?></td>
                                <td>₱<?php echo number_format((float)($service['service_revenue'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align: center;">No service data available for this period</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td><?php echo number_format($serviceTotalCount); ?></td>
                        <td>₱<?php echo number_format($serviceTotalRevenue, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="section" style="margin-top: 10px;">
            <h3>Bundle Services Performance</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Package</th>
                        <th>Count</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $packageTotalCount = 0;
                    $packageTotalRevenue = 0;
                    
                    if (!empty($packageServiceStats)): ?>
                        <?php foreach ($packageServiceStats as $pkg): 
                            $packageTotalCount += (int)$pkg['service_count'];
                            $packageTotalRevenue += (float)($pkg['service_revenue'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pkg['service_name']); ?></td>
                                <td><?php echo number_format($pkg['service_count']); ?></td>
                                <td>₱<?php echo number_format((float)($pkg['service_revenue'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center; color:#7f8c8d;">No package service data available for this period</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td><?php echo number_format($packageTotalCount); ?></td>
                        <td>₱<?php echo number_format($packageTotalRevenue, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="section">
            <h3>Breakdown of Services in Bundles</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Total Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $serviceOnlyTotalCount = 0;
                    
                    if (!empty($serviceOnlyStats)): ?>
                        <?php foreach ($serviceOnlyStats as $svc): 
                            $serviceOnlyTotalCount += (int)$svc['total_count'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($svc['service_name']); ?></td>
                                <td><?php echo number_format($svc['total_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" style="text-align: center;">No service usage data available for this period</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td><?php echo number_format($serviceOnlyTotalCount); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="section">
            <h3>Top Consumed Items</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($inventoryStats)): ?>
                        <?php foreach (array_slice($inventoryStats, 0, 10) as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo number_format($item['total_quantity_sold']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" style="text-align: center;">No inventory data available for this period</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h3>Expenses</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expense Name</th>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Debug: Check if we have any expenses
                    if (empty($expenses)) {
                        error_log("No expenses found for period $startDate to $endDate");
                    } else {
                        error_log("Found " . count($expenses) . " expenses for period $startDate to $endDate");
                    }
                    ?>
                    <?php if (!empty($expenses)): ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($expense['expense_date'])); ?></td>
                                <td><?php echo htmlspecialchars($expense['expense_name']); ?></td>
                                <td><?php echo htmlspecialchars($expense['description'] ?: '-'); ?></td>
                                <td>₱<?php echo number_format((float)$expense['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background-color: #f8f9fa; font-weight: bold;">
                            <td colspan="3" style="text-align: right;">Total Expenses:</td>
                            <td>₱<?php echo number_format($totalExpenses, 2); ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #e74c3c;">
                                No expenses recorded for this period (<?php echo date('M d, Y', strtotime($startDate)); ?> to <?php echo date('M d, Y', strtotime($endDate)); ?>)
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>Report Summary</h4>
                    <p><strong>Total Revenue:</strong> ₱<?php echo number_format($transactionStats['total_revenue'] ?? 0, 2); ?></p>
                    <p><strong>Total Transactions:</strong> <?php echo number_format($transactionStats['total_transactions'] ?? 0); ?></p>
                    <p><strong>New Patients:</strong> <?php echo number_format($patientStats['new_patients'] ?? 0); ?></p>
                </div>
                
                <div class="footer-section">
                    <h4>System Information</h4>
                    <p>Generated by: La Vita MS Clinic Management System</p>
                    <p>Report Type: <?php echo ucfirst($reportType); ?> Analytics</p>
                    <p>User: <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                </div>
                
                <div class="footer-section">
                    <h4>Contact Information</h4>
                    <p>For questions or support, please contact:</p>
                    <p>System Administrator</p>
                    <p>La Vita MS Clinic</p>
                </div>
            </div>
            
            <div class="footer-divider"></div>
            
            <div class="footer-disclaimer">
                <p><strong>Disclaimer:</strong> This report contains confidential information and is intended for authorized personnel only.</p>
                <p>© <?php echo date('Y'); ?> La Vita MS. All rights reserved.</p>
            </div>
        </div>

        <button class="print-button" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
</body>
</html>
