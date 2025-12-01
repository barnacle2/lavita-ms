<?php
//
// inventory.php
// This is the page for the Inventory management section.
// It has been updated for a modern design, with unit-specific low stock thresholds.
//
// The $conn variable is available from the main index.php file.
//
$message = '';
$editData = null;

// Define available categories
$categories = ['Medicine', 'Consumable', 'Equipment', 'Supply', 'Other'];

// --- Define Unit-Specific Low Stock Thresholds ---
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
$default_threshold = 10; // Default threshold for unlisted unit types (e.g., 'ml', 'tab', 'units')

// --- Handle CRUD operations (No change in logic from last update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Handle ADD or UPDATE
    if (isset($_POST['add_inventory']) || isset($_POST['update_inventory'])) {
        // Collect and sanitize all common input fields
        $item_name = $_POST['item_name'];
        $stock_quantity = $_POST['stock_quantity'];
        $unit_type = $_POST['unit_type'];
        // Use NULL if expiration date field is empty, which resolves the deprecated warning on input processing
        $expiration_date = empty($_POST['expiration_date']) ? NULL : $_POST['expiration_date']; 
        $category = $_POST['category'];
        // Optional per-item low stock threshold (if empty, use unit/default thresholds)
        $low_stock_threshold_custom = isset($_POST['low_stock_threshold']) && $_POST['low_stock_threshold'] !== ''
            ? (int)$_POST['low_stock_threshold']
            : NULL;

        if (isset($_POST['add_inventory'])) {
            // Insert the new inventory item
            $stmt = $conn->prepare("INSERT INTO inventory (item_name, stock_quantity, unit_type, expiration_date, category, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sisssi", $item_name, $stock_quantity, $unit_type, $expiration_date, $category, $low_stock_threshold_custom);

            if ($stmt->execute()) {
                $message = 'Inventory item added successfully!';
            } else {
                $message = 'Error adding inventory item: ' . $stmt->error;
            }
            $stmt->close();

        } elseif (isset($_POST['update_inventory'])) {
            // Update the existing inventory item
            $id = $_POST['inventory_id'];
            $stmt = $conn->prepare("UPDATE inventory SET item_name = ?, stock_quantity = ?, unit_type = ?, expiration_date = ?, category = ?, low_stock_threshold = ? WHERE id = ?");
            $stmt->bind_param("sisssii", $item_name, $stock_quantity, $unit_type, $expiration_date, $category, $low_stock_threshold_custom, $id);

            if ($stmt->execute()) {
                $message = 'Inventory item updated successfully!';
            } else {
                $message = 'Error updating inventory item: ' . $stmt->error;
            }
            $stmt->close();
        }
    }

} elseif (isset($_GET['delete'])) {
    // 2. Handle DELETE
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = 'Inventory item deleted successfully!';
    } else {
        $message = 'Error deleting inventory item: ' . $stmt->error;
    }
    $stmt->close();

} elseif (isset($_GET['edit'])) {
    // 3. Fetch data for editing
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, item_name, stock_quantity, unit_type, expiration_date, category, low_stock_threshold FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result_edit = $stmt->get_result();
    $editData = $result_edit->fetch_assoc();
    $stmt->close();
}

// --- Fetch all inventory items for the table view (No change in logic from last update) ---
$result = $conn->query("SELECT id, item_name, stock_quantity, unit_type, expiration_date, category, low_stock_threshold, last_updated FROM inventory ORDER BY item_name ASC");

?>

<style>
    /* Global Styles */
    :root {
        --primary-color: #007bff; /* Vibrant Blue */
        --success-color: #28a745; /* Success Green */
        --danger-color: #dc3545; /* Alert Red */
        --background-light: #f8f9fa; /* Very light gray */
        --text-dark: #343a40;
        --border-light: #dee2e6;
        --shadow-subtle: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }

    /* Page Container */
    .page-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    .page-container h1, .page-container h2 {
        color: var(--text-dark);
        margin-bottom: 20px;
    }

    /* Card/Container Styling */
    .table-card {
        background-color: #fff;
        padding: 25px;
        border-radius: 8px;
        box-shadow: var(--shadow-subtle);
        margin-top: 20px;
    }

    /* Message Boxes */
    .message {
        padding: 12px 20px;
        margin-bottom: 20px;
        border-radius: 4px;
        font-weight: 600;
        border: 1px solid transparent;
        transition: all 0.3s ease;
    }
    .success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    .error {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }

    /* Button Styling */
    .btn {
        padding: 10px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: background-color 0.2s, box-shadow 0.2s;
    }
    .btn-primary {
        background-color: var(--primary-color);
        color: #fff;
    }
    .btn-primary:hover {
        background-color: #0056b3;
    }
    .action-btn {
        background: none;
        border: none;
        color: #6c757d; /* Muted color for actions */
        padding: 5px;
        margin: 0 5px;
        cursor: pointer;
        transition: color 0.2s;
        font-size: 1.1em;
    }
    .action-btn.edit:hover {
        color: var(--primary-color);
    }
    .action-btn.delete:hover {
        color: var(--danger-color);
    }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px 4px 8px;
        border-radius: 15px;
        font-size: 0.65em;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        background-color: white;
        border: 1px solid;
    }
    
    .status-critical {
        color: #e74c3c;
        border-color: #e74c3c;
    }
    
    .status-critical i {
        color: #e74c3c;
    }
    
    .status-in-stock {
        color: #2ecc71;
        border-color: #2ecc71;
    }
    
    .status-in-stock i {
        color: #2ecc71;
    }
    
    /* Critical item styling */
    .item-name {
        position: relative;
        padding-left: 12px;
    }
    
    .critical-item .item-name::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background-color: #e74c3c;
        border-radius: 2px;
    }
    
    /* Search and Table Styling */
    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .search-container {
        position: relative;
        width: 250px;
    }
    
    .search-input {
        width: 100%;
        padding: 8px 35px 8px 15px;
        border: 1px solid #ddd;
        border-radius: 20px;
        font-size: 0.9em;
        transition: all 0.3s ease;
    }
    
    .search-input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
        padding-right: 35px; /* Ensure space for icon when focused */
    }
    
    .search-icon {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
        pointer-events: none; /* Makes the icon non-interactive */
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        text-align: left;
    }
    .data-table th, .data-table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--border-light);
        vertical-align: middle;
    }
    .data-table th {
        background-color: var(--background-light);
        color: var(--text-dark);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.85em;
    }
    .data-table tbody tr:hover {
        background-color: #f1f1f1;
        transition: background-color 0.2s;
    }
    
    /* Prevent hover effect on critical items */
    .low-stock:hover {
        background-color: transparent !important;
    }
    /* Low stock row styling (removed background color) */
    .low-stock {
        /* No background color, just using the red line indicator */
    }
    /* Low stock icon styling */
    .low-stock-icon {
        color: #ffc107; /* Bright yellow/orange warning color */
        margin-left: 8px;
        font-size: 1.1em;
        vertical-align: middle;
        text-shadow: 0 0 2px rgba(0,0,0,0.2);
    }

    /* Modal Styles */
    .modal {
        display: none; 
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5); /* Darker overlay */
        padding-top: 50px;
    }
    .modal-content {
        background-color: #fff;
        margin: 5% auto;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        width: 90%;
        max-width: 600px;
        position: relative;
    }
    .close-btn {
        color: #aaa;
        float: right;
        font-size: 30px;
        font-weight: bold;
        line-height: 1;
        transition: color 0.2s;
    }
    .close-btn:hover,
    .close-btn:focus {
        color: var(--text-dark);
        text-decoration: none;
        cursor: pointer;
    }
    
    /* Form Input Styles */
    label {
        display: block;
        margin-top: 15px;
        margin-bottom: 5px;
        font-weight: 600;
        color: var(--text-dark);
        font-size: 0.9em;
    }
    input[type="text"], input[type="number"], input[type="date"], select {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border-light);
        border-radius: 4px;
        box-sizing: border-box;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    /* Force the inventory search bar to stay pill-shaped */
    .search-container .search-input {
        border-radius: 999px !important;
    }
    input:focus, select:focus {
        border-color: var(--primary-color);
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }
    .form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 10px;
    }
    .form-group {
        flex: 1;
    }
</style>

<div class="page-container">
    <h1>Inventory Management</h1>
    
    <?php if ($message): ?>
        <div class="message <?php echo strpos($message, 'Error') !== false ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <button onclick="openAddModal()" class="btn btn-primary" style="margin-bottom: 20px;">
        <i class="fas fa-plus"></i> Add New Item
    </button>

    <div id="inventoryModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add New Inventory Item</h2>
            
            <form method="POST">
                <input type="hidden" name="inventory_id" id="inventory_id">

                <div class="form-group">
                    <label for="item_name">Item Name:</label>
                    <input type="text" name="item_name" id="item_name" required maxlength="255" value="<?php echo htmlspecialchars($editData['item_name'] ?? ''); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="stock_quantity">Stock Quantity:</label>
                        <input type="number" name="stock_quantity" id="stock_quantity" required min="0" value="<?php echo htmlspecialchars($editData['stock_quantity'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="unit_type">Unit Type (e.g., pcs, box, ml, tab):</label>
                        <input type="text" name="unit_type" id="unit_type" required maxlength="50" value="<?php echo htmlspecialchars($editData['unit_type'] ?? 'units'); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="category">Category:</label>
                        <select name="category" id="category" required>
                            <?php 
                            $current_category = $editData['category'] ?? '';
                            foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($current_category == $cat) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="expiration_date">Expiration Date (Optional):</label>
                        <input type="date" name="expiration_date" id="expiration_date" value="<?php echo htmlspecialchars($editData['expiration_date'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="low_stock_threshold">Low Stock Threshold (Optional):</label>
                        <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" placeholder="Use default based on unit" value="<?php echo htmlspecialchars($editData['low_stock_threshold'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" name="add_inventory" id="submitBtn" class="btn btn-primary" 
                        style="width: 100%; margin-top: 20px; font-size: 1.1em; padding: 12px 15px;">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </form>
        </div>
    </div>

    <div class="table-card">
        <div class="table-header">
            <h2>Current Stock</h2>
            <div class="search-container">
                <input type="text" id="inventorySearch" placeholder="Search items..." class="search-input">
                <i class="fas fa-search search-icon"></i>
            </div>
        </div>
        <table class="data-table" id="inventoryTable">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Stock</th>
                    <th>Unit Type</th>
                    <th>Status</th>
                    <th>Category</th>
                    <th>Expiration</th>
                    <th>Last Updated</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        
                        // 1. Determine the unit type and normalize it to lower case
                        $unit_type_lower = strtolower($row['unit_type']);
                        
                        // 2. Determine the effective low-stock threshold
                        // Prefer per-item custom threshold if set, otherwise fall back to unit/default
                        $low_stock_threshold = isset($row['low_stock_threshold']) && $row['low_stock_threshold'] !== null
                            ? (int)$row['low_stock_threshold']
                            : ($low_stock_thresholds[$unit_type_lower] ?? $default_threshold);

                        // 3. Check for low stock condition and set status
                        $is_low_stock = $row['stock_quantity'] <= $low_stock_threshold; 
                        $status_text = $is_low_stock ? 'Low Stock' : 'In Stock';
                        $status_class = $is_low_stock ? 'status-critical' : 'status-in-stock';
                        $row_class = $is_low_stock ? 'low-stock' : '';

                        // Low stock icon logic
                        $low_stock_icon = '';
                        if ($is_low_stock) {
                            $low_stock_icon = '<i class="fas fa-exclamation-triangle low-stock-icon" title="LOW STOCK: ' . htmlspecialchars($row['stock_quantity']) . ' ' . htmlspecialchars($row['unit_type']) . ' remaining (Threshold: ' . $low_stock_threshold . ')"></i>';
                        }

                        echo '
                        <tr class="' . $row_class . '">
                            <td class="item-name' . ($is_low_stock ? ' critical-item' : '') . '">' . htmlspecialchars($row['item_name']) . $low_stock_icon . '</td>
                            <td>' . htmlspecialchars($row['stock_quantity']) . '</td>
                            <td>' . htmlspecialchars($row['unit_type']) . '</td>
                            <td><span class="status-badge ' . $status_class . '">' . 
                                ($is_low_stock ? 
                                    '<i class="fas fa-exclamation-circle"></i>' : 
                                    '<i class="fas fa-check-circle"></i>'
                                ) . 
                                $status_text . 
                            '</span></td>
                            <td>' . htmlspecialchars($row['category']) . '</td>
                            <td>' . htmlspecialchars($row['expiration_date'] ?? 'N/A') . '</td>
                            <td>' . date('M d, Y H:i', strtotime($row['last_updated'])) . '</td>
                            <td style="white-space: nowrap; text-align: center;">
                                <a href="#" class="action-btn edit" title="Edit" 
                                    data-id="' . htmlspecialchars($row['id']) . '"
                                    data-item_name="' . htmlspecialchars($row['item_name']) . '"
                                    data-stock_quantity="' . htmlspecialchars($row['stock_quantity']) . '"
                                    data-unit_type="' . htmlspecialchars($row['unit_type']) . '"
                                    data-expiration_date="' . htmlspecialchars($row['expiration_date'] ?? '') . '"
                                    data-category="' . htmlspecialchars($row['category']) . '"
                                    data-low_stock_threshold="' . htmlspecialchars($row['low_stock_threshold'] ?? '') . '"
                                    onclick="openEditModal(this.dataset)">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?page=inventory&delete=' . htmlspecialchars($row['id']) . '" class="action-btn delete" title="Delete" onclick="return confirm(\'Are you sure you want to delete this inventory item?\');"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>';
                    }
                } else {
                    echo '<tr><td colspan="8" style="text-align:center; padding: 15px; font-style: italic;">No inventory items found.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const modal = document.getElementById('inventoryModal');
    const modalTitle = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('submitBtn');
    const form = modal.querySelector('form');

    function openModal() {
        modal.style.display = 'block';
        // Add a class to body to prevent scrolling when modal is open
        document.body.style.overflow = 'hidden'; 
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto'; // Restore scrolling
        form.reset();
        // Clear hidden ID and reset button/title to Add state
        document.getElementById('inventory_id').value = '';
        submitBtn.name = 'add_inventory';
        submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Item';
        modalTitle.innerText = 'Add New Inventory Item';
        submitBtn.classList.remove('btn-danger'); // Remove update style if present
        submitBtn.classList.add('btn-primary');
    }

    function openAddModal() {
        closeModal(); // Ensure it's clean
        modalTitle.innerText = 'Add New Inventory Item';
        submitBtn.name = 'add_inventory';
        submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Item';
        openModal();
    }

    function openEditModal(data) {
        closeModal(); // Start fresh

        // Set the form fields with data
        document.getElementById('inventory_id').value = data.id;
        document.getElementById('item_name').value = data.item_name;
        document.getElementById('stock_quantity').value = data.stock_quantity;
        document.getElementById('unit_type').value = data.unit_type;
        document.getElementById('expiration_date').value = data.expiration_date;
        document.getElementById('low_stock_threshold').value = data.low_stock_threshold || '';

        // Set the category dropdown
        const categorySelect = document.getElementById('category');
        for(let i = 0; i < categorySelect.options.length; i++) {
            if(categorySelect.options[i].value === data.category) {
                categorySelect.options[i].selected = true;
                break;
            }
        }
        
        // Update form action, title, and buttons
        form.name = 'update_inventory';
        submitBtn.name = 'update_inventory'; 
        modalTitle.innerText = 'Edit Inventory Item (ID: ' + data.id + ')';
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
        submitBtn.classList.remove('btn-primary');
        submitBtn.classList.add('btn-danger'); // Using danger color for update to distinguish from 'Add' primary color

        openModal();
    }

    // Close the modal when the user clicks anywhere outside of the modal
    window.onclick = function(event) {
        if (event.target === modal) {
            closeModal();
        }
    }

    // Search functionality
    document.getElementById('inventorySearch').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#inventoryTable tbody tr');
        
        rows.forEach(row => {
            const itemName = row.cells[0].textContent.toLowerCase();
            const itemCategory = row.cells[4].textContent.toLowerCase();
            const itemUnit = row.cells[2].textContent.toLowerCase();
            
            if (itemName.includes(searchTerm) || 
                itemCategory.includes(searchTerm) ||
                itemUnit.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    // Check if $editData is set (means either an edit link was clicked or a POST failed)
    <?php if ($editData): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Fallback for failed POST, using PHP to set JS data and open modal
        const failedEditData = {
            id: '<?php echo htmlspecialchars($editData['id']); ?>',
            item_name: '<?php echo htmlspecialchars($editData['item_name'] ?? ''); ?>',
            stock_quantity: '<?php echo htmlspecialchars($editData['stock_quantity'] ?? ''); ?>',
            unit_type: '<?php echo htmlspecialchars($editData['unit_type'] ?? 'units'); ?>',
            expiration_date: '<?php echo htmlspecialchars($editData['expiration_date'] ?? ''); ?>',
            category: '<?php echo htmlspecialchars($editData['category'] ?? ''); ?>',
            low_stock_threshold: '<?php echo htmlspecialchars($editData['low_stock_threshold'] ?? ''); ?>'
        };

        // Populate form via openEditModal function
        openEditModal(failedEditData);
        // And update title to show submission error status
        modalTitle.innerText = 'Edit Inventory Item (ID: ' + failedEditData.id + ') - Submission Error';
    });
    <?php endif; ?>

</script>