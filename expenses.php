<?php
//
// expenses.php
// Expenses Management Page
// The $conn variable is available from the main index.php file.
//

$message = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_expense'])) {
        $expense_name = $_POST['expense_name'];
        $amount = $_POST['amount'];
        $expense_date = $_POST['expense_date'];
        $description = $_POST['description'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO expenses (expense_name, amount, expense_date, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sdss", $expense_name, $amount, $expense_date, $description);
        
        if ($stmt->execute()) {
            $message = 'Expense added successfully!';
        } else {
            $message = 'Error adding expense: ' . $stmt->error;
        }
        $stmt->close();
    }
} elseif (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = 'Expense deleted successfully!';
    } else {
        $message = 'Error deleting expense: ' . $stmt->error;
    }
    $stmt->close();
    // Preserve filter parameters when redirecting
    $redirect_url = '?page=expenses';
    if (!empty($_GET['filter_start'])) $redirect_url .= '&filter_start=' . urlencode($_GET['filter_start']);
    if (!empty($_GET['filter_end'])) $redirect_url .= '&filter_end=' . urlencode($_GET['filter_end']);
    echo '<script>window.location.href="' . $redirect_url . '";</script>';
    exit();
}

// Handle date filtering
$filter_start = isset($_GET['filter_start']) ? $_GET['filter_start'] : '';
$filter_end = isset($_GET['filter_end']) ? $_GET['filter_end'] : '';

// Build the expenses query with optional date filtering
$expenses_query = "SELECT * FROM expenses WHERE 1=1";
$params = [];
$types = '';

if (!empty($filter_start)) {
    $expenses_query .= " AND expense_date >= ?";
    $params[] = $filter_start . ' 00:00:00';
    $types .= 's';
}

if (!empty($filter_end)) {
    $expenses_query .= " AND expense_date <= ?";
    $params[] = $filter_end . ' 23:59:59';
    $types .= 's';
}

$expenses_query .= " ORDER BY expense_date DESC, created_at DESC";

// Fetch filtered expenses
$expenses = [];
$total_expenses = 0;

if (!empty($params)) {
    $stmt = $conn->prepare($expenses_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $expenses_result = $stmt->get_result();
    
    if ($expenses_result) {
        while ($row = $expenses_result->fetch_assoc()) {
            $expenses[] = $row;
            $total_expenses += $row['amount'];
        }
    }
    $stmt->close();
} else {
    $expenses_result = $conn->query($expenses_query);
    if ($expenses_result) {
        while ($row = $expenses_result->fetch_assoc()) {
            $expenses[] = $row;
            $total_expenses += $row['amount'];
        }
    }
}

// Calculate all-time earnings (not affected by filter)
$earnings_query = "SELECT SUM(total_amount) AS total_earnings FROM transactions";
$earnings_result = $conn->query($earnings_query);
$total_earnings = 0;
if ($earnings_result) {
    $earnings_row = $earnings_result->fetch_assoc();
    $total_earnings = $earnings_row['total_earnings'] ?? 0;
}

$net_earnings = $total_earnings - $total_expenses;
?>

<style>
    .expenses-page {
        padding: 20px;
    }

    .expense-form-card {
        background: #fff;
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 30px;
    }

    .expense-form-header h4 {
        margin: 0 0 20px 0;
        color: #2c3e50;
        font-size: 1.3rem;
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 10px;
    }

    .expense-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .expense-form-field label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #34495e;
        font-size: 0.95rem;
    }

    .expense-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.95rem;
        transition: border-color 0.2s;
    }

    .expense-input:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }

    .expense-textarea {
        resize: vertical;
        min-height: 80px;
    }

    .expense-submit-btn {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 6px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .expense-submit-btn:hover {
        background: linear-gradient(135deg, #2980b9, #21618c);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        transform: translateY(-1px);
    }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .summary-card {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-left: 4px solid #3498db;
    }

    .summary-card.earnings {
        border-left-color: #27ae60;
    }

    .summary-card.expenses {
        border-left-color: #e74c3c;
    }

    .summary-card.net {
        border-left-color: #f39c12;
    }

    .summary-card h4 {
        margin: 0 0 10px 0;
        color: #7f8c8d;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .summary-card .amount {
        font-size: 2rem;
        font-weight: bold;
        color: #2c3e50;
    }

    .summary-card.earnings .amount {
        color: #27ae60;
    }

    .summary-card.expenses .amount {
        color: #e74c3c;
    }

    .summary-card.net .amount {
        color: #f39c12;
    }

    .summary-card.net.negative .amount {
        color: #e74c3c;
    }

    .expenses-list-card {
        background: #fff;
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .expenses-list-header h4 {
        margin: 0 0 20px 0;
        color: #2c3e50;
        font-size: 1.3rem;
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 10px;
    }

    .table-container {
        overflow-x: auto;
    }

    .expenses-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .expenses-table thead {
        background-color: #f8f9fa;
    }

    .expenses-table th,
    .expenses-table td {
        padding: 12px;
        text-align: left;
        border: 1px solid #dee2e6;
    }

    .expenses-table th {
        font-weight: 600;
        color: #2c3e50;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    .expenses-table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .action-btn {
        color: #dc3545;
        text-decoration: none;
        font-size: 1.1rem;
        transition: color 0.2s;
    }

    .action-btn:hover {
        color: #c82333;
    }

    .no-expenses {
        text-align: center;
        padding: 40px;
        color: #7f8c8d;
        font-size: 1.1rem;
    }

    .filter-section {
        background: #f8f9fa;
        padding: 15px 20px;
        border-radius: 6px;
        margin-bottom: 20px;
        border: 1px solid #e9ecef;
    }

    .filter-controls {
        display: flex;
        gap: 15px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .filter-field {
        display: flex;
        flex-direction: column;
        min-width: 180px;
    }

    .filter-field label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 5px;
    }

    .filter-field input {
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 0.9rem;
    }

    .filter-btn {
        padding: 8px 20px;
        border: none;
        border-radius: 4px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .filter-btn.apply {
        background: #3498db;
        color: white;
    }

    .filter-btn.apply:hover {
        background: #2980b9;
    }

    .filter-btn.clear {
        background: #6c757d;
        color: white;
    }

    .filter-btn.clear:hover {
        background: #5a6268;
    }

    .filter-info {
        font-size: 0.9rem;
        color: #6c757d;
        margin-top: 10px;
    }

    .filter-info strong {
        color: #495057;
    }

    @media (max-width: 768px) {
        .expense-form-grid {
            grid-template-columns: 1fr;
        }

        .summary-cards {
            grid-template-columns: 1fr;
        }

        .filter-controls {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-field {
            width: 100%;
        }
    }
</style>

<div class="content expenses-page">
    <?php if (!empty($message)): ?>
        <div style="padding: 12px 20px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 6px; margin-bottom: 20px; font-weight: 500;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card earnings">
            <h4>All Time Earnings</h4>
            <div class="amount">₱<?php echo number_format($total_earnings, 2); ?></div>
        </div>
        <div class="summary-card expenses">
            <h4>Total Expenses<?php echo (!empty($filter_start) || !empty($filter_end)) ? ' (Filtered)' : ''; ?></h4>
            <div class="amount">₱<?php echo number_format($total_expenses, 2); ?></div>
        </div>
        <div class="summary-card net <?php echo $net_earnings < 0 ? 'negative' : ''; ?>">
            <h4>Net Earnings</h4>
            <div class="amount">₱<?php echo number_format($net_earnings, 2); ?></div>
        </div>
    </div>

    <!-- Add Expense Form -->
    <div class="expense-form-card">
        <div class="expense-form-header">
            <h4>Add New Expense</h4>
        </div>
        <form method="POST">
            <div class="expense-form-grid">
                <div class="expense-form-field">
                    <label for="expense_name">Expense Name *</label>
                    <input type="text" name="expense_name" id="expense_name" class="expense-input" required placeholder="e.g., Office Supplies, Utilities">
                </div>
                <div class="expense-form-field">
                    <label for="amount">Amount (₱) *</label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0" class="expense-input" required placeholder="0.00">
                </div>
                <div class="expense-form-field">
                    <label for="expense_date">Date *</label>
                    <input type="datetime-local" name="expense_date" id="expense_date" class="expense-input" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
            </div>
            <div class="expense-form-field" style="margin-bottom: 20px;">
                <label for="description">Description (Optional)</label>
                <textarea name="description" id="description" class="expense-input expense-textarea" placeholder="Additional details about this expense..."></textarea>
            </div>
            <button type="submit" name="add_expense" class="expense-submit-btn">
                <i class="fas fa-plus-circle"></i> Add Expense
            </button>
        </form>
    </div>

    <!-- Expenses List -->
    <div class="expenses-list-card">
        <div class="expenses-list-header">
            <h4>Expense History</h4>
        </div>
        
        <!-- Date Filter Section -->
        <div class="filter-section">
            <form method="GET" action="">
                <input type="hidden" name="page" value="expenses">
                <div class="filter-controls">
                    <div class="filter-field">
                        <label for="filter_start">From Date</label>
                        <input type="date" name="filter_start" id="filter_start" value="<?php echo htmlspecialchars($filter_start); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="filter_end">To Date</label>
                        <input type="date" name="filter_end" id="filter_end" value="<?php echo htmlspecialchars($filter_end); ?>">
                    </div>
                    <button type="submit" class="filter-btn apply">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    <a href="?page=expenses" class="filter-btn clear" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-times"></i> Clear Filter
                    </a>
                </div>
                <?php if (!empty($filter_start) || !empty($filter_end)): ?>
                    <div class="filter-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Showing expenses from:</strong> 
                        <?php 
                            if (!empty($filter_start) && !empty($filter_end)) {
                                echo date('M d, Y', strtotime($filter_start)) . ' to ' . date('M d, Y', strtotime($filter_end));
                            } elseif (!empty($filter_start)) {
                                echo date('M d, Y', strtotime($filter_start)) . ' onwards';
                            } else {
                                echo 'up to ' . date('M d, Y', strtotime($filter_end));
                            }
                        ?>
                        (<?php echo count($expenses); ?> expense<?php echo count($expenses) != 1 ? 's' : ''; ?>)
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-container">
            <?php if (!empty($expenses)): ?>
                <table class="expenses-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Expense Name</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo date('M d, Y g:i A', strtotime($expense['expense_date'])); ?></td>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($expense['expense_name']); ?></td>
                                <td style="color: #e74c3c; font-weight: 600;">₱<?php echo number_format($expense['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($expense['description'] ?: '-'); ?></td>
                                <td style="text-align: center;">
                                    <?php 
                                        $delete_url = '?page=expenses&delete=' . $expense['id'];
                                        if (!empty($filter_start)) $delete_url .= '&filter_start=' . urlencode($filter_start);
                                        if (!empty($filter_end)) $delete_url .= '&filter_end=' . urlencode($filter_end);
                                    ?>
                                    <a href="<?php echo $delete_url; ?>" 
                                       class="action-btn" 
                                       title="Delete" 
                                       onclick="return confirm('Are you sure you want to delete this expense?');">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background-color: #f8f9fa; font-weight: bold;">
                            <td colspan="2" style="text-align: right;">Total Expenses<?php echo (!empty($filter_start) || !empty($filter_end)) ? ' (Filtered)' : ''; ?>:</td>
                            <td style="color: #e74c3c; font-size: 1.1rem;">₱<?php echo number_format($total_expenses, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <div class="no-expenses">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #d1d5db; margin-bottom: 15px;"></i>
                    <p><?php echo (!empty($filter_start) || !empty($filter_end)) ? 'No expenses found for the selected date range.' : 'No expenses recorded yet.'; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>