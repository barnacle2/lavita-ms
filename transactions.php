<?php
//
// transactions.php
// This is the page for the Transactions history.
// It has been updated to handle multiple services per transaction.
//
// The $conn variable is available from the main index.php file.
//
$message = '';
$editData = null;

function deductServiceInventory($conn, $serviceId) {
    // First, load base service info
    $svcStmt = $conn->prepare("SELECT service_type, inventory_id, quantity_needed, description FROM services WHERE id = ?");
    $svcStmt->bind_param("i", $serviceId);
    $svcStmt->execute();
    $svcRes = $svcStmt->get_result();
    if ($row = $svcRes->fetch_assoc()) {
        // Collect all bindings: prefer rows from service_inventory_bindings, fallback to single columns
        $bindings = [];

        $bindStmt = $conn->prepare("SELECT inventory_id, quantity_needed FROM service_inventory_bindings WHERE service_id = ?");
        $bindStmt->bind_param("i", $serviceId);
        $bindStmt->execute();
        $bindRes = $bindStmt->get_result();
        while ($b = $bindRes->fetch_assoc()) {
            $invId = (int)($b['inventory_id'] ?? 0);
            $qtyNeed = (int)($b['quantity_needed'] ?? 0);
            if ($invId > 0 && $qtyNeed > 0) {
                $bindings[] = ['inventory_id' => $invId, 'quantity_needed' => $qtyNeed];
            }
        }
        $bindStmt->close();

        if (empty($bindings)) {
            $invId = (int)($row['inventory_id'] ?? 0);
            $qtyNeed = (int)($row['quantity_needed'] ?? 0);
            if ($invId > 0 && $qtyNeed > 0) {
                $bindings[] = ['inventory_id' => $invId, 'quantity_needed' => $qtyNeed];
            }
        }

        // Apply stock deduction for each binding
        foreach ($bindings as $b) {
            $invId = $b['inventory_id'];
            $qtyNeed = $b['quantity_needed'];

            $chk = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
            $chk->bind_param("i", $invId);
            $chk->execute();
            $stockRes = $chk->get_result();
            $stockRow = $stockRes->fetch_assoc();
            $chk->close();
            if (!$stockRow || (int)$stockRow['stock_quantity'] < $qtyNeed) {
                $svcStmt->close();
                throw new mysqli_sql_exception('Insufficient stock for service-bound inventory ID ' . $invId);
            }
            $upd = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
            $upd->bind_param("ii", $qtyNeed, $invId);
            $upd->execute();
            $upd->close();
        }

        // Handle package services recursively
        if (($row['service_type'] ?? '') === 'package') {
            $idString = str_replace('Package contents: ', '', (string)($row['description'] ?? ''));
            if ($idString !== '') {
                $ids = array_filter(array_map('intval', explode(',', $idString)));
                foreach ($ids as $childId) {
                    deductServiceInventory($conn, $childId);
                }
            }
        }
    }
    $svcStmt->close();
}

function restoreServiceInventory($conn, $serviceId) {
    // First, load base service info
    $svcStmt = $conn->prepare("SELECT service_type, inventory_id, quantity_needed, description FROM services WHERE id = ?");
    $svcStmt->bind_param("i", $serviceId);
    $svcStmt->execute();
    $svcRes = $svcStmt->get_result();
    if ($row = $svcRes->fetch_assoc()) {
        $bindings = [];

        $bindStmt = $conn->prepare("SELECT inventory_id, quantity_needed FROM service_inventory_bindings WHERE service_id = ?");
        $bindStmt->bind_param("i", $serviceId);
        $bindStmt->execute();
        $bindRes = $bindStmt->get_result();
        while ($b = $bindRes->fetch_assoc()) {
            $invId = (int)($b['inventory_id'] ?? 0);
            $qtyNeed = (int)($b['quantity_needed'] ?? 0);
            if ($invId > 0 && $qtyNeed > 0) {
                $bindings[] = ['inventory_id' => $invId, 'quantity_needed' => $qtyNeed];
            }
        }
        $bindStmt->close();

        if (empty($bindings)) {
            $invId = (int)($row['inventory_id'] ?? 0);
            $qtyNeed = (int)($row['quantity_needed'] ?? 0);
            if ($invId > 0 && $qtyNeed > 0) {
                $bindings[] = ['inventory_id' => $invId, 'quantity_needed' => $qtyNeed];
            }
        }

        foreach ($bindings as $b) {
            $invId = $b['inventory_id'];
            $qtyNeed = $b['quantity_needed'];

            $upd = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ? WHERE id = ?");
            $upd->bind_param("ii", $qtyNeed, $invId);
            $upd->execute();
            $upd->close();
        }

        // Handle package services recursively
        if (($row['service_type'] ?? '') === 'package') {
            $idString = str_replace('Package contents: ', '', (string)($row['description'] ?? ''));
            if ($idString !== '') {
                $ids = array_filter(array_map('intval', explode(',', $idString)));
                foreach ($ids as $childId) {
                    restoreServiceInventory($conn, $childId);
                }
            }
        }
    }
    $svcStmt->close();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_transaction'])) {
        $patient_id = $_POST['patient_id'];
        $transaction_date = $_POST['transaction_date'];
        $description = $_POST['description'];
        $service_ids = $_POST['service_id'];

        // --- MODIFICATION START ---
        // Calculate total amount from selected services and medicines
        $total_amount = 0;
        $service_names = [];
        foreach ($service_ids as $service_id) {
            $stmt = $conn->prepare("SELECT service_price, service_name FROM services WHERE id = ?");
            $stmt->bind_param("i", $service_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_amount += $row['service_price'];
                $service_names[] = $row['service_name'];
            }
            $stmt->close();
        }
        $service_names_string = implode(', ', $service_names);

        // Prepare medicine data for JSON and calculate total
        $medicine_items = [];
        if (isset($_POST['medicine_id']) && isset($_POST['medicine_quantity'])) {
            foreach ($_POST['medicine_id'] as $key => $medicine_id) {
                if (!empty($medicine_id) && isset($_POST['medicine_quantity'][$key]) && $_POST['medicine_quantity'][$key] > 0) {
                    $quantity = (int)$_POST['medicine_quantity'][$key];
                    $stmt = $conn->prepare("SELECT item_name, unit_price, unit_type FROM inventory WHERE id = ?");
                    $stmt->bind_param("i", $medicine_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $total_amount += $row['unit_price'] * $quantity;
                        $medicine_items[] = [
                            'name' => $row['item_name'],
                            'id' => $medicine_id,
                            'quantity' => $quantity,
                            'unit' => $row['unit_type'],
                            'total' => $row['unit_price'] * $quantity,
                        ];
                    }
                    $stmt->close();
                }
            }
        }
        $medicine_given_json = json_encode($medicine_items);

        // Apply discount (percentage) if provided
        $discount_name = isset($_POST['discount_name']) ? trim($_POST['discount_name']) : '';
        $discount_percent = isset($_POST['discount_percent']) ? floatval($_POST['discount_percent']) : 0.0;
        $discount_percent = max(0.0, min(100.0, $discount_percent));
        $final_total = $total_amount * (1 - ($discount_percent / 100.0));
        if ($final_total < 0) { $final_total = 0; }
        // Append discount info to description for traceability (used by receipt)
        $description_with_discount = $description;
        if ($discount_percent > 0 || $discount_name !== '') {
            $label = $discount_name !== '' ? $discount_name : 'Discount';
            $description_with_discount .= ' [Discount: ' . $label . ' (' . rtrim(rtrim(number_format($discount_percent, 2, '.', ''), '0'), '.') . '%)]';
        }
        // --- MODIFICATION END ---

        $conn->begin_transaction();
        try {
            // Insert the new transaction
            $stmt = $conn->prepare("INSERT INTO transactions (patient_id, transaction_date, total_amount, description, services, medicine_given) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isdsss", $patient_id, $transaction_date, $final_total, $description_with_discount, $service_names_string, $medicine_given_json);
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            $stmt->close();

            // Deduct inventory for medicines with stock checks
            if (isset($_POST['medicine_id']) && isset($_POST['medicine_quantity'])) {
                $medicine_ids = $_POST['medicine_id'];
                $medicine_quantities = $_POST['medicine_quantity'];

                foreach ($medicine_ids as $key => $medicine_id) {
                    if (!empty($medicine_id)) {
                        $quantity_given = (int)$medicine_quantities[$key];
                        if ($quantity_given > 0) {
                            // Check stock first
                            $chk = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
                            $chk->bind_param("i", $medicine_id);
                            $chk->execute();
                            $stockRes = $chk->get_result();
                            $row = $stockRes->fetch_assoc();
                            $chk->close();
                            if (!$row || (int)$row['stock_quantity'] < $quantity_given) {
                                throw new mysqli_sql_exception('Insufficient stock for inventory ID ' . (int)$medicine_id);
                            }

                            $stmt = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
                            $stmt->bind_param("ii", $quantity_given, $medicine_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }

            // Insert each selected service into the linking table
            foreach ($service_ids as $service_id) {
                $stmt = $conn->prepare("SELECT service_price FROM services WHERE id = ?");
                $stmt->bind_param("i", $service_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $price = $result->fetch_assoc()['service_price'];
                $stmt->close();

                $stmt_link = $conn->prepare("INSERT INTO transaction_services (transaction_id, service_id, price_at_transaction) VALUES (?, ?, ?)");
                $stmt_link->bind_param("iid", $transaction_id, $service_id, $price);
                $stmt_link->execute();
                $stmt_link->close();
            }

            foreach ($service_ids as $service_id) {
                deductServiceInventory($conn, (int)$service_id);
            }

            $conn->commit();
            // After successful insert, redirect so we can auto-open and scroll to this transaction in the list
            header('Location: ?page=transactions&new_txn=' . (int)$transaction_id);
            exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $message = 'Error adding transaction: ' . $e->getMessage();
        }
    } elseif (isset($_POST['update_transaction'])) {
        $id = $_POST['transaction_id'];
        $patient_id = $_POST['patient_id'];
        $transaction_date = $_POST['transaction_date'];
        $description = $_POST['description'];
        $service_ids = $_POST['service_id'];

        // --- MODIFICATION START ---
        // Recalculate total amount from selected services and medicines
        $total_amount = 0;
        $service_names = [];
        foreach ($service_ids as $service_id) {
            $stmt = $conn->prepare("SELECT service_price, service_name FROM services WHERE id = ?");
            $stmt->bind_param("i", $service_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_amount += $row['service_price'];
                $service_names[] = $row['service_name'];
            }
            $stmt->close();
        }
        $service_names_string = implode(', ', $service_names);

        $medicine_items = [];
        if (isset($_POST['medicine_id']) && isset($_POST['medicine_quantity'])) {
            foreach ($_POST['medicine_id'] as $key => $medicine_id) {
                if (!empty($medicine_id) && isset($_POST['medicine_quantity'][$key]) && $_POST['medicine_quantity'][$key] > 0) {
                    $quantity = (int)$_POST['medicine_quantity'][$key];
                    $stmt = $conn->prepare("SELECT item_name, unit_price, unit_type FROM inventory WHERE id = ?");
                    $stmt->bind_param("i", $medicine_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $total_amount += $row['unit_price'] * $quantity;
                        $medicine_items[] = [
                            'name' => $row['item_name'],
                            'id' => $medicine_id,
                            'quantity' => $quantity,
                            'unit' => $row['unit_type'],
                            'total' => $row['unit_price'] * $quantity,
                        ];
                    }
                    $stmt->close();
                }
            }
        }
        $medicine_given_json = json_encode($medicine_items);
        // --- MODIFICATION END ---

        $conn->begin_transaction();
        try {
            // First, retrieve the old medicine data to restore stock
            // --- MODIFICATION START ---
            $old_stmt = $conn->prepare("SELECT medicine_given FROM transactions WHERE transaction_id = ?");
            $old_stmt->bind_param("i", $id);
            $old_stmt->execute();
            $old_result = $old_stmt->get_result();
            $old_data = $old_result->fetch_assoc();
            $old_stmt->close();

            if (!empty($old_data['medicine_given'])) {
                $old_medicines = json_decode($old_data['medicine_given'], true);
                if ($old_medicines && is_array($old_medicines)) {
                    foreach ($old_medicines as $item) {
                        if (isset($item['id']) && isset($item['quantity'])) {
                            $restore_stmt = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ? WHERE id = ?");
                            $restore_stmt->bind_param("ii", $item['quantity'], $item['id']);
                            $restore_stmt->execute();
                            $restore_stmt->close();
                        }
                    }
                }
            }

            $s_prev = $conn->prepare("SELECT service_id FROM transaction_services WHERE transaction_id = ?");
            $s_prev->bind_param("i", $id);
            $s_prev->execute();
            $prevRes = $s_prev->get_result();
            while ($r = $prevRes->fetch_assoc()) {
                restoreServiceInventory($conn, (int)$r['service_id']);
            }
            $s_prev->close();

            // Now, reduce stock for the new medicine list
            if (isset($_POST['medicine_id']) && isset($_POST['medicine_quantity'])) {
                $medicine_ids = $_POST['medicine_id'];
                $medicine_quantities = $_POST['medicine_quantity'];

                foreach ($medicine_ids as $key => $medicine_id) {
                    if (!empty($medicine_id)) {
                        $quantity_given = (int)$medicine_quantities[$key];
                        if ($quantity_given > 0) {
                            // Check stock
                            $chk = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
                            $chk->bind_param("i", $medicine_id);
                            $chk->execute();
                            $stockRes = $chk->get_result();
                            $row = $stockRes->fetch_assoc();
                            $chk->close();
                            if (!$row || (int)$row['stock_quantity'] < $quantity_given) {
                                throw new mysqli_sql_exception('Insufficient stock for inventory ID ' . (int)$medicine_id);
                            }
                            $stmt = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
                            $stmt->bind_param("ii", $quantity_given, $medicine_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }
            // --- MODIFICATION END ---

            // Update the main transaction record
            $stmt = $conn->prepare("UPDATE transactions SET patient_id = ?, transaction_date = ?, total_amount = ?, description = ?, services = ?, medicine_given = ? WHERE transaction_id = ?");
            $stmt->bind_param("isdsssi", $patient_id, $transaction_date, $final_total, $description_with_discount, $service_names_string, $medicine_given_json, $id);
            $stmt->execute();
            $stmt->close();

            // First, delete old service links
            $stmt_delete = $conn->prepare("DELETE FROM transaction_services WHERE transaction_id = ?");
            $stmt_delete->bind_param("i", $id);
            $stmt_delete->execute();
            $stmt_delete->close();
            
            // Then, insert new service links and deduct service-bound inventory (including package contents)
            foreach ($service_ids as $service_id) {
                $stmt = $conn->prepare("SELECT service_price, inventory_id, quantity_needed FROM services WHERE id = ?");
                $stmt->bind_param("i", $service_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $svc = $result->fetch_assoc();
                $stmt->close();

                $price = $svc['service_price'];
                $stmt_link = $conn->prepare("INSERT INTO transaction_services (transaction_id, service_id, price_at_transaction) VALUES (?, ?, ?)");
                $stmt_link->bind_param("iid", $id, $service_id, $price);
                $stmt_link->execute();
                $stmt_link->close();

                deductServiceInventory($conn, (int)$service_id);
            }

            $conn->commit();
            $message = 'Transaction updated successfully!';
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $message = 'Error updating transaction: ' . $e->getMessage();
        }
    }
} elseif (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    // --- MODIFICATION START ---
    // Restore inventory stock before deleting transaction
    $stmt_old = $conn->prepare("SELECT medicine_given FROM transactions WHERE transaction_id = ?");
    $stmt_old->bind_param("i", $id);
    $stmt_old->execute();
    $result_old = $stmt_old->get_result();
    $row_old = $result_old->fetch_assoc();
    $stmt_old->close();

    if ($row_old && !empty($row_old['medicine_given'])) {
        $medicines_to_restore = json_decode($row_old['medicine_given'], true);
        if ($medicines_to_restore && is_array($medicines_to_restore)) {
            foreach ($medicines_to_restore as $med) {
                if (isset($med['id']) && isset($med['quantity'])) {
                    $stmt_restore = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    $stmt_restore->bind_param("ii", $med['quantity'], $med['id']);
                    $stmt_restore->execute();
                    $stmt_restore->close();
                }
            }
        }
    }

    $s_prev = $conn->prepare("SELECT service_id FROM transaction_services WHERE transaction_id = ?");
    $s_prev->bind_param("i", $id);
    $s_prev->execute();
    $prevRes = $s_prev->get_result();
    while ($r = $prevRes->fetch_assoc()) {
        restoreServiceInventory($conn, (int)$r['service_id']);
    }
    $s_prev->close();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM transaction_services WHERE transaction_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM transactions WHERE transaction_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $message = 'Transaction deleted successfully!';
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $message = 'Error deleting transaction: ' . $e->getMessage();
    }
    // --- MODIFICATION END ---
    header("Location: ?page=transactions");
    exit();
} elseif (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE transaction_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
    
    // Fetch associated services for the edit form
    $stmt_services = $conn->prepare("SELECT service_id FROM transaction_services WHERE transaction_id = ?");
    $stmt_services->bind_param("i", $id);
    $stmt_services->execute();
    $service_ids_result = $stmt_services->get_result();
    $editData['services'] = [];
    while ($row = $service_ids_result->fetch_assoc()) {
        $editData['services'][] = $row['service_id'];
    }
    $stmt_services->close();

    // --- MODIFICATION START ---
    // Decode medicine_given for the edit form
    $editData['medicine_given_decoded'] = json_decode($editData['medicine_given'] ?? '[]', true);
    // --- MODIFICATION END ---

    // Prefill discount fields by parsing description pattern: [Discount: Name (X%)]
    $editData['discount_name'] = '';
    $editData['discount_percent'] = '';
    if (!empty($editData['description'])) {
        if (preg_match('/\[Discount:\s*(.*?)\s*\((\d+(?:\.\d+)?)%\)\]/', $editData['description'], $m)) {
            $editData['discount_name'] = $m[1];
            $editData['discount_percent'] = $m[2];
        }
    }
}

// Fetch all patients for the dropdown menu
$patientsResult = $conn->query("SELECT id, fullname, sex FROM patients ORDER BY fullname ASC");
$patients = [];
if ($patientsResult->num_rows > 0) {
    while ($row = $patientsResult->fetch_assoc()) {
        $patients[] = $row;
    }
}

// Fetch all services for the dropdown menu and price calculation
$servicesResult = $conn->query("SELECT id, service_name, service_price FROM services ORDER BY service_name ASC");
$services = [];
if ($servicesResult->num_rows > 0) {
    while ($row = $servicesResult->fetch_assoc()) {
        $services[] = $row;
    }
}
// --- MODIFICATION START ---
// Fetch all inventory items for the dropdowns
$inventoryResult = $conn->query("SELECT id, item_name, stock_quantity, unit_type FROM inventory WHERE stock_quantity > 0 ORDER BY item_name");
$inventoryItems = [];
while ($row = $inventoryResult->fetch_assoc()) {
    $inventoryItems[] = $row;
}
$inventoryResult->close();
// --- MODIFICATION END ---

// Fetch and display transaction data. Use GROUP_CONCAT to list all services.
$result = $conn->query("
SELECT t.transaction_id, t.transaction_date, t.total_amount, t.description, t.medicine_given,
       p.fullname,
       COALESCE(
           NULLIF(GROUP_CONCAT(
               DISTINCT 
               CASE 
                   WHEN s.service_name IS NULL AND ts.service_id IS NOT NULL THEN 'Service not found' 
                   ELSE s.service_name 
               END 
               ORDER BY s.service_name SEPARATOR ', '
           ), ''), 
           'No services found'
       ) AS service_names,
       COALESCE(SUM(ts.price_at_transaction), 0) AS services_total
FROM transactions t
LEFT JOIN patients p ON t.patient_id = p.id
LEFT JOIN transaction_services ts ON t.transaction_id = ts.transaction_id
LEFT JOIN services s ON ts.service_id = s.id
GROUP BY t.transaction_id, t.transaction_date, t.total_amount, t.description, t.medicine_given, p.fullname
ORDER BY t.transaction_date DESC, t.transaction_id DESC
");
?>
<style>
    /* Transactions list search bar with icon */
    .transactions-search-container {
        position: relative;
        display: inline-block;
    }

    .transactions-search-container .transactions-search-input {
        padding-right: 32px; /* room for icon without changing overall size */
    }

    .transactions-search-icon {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #888;
        font-size: 0.9rem;
        pointer-events: none;
    }

    /* Temporary highlight for newly added transaction */
    .highlight-new-transaction {
        animation: highlightFade 4s ease-out;
        background-color: #fff7cc !important;
    }

    @keyframes highlightFade {
        0% { background-color: #ffe58f; }
        100% { background-color: transparent; }
    }
</style>
<div class="content transactions-page">
    <div class="card">
        <div class="card-body">
            <?php if (!empty($message)): ?>
                <div style="padding: 10px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="billing-form-panel">
                <div class="billing-form-header">
                    <h4 class="billing-form-title"><?php echo $editData ? 'Edit Transaction' : 'Billing Form'; ?></h4>
                </div>
                <input type="hidden" name="transaction_id" value="<?php echo $editData ? htmlspecialchars($editData['transaction_id']) : ''; ?>">
                <div class="billing-form-grid">
                    <div class="billing-field">
                        <label for="patient_id" class="billing-label">Patient</label>
                        <select name="patient_id" id="patient_id" required class="billing-input">
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $patient): ?>
                                <option value="<?php echo htmlspecialchars($patient['id']); ?>" <?php echo $editData && $editData['patient_id'] == $patient['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($patient['fullname']); ?> (<?php echo htmlspecialchars($patient['sex'] ?? 'Prefer not to say'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="billing-field">
                        <label for="transaction_date" class="billing-label">Date</label>
                        <input type="date" name="transaction_date" id="transaction_date" value="<?php echo $editData ? htmlspecialchars($editData['transaction_date']) : date('Y-m-d'); ?>" required class="billing-input">
                    </div>
                    <div class="billing-field full-width">
                        <label for="description" class="billing-label">Description</label>
                        <input type="text" name="description" id="description" value="<?php echo $editData ? htmlspecialchars($editData['description']) : ''; ?>" placeholder="e.g., Annual Check-up" class="billing-input">
                    </div>
                </div>
                
                <div class="billing-section">
                    <label class="billing-label">Services</label>
                    <div id="service-list" class="billing-dynamic-list">
                        <?php if ($editData && !empty($editData['services'])): ?>
                            <?php foreach ($editData['services'] as $service_id): ?>
                                <div class="billing-dynamic-row">
                                    <select name="service_id[]" onchange="updateTotal()" class="billing-input billing-select">
                                        <option value="">-- Select Service --</option>
                                        <?php foreach ($services as $service): ?>
                                            <option value="<?php echo htmlspecialchars($service['id']); ?>" data-price="<?php echo htmlspecialchars($service['service_price']); ?>" <?php echo $service_id == $service['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($service['service_name']); ?> (₱<?php echo htmlspecialchars($service['service_price']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="billing-remove-btn" onclick="removeService(this)">Remove</button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="billing-dynamic-row">
                                <select name="service_id[]" onchange="updateTotal()" class="billing-input billing-select">
                                    <option value="">-- Select Service --</option>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?php echo htmlspecialchars($service['id']); ?>" data-price="<?php echo htmlspecialchars($service['service_price']); ?>">
                                            <?php echo htmlspecialchars($service['service_name']); ?> (₱<?php echo htmlspecialchars($service['service_price']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="billing-remove-btn" onclick="removeService(this)">Remove</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="billing-add-btn" onclick="addService()">Add Another Service</button>
                </div>
                
                <div class="billing-discount-grid">
                    <div class="billing-field">
                        <label for="discount_name" class="billing-label">Discount Name</label>
                        <input type="text" name="discount_name" id="discount_name" value="<?php echo htmlspecialchars($editData['discount_name'] ?? ''); ?>" placeholder="e.g., Senior Citizen" class="billing-input">
                    </div>
                    <div class="billing-field">
                        <label for="discount_percent" class="billing-label">Discount (%)</label>
                        <input type="number" name="discount_percent" id="discount_percent" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($editData['discount_percent'] ?? ''); ?>" oninput="updateTotal()" class="billing-input">
                    </div>
                    <div class="billing-field">
                        <label for="total-amount-display" class="billing-label">Final Total</label>
                        <input type="text" id="total-amount-display" value="₱<?php echo $editData ? number_format($editData['total_amount'], 2) : '0.00'; ?>" readonly class="billing-input billing-total-display">
                        <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $editData ? htmlspecialchars($editData['total_amount']) : '0.00'; ?>">
                    </div>
                </div>

                <input type="hidden" id="medicine_given">
                
                <div class="billing-actions">
                    <button type="submit" name="<?php echo $editData ? 'update_transaction' : 'add_transaction'; ?>" class="billing-primary-btn">
                        <?php echo $editData ? 'Update Transaction' : 'Add Transaction'; ?>
                    </button>
                    <?php if ($editData): ?>
                        <a href="?page=transactions" class="billing-secondary-btn">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="transactions-toggle">
                <button type="button" onclick="toggleTransactionsList()" id="toggleListBtn" class="transactions-toggle-btn">
                    Show Transactions List
                </button>
            </div>

            <div id="transactions-list-container" class="transactions-table-wrapper" style="display: none;">
                <div class="transactions-table-header">
                    <h4 class="transactions-table-title">Transactions List</h4>
                    <div class="transactions-search-container">
                        <input type="text" id="transactions-search-input" class="transactions-search-input" placeholder="Search by ID, patient, service or description" oninput="filterTransactionsTable()">
                        <i class="fas fa-search transactions-search-icon"></i>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Patient</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Services</th>
                            <th>Discount</th>
                            <th>Total Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr data-txn-id="<?php echo htmlspecialchars($row['transaction_id']); ?>">
                                    <td><?php echo htmlspecialchars($row['transaction_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                                    <td><?php echo htmlspecialchars(date('F j, Y', strtotime($row['transaction_date']))); ?></td>
                                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                                    <td><?php echo htmlspecialchars($row['service_names']); ?></td>
                                    <?php
                                        // Compute discount details
                                        $services_total = (float)($row['services_total'] ?? 0);
                                        $subtotal = $services_total;
                                        $final_total = (float)$row['total_amount'];
                                        $discount_amount = max(0, $subtotal - $final_total);
                                        $discount_name = '';
                                        $discount_percent = '';
                                        if (!empty($row['description']) && preg_match('/\[Discount:\s*(.*?)\s*\((\d+(?:\.\d+)?)%\)\]/', $row['description'], $m)) {
                                            $discount_name = $m[1];
                                            $discount_percent = $m[2];
                                        }
                                    ?>
                                    <td>
                                        <?php if ($discount_amount > 0): ?>
                                            <?php echo htmlspecialchars($discount_name ?: 'Discount'); ?>
                                            <?php if ($discount_percent !== ''): ?> (<?php echo htmlspecialchars($discount_percent); ?>%)<?php endif; ?>
                                             — ₱<?php echo number_format($discount_amount, 2); ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td>
                                        <a href="print_transaction.php?id=<?php echo htmlspecialchars($row['transaction_id']); ?>" target="_blank" class="action-btn print" title="Print Receipt"><i class="fas fa-print"></i></a>
                                        <a href="?page=transactions&edit=<?php echo htmlspecialchars($row['transaction_id']); ?>" class="action-btn edit" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="?page=transactions&delete=<?php echo htmlspecialchars($row['transaction_id']); ?>" class="action-btn delete" title="Delete" onclick="return confirm('Are you sure you want to delete this transaction?');"><i class="fas fa-trash-alt"></i></a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">No transactions found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
    </div>
</div>
<script>
    // JSON data for services and inventory (for JavaScript)
    const services = <?php echo json_encode($services); ?>;
    const inventoryItems = <?php 
        $inventory_query = "SELECT id, item_name, unit_price, unit_type FROM inventory";
        $inventory_result = $conn->query($inventory_query);
        $inventory = [];
        while ($row = $inventory_result->fetch_assoc()) {
            $inventory[] = $row;
        }
        echo json_encode($inventory);
    ?>;

    function addService() {
        const serviceList = document.getElementById('service-list');
        const newServiceRow = document.createElement('div');
        newServiceRow.className = 'billing-dynamic-row';
        newServiceRow.innerHTML = `
            <select name="service_id[]" onchange="updateTotal()" class="billing-input billing-select">
                <option value="">-- Select Service --</option>
                ${services.map(service => `<option value="${service.id}" data-price="${service.service_price}">${service.service_name} (₱${service.service_price})</option>`).join('')}
            </select>
            <button type="button" class="billing-remove-btn" onclick="removeService(this)">Remove</button>
        `;
        serviceList.appendChild(newServiceRow);
    }

    function removeService(button) {
        const row = button.parentNode;
        row.parentNode.removeChild(row);
        updateTotal();
    }

    function updateTotal() {
        let servicesTotal = 0;
        let medicineTotal = 0;
        
        // Calculate services total
        document.querySelectorAll('select[name="service_id[]"]').forEach(select => {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption.value) {
                const price = parseFloat(selectedOption.getAttribute('data-price') || 0);
                if (!isNaN(price)) {
                    servicesTotal += price;
                }
            }
        });
        
        // Calculate medicine total
        document.querySelectorAll('#medicine-list > div').forEach(row => {
            const select = row.querySelector('select');
            const quantityInput = row.querySelector('input[type="number"]');
            if (select && quantityInput) {
                const price = parseFloat(select.selectedOptions[0]?.dataset.price || 0);
                const quantity = parseInt(quantityInput.value) || 0;
                medicineTotal += price * quantity;
            }
        });
        
        // Subtotal before discount
        const subtotal = servicesTotal + medicineTotal;
        
        // Apply discount percent if provided
        const discountInput = document.getElementById('discount_percent');
        let discountPercent = 0;
        if (discountInput && discountInput.value !== '') {
            discountPercent = Math.min(100, Math.max(0, parseFloat(discountInput.value)));
        }
        const finalTotal = subtotal * (1 - discountPercent / 100);
        
        const displayEl = document.getElementById('total-amount-display');
        if (displayEl && 'value' in displayEl) {
            displayEl.value = '₱' + (isFinite(finalTotal) ? finalTotal.toFixed(2) : '0.00');
        } else if (displayEl) {
            displayEl.textContent = '₱' + (isFinite(finalTotal) ? finalTotal.toFixed(2) : '0.00');
        }
        document.getElementById('total_amount').value = (isFinite(finalTotal) ? finalTotal.toFixed(2) : '0.00');
        
        // Update the hidden medicine_given field
        updateMedicineGiven();
    }

    // Medicine functions
    function updateMedicinePrice(select) {
        const row = select.closest('div');
        const stock = parseInt(select.selectedOptions[0]?.dataset.stock || 0);
        const price = parseFloat(select.selectedOptions[0]?.dataset.price || 0);
        const unit = select.selectedOptions[0]?.dataset.unit || '';
        
        // Update max quantity based on stock
        const quantityInput = row.querySelector('input[type="number"]');
        if (quantityInput) {
            quantityInput.max = stock;
            if (parseInt(quantityInput.value) > stock) {
                quantityInput.value = stock;
            }
        }
        
        // Update the total for this row
        updateMedicineTotal(quantityInput);
    }
    
    function updateMedicineTotal(input) {
        const row = input.closest('div');
        const select = row.querySelector('select');
        const price = parseFloat(select?.selectedOptions[0]?.dataset.price || 0);
        const quantity = parseInt(input.value) || 0;
        const total = price * quantity;
        
        const totalSpan = row.querySelector('.medicine-total');
        if (totalSpan) {
            totalSpan.textContent = total.toFixed(2);
        }
        
        updateTotal();
    }
    
    function addMedicine() {
        const container = document.getElementById('medicine-list');
        const newRow = container.firstElementChild.cloneNode(true);
        
        // Reset values
        const select = newRow.querySelector('select');
        const input = newRow.querySelector('input[type="number"]');
        const totalSpan = newRow.querySelector('.medicine-total');
        
        select.selectedIndex = 0;
        input.value = 1;
        if (totalSpan) totalSpan.textContent = '0.00';
        
        container.appendChild(newRow);
    }
    
    function removeMedicine(button) {
        const container = document.getElementById('medicine-list');
        if (container.children.length > 1) {
            button.closest('div').remove();
            updateTotal();
        } else {
            // Reset the single remaining row
            const row = button.closest('div');
            const select = row.querySelector('select');
            const input = row.querySelector('input[type="number"]');
            const totalSpan = row.querySelector('.medicine-total');
            
            select.selectedIndex = 0;
            input.value = 1;
            if (totalSpan) totalSpan.textContent = '0.00';
        }
        updateTotal();
    }
    
    function updateMedicineGiven() {
        const medicineStrings = [];
        document.querySelectorAll('#medicine-list > div').forEach(row => {
            const select = row.querySelector('select');
            const quantityInput = row.querySelector('input[type="number"]');

            if (select && select.value && quantityInput && quantityInput.value > 0) {
                const name = select.options[select.selectedIndex].text.split(' (')[0];
                const quantity = parseInt(quantityInput.value);
                const unit = select.options[select.selectedIndex].dataset.unit; // Now getting unit from data attribute
                
                // --- MODIFICATION START ---
                // The new format includes quantity and unit
                medicineStrings.push(`${name} (${quantity} ${unit})`);
                // --- MODIFICATION END ---
            }
        });
        document.getElementById('medicine_given').value = JSON.stringify(medicineStrings);
    }

    function toggleTransactionsList() {
        const container = document.getElementById('transactions-list-container');
        const button = document.getElementById('toggleListBtn');
        if (container.style.display === 'none' || container.style.display === '') {
            container.style.display = 'block';
            button.textContent = 'Hide Transactions List';
        } else {
            container.style.display = 'none';
            button.textContent = 'Show Transactions List';
        }
    }
    
function filterTransactionsTable() {
    const query = document.getElementById('transactions-search-input').value.toLowerCase();
    document.querySelectorAll('#transactions-list-container table tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
}

    // Initial call to set total on page load if editing
    document.addEventListener('DOMContentLoaded', function () {
        updateTotal();

        // Auto-open list and scroll to newly created transaction (if any)
        const params = new URLSearchParams(window.location.search);
        const newTxnId = params.get('new_txn');
        if (!newTxnId) return;

        const container = document.getElementById('transactions-list-container');
        const button = document.getElementById('toggleListBtn');

        if (container && (container.style.display === 'none' || container.style.display === '')) {
            container.style.display = 'block';
            if (button) button.textContent = 'Hide Transactions List';
        }

        setTimeout(function () {
            const selector = 'tr[data-txn-id="' + CSS.escape(newTxnId) + '"]';
            const row = document.querySelector(selector);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.add('highlight-new-transaction');
            }
        }, 200);
    });
</script>