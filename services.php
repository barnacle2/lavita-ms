<?php
//
// services.php
// Updated to include Inventory Binding for Individual Services.
//
// The $conn variable is available from the main index.php file.
//
$message = '';
$editData = null;
$packageEditData = null;
$editBindings = [];

// --- 1. Fetch Inventory Items for the Dropdown ---
$inventory_result = $conn->query("SELECT id, item_name, unit_type, stock_quantity FROM inventory ORDER BY item_name ASC");
$inventory_items = [];
if ($inventory_result) {
    while ($row = $inventory_result->fetch_assoc()) {
        $inventory_items[] = $row;
    }
}

// --- 2. Fetch Individual Services for Packages ---
$individual_services_result = $conn->query("SELECT id, service_name, service_price FROM services WHERE service_type = 'individual' ORDER BY service_name ASC");
$individual_services = [];
$service_name_map = []; 
if ($individual_services_result) {
    while ($row = $individual_services_result->fetch_assoc()) {
        $individual_services[] = $row;
        $service_name_map[$row['id']] = $row['service_name']; 
    }
}

// --- 3. Handle CRUD operations ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_service']) || isset($_POST['add_package'])) {
        $service_name = $_POST['service_name'];
        $description = $_POST['description'] ?? ''; 
        $service_price = $_POST['service_price'];
        $service_type = isset($_POST['add_package']) ? 'package' : 'individual';
        // Auto-set category to 'Bundle' for packages, otherwise use the provided category
        $category = ($service_type === 'package') ? 'Bundle' : ($_POST['category'] ?? '');
        
        // Inventory Binding Logic (supports multiple bindings for individual services)
        $main_inventory_id = null;
        $main_quantity_needed = 1;

        $inventory_ids = isset($_POST['inventory_id']) ? (array)$_POST['inventory_id'] : [];
        $quantities = isset($_POST['quantity_needed']) ? (array)$_POST['quantity_needed'] : [];

        if ($service_type === 'individual') {
            // Determine main inventory binding from first non-empty row (for backward compatibility)
            foreach ($inventory_ids as $idx => $invIdRaw) {
                $invId = (int)$invIdRaw;
                if ($invId > 0) {
                    $qty = isset($quantities[$idx]) && $quantities[$idx] !== '' ? (int)$quantities[$idx] : 1;
                    $main_inventory_id = $invId;
                    $main_quantity_needed = max(1, $qty);
                    break;
                }
            }
        } elseif ($service_type === 'package' && isset($_POST['package_services']) && is_array($_POST['package_services'])) {
            // Package Logic
            $package_services = implode(',', array_map('intval', $_POST['package_services']));
            $description = "Package contents: " . $package_services; 
        }

        $stmt = $conn->prepare("INSERT INTO services (service_name, description, service_price, category, service_type, inventory_id, quantity_needed) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdssii", $service_name, $description, $service_price, $category, $service_type, $main_inventory_id, $main_quantity_needed);

        if ($stmt->execute()) {
            $newServiceId = $conn->insert_id;

            // Sync multiple bindings for individual services into service_inventory_bindings
            if ($service_type === 'individual') {
                $del = $conn->prepare("DELETE FROM service_inventory_bindings WHERE service_id = ?");
                $del->bind_param("i", $newServiceId);
                $del->execute();
                $del->close();

                $bind = $conn->prepare("INSERT INTO service_inventory_bindings (service_id, inventory_id, quantity_needed) VALUES (?, ?, ?)");
                foreach ($inventory_ids as $idx => $invIdRaw) {
                    $invId = (int)$invIdRaw;
                    if ($invId <= 0) continue;
                    $qty = isset($quantities[$idx]) && $quantities[$idx] !== '' ? (int)$quantities[$idx] : 1;
                    if ($qty <= 0) $qty = 1;
                    $bind->bind_param("iii", $newServiceId, $invId, $qty);
                    $bind->execute();
                }
                $bind->close();
            }

            $message = $service_type === 'package' ? 'Package added successfully!' : 'Service added successfully!';
        } else {
            $message = 'Error adding ' . $service_type . ': ' . $stmt->error;
        }
        $stmt->close();

    } elseif (isset($_POST['update_service']) || isset($_POST['update_package'])) {
        $id = $_POST['service_id'];
        $service_name = $_POST['service_name'];
        $description = $_POST['description'] ?? '';
        $service_price = $_POST['service_price'];
        $service_type = isset($_POST['update_package']) ? 'package' : 'individual';
        // Auto-set category to 'Bundle' for packages, otherwise use the provided category
        $category = ($service_type === 'package') ? 'Bundle' : ($_POST['category'] ?? '');
        
        // Inventory Binding Logic (supports multiple bindings for individual services)
        $main_inventory_id = null;
        $main_quantity_needed = 1;

        $inventory_ids = isset($_POST['inventory_id']) ? (array)$_POST['inventory_id'] : [];
        $quantities = isset($_POST['quantity_needed']) ? (array)$_POST['quantity_needed'] : [];

        if ($service_type === 'individual') {
            foreach ($inventory_ids as $idx => $invIdRaw) {
                $invId = (int)$invIdRaw;
                if ($invId > 0) {
                    $qty = isset($quantities[$idx]) && $quantities[$idx] !== '' ? (int)$quantities[$idx] : 1;
                    $main_inventory_id = $invId;
                    $main_quantity_needed = max(1, $qty);
                    break;
                }
            }
        } elseif ($service_type === 'package' && isset($_POST['package_services']) && is_array($_POST['package_services'])) {
            $package_services = implode(',', array_map('intval', $_POST['package_services']));
            $description = "Package contents: " . $package_services;
        }

        $stmt = $conn->prepare("UPDATE services SET service_name = ?, description = ?, service_price = ?, category = ?, service_type = ?, inventory_id = ?, quantity_needed = ? WHERE id = ?");
        $stmt->bind_param("ssdssiii", $service_name, $description, $service_price, $category, $service_type, $main_inventory_id, $main_quantity_needed, $id);

        if ($stmt->execute()) {
            // Sync multiple bindings for individual services into service_inventory_bindings
            if ($service_type === 'individual') {
                $del = $conn->prepare("DELETE FROM service_inventory_bindings WHERE service_id = ?");
                $del->bind_param("i", $id);
                $del->execute();
                $del->close();

                $bind = $conn->prepare("INSERT INTO service_inventory_bindings (service_id, inventory_id, quantity_needed) VALUES (?, ?, ?)");
                foreach ($inventory_ids as $idx => $invIdRaw) {
                    $invId = (int)$invIdRaw;
                    if ($invId <= 0) continue;
                    $qty = isset($quantities[$idx]) && $quantities[$idx] !== '' ? (int)$quantities[$idx] : 1;
                    if ($qty <= 0) $qty = 1;
                    $bind->bind_param("iii", $id, $invId, $qty);
                    $bind->execute();
                }
                $bind->close();
            }

            $message = 'Service/Package updated successfully!';
        } else {
            $message = 'Error updating: ' . $stmt->error;
        }
        $stmt->close();
    }
} elseif (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = 'Service deleted successfully!';
    } else {
        $message = 'Error deleting: ' . $stmt->error;
    }
    $stmt->close();
    echo '<script>window.location.href="?page=services";</script>';
    exit();
} elseif (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
    
    if ($editData && $editData['service_type'] === 'package') {
        $packageEditData = $editData;
        $editData = null; 
    } elseif ($editData && $editData['service_type'] === 'individual') {
        // Load existing multi-inventory bindings for this service
        $bindStmt = $conn->prepare("SELECT inventory_id, quantity_needed FROM service_inventory_bindings WHERE service_id = ?");
        $bindStmt->bind_param("i", $id);
        $bindStmt->execute();
        $bindRes = $bindStmt->get_result();
        $editBindings = [];
        while ($b = $bindRes->fetch_assoc()) {
            $editBindings[] = $b;
        }
        $bindStmt->close();
    }
}

// --- 4. Fetch all services (Joined with Inventory for display) ---
$query = "
    SELECT s.*, i.item_name as inv_name, i.unit_type, i.stock_quantity 
    FROM services s 
    LEFT JOIN inventory i ON s.inventory_id = i.id 
    ORDER BY s.service_type ASC, s.service_name ASC
";
$all_services_result = $conn->query($query);

// Collect distinct service categories for filter dropdown (including both services and bundles)
$serviceCategories = [];
$allRows = [];

if ($all_services_result && $all_services_result->num_rows > 0) {
    while ($row = $all_services_result->fetch_assoc()) {
        $cat = trim((string)($row['category'] ?? ''));
        if ($cat !== '' && !in_array($cat, $serviceCategories, true)) {
            $serviceCategories[] = $cat;
        }
        // Store row for display
        $allRows[] = $row;
    }
    sort($serviceCategories);
}

// If no categories found, ensure we have an empty array
$serviceCategories = array_unique($serviceCategories);
sort($serviceCategories);

// Preload all inventory bindings per service for display
$bindingsByService = [];
$bindListRes = $conn->query("SELECT sib.service_id, sib.quantity_needed, i.item_name, i.unit_type, i.stock_quantity FROM service_inventory_bindings sib JOIN inventory i ON sib.inventory_id = i.id");
if ($bindListRes) {
    while ($b = $bindListRes->fetch_assoc()) {
        $sid = (int)$b['service_id'];
        if (!isset($bindingsByService[$sid])) {
            $bindingsByService[$sid] = [];
        }
        $bindingsByService[$sid][] = $b;
    }
    $bindListRes->close();
}
?>

<style>
    .service-form-actions .service-cancel-link,
    .package-form-actions .package-cancel-link {
        margin-left: 10px;
        background-color: #dc3545;
        color: #ffffff !important;
        padding: 8px 16px;
        border-radius: 999px;
        border: 1px solid #dc3545;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .service-form-actions .service-cancel-link:hover,
    .package-form-actions .package-cancel-link:hover {
        background-color: #e4606d;
        text-decoration: none;
    }

    /* Services list search bar with icon */
    .services-search-container {
        position: relative;
        display: inline-block;
    }

    .services-search-container .services-search-input {
        padding-right: 32px; /* room for icon, height/width stay the same */
    }

    .services-search-icon {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #888;
        font-size: 0.9rem;
        pointer-events: none;
    }

    /* Inventory bindings layout */
    .inventory-binding-row {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        margin-bottom: 8px;
    }

    .inventory-binding-row .service-form-field {
        flex: 1;
    }

    .inventory-binding-add {
        margin-top: 10px;
        padding: 8px 18px;
        border-radius: 999px;
        border: 1px solid #10b981;
        background-color: #10b981;
        color: #ffffff;
        font-size: 0.9rem;
        cursor: pointer;
        font-weight: 500;
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }

    .inventory-binding-add:hover {
        background-color: #059669;
        border-color: #059669;
    }

    .inventory-binding-remove {
        border: none;
        background: rgba(239, 68, 68, 0.1);
        color: #fca5a5;
        font-size: 1.1rem;
        cursor: pointer;
        padding: 4px 8px;
        line-height: 1;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    }

    .inventory-binding-remove:hover {
        background: #dc2626;
        color: #ffffff;
        box-shadow: 0 1px 4px rgba(0,0,0,0.4);
    }
</style>

<div class="content services-page">
    <div class="card">
        <div class="card-body">
            <?php if (!empty($message)): ?>
                <div style="padding: 10px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

        <form method="POST" class="service-form-card">
            <div class="service-form-header">
                <h4><?php echo $editData ? 'Edit Individual Service' : 'Add Individual Service'; ?></h4>
            </div>
            <input type="hidden" name="service_id" value="<?php echo $editData ? htmlspecialchars($editData['id']) : ''; ?>">
            
            <div class="service-form-grid">
                <div class="service-info-column">
                    <div class="service-form-field">
                        <label for="service_name">Service Name</label>
                        <input type="text" name="service_name" id="service_name" value="<?php echo $editData ? htmlspecialchars($editData['service_name']) : ''; ?>" required class="service-input">
                    </div>
                    <div class="service-form-field">
                        <label for="service_price">Price (₱)</label>
                        <input type="number" name="service_price" id="service_price" step="0.01" value="<?php echo $editData ? htmlspecialchars($editData['service_price']) : ''; ?>" required class="service-input">
                    </div>
                    <div class="service-form-field">
                        <label for="category">Category</label>
                        <input type="text" name="category" id="category" value="<?php echo $editData ? htmlspecialchars($editData['category'] ?? '') : ''; ?>" placeholder="e.g., Consultation, Laboratory, Dental" class="service-input" list="category-suggestions">
                        <datalist id="category-suggestions">
                            <?php foreach ($serviceCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="service-form-field">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" rows="4" class="service-input service-textarea"><?php echo $editData ? htmlspecialchars($editData['description']) : ''; ?></textarea>
                    </div>
                </div>

                <div class="inventory-panel">
                    <div class="inventory-panel-header">
                        <h5>Inventory Integration (Auto-Deduct)</h5>
                        <p>Select one or more inventory items to automatically deduct when this service is availed.</p>
                    </div>

                    <?php
                    // Determine bindings for form (edit vs add)
                    $bindingsForForm = [];
                    if (!empty($editBindings)) {
                        $bindingsForForm = $editBindings;
                    } elseif ($editData && $editData['inventory_id']) {
                        $bindingsForForm[] = [
                            'inventory_id' => $editData['inventory_id'],
                            'quantity_needed' => $editData['quantity_needed'] ?? 1,
                        ];
                    } else {
                        $bindingsForForm[] = [
                            'inventory_id' => '',
                            'quantity_needed' => 1,
                        ];
                    }
                    ?>

                    <div id="inventory-bindings-container">
                        <?php foreach ($bindingsForForm as $idx => $bind): ?>
                            <div class="inventory-binding-row">
                                <div class="service-form-field">
                                    <label>Bind to Inventory Item</label>
                                    <select name="inventory_id[]" class="service-input">
                                        <option value="">-- No Inventory Deduction --</option>
                                        <?php foreach ($inventory_items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>" <?php echo ((int)($bind['inventory_id'] ?? 0) === (int)$item['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($item['item_name']); ?> (Stock: <?php echo $item['stock_quantity'] . ' ' . $item['unit_type']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="service-form-field">
                                    <label>Quantity to Deduct</label>
                                    <input type="number" name="quantity_needed[]" min="1" value="<?php echo htmlspecialchars($bind['quantity_needed'] ?? 1); ?>" class="service-input">
                                </div>

                                <button type="button" class="inventory-binding-remove" onclick="removeInventoryBindingRow(this)" style="margin-top: 26px;">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="inventory-binding-add" onclick="addInventoryBindingRow()">+ Add another item</button>
                </div>
            </div>

            <div class="service-form-actions">
                <button type="submit" name="<?php echo $editData ? 'update_service' : 'add_service'; ?>" class="service-submit-btn">
                    <?php echo $editData ? 'Save Changes' : 'Create Service'; ?>
                </button>
                <?php if ($editData): ?>
                    <a href="?page=services" class="service-cancel-link">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
        
        <form method="POST" class="package-form-card">
            <div class="package-form-header">
                <h4><?php echo $packageEditData ? 'Edit Bundle Service' : 'Create Bundle'; ?></h4>
            </div>
            <input type="hidden" name="service_id" value="<?php echo $packageEditData ? htmlspecialchars($packageEditData['id']) : ''; ?>">
            <div class="package-form-grid">
                <div class="package-form-column">
                    <div class="package-form-field">
                        <label for="package_name">Package Name</label>
                        <input type="text" name="service_name" id="package_name" value="<?php echo $packageEditData ? htmlspecialchars($packageEditData['service_name']) : ''; ?>" required class="package-input">
                    </div>
                    <div class="package-form-field">
                        <label for="package_price">Package Price (₱)</label>
                        <input type="number" name="service_price" id="package_price" step="0.01" value="<?php echo $packageEditData ? htmlspecialchars($packageEditData['service_price']) : ''; ?>" required class="package-input">
                    </div>
                </div>
                
                <div class="package-services-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 10px;">
                        <label for="package_services" style="white-space: nowrap;">Select Included Services</label>
                        <div class="services-search-container" style="flex: 1; max-width: 300px; position: relative; margin-right: 13px;">
                            <input type="text" id="services-search" class="services-search-input" placeholder="Search services..." style="width: 100%; height: 20px; padding: 8px 1px 8px 15px; border: 1px solid #d1d5db; border-radius: 999px; font-size: 0.95rem; transition: border-color 0.2s;">
                            <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #6b7280; pointer-events: none; font-size: 1rem;"></i>
                        </div>
                    </div>
                    <div id="package_services" class="package-services-list">
                        <?php
                        $selected_services = $packageEditData ? explode(',', str_replace('Package contents: ', '', $packageEditData['description'])) : [];
                        
                        if (empty($individual_services)) {
                            echo '<p style="color:#666;">No individual services found.</p>';
                        } else {
                            foreach ($individual_services as $service) {
                                $service_id = htmlspecialchars($service['id']);
                                $service_name = htmlspecialchars($service['service_name']);
                                $service_price = htmlspecialchars($service['service_price']);
                                $checked = in_array($service['id'], $selected_services) ? 'checked' : '';
                                
                                echo '
                                <div style="margin-bottom: 5px;">
                                    <input type="checkbox" name="package_services[]" value="' . $service_id . '" id="service_' . $service_id . '" ' . $checked . '>
                                    <label for="service_' . $service_id . '" style="cursor: pointer;">
                                        ' . $service_name . ' (₱' . $service_price . ')
                                    </label>
                                </div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="package-form-actions">
                <button type="submit" name="<?php echo $packageEditData ? 'update_package' : 'add_package'; ?>" class="package-submit-btn">
                    <?php echo $packageEditData ? 'Save Bundle' : 'Create Bundle'; ?>
                </button>
                <?php if ($packageEditData): ?>
                    <a href="?page=services" class="package-cancel-link">Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="services-list-header" style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap;">
            <div style="display:flex; flex-direction:column; gap:6px; min-width:200px;">
                <h4 style="margin:0;">Services & Bundle</h4>
                <select id="services-category-filter" onchange="filterServicesTable()" style="max-width:220px;">
                    <option value="">All categories</option>
                    <option value="type:service">💊 Service</option>
                    <option value="type:package">📦 Bundle</option>
                    <?php if (!empty($serviceCategories)): ?>
                        <option disabled>──────────</option>
                        <?php foreach ($serviceCategories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="services-search-container">
                <input type="text" id="services-search-input" class="services-search-input" placeholder="Search by name, type or description" oninput="filterServicesTable()">
                <i class="fas fa-search services-search-icon"></i>
            </div>
        </div>
        <div class="table-container">
        <table style="width:100%; border-collapse: collapse; margin-top: 5px;">
            <thead>
                <tr style="background-color: #f2f2f2; text-align: left;">
                    <th style="border: 1px solid #ddd; padding: 10px;">Type</th>
                    <th style="border: 1px solid #ddd; padding: 10px;">Name</th>
                    <th style="border: 1px solid #ddd; padding: 10px;">Price</th>
                    <th style="border: 1px solid #ddd; padding: 10px;">Category</th>
                    <th style="border: 1px solid #ddd; padding: 10px;">Configuration / Details</th>
                    <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($allRows)) {
                    foreach ($allRows as $row) {
                        $type_label = $row['service_type'] === 'package' ? '<span style="color:#007bff;">📦 Bundle</span>' : '<span style="color:#28a745;">💊 Service</span>';
                        
                        // Logic to display details
                        $details_html = '';
                        if ($row['service_type'] === 'package') {
                            $id_string = str_replace('Package contents: ', '', $row['description']);
                            $ids = explode(',', $id_string);
                            $names = [];
                            foreach ($ids as $id) {
                                $id = trim($id);
                                if(isset($service_name_map[$id])) {
                                    $names[] = htmlspecialchars($service_name_map[$id]);
                                }
                            }
                            $details_html = '<small><strong>Contains:</strong> ' . implode(', ', $names) . '</small>';
                        } else {
                            // Individual service description + Inventory Info
                            $desc = htmlspecialchars($row['description']);
                            $details_html = $desc ? "<div>$desc</div>" : "";

                            $serviceId = (int)$row['id'];
                            $bindings = $bindingsByService[$serviceId] ?? [];

                            if (!empty($bindings)) {
                                $isDark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark';
                                $bgColor = $isDark ? '#1f2937' : '#f9f9f9';
                                $borderColor = $isDark ? '#374151' : '#ddd';
                                $textColor = $isDark ? '#e5e7eb' : '#333';
                                
                                $details_html .= '<div class="auto-deduct-container" style="margin-top: 5px; padding: 8px; background: ' . $bgColor . '; border: 1px dashed ' . $borderColor . '; border-radius: 4px; font-size: 0.85rem; color: ' . $textColor . ';">
                                    <i class="fas fa-link"></i> <strong>Auto-Deduct:</strong><br>';
                                
                                foreach ($bindings as $b) {
                                    $qty = (int)($b['quantity_needed'] ?? 1);
                                    $iname = htmlspecialchars($b['item_name']);
                                    $unit = htmlspecialchars($b['unit_type']);
                                    $stock = (int)($b['stock_quantity'] ?? 0);
                                    $stockStatus = $stock < $qty
                                        ? '<span style="color: #f87171; font-weight: 500;">(Low Stock: ' . $stock . ')</span>'
                                        : '<span style="color: #4ade80;">(Avail: ' . $stock . ')</span>';
                                    $details_html .= '<div style="margin-top: 4px; padding: 2px 0;">' . $qty . ' ' . $unit . ' of <u>' . $iname . '</u> ' . $stockStatus . '</div>';
                                }
                                $details_html .= '</div>';
                            } elseif ($row['inventory_id']) {
                                // Fallback to single inventory columns if no bindings row yet
                                $isDark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark';
                                $bgColor = $isDark ? '#1f2937' : '#f9f9f9';
                                $borderColor = $isDark ? '#374151' : '#ddd';
                                $textColor = $isDark ? '#e5e7eb' : '#333';
                                
                                $stock_status = $row['stock_quantity'] < $row['quantity_needed'] 
                                    ? '<span style="color: #f87171; font-weight: 500;">(Low Stock: '.$row['stock_quantity'].')</span>' 
                                    : '<span style="color: #4ade80;">(Avail: '.$row['stock_quantity'].')</span>';

                                $details_html .= '
                                <div class="auto-deduct-container" style="margin-top: 5px; padding: 8px; background: ' . $bgColor . '; border: 1px dashed ' . $borderColor . '; border-radius: 4px; font-size: 0.85rem; color: ' . $textColor . ';">
                                    <i class="fas fa-link"></i> <strong>Auto-Deduct:</strong> <br>
                                    ' . $row['quantity_needed'] . ' ' . $row['unit_type'] . ' of <u>' . htmlspecialchars($row['inv_name']) . '</u> ' . $stock_status . '
                                </div>';
                            } else {
                                $details_html .= '<div style="font-size: 0.8rem; color: #999; margin-top: 5px;">No inventory linked</div>';
                            }
                        }

                        $rowCategory = trim((string)($row['category'] ?? ''));
                        $rowCategoryAttr = strtolower($rowCategory);

                        echo '
                        <tr data-category="' . htmlspecialchars($rowCategoryAttr) . '">
                            <td style="border: 1px solid #ddd; padding: 10px;">' . $type_label . '</td>
                            <td style="border: 1px solid #ddd; padding: 10px; font-weight: bold;">' . htmlspecialchars($row['service_name']) . '</td>
                            <td style="border: 1px solid #ddd; padding: 10px;">₱' . number_format($row['service_price'], 2) . '</td>
                            <td style="border: 1px solid #ddd; padding: 10px;">' . ($rowCategory !== '' ? htmlspecialchars($rowCategory) : '<span style="color:#9ca3af;">Uncategorized</span>') . '</td>
                            <td style="border: 1px solid #ddd; padding: 10px;">' . $details_html . '</td>
                            <td style="border: 1px solid #ddd; padding: 10px; white-space: nowrap; text-align: center;">
                                <a href="?page=services&edit=' . htmlspecialchars($row['id']) . '" class="action-btn edit" title="Edit" style="color: #007bff; margin-right: 10px;"><i class="fas fa-edit"></i></a>
                                <a href="?page=services&delete=' . htmlspecialchars($row['id']) . '" class="action-btn delete" title="Delete" style="color: #dc3545;" onclick="return confirm(\'Are you sure you want to delete this item?\');"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>';
                    }
                } else {
                    echo '<tr><td colspan="5" style="text-align:center; padding: 15px;">No services or packages found.</td></tr>';
                }
                ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php if (isset($_GET['ajax'])) { echo '<!-- AJAX response -->'; } ?>
<script>
// Add this JavaScript code to handle the form submission and update the services list
document.addEventListener('DOMContentLoaded', function() {
    const serviceForm = document.querySelector('form[action*="page=services"]');
    if (serviceForm) {
        serviceForm.addEventListener('submit', async function(e) {
            // Only handle the service creation form, not the package form
            const submitBtn = e.submitter || this.querySelector('button[type="submit"]');
            if (submitBtn && (submitBtn.name === "add_service" || submitBtn.name === "update_service")) {
                e.preventDefault();
                
                const formData = new FormData(this);
                try {
                    const response = await fetch('services.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.text();
                    
                    // Check if the submission was successful
                    if (result.includes('successfully')) {
                        // Close the modal if it's open
                        const modal = document.getElementById('serviceModal');
                        if (modal) {
                            bootstrap.Modal.getInstance(modal)?.hide();
                        }
                        
                        // Reload the page to ensure all data is in sync
                        window.location.reload();
                    } else {
                        showMessage('Error: ' + result, 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showMessage('An error occurred while saving the service.', 'error');
                }
            }
        });
    }
    
    // Function to update the services list
    async function updateServicesList() {
        const response = await fetch('services.php?ajax=1');
        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Update the services list in the package form
        const newServicesList = doc.getElementById('package_services');
        if (newServicesList) {
            const currentList = document.getElementById('package_services');
            if (currentList) {
                currentList.innerHTML = newServicesList.innerHTML;
            }
        }
    }
    
    // Function to show messages
    function showMessage(message, type = 'success') {
        // Remove any existing messages
        const existingMessages = document.querySelectorAll('.message');
        existingMessages.forEach(msg => msg.remove());
        
        // Create and show the message
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type}`;
        messageDiv.textContent = message;
        
        const cardBody = document.querySelector('.card-body');
        if (cardBody) {
            cardBody.insertBefore(messageDiv, cardBody.firstChild);
            
            // Auto-hide the message after 5 seconds
            setTimeout(() => {
                messageDiv.remove();
            }, 5000);
        }
    }
});

// Add this CSS for the message styling
const style = document.createElement('style');
style.textContent = `
    .message {
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
        font-weight: 500;
    }
    .message.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .message.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
`;
document.head.appendChild(style);

function addInventoryBindingRow() {
    const container = document.getElementById('inventory-bindings-container');
    if (!container) return;

    const rows = container.getElementsByClassName('inventory-binding-row');
    if (!rows.length) return;

    const lastRow = rows[rows.length - 1];
    const clone = lastRow.cloneNode(true);

    // Clear values in cloned row
    const selects = clone.getElementsByTagName('select');
    for (let i = 0; i < selects.length; i++) {
        selects[i].value = '';
    }
    const inputs = clone.getElementsByTagName('input');
    for (let i = 0; i < inputs.length; i++) {
        if (inputs[i].type === 'number') {
            inputs[i].value = '1';
        }
    }

    container.appendChild(clone);
}

function removeInventoryBindingRow(button) {
    const container = document.getElementById('inventory-bindings-container');
    if (!container) return;
    const rows = container.getElementsByClassName('inventory-binding-row');
    if (rows.length <= 1) {
        // Keep at least one row
        const firstRow = rows[0];
        const selects = firstRow.getElementsByTagName('select');
        for (let i = 0; i < selects.length; i++) {
            selects[i].value = '';
        }
        const inputs = firstRow.getElementsByTagName('input');
        for (let i = 0; i < inputs.length; i++) {
            if (inputs[i].type === 'number') {
                inputs[i].value = '1';
            }
        }
        return;
    }

    const row = button.closest('.inventory-binding-row');
    if (row && container.contains(row)) {
        container.removeChild(row);
    }
}

function filterServicesTable() {
    const queryInput = document.getElementById('services-search-input');
    const categorySelect = document.getElementById('services-category-filter');
    if (!queryInput) return;

    const query = queryInput.value.toLowerCase();
    const selectedValue = categorySelect ? categorySelect.value : '';
    
    // Check if we're filtering by service type or category
    const isServiceTypeFilter = selectedValue.startsWith('type:');
    const filterType = isServiceTypeFilter ? selectedValue.split(':')[1] : '';
    const selectedCategory = isServiceTypeFilter ? '' : selectedValue.toLowerCase();

    const table = document.querySelector('.table-container table');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    let visibleRows = 0;

    rows.forEach(row => {
        const rowText = row.textContent.toLowerCase();
        const rowCategory = (row.getAttribute('data-category') || '').toLowerCase();
        const typeCell = row.querySelector('td:first-child');
        if (!typeCell) return;
        
        const rowType = typeCell.textContent.toLowerCase();
        const isPackage = rowType.includes('bundle');
        const isService = rowType.includes('service');

        // Text search
        const matchesText = query === '' || rowText.includes(query);
        
        // Check filter type
        let matchesFilter = true;
        if (isServiceTypeFilter) {
            if (filterType === 'package') {
                matchesFilter = isPackage;
            } else if (filterType === 'service') {
                matchesFilter = isService;
            }
        } else if (selectedCategory) {
            matchesFilter = rowCategory === selectedCategory;
        }

        // Show/hide row based on filters
        const shouldShow = matchesText && matchesFilter;
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleRows++;
    });

    // Show message if no rows match the filter
    const noResultsRow = document.querySelector('.no-results-message');
    if (noResultsRow) {
        noResultsRow.style.display = visibleRows === 0 ? 'table-row' : 'none';
    } else if (visibleRows === 0) {
        // Create and show no results message if it doesn't exist
        const tbody = table.querySelector('tbody');
        if (tbody) {
            const tr = document.createElement('tr');
            tr.className = 'no-results-message';
            tr.innerHTML = '<td colspan="6" style="text-align:center; padding: 15px;">No matching services or bundles found.</td>';
            tbody.appendChild(tr);
        }
    }
}

// Search functionality for bundle services
const servicesSearch = document.getElementById('services-search');
if (servicesSearch) {
    servicesSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const serviceItems = document.querySelectorAll('#package_services > div');
        
        let visibleCount = 0;
        
        serviceItems.forEach(item => {
            const label = item.querySelector('label');
            if (label) {
                const text = label.textContent.toLowerCase();
                const isVisible = text.includes(searchTerm);
                item.style.display = isVisible ? '' : 'none';
                if (isVisible) visibleCount++;
            }
        });
        
        // Show no results message if no items match
        const noResultsMsg = document.querySelector('.no-services-message');
        if (visibleCount === 0 && serviceItems.length > 0) {
            if (!noResultsMsg) {
                const msg = document.createElement('p');
                msg.className = 'no-services-message';
                msg.style.color = '#666; margin-top: 10px;';
                msg.textContent = 'No services match your search.';
                document.querySelector('.package-services-list').appendChild(msg);
            } else {
                noResultsMsg.style.display = 'block';
            }
        } else if (noResultsMsg) {
            noResultsMsg.style.display = 'none';
        }
    });
}
</script>