<?php
//
// print_transaction.php
// This file generates a printable transaction sheet for a single transaction.
//
// Assumes the database connection is available from a central file.
//
session_start();

// Database configuration (this should ideally be in a separate, included file)
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

// Check if a transaction ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid transaction ID provided.");
}

$transaction_id = $_GET['id'];

// Fetch transaction details, including patient name and sex
$stmt = $conn->prepare("
SELECT t.*, p.fullname, p.sex
FROM transactions t
LEFT JOIN patients p ON t.patient_id = p.id
WHERE t.transaction_id = ?
");
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();
$transaction = $result->fetch_assoc();
$stmt->close();

if (!$transaction) {
    die("Transaction not found.");
}
// Generate a formatted receipt number with a prefix and padded ID
$receipt_code = 'REC-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
// Fetch all services and associated medicine details for this transaction
$stmt_services = $conn->prepare("
    SELECT 
        s.service_name, 
        ts.price_at_transaction, 
        t.medicine_given,
        t.total_amount
    FROM transaction_services ts
    LEFT JOIN services s ON ts.service_id = s.id
    LEFT JOIN transactions t ON ts.transaction_id = t.transaction_id
    WHERE ts.transaction_id = ?
");
$stmt_services->bind_param("i", $transaction_id);
$stmt_services->execute();
$servicesResult = $stmt_services->get_result();
$services = [];
while ($row = $servicesResult->fetch_assoc()) {
    $services[] = $row;
}
$stmt_services->close();

// Close the database connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RECEIPT #<?php echo htmlspecialchars($transaction['transaction_id']); ?></title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Override global site CSS that disables scrolling */
        html, body {
            overflow: auto !important;
            height: auto;
            min-height: 100%;
        }
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f9;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px 40px 40px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
            gap: 20px;
        }
        .header-content {
            text-align: center;
            margin-left: -10px;
        }
        .logo-emblem {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }
        .header h1 {
            margin: 0;
            color: #2c3e50;
            margin-left: -55px;
        }
        .header p {
            margin: 5px 0 0;
            color: #7f8c8d;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .details-grid div {
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: 4px;
        }
        .details-grid label {
            font-weight: bold;
            color: #555;
            display: block;
            margin-bottom: 5px;
        }
        .details-grid p {
            margin: 0;
        }
        .services-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .services-table th, .services-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .services-table th {
            background-color: #ecf0f1;
            color: #2c3e50;
        }
        .total-row {
            font-weight: bold;
            background-color: #d4e6f1;
        }
        .total-row td:first-child {
            text-align: left;
            padding-left: 20px;
        }
        .total-row td:last-child {
            text-align: right;
            padding-right: 20px;
            font-size: 1.1em;
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
        }
        .print-button:hover {
            background-color: #2980b9;
        }

        /* Print-specific styles */
        @media print {
            body {
                background-color: #fff;
            }
            .receipt-container {
                box-shadow: none;
                border: 1px solid #ccc;
                margin: 0;
                padding: 20px;
            }
            .print-button {
                display: none;
            }
            html, body {
                overflow: visible !important;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="header">
            <img src="logo.png" alt="Clinic Logo" class="logo-emblem">
            <div class="header-content">
                <h1>RECEIPT</h1>
                <p>La Vita Care Diagnostics, Medicine & Medical Supplies, Inc.</p>
            </div>
        </div>

        <div class="details-grid">
            <div>
                <label>Receipt No.</label>
                <p><?php echo htmlspecialchars($receipt_code); ?></p>
            </div>
            <div>
                <label>Date</label>
                <p><?php echo htmlspecialchars(date('F j, Y', strtotime($transaction['transaction_date']))); ?></p>
            </div>
            <div>
                <label>Patient</label>
                <p><?php echo htmlspecialchars($transaction['fullname']); ?></p>
            </div>
            <div>
                <label>Sex</label>
                <p><?php echo htmlspecialchars($transaction['sex'] ?? 'Prefer not to say'); ?></p>
            </div>
            <div>
                <label>Package</label>
                <p style="font-weight: bold; color: <?php echo count($services) > 1 ? '#28a745' : '#6c757d'; ?>;">
                    <?php 
                    if (count($services) > 1) {
                        echo '<i class="fas fa-check-circle"></i> Package (' . count($services) . ' services)';
                    } else {
                        echo '<i class="fas fa-times-circle"></i> Single Service';
                    }
                    ?>
                </p>
            </div>
            <div>
                <label>Services Count</label>
                <p><?php echo count($services); ?> service<?php echo count($services) != 1 ? 's' : ''; ?></p>
            </div>
            <div style="grid-column: span 2;">
                <label>Description</label>
                <p><?php echo htmlspecialchars($transaction['description']); ?></p>
            </div>
        </div>

        <!-- Services Table -->
        <table class="services-table">
            <thead>
                <tr>
                    <th>Service Name</th>
                    <th style="width: 180px; text-align:right;">Price</th>
                </tr>
            </thead>
            <tbody>
            <?php 
                $total_services = 0;
                foreach ($services as $service): 
                    $service_price = (float)$service['price_at_transaction'];
                    $total_services += $service_price;
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format($service_price, 2); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr class="total-row">
                    <td><strong>Subtotal (Services)</strong></td>
                    <td style="text-align:right;">₱<?php echo number_format($total_services, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Medicines Table -->
        <?php 
            $medicines_list = [];
            if (!empty($transaction['medicine_given'])) {
                $decoded = json_decode($transaction['medicine_given'], true);
                if (is_array($decoded)) {
                    $medicines_list = $decoded;
                }
            }
            $total_medicines = 0;
            if (!empty($medicines_list)):
        ?>
        <table class="services-table" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th>Medicine Given</th>
                    <th style="width: 180px; text-align:right;">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medicines_list as $medicine): 
                    $qty = isset($medicine['quantity']) ? (float)$medicine['quantity'] : 0;
                    $unit = 0;
                    if (isset($medicine['price'])) {
                        $unit = (float)$medicine['price'];
                    } elseif (isset($medicine['unit_price'])) {
                        $unit = (float)$medicine['unit_price'];
                    } elseif (isset($medicine['unitPrice'])) {
                        $unit = (float)$medicine['unitPrice'];
                    }
                    $line_total = isset($medicine['total']) ? (float)$medicine['total'] : ($qty * $unit);
                    $total_medicines += $line_total;
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($medicine['name']); ?><?php if ($qty > 0): ?> x <?php echo htmlspecialchars($qty); ?><?php endif; ?></td>
                    <td style="text-align:right;">₱<?php echo number_format($line_total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td><strong>Subtotal (Medicines)</strong></td>
                    <td style="text-align:right;">₱<?php echo number_format($total_medicines, 2); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Grand Total -->
        <table class="services-table" style="margin-top: 10px;">
            <tbody>
                <tr class="total-row" style="font-size: 1.2em; background-color: #d4edda;">
                    <td><strong>Total Amount</strong></td>
                    <td style="text-align:right;"><strong>₱<?php echo number_format($transaction['total_amount'], 2); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <button class="print-button" onclick="window.print()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
    </div>
</body>
</html>
