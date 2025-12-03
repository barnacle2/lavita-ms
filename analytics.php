<?php
//
// analytics.php
// This page provides detailed analytics and reporting functionality
// with options for Daily, Weekly, Monthly, and Yearly reports with print capabilities.
//

// Handle report type and date range
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'daily'; // Default to daily

// Default dates based on report type if not explicitly set
if ($reportType == 'daily' && !isset($_GET['start_date']) && !isset($_GET['end_date'])) {
    // Default Daily report to the last 30 days
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate = date('Y-m-d');
} elseif ($reportType == 'weekly' && !isset($_GET['start_date']) && !isset($_GET['end_date'])) {
    // Default Weekly report to the last 12 weeks
    $startDate = date('Y-m-d', strtotime('-12 weeks'));
    $endDate = date('Y-m-d');
} else {
    // Default Monthly/Yearly report to the current month or use existing GET parameters
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
}

// Validate dates
if (!strtotime($startDate))
    $startDate = date('Y-m-01');
if (!strtotime($endDate))
    $endDate = date('Y-m-t');

// Ensure end date is not before start date
if (strtotime($endDate) < strtotime($startDate)) {
    $endDate = $startDate;
}

// FIX FOR BIND_PARAM ERROR: Define full datetime variables once
$startDateTime = $startDate . ' 00:00:00';
$endDateTime = $endDate . ' 23:59:59';

// Format for display
$displayStartDate = date('M d, Y', strtotime($startDate));
$displayEndDate = date('M d, Y', strtotime($endDate));

// --- Fetch Analytics Data ---
$analyticsData = [];

// Get patient statistics
$patientQuery = "SELECT 
    COUNT(*) as total_patients,
    COUNT(CASE WHEN date_registered BETWEEN ? AND ? THEN 1 END) as new_patients
    FROM patients";
$stmt = $conn->prepare($patientQuery);
// Fix: Use pre-defined full datetime variables
$stmt->bind_param('ss', $startDateTime, $endDateTime);
$stmt->execute();
$patientResult = $stmt->get_result();
$patientStats = $patientResult->fetch_assoc();
$stmt->close();

// Get transaction statistics
$transactionQuery = "SELECT 
    COUNT(*) as total_transactions,
    SUM(total_amount) as total_revenue,
    AVG(total_amount) as avg_transaction_value
    FROM transactions 
    WHERE transaction_date BETWEEN ? AND ?";
$stmt = $conn->prepare($transactionQuery);
// Fix: Use pre-defined full datetime variables
$stmt->bind_param('ss', $startDateTime, $endDateTime);
$stmt->execute();
$transactionResult = $stmt->get_result();
$transactionStats = $transactionResult->fetch_assoc();
$stmt->close();

// Get service breakdown - Individual services
$serviceQuery = "SELECT 
    s.service_name,
    COUNT(ts.service_id) as service_count,
    SUM(ts.price_at_transaction) as service_revenue /* RETAINED FOR CHART FUNCTIONALITY, HIDDEN IN TABLE */
    FROM transaction_services ts
    JOIN services s ON ts.service_id = s.id
    JOIN transactions t ON ts.transaction_id = t.transaction_id
    WHERE t.transaction_date BETWEEN ? AND ?
    AND s.service_type = 'individual' 
    GROUP BY s.service_name
    ORDER BY service_revenue DESC";
$stmt = $conn->prepare($serviceQuery);
$stmt->bind_param('ss', $startDateTime, $endDateTime);
$stmt->execute();
$serviceResult = $stmt->get_result();
$serviceStats = [];
while ($row = $serviceResult->fetch_assoc()) {
    $serviceStats[] = $row;
}
$stmt->close();

// Get service breakdown - Package services
$pkgQuery = "SELECT 
    s.service_name,
    COUNT(ts.service_id) as service_count,
    SUM(ts.price_at_transaction) as service_revenue
    FROM transaction_services ts
    JOIN services s ON ts.service_id = s.id
    JOIN transactions t ON ts.transaction_id = t.transaction_id
    WHERE t.transaction_date BETWEEN ? AND ?
    AND s.service_type = 'package'
    GROUP BY s.service_name
    ORDER BY service_revenue DESC";
$stmt = $conn->prepare($pkgQuery);
$stmt->bind_param('ss', $startDateTime, $endDateTime);
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
        $name = (string) $svcRow['service_name'];
        $cnt = (int) ($svcRow['service_count'] ?? 0);
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
            $allServices[(int) $r['id']] = $r;
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
        $stmt->bind_param('ss', $startDateTime, $endDateTime);
        $stmt->execute();
        $pkgUsageRes = $stmt->get_result();
        while ($row = $pkgUsageRes->fetch_assoc()) {
            $pkgId = (int) $row['service_id'];
            $uses = (int) $row['use_count'];
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
                $name = (string) $innerSvc['service_name'];
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
            'total_count' => $cnt,
        ];
    }
    usort($serviceOnlyStats, function ($a, $b) {
        $c = ($b['total_count'] <=> $a['total_count']);
        if ($c !== 0)
            return $c;
        return strcmp($a['service_name'], $b['service_name']);
    });
} catch (Exception $e) {
    $serviceOnlyStats = [];
}

// Get Top Consumed Items (Medicines dispensed + inventory bound to services incl. packages)
$inventoryStats = [];
try {
    // Build maps for inventory names and service bindings
    $invMap = [];
    $invRes = $conn->query("SELECT id, item_name, unit_price FROM inventory");
    while ($r = $invRes->fetch_assoc()) {
        $invMap[(int) $r['id']] = ['name' => $r['item_name'], 'price' => (float) ($r['unit_price'] ?? 0)];
    }
    if ($invRes) {
        $invRes->close();
    }

    $svcMap = [];
    $svcRes = $conn->query("SELECT id, service_type, inventory_id, quantity_needed, description FROM services");
    while ($r = $svcRes->fetch_assoc()) {
        $svcMap[(int) $r['id']] = $r;
    }
    if ($svcRes) {
        $svcRes->close();
    }

    $aggByName = [];

    // 1) From medicine_given JSON
    $inventoryQuery = "SELECT medicine_given FROM transactions 
                      WHERE transaction_date BETWEEN ? AND ? 
                      AND medicine_given IS NOT NULL 
                      AND medicine_given != '[]' 
                      AND medicine_given != ''";
    $stmt = $conn->prepare($inventoryQuery);
    $stmt->bind_param('ss', $startDateTime, $endDateTime);
    $stmt->execute();
    $inventoryResult = $stmt->get_result();

    while ($row = $inventoryResult->fetch_assoc()) {
        $medicines = json_decode($row['medicine_given'], true);
        if ($medicines && is_array($medicines)) {
            foreach ($medicines as $item) {
                if (isset($item['name']) && isset($item['quantity'])) {
                    $name = (string) $item['name'];
                    $qty = floatval($item['quantity']);
                    $rev = isset($item['total']) ? floatval($item['total']) : 0.0;
                    if (!isset($aggByName[$name])) {
                        $aggByName[$name] = ['times' => 0, 'qty' => 0, 'rev' => 0];
                    }
                    $aggByName[$name]['times'] += 1;
                    $aggByName[$name]['qty'] += $qty;
                    $aggByName[$name]['rev'] += $rev;
                }
            }
        }
    }
    $stmt->close();

    // 2) From service-bound inventory, including packages
    $stmt = $conn->prepare("SELECT ts.service_id FROM transaction_services ts JOIN transactions t ON ts.transaction_id=t.transaction_id WHERE t.transaction_date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $startDateTime, $endDateTime);
    $stmt->execute();
    $tsRes = $stmt->get_result();
    while ($r = $tsRes->fetch_assoc()) {
        $sid = (int) $r['service_id'];
        if (!isset($svcMap[$sid]))
            continue;
        $svc = $svcMap[$sid];
        if ($svc['service_type'] === 'individual') {
            $invId = (int) ($svc['inventory_id'] ?? 0);
            $qtyNeed = (float) ($svc['quantity_needed'] ?? 0);
            if ($invId && $qtyNeed > 0 && isset($invMap[$invId])) {
                $name = $invMap[$invId]['name'];
                if (!isset($aggByName[$name])) {
                    $aggByName[$name] = ['times' => 0, 'qty' => 0, 'rev' => 0];
                }
                $aggByName[$name]['times'] += 1;
                $aggByName[$name]['qty'] += $qtyNeed;
                $aggByName[$name]['rev'] += ($invMap[$invId]['price'] * $qtyNeed);
            }
        } elseif ($svc['service_type'] === 'package') {
            // Parse package contents from description: "Package contents: 1,2,3"
            $ids = [];
            if (!empty($svc['description']) && preg_match('/Package contents:\\s*([0-9, ]+)/', $svc['description'], $m)) {
                $ids = array_filter(array_map('intval', explode(',', $m[1])));
            }
            foreach ($ids as $innerId) {
                if (!isset($svcMap[$innerId]))
                    continue;
                $inner = $svcMap[$innerId];
                if ($inner['service_type'] === 'individual') {
                    $invId = (int) ($inner['inventory_id'] ?? 0);
                    $qtyNeed = (float) ($inner['quantity_needed'] ?? 0);
                    if ($invId && $qtyNeed > 0 && isset($invMap[$invId])) {
                        $name = $invMap[$invId]['name'];
                        if (!isset($aggByName[$name])) {
                            $aggByName[$name] = ['times' => 0, 'qty' => 0, 'rev' => 0];
                        }
                        $aggByName[$name]['times'] += 1;
                        $aggByName[$name]['qty'] += $qtyNeed;
                        $aggByName[$name]['rev'] += ($invMap[$invId]['price'] * $qtyNeed);
                    }
                }
            }
        }
    }
    $stmt->close();

    // Convert to array format and sort by quantity desc
    foreach ($aggByName as $name => $data) {
        $inventoryStats[] = [
            'item_name' => $name,
            'times_sold' => $data['times'],
            'total_quantity_sold' => $data['qty'],
            'total_revenue' => $data['rev']
        ];
    }
    usort($inventoryStats, function ($a, $b) {
        // prioritize quantity, then revenue
        $q = $b['total_quantity_sold'] <=> $a['total_quantity_sold'];
        return $q !== 0 ? $q : ($b['total_revenue'] <=> $a['total_revenue']);
    });

} catch (Exception $e) {
    // If there's any error, just set empty array
    $inventoryStats = [];
}

// Get daily/weekly/monthly trends based on report type
$trendData = [];
$trendLabels = [];

if ($reportType == 'daily') {
    // Daily data for the selected range (e.g., last 30 days)
    $trendQuery = "SELECT 
        DATE_FORMAT(transaction_date, '%Y-%m-%d') as date_day,
        DATE_FORMAT(transaction_date, '%b %d') as day_name,
        COUNT(*) as transaction_count,
        SUM(total_amount) as daily_revenue
        FROM transactions 
        WHERE transaction_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m-%d')
        ORDER BY date_day ASC";
    $stmt = $conn->prepare($trendQuery);
    // Fix: Use pre-defined full datetime variables
    $stmt->bind_param('ss', $startDateTime, $endDateTime);
    $stmt->execute();
    $trendResult = $stmt->get_result();
    while ($row = $trendResult->fetch_assoc()) {
        $trendLabels[] = $row['day_name'];
        $trendData[] = $row['daily_revenue'];
    }
    $stmt->close();
} elseif ($reportType == 'weekly') {
    // Weekly data for the selected range
    $trendQuery = "SELECT 
        YEARWEEK(transaction_date, 1) as week_number,
        DATE_FORMAT(MIN(transaction_date), '%Y-%m-%d') as week_start,
        CONCAT('Week ', YEARWEEK(transaction_date, 1), ' (', DATE_FORMAT(MIN(transaction_date), '%b %d'), ')') as week_label,
        COUNT(*) as transaction_count,
        SUM(total_amount) as weekly_revenue
        FROM transactions 
        WHERE transaction_date BETWEEN ? AND ?
        GROUP BY YEARWEEK(transaction_date, 1)
        ORDER BY week_number ASC";
    $stmt = $conn->prepare($trendQuery);
    // Fix: Use pre-defined full datetime variables
    $stmt->bind_param('ss', $startDateTime, $endDateTime);
    $stmt->execute();
    $trendResult = $stmt->get_result();
    while ($row = $trendResult->fetch_assoc()) {
        $trendLabels[] = $row['week_label'];
        $trendData[] = $row['weekly_revenue'];
    }
    $stmt->close();
} elseif ($reportType == 'monthly') {
    // Monthly data for the selected range
    $trendQuery = "SELECT 
        DATE_FORMAT(transaction_date, '%Y-%m') as month_year,
        DATE_FORMAT(transaction_date, '%M %Y') as month_name,
        COUNT(*) as transaction_count,
        SUM(total_amount) as monthly_revenue
        FROM transactions 
        WHERE transaction_date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month_year ASC";
    $stmt = $conn->prepare($trendQuery);
    // Fix: Use pre-defined full datetime variables
    $stmt->bind_param('ss', $startDateTime, $endDateTime);
    $stmt->execute();
    $trendResult = $stmt->get_result();
    while ($row = $trendResult->fetch_assoc()) {
        $trendLabels[] = $row['month_name'];
        $trendData[] = $row['monthly_revenue'];
    }
    $stmt->close();
} else {
    // Yearly data for the selected range
    $trendQuery = "SELECT 
        YEAR(transaction_date) as year,
        COUNT(*) as transaction_count,
        SUM(total_amount) as yearly_revenue
        FROM transactions 
        WHERE transaction_date BETWEEN ? AND ?
        GROUP BY YEAR(transaction_date)
        ORDER BY year ASC";
    $stmt = $conn->prepare($trendQuery);
    // Fix: Use pre-defined full datetime variables
    $stmt->bind_param('ss', $startDateTime, $endDateTime);
    $stmt->execute();
    $trendResult = $stmt->get_result();
    while ($row = $trendResult->fetch_assoc()) {
        $trendLabels[] = $row['year'];
        $trendData[] = $row['yearly_revenue'];
    }
    $stmt->close();
}

// Get expenses data for the period
$expensesQuery = "SELECT 
    expense_name,
    amount,
    expense_date,
    description
    FROM expenses 
    WHERE expense_date BETWEEN ? AND ?
    ORDER BY expense_date DESC";
$stmt = $conn->prepare($expensesQuery);
$stmt->bind_param('ss', $startDateTime, $endDateTime);
$stmt->execute();
$expensesResult = $stmt->get_result();
$expensesStats = [];
$totalExpenses = 0;
while ($row = $expensesResult->fetch_assoc()) {
    $expensesStats[] = $row;
    $totalExpenses += $row['amount'];
}
$stmt->close();

// Calculate Cash on Hand (Total Revenue - Total Expenses)
$cashOnHand = ($transactionStats['total_revenue'] ?? 0) - $totalExpenses;
?>

<style>
    .analytics-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .report-controls {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .report-controls h3 {
        margin-top: 0;
        color: #2c3e50;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .controls-row {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 15px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        min-width: 150px;
    }

    .form-group label {
        font-weight: 500;
        margin-bottom: 5px;
        color: #34495e;
    }

    .form-group select,
    .form-group input {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background-color 0.3s;
    }

    .btn-primary {
        background-color: #3498db;
        color: white;
    }

    .btn-primary:hover {
        background-color: #2980b9;
    }

    .btn-success {
        background-color: #27ae60;
        color: white;
    }

    .btn-success:hover {
        background-color: #229954;
    }

    .btn-secondary {
        background-color: #95a5a6;
        color: white;
    }

    .btn-secondary:hover {
        background-color: #7f8c8d;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        text-align: center;
        border-left: 4px solid #3498db;
    }

    .stat-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .stat-card .stat-value {
        font-size: 2.5em;
        font-weight: bold;
        color: #2c3e50;
        margin-bottom: 5px;
    }

    .stat-card .stat-label {
        color: #95a5a6;
        font-size: 12px;
    }

    .charts-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 30px;
    }

    .chart-card {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .chart-card h3 {
        margin-top: 0;
        color: #2c3e50;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }

    .data-tables {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 30px;
    }

    .table-card {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .table-card h3 {
        margin-top: 0;
        color: #2c3e50;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .data-table th,
    .data-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }

    .data-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #2c3e50;
    }

    .data-table tr:hover {
        background-color: #f8f9fa;
    }

    .print-section {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        margin-top: 30px;
    }

    .print-section h3 {
        margin-top: 0;
        color: #2c3e50;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    @media print {

        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #fff;
            color: #333;
            margin: 0;
            padding: 20px;
            line-height: 1.4;
        }

        /* Hide navigation and controls */
        .report-controls,
        .print-section,
        .sidebar,
        .top-header,
        .content-header {
            display: none !important;
        }

        /* Main content - centered like transaction receipt */
        .main-content {
            margin: 0;
            padding: 0;
            width: 100%;
        }

        .analytics-container {
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }

        /* Clean Header - like transaction receipt */
        .print-header {
            display: block !important;
            text-align: center;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .clinic-branding {
            margin-bottom: 20px;
        }

        .clinic-logo img {
            height: 60px;
            width: auto;
            margin-bottom: 10px;
        }

        .clinic-name {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin: 0 0 5px 0;
        }

        .clinic-subtitle {
            font-size: 14px;
            color: #7f8c8d;
            margin: 0 0 3px 0;
        }

        .clinic-address {
            font-size: 12px;
            color: #95a5a6;
            margin: 0;
        }

        .report-title h2 {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            margin: 0 0 10px 0;
        }

        .report-period {
            font-size: 14px;
            color: #555;
            margin: 5px 0;
        }

        .report-generated {
            font-size: 12px;
            color: #7f8c8d;
            margin: 5px 0 0 0;
        }

        /* Statistics Cards - simple and clean */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 30px;
            page-break-inside: avoid;
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

        /* Charts - simple and clean */
        .charts-container {
            display: block;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }

        .chart-card {
            background-color: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            margin-bottom: 20px;
            page-break-inside: avoid;
            width: 100%;
            border-radius: 4px;
        }

        .chart-card h3 {
            font-size: 16px;
            margin: 0 0 15px 0;
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            font-weight: bold;
        }

        .chart-container {
            height: 250px;
            width: 100%;
        }

        /* Data Tables - simple and clean like transaction receipt */
        .data-tables {
            display: block;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }

        .table-card {
            background-color: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            margin-bottom: 200px;
            page-break-inside: avoid;
            width: 100%;
            border-radius: 4px;
        }

        .table-card h3 {
            font-size: 16px;
            margin: 0 0 15px 0;
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            font-weight: bold;
        }

        .data-table {
            font-size: 12px;
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
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

        /* Page breaks */
        .chart-card,
        .table-card {
            page-break-inside: avoid;
        }

        /* Simple Footer */
        .print-footer {
            display: block;
            margin-top: 30px;
            padding: 20px;
            border-top: 2px solid #eee;
            background-color: #f9f9f9;
            border-radius: 4px;
            page-break-before: avoid;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .footer-section {
            text-align: left;
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

        /* Hide elements not needed in print */
        .btn,
        .form-group {
            display: none !important;
        }

        /* Ensure charts are visible */
        canvas {
            max-width: 100%;
            height: auto;
        }
    }

    @media (max-width: 768px) {

        .charts-container,
        .data-tables {
            grid-template-columns: 1fr;
        }

        .controls-row {
            flex-direction: column;
            align-items: stretch;
        }

        .form-group {
            min-width: auto;
        }
    }

    /* Additional page-specific styles can go here */
</style>

<div class="analytics-container analytics-page">
    <div class="print-header" style="display: none;">
        <div class="clinic-branding">
            <div class="clinic-logo">
                <img src="logo.png" alt="La Vita MS Logo" style="height: 60px; width: auto;">
            </div>
            <div class="clinic-info">
                <h1 class="clinic-name">LA VITA MS</h1>
                <p class="clinic-subtitle">Clinic Management System</p>
                <p class="clinic-address">Diagnostics, Medicine & Medical Supplies, Inc.</p>
            </div>
        </div>

        <div class="report-title">
            <h2><?php echo ucfirst($reportType); ?> ANALYTICS REPORT</h2>
            <div class="report-period">
                <strong>Report Period:</strong> <?php echo $displayStartDate; ?> to <?php echo $displayEndDate; ?>
            </div>
            <div class="report-generated">
                Generated on: <?php echo date('F d, Y \a\t g:i A'); ?>
            </div>
        </div>
    </div>

    <div class="content-header">
    </div>

    <div class="report-controls">
        <h3>Generate Report</h3>
        <form id="reportForm" method="GET" action="">
            <input type="hidden" name="page" value="analytics">

            <div class="controls-row">
                <div class="form-group">
                    <label for="report_type">Report Type</label>
                    <select id="report_type" name="report_type" onchange="this.form.submit()">
                        <option value="daily" <?php echo ($reportType == 'daily') ? 'selected' : ''; ?>>Daily Report
                        </option>
                        <option value="weekly" <?php echo ($reportType == 'weekly') ? 'selected' : ''; ?>>Weekly Report
                        </option>
                        <option value="monthly" <?php echo ($reportType == 'monthly') ? 'selected' : ''; ?>>Monthly Report
                        </option>
                        <option value="yearly" <?php echo ($reportType == 'yearly') ? 'selected' : ''; ?>>Yearly Report
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date"
                        value="<?php echo htmlspecialchars($startDate); ?>" onchange="this.form.submit()">
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"
                        onchange="this.form.submit()">
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <a href="?page=analytics&report_type=<?php echo $reportType; ?>" class="btn btn-secondary">
                        <i class="fas fa-sync"></i> Reset
                    </a>
                </div>
            </div>
        </form>

        <div
            style="margin-top: 15px; padding: 10px; background-color: #e8f4fd; border-radius: 4px; border-left: 4px solid #3498db;">
            <strong>Report Period:</strong> <?php echo $displayStartDate; ?> to <?php echo $displayEndDate; ?>
            <br>
            <strong>Report Type:</strong> <?php echo ucfirst($reportType); ?> Report
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h4>Total Revenue</h4>
            <div class="stat-value">₱<?php echo number_format($transactionStats['total_revenue'] ?? 0, 2); ?></div>
            <div class="stat-label">Period Total</div>
        </div>

        <div class="stat-card">
            <h4>Total Transactions</h4>
            <div class="stat-value"><?php echo number_format($transactionStats['total_transactions'] ?? 0); ?></div>
            <div class="stat-label">Period Total</div>
        </div>

        <div class="stat-card">
            <h4>Average Transaction</h4>
            <div class="stat-value">₱<?php echo number_format($transactionStats['avg_transaction_value'] ?? 0, 2); ?>
            </div>
            <div class="stat-label">Per Transaction</div>
        </div>

        <div class="stat-card">
            <h4>New Patients</h4>
            <div class="stat-value"><?php echo number_format($patientStats['new_patients'] ?? 0); ?></div>
            <div class="stat-label">Period Total</div>
        </div>

        <div class="stat-card" style="border-left-color: #27ae60;">
            <h4>Cash on Hand</h4>
            <div class="stat-value">₱<?php echo number_format($cashOnHand, 2); ?></div>
            <div class="stat-label">Revenue - Expenses</div>
        </div>
    </div>

    <div class="charts-container">
        <div class="chart-card">
            <h3><?php echo ucfirst($reportType); ?> Revenue Trend</h3>
            <div class="chart-container">
                <canvas id="revenueTrendChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Service Revenue Distribution</h3>
            <div class="chart-container">
                <canvas id="serviceDistributionChart"></canvas>
            </div>
        </div>
    </div>

    <div class="data-tables">
        <div class="table-card">
            <h3>Individual Services Performance</h3>
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
                    $individualTotalCount = 0;
                    $individualTotalRevenue = 0;
                    
                    if (empty($serviceStats)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #95a5a6;">No service data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($serviceStats as $service): 
                            $individualTotalCount += (int)$service['service_count'];
                            $individualTotalRevenue += (float)($service['service_revenue'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                <td><?php echo number_format($service['service_count']); ?></td>
                                <td>₱<?php echo number_format((float) ($service['service_revenue'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td><?php echo number_format($individualTotalCount); ?></td>
                        <td>₱<?php echo number_format($individualTotalRevenue, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="table-card">
            <h3>Bundled Services Performance</h3>
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
                    $bundleTotalCount = 0;
                    $bundleTotalRevenue = 0;
                    
                    if (empty($packageServiceStats)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #95a5a6;">No package service data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($packageServiceStats as $pkg): 
                            $bundleTotalCount += (int)$pkg['service_count'];
                            $bundleTotalRevenue += (float)($pkg['service_revenue'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pkg['service_name']); ?></td>
                                <td><?php echo number_format($pkg['service_count']); ?></td>
                                <td>₱<?php echo number_format((float) ($pkg['service_revenue'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td><?php echo number_format($bundleTotalCount); ?></td>
                        <td>₱<?php echo number_format($bundleTotalRevenue, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="data-tables">
        <div class="table-card">
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
                    $serviceInBundlesTotalCount = 0;
                    
                    if (empty($serviceOnlyStats)): ?>
                        <tr>
                            <td colspan="2" style="text-align: center; color: #95a5a6;">No service usage data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($serviceOnlyStats as $svc): 
                            $serviceInBundlesTotalCount += (int)$svc['total_count'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($svc['service_name']); ?></td>
                                <td><?php echo number_format($svc['total_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td><?php echo number_format($serviceInBundlesTotalCount); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="table-card">
            <h3>Top Consumed Items</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventoryStats)): ?>
                        <tr>
                            <td colspan="2" style="text-align: center; color: #95a5a6;">No inventory data available</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (array_slice($inventoryStats, 0, 10) as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo number_format($item['total_quantity_sold']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="data-tables">
        <div class="table-card" style="grid-column: 1 / -1;">
            <h3>Expenses (Period)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expense Name</th>
                        <th>Amount</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expensesStats)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #95a5a6;">No expenses recorded for this period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expensesStats as $expense): ?>
                            <tr>
                                <td><?php echo date('M d, Y g:i A', strtotime($expense['expense_date'])); ?></td>
                                <td><?php echo htmlspecialchars($expense['expense_name']); ?></td>
                                <td style="color: #e74c3c; font-weight: 600;">
                                    ₱<?php echo number_format($expense['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($expense['description'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background-color: #f8f9fa; font-weight: bold;">
                            <td colspan="2" style="text-align: right;">Total Expenses:</td>
                            <td style="color: #e74c3c; font-size: 1.1em;">₱<?php echo number_format($totalExpenses, 2); ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="print-section">
        <h3>Print Report</h3>
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <button onclick="printReport('<?php echo $reportType; ?>')" class="btn btn-success">
                <i class="fas fa-print"></i> Print <?php echo ucfirst($reportType); ?> Report
            </button>
        </div>
    </div>

    <div class="print-footer" style="display: none;">
        <div class="footer-content">
            <div class="footer-section">
                <h4>Report Summary</h4>
                <p><strong>Total Revenue:</strong>
                    ₱<?php echo number_format($transactionStats['total_revenue'] ?? 0, 2); ?></p>
                <p><strong>Total Transactions:</strong>
                    <?php echo number_format($transactionStats['total_transactions'] ?? 0); ?></p>
                <p><strong>New Patients:</strong> <?php echo number_format($patientStats['new_patients'] ?? 0); ?></p>
            </div>

            <div class="footer-section">
                <h4>System Information</h4>
                <p>Generated by: La Vita MS Clinic Management System</p>
                <p>Report Type: <?php echo ucfirst($reportType); ?> Analytics</p>
                <p>User: Authorized User</p>
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
            <p><strong>Disclaimer:</strong> This report contains confidential information and is intended for authorized
                personnel only.</p>
            <p>© <?php echo date('Y'); ?> La Vita MS. All rights reserved.</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Initialize date pickers
    document.addEventListener('DOMContentLoaded', function () {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('start_date').max = today;
        document.getElementById('end_date').max = today;

        // Set minimum end date based on start date
        document.getElementById('start_date').addEventListener('change', function () {
            document.getElementById('end_date').min = this.value;
            if (new Date(document.getElementById('end_date').value) < new Date(this.value)) {
                document.getElementById('end_date').value = this.value;
            }
        });

        // Set maximum start date based on end date
        document.getElementById('end_date').addEventListener('change', function () {
            document.getElementById('start_date').max = this.value;
            if (new Date(document.getElementById('start_date').value) > new Date(this.value)) {
                document.getElementById('start_date').value = this.value;
            }
        });
    });

    // Print function
    function printReport(reportType) {
        // Get current report parameters from form elements
        const reportTypeValue = document.getElementById('report_type').value;
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;

        // The print function is currently redirecting to print_report.php
        // If you are using this external file, please ensure you update it with the same two-column table structure.
        const printUrl = `print_report.php?type=${reportTypeValue}&start=${startDate}&end=${endDate}`;
        window.open(printUrl, '_blank');

        // Alternative: To print the current page content (which has the updated tables), you would use:
        // window.print();
    }

    // Chart data from PHP
    const trendLabels = <?php echo json_encode($trendLabels); ?>;
    const trendData = <?php echo json_encode($trendData); ?>;
    // Use the serviceStats array for labels and data
    const serviceStats = <?php echo json_encode($serviceStats); ?>;
    const serviceLabels = serviceStats.map(s => s.service_name);
    const serviceData = serviceStats.map(s => s.service_revenue);


    // Revenue Trend Chart
    const trendCtx = document.getElementById('revenueTrendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Revenue (₱)',
                data: trendData,
                backgroundColor: 'rgba(52, 152, 219, 0.2)',
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: 'rgba(52, 152, 219, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // Service Distribution Chart
    const serviceCtx = document.getElementById('serviceDistributionChart').getContext('2d');
    new Chart(serviceCtx, {
        type: 'doughnut',
        data: {
            labels: serviceLabels,
            datasets: [{
                data: serviceData,
                backgroundColor: [
                    '#3498db',
                    '#e74c3c',
                    '#2ecc71',
                    '#f1c40f',
                    '#9b59b6',
                    '#1abc9c',
                    '#e67e22',
                    '#34495e'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
</script>