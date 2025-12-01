<?php
// print_patient_transactions.php
// Printable report for a single patient's transactions.

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "clinic_ms";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if ($patientId <= 0) {
    $conn->close();
    die('Invalid patient ID.');
}

// Fetch patient info
$stmt = $conn->prepare("SELECT patient_code, fullname, date_of_birth, sex, contact_number, address, date_registered FROM patients WHERE id = ?");
$stmt->bind_param('i', $patientId);
$stmt->execute();
$patientRes = $stmt->get_result();
$patient    = $patientRes->fetch_assoc();
$stmt->close();

if (!$patient) {
    $conn->close();
    die('Patient not found.');
}

// Fetch transactions with aggregated services
$txStmt = $conn->prepare(
    "SELECT t.transaction_id, t.transaction_date, t.total_amount, t.description, 
            GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_names,
            t.medicine_given
     FROM transactions t
     LEFT JOIN transaction_services ts ON t.transaction_id = ts.transaction_id
     LEFT JOIN services s ON ts.service_id = s.id
     WHERE t.patient_id = ?
     GROUP BY t.transaction_id, t.transaction_date, t.total_amount, t.description, t.medicine_given
     ORDER BY t.transaction_date DESC"
);
$txStmt->bind_param('i', $patientId);
$txStmt->execute();
$txRes = $txStmt->get_result();
$transactions = [];
while ($row = $txRes->fetch_assoc()) {
    $transactions[] = $row;
}
$txStmt->close();

$conn->close();

function formatMedicines(?string $json): string {
    if (!$json) return '';
    $meds = json_decode($json, true);
    if (!$meds || !is_array($meds)) return '';
    $parts = [];
    foreach ($meds as $m) {
        $name = $m['name'] ?? 'Unknown';
        $qty  = $m['quantity'] ?? '';
        $unit = $m['unit'] ?? '';
        if ($qty !== '' && $unit !== '') {
            $parts[] = $name . ' (' . $qty . ' ' . $unit . ')';
        } else {
            $parts[] = $name;
        }
    }
    return implode(', ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Transactions - <?php echo htmlspecialchars($patient['fullname']); ?></title>
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
        .section {
            margin-bottom: 25px;
        }
        .section h3 {
            font-size: 16px;
            margin: 0 0 12px 0;
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 6px;
            font-weight: bold;
        }
        .patient-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 20px;
            font-size: 14px;
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
        }
        .patient-details label {
            font-weight: bold;
            color: #555;
        }
        .transactions-list {
            margin-top: 10px;
        }
        .txn-card {
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 10px;
            overflow: hidden;
            font-size: 13px;
        }
        .txn-header {
            background-color: #f8f9fa;
            padding: 8px 12px;
            display: flex;
            justify-content: space-between;
        }
        .txn-body {
            padding: 10px 12px;
        }
        .txn-body div {
            margin-bottom: 4px;
        }
        .txn-label {
            font-weight: bold;
            color: #555;
        }
        .print-button {
            display: block;
            margin: 20px auto 0;
            padding: 12px 24px;
            font-size: 16px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .print-button:hover { background-color: #2980b9; }
        @media print {
            html, body {
                background-color: #fff;
                height: auto;
                overflow: visible;
                padding: 0;
                margin: 0;
            }
            .report-container {
                box-shadow: none;
                border: 1px solid #ccc;
                margin: 0;
                padding: 20px;
            }
            .print-button {
                display: none;
            }
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
            <p>Patient Transactions Report</p>
        </div>

        <div class="section">
            <h3>Patient Information</h3>
            <div class="patient-details">
                <div><label>Patient Code:</label> <?php echo htmlspecialchars($patient['patient_code']); ?></div>
                <div><label>Full Name:</label> <?php echo htmlspecialchars($patient['fullname']); ?></div>
                <div><label>Date of Birth:</label> <?php echo htmlspecialchars($patient['date_of_birth']); ?></div>
                <div><label>Sex:</label> <?php echo htmlspecialchars($patient['sex'] ?? ''); ?></div>
                <div><label>Contact Number:</label> <?php echo htmlspecialchars($patient['contact_number'] ?? ''); ?></div>
                <div><label>Address:</label> <?php echo htmlspecialchars($patient['address'] ?? ''); ?></div>
                <div><label>Date Registered:</label> <?php echo htmlspecialchars($patient['date_registered'] ?? ''); ?></div>
            </div>
        </div>

        <div class="section">
            <h3>Transactions</h3>
            <?php if (empty($transactions)): ?>
                <p>No transactions found for this patient.</p>
            <?php else: ?>
                <div class="transactions-list">
                    <?php foreach ($transactions as $tx): ?>
                        <div class="txn-card">
                            <div class="txn-header">
                                <div><strong>Date:</strong> <?php echo htmlspecialchars($tx['transaction_date']); ?></div>
                                <div><strong>Total:</strong> ₱<?php echo number_format((float)$tx['total_amount'], 2); ?></div>
                            </div>
                            <div class="txn-body">
                                <?php if (!empty($tx['description'])): ?>
                                    <div><span class="txn-label">Description:</span> <?php echo htmlspecialchars($tx['description']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($tx['service_names'])): ?>
                                    <div><span class="txn-label">Services:</span> <?php echo htmlspecialchars($tx['service_names']); ?></div>
                                <?php endif; ?>
                                <?php $medStr = formatMedicines($tx['medicine_given']); ?>
                                <?php if ($medStr !== ''): ?>
                                    <div><span class="txn-label">Medicines:</span> <?php echo htmlspecialchars($medStr); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <button class="print-button" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</body>
</html>
