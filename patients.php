<?php
//
// patients.php
// This is the page for the Patients management section.
// It includes logic for creating, reading, updating, and deleting patient data.
//
// The $conn variable is available from the main index.php file.
//
$message = '';
$editData = null;

// Lightweight AJAX endpoint for duplicate name check
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $qname = trim($_GET['fullname'] ?? '');
    $qdob = trim($_GET['date_of_birth'] ?? '');
    $exists = false;
    if ($qname !== '') {
        if ($qdob !== '') {
            $stmt = $conn->prepare("SELECT 1 FROM patients WHERE LOWER(fullname)=LOWER(?) AND date_of_birth = ? LIMIT 1");
            $stmt->bind_param("ss", $qname, $qdob);
        } else {
            $stmt = $conn->prepare("SELECT 1 FROM patients WHERE LOWER(fullname)=LOWER(?) LIMIT 1");
            $stmt->bind_param("s", $qname);
        }
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
    }
    echo json_encode(['exists' => $exists]);
    exit();
}

// Lightweight JSON endpoint to fetch a patient's transaction history
if (isset($_GET['patient_txn']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $pid = (int)$_GET['id'];
    $out = ['transactions' => []];
    if ($pid > 0) {
        // Fetch transactions
        $stmt = $conn->prepare("SELECT transaction_id, transaction_date, total_amount, description, services, medicine_given FROM transactions WHERE patient_id = ? ORDER BY transaction_date DESC");
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $tx = [
                'transaction_id' => (int)$row['transaction_id'],
                'transaction_date' => $row['transaction_date'],
                'total_amount' => (float)$row['total_amount'],
                'description' => (string)($row['description'] ?? ''),
                'services' => [],
                'medicines' => []
            ];
            // Services via join
            $s = $conn->prepare("SELECT s.service_name, ts.price_at_transaction FROM transaction_services ts JOIN services s ON ts.service_id = s.id WHERE ts.transaction_id = ?");
            $s->bind_param('i', $row['transaction_id']);
            $s->execute();
            $sr = $s->get_result();
            while ($srw = $sr->fetch_assoc()) {
                $tx['services'][] = ['name' => $srw['service_name'], 'price' => (float)$srw['price_at_transaction']];
            }
            $s->close();
            // Medicines from JSON
            if (!empty($row['medicine_given'])) {
                $med = json_decode($row['medicine_given'], true);
                if (is_array($med)) {
                    foreach ($med as $m) {
                        if (isset($m['name'])) {
                            $tx['medicines'][] = [
                                'name' => (string)$m['name'],
                                'quantity' => isset($m['quantity']) ? (float)$m['quantity'] : null,
                                'total' => isset($m['total']) ? (float)$m['total'] : null
                            ];
                        }
                    }
                }
            }
            $out['transactions'][] = $tx;
        }
        $stmt->close();
    }
    echo json_encode($out);
    exit();
}

// Check for success message after redirect
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $message = 'Patient deleted successfully!';
}

// Handle patient deletion first, before any output
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM patients WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        $message = 'Patient deleted successfully!';
    } else {
        $message = 'Error deleting patient: ' . $stmt->error;
    }
    $stmt->close();
    // Use JavaScript to redirect after setting the message
    echo '<script>window.location.href = "?page=patients&deleted=1";</script>';
    exit();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_patient'])) {
        $fullname = $_POST['fullname'];
        $date_of_birth = $_POST['date_of_birth'];
        $sex = $_POST['sex'];
        $contact_number = $_POST['contact_number'];
        $address = $_POST['address'];

        // Server-side duplicate guard (fullname + DOB if provided, fallback to name only)
        if (!empty($fullname)) {
            if (!empty($date_of_birth)) {
                $dup = $conn->prepare("SELECT 1 FROM patients WHERE LOWER(fullname)=LOWER(?) AND date_of_birth = ? LIMIT 1");
                $dup->bind_param("ss", $fullname, $date_of_birth);
            } else {
                $dup = $conn->prepare("SELECT 1 FROM patients WHERE LOWER(fullname)=LOWER(?) LIMIT 1");
                $dup->bind_param("s", $fullname);
            }
            $dup->execute();
            $dup->store_result();
            if ($dup->num_rows > 0) {
                $dup->close();
                $message = 'This patient already exists.';
            } else {
                $dup->close();
            }
        }

        if (empty($message)) {
            $patient_code = 'PAT-' . rand(100, 999);

            $stmt = $conn->prepare("INSERT INTO patients (patient_code, fullname, date_of_birth, sex, contact_number, address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $patient_code, $fullname, $date_of_birth, $sex, $contact_number, $address);

            if ($stmt->execute()) {
                $message = 'Patient added successfully!';
            } else {
                $message = 'Error adding patient: ' . $stmt->error;
            }
            $stmt->close();
        }
    } elseif (isset($_POST['update_patient'])) {
        $id = $_POST['patient_id'];
        $fullname = $_POST['fullname'];
        $date_of_birth = $_POST['date_of_birth'];
        $sex = $_POST['sex'];
        $contact_number = $_POST['contact_number'];
        $address = $_POST['address'];

        $stmt = $conn->prepare("UPDATE patients SET fullname = ?, date_of_birth = ?, sex = ?, contact_number = ?, address = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $fullname, $date_of_birth, $sex, $contact_number, $address, $id);

        if ($stmt->execute()) {
            $message = 'Patient updated successfully!';
        } else {
            $message = 'Error updating patient: ' . $stmt->error;
        }
        $stmt->close();
    }
} elseif (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
}

// Fetch and display patient data (with optional search)
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchStmt = null;
if ($search !== '') {
    $like = '%' . $search . '%';
    $searchStmt = $conn->prepare("SELECT * FROM patients WHERE fullname LIKE ? OR patient_code LIKE ? OR contact_number LIKE ? OR address LIKE ? ORDER BY id DESC");
    $searchStmt->bind_param('ssss', $like, $like, $like, $like);
    $searchStmt->execute();
    $result = $searchStmt->get_result();
    // We'll close $searchStmt after rendering since $result is used below; mysqli keeps data buffered
} else {
    $result = $conn->query("SELECT * FROM patients ORDER BY id DESC");
}
?>
<div class="content patients-page">
    <div class="card">
        <div class="card-body">
        <?php if (!empty($message)): ?>
            <div style="padding: 10px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; margin-bottom: 15px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="patient-form-panel">
            <h4 class="patient-form-title"><?php echo $editData ? 'Edit Patient' : 'Add Patient Info'; ?></h4>
            <input type="hidden" name="patient_id" value="<?php echo $editData ? htmlspecialchars($editData['id']) : ''; ?>">
            <div class="patient-form-grid">
                <div class="patient-form-group">
                    <label for="fullname" class="patient-form-label">Full Name</label>
                    <input type="text" name="fullname" id="fullname" class="patient-form-control" value="<?php echo $editData ? htmlspecialchars($editData['fullname']) : ''; ?>" required>
                </div>
                <div class="patient-form-group">
                    <label for="date_of_birth" class="patient-form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" id="date_of_birth" class="patient-form-control" value="<?php echo $editData ? htmlspecialchars($editData['date_of_birth']) : ''; ?>" required>
                </div>
                <div class="patient-form-group full-width">
                    <label for="address" class="patient-form-label">Address</label>
                    <input type="text" name="address" id="address" class="patient-form-control" value="<?php echo $editData ? htmlspecialchars($editData['address']) : ''; ?>">
                </div>
                <div class="patient-form-group">
                    <label for="sex" class="patient-form-label">Sex</label>
                    <select name="sex" id="sex" class="patient-form-control">
                        <option value="Male" <?php echo ($editData && $editData['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($editData && $editData['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Prefer not to say" <?php echo ($editData && $editData['sex'] == 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                    </select>
                </div>
                <div class="patient-form-group">
                    <label for="contact_number" class="patient-form-label">Contact Number</label>
                    <input type="text" name="contact_number" id="contact_number" class="patient-form-control" value="<?php echo $editData ? htmlspecialchars($editData['contact_number']) : ''; ?>">
                </div>
            </div>
            <div class="patient-form-actions">
                <button id="addPatientBtn" type="submit" name="<?php echo $editData ? 'update_patient' : 'add_patient'; ?>" class="patient-primary-btn"><?php echo $editData ? 'Update Patient' : 'Add Patient'; ?></button>
                <?php if ($editData): ?>
                    <a href="?page=patients" class="patient-cancel-link">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>

        <p>Here you can manage all patient records. The table below shows sample data.</p>
        <h4 style="margin-top: 15px; margin-bottom: 8px; font-weight: 700;">Patients List</h4>
        <form method="GET" action="" class="patients-search-form">
            <input type="hidden" name="page" value="patients">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, code, contact or address" class="patients-search-input">
            <button type="submit" class="patients-search-btn">Search</button>
            <?php if ($search !== ''): ?>
                <a href="?page=patients" class="patients-clear-btn">Clear</a>
            <?php endif; ?>
        </form>
        <div class="table-container">
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background-color: #f2f2f2;">
                    <th style="border: 1px solid #ddd; padding: 8px;">Patient Code</th>
                    <th style="border: 1px solid #ddd; padding: 8px;">Full Name</th>
                    <th style="border: 1px solid #ddd; padding: 8px;">Date of Birth</th>
                    <th style="border: 1px solid #ddd; padding: 8px;">Sex</th>
                    <th style="border: 1px solid #ddd; padding: 8px;">Contact Number</th>
                    <th style="border: 1px solid #ddd; padding: 8px;">Address</th>
                    <th style="border: 1px solid #ddd; padding: 8px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo '
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['patient_code']) . '</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['fullname']) . '</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['date_of_birth']) . '</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['sex'] ?? 'Prefer not to say') . '</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['contact_number']) . '</td>
                            <td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($row['address']) . '</td>
                            <td style="border: 1px solid #ddd; padding: 8px; white-space: nowrap; text-align: center;">
                                <a href="javascript:void(0)" class="action-btn" title="View Details" style="color:#17a2b8; margin-right:10px;" onclick="openTxnModal(' . (int)$row['id'] . ', \'' . htmlspecialchars(addslashes($row['fullname'])) . '\')"><i class="fas fa-eye"></i></a>
                                <a href="?page=patients&edit=' . htmlspecialchars($row['id']) . '" class="action-btn edit" title="Edit" style="color: #007bff; margin-right: 10px;"><i class="fas fa-edit"></i></a>
                                <a href="?page=patients&delete=' . htmlspecialchars($row['id']) . '" class="action-btn delete" title="Delete" onclick="return confirm(\'Are you sure you want to delete this patient?\');"><i class="fas fa-trash-alt"></i></a>
                            </td>
                        </tr>';
                    }
                } else {
                    echo '<tr><td colspan="7" style="text-align:center; padding: 8px;">No patients found.</td></tr>';
                }
                ?>
            </tbody>
        </table>
        </div>
        <?php if ($searchStmt instanceof mysqli_stmt) { $searchStmt->close(); } ?>
    </div>
</div>
</div>

<script>
// Live duplicate check: fullname + (optional) DOB
(function(){
  const nameInput = document.getElementById('fullname');
  const dobInput = document.getElementById('date_of_birth');
  const submitBtn = document.getElementById('addPatientBtn');
  const msgBox = document.createElement('div');
  msgBox.style.marginTop = '8px';
  msgBox.style.color = '#dc3545';
  msgBox.style.fontWeight = '600';
  nameInput && nameInput.parentNode.appendChild(msgBox);

  let timer; 
  function check(){
    const fullname = (nameInput?.value || '').trim();
    const dob = dobInput?.value || '';
    if (!fullname) { msgBox.textContent=''; submitBtn.disabled=false; return; }
    const url = `?page=patients&check_name=1&fullname=${encodeURIComponent(fullname)}&date_of_birth=${encodeURIComponent(dob)}`;
    fetch(url, {cache:'no-store'})
      .then(r=>r.ok ? r.json(): {exists:false})
      .then(data=>{
        if (data && data.exists){
          msgBox.textContent = 'This patient already exists';
          submitBtn.disabled = true;
          submitBtn.style.opacity = '0.6';
          submitBtn.style.cursor = 'not-allowed';
        } else {
          msgBox.textContent = '';
          submitBtn.disabled = false;
          submitBtn.style.opacity = '';
          submitBtn.style.cursor = '';
        }
      }).catch(()=>{});
  }
  function debounce(){ clearTimeout(timer); timer = setTimeout(check, 300); }
  if (nameInput){ nameInput.addEventListener('input', debounce); nameInput.addEventListener('blur', check); }
  if (dobInput){ dobInput.addEventListener('change', check); }
})();

// Transactions modal
let txnModal; let txnContent; let txnTitle; let currentPatientId = null;
document.addEventListener('DOMContentLoaded', function(){
  txnModal = document.createElement('div');
  txnModal.className = 'txn-modal';
  txnModal.style.position='fixed'; txnModal.style.left=0; txnModal.style.top=0; txnModal.style.right=0; txnModal.style.bottom=0;
  txnModal.style.background='rgba(0,0,0,0.4)'; txnModal.style.display='none'; txnModal.style.zIndex=1000;
  
  const inner = document.createElement('div');
  inner.className = 'txn-modal-content';
  inner.style.maxWidth='800px'; 
  inner.style.margin='40px auto'; 
  inner.style.borderRadius='6px'; 
  inner.style.boxShadow='0 10px 30px rgba(0,0,0,0.2)'; 
  inner.style.overflow='hidden';
  
  inner.innerHTML = `
    <div class="txn-modal-header">
      <h3 id="txnTitle" class="txn-modal-title">Patient Transactions</h3>
      <div>
        <button type="button" class="txn-print-btn" onclick="printPatientTransactions()">
          <i class="fas fa-print"></i> Print
        </button>
        <button class="txn-close-btn" onclick="closeTxnModal()">×</button>
      </div>
    </div>
    <div id="txnBody" class="txn-modal-body"></div>
  `;
  
  txnModal.appendChild(inner); 
  document.body.appendChild(txnModal);
  txnContent = document.getElementById('txnBody'); 
  txnTitle = document.getElementById('txnTitle');
  
  // Apply dark mode if needed
  updateTxnModalTheme();
  
  // Watch for theme changes
  const observer = new MutationObserver(updateTxnModalTheme);
  observer.observe(document.body, { 
    attributes: true,
    attributeFilter: ['class']
  });
});

function updateTxnModalTheme() {
  if (!txnModal) return;
  
  const isDark = document.body.classList.contains('theme-dark');
  const content = txnModal.querySelector('.txn-modal-content');
  const header = txnModal.querySelector('.txn-modal-header');
  
  if (isDark) {
    content.style.backgroundColor = '#1f2937';
    content.style.color = '#e5e7eb';
    content.style.border = '1px solid #374151';
    
    if (header) {
      header.style.borderBottom = '1px solid #374151';
      header.style.backgroundColor = '#1f2937';
      header.style.color = '#e5e7eb';
    }
    
    // Update any tables inside the modal
    const tables = txnModal.querySelectorAll('table');
    tables.forEach(table => {
      table.style.color = '#e5e7eb';
      table.style.borderColor = '#4b5563';
      table.style.backgroundColor = '#1f2937';
    });
    
    const cells = txnModal.querySelectorAll('th, td');
    cells.forEach(cell => {
      cell.style.borderColor = '#4b5563';
      cell.style.color = '#e5e7eb';
      cell.style.backgroundColor = '#1f2937';
    });
    
    // Style the print button
    const printBtn = txnModal.querySelector('.txn-print-btn');
    if (printBtn) {
      printBtn.style.backgroundColor = '#2563eb';
      printBtn.style.borderColor = '#2563eb';
      printBtn.style.color = '#ffffff';
    }
    
    // Style the modal body
    const modalBody = txnModal.querySelector('.txn-modal-body');
    if (modalBody) {
      modalBody.style.backgroundColor = '#1f2937';
      modalBody.style.color = '#e5e7eb';
    }
  } else {
    content.style.backgroundColor = '#fff';
    content.style.color = '#333';
    content.style.border = 'none';
    
    if (header) {
      header.style.borderBottom = '1px solid #eee';
      header.style.backgroundColor = '#f8f9fa';
      header.style.color = '';
    }
    
    // Reset table styles
    const tables = txnModal.querySelectorAll('table');
    tables.forEach(table => {
      table.style.color = '';
      table.style.borderColor = '';
      table.style.backgroundColor = '';
    });
    
    const cells = txnModal.querySelectorAll('th, td');
    cells.forEach(cell => {
      cell.style.borderColor = '';
      cell.style.color = '';
      cell.style.backgroundColor = '';
    });
    
    // Reset print button
    const printBtn = txnModal.querySelector('.txn-print-btn');
    if (printBtn) {
      printBtn.style.backgroundColor = '';
      printBtn.style.borderColor = '';
      printBtn.style.color = '';
    }
    
    // Reset modal body
    const modalBody = txnModal.querySelector('.txn-modal-body');
    if (modalBody) {
      modalBody.style.backgroundColor = '';
      modalBody.style.color = '';
    }
  }
}

function openTxnModal(pid, name){
  if (!txnModal) return; currentPatientId = pid; txnTitle.textContent = 'Transactions for ' + name;
  txnContent.innerHTML = '<div class="txn-loading">Loading...</div>';
  txnModal.style.display='block';
  fetch(`?page=patients&patient_txn=1&id=${encodeURIComponent(pid)}`, {cache:'no-store'})
    .then(r=>r.json())
    .then(data=>{
      const txns = (data && data.transactions) ? data.transactions : [];
      if (!txns.length){ 
        txnContent.innerHTML = '<div class="txn-no-data">No transactions found.</div>'; 
        return; 
      }
      const parts = txns.map(t=>{
        const services = (t.services||[]).map(s=>`${s.name} (₱${Number(s.price).toFixed(2)})`).join(', ');
        const meds = (t.medicines||[]).map(m=>`${m.name}${m.quantity? ' x'+m.quantity: ''}`).join(', ');
        return `
        <div class="txn-item">
            <div class="txn-item-header">
                <div class="txn-date"><strong>Date:</strong> ${t.transaction_date}</div>
                <div class="txn-total"><strong>Total:</strong> ₱${Number(t.total_amount).toFixed(2)}</div>
            </div>
            <div class="txn-item-body">
                ${t.description ? `<div class="txn-description"><strong>Description:</strong> ${escapeHtml(t.description)}</div>` : ''}
                ${services ? `<div class="txn-services"><strong>Services:</strong> ${services}</div>` : ''}
                ${meds ? `<div class="txn-medicines"><strong>Medicines:</strong> ${meds}</div>` : ''}
            </div>
        </div>`;
      });
      txnContent.innerHTML = parts.join('');
      
      // Apply theme to newly loaded content
      updateTxnModalTheme();
    }).catch(()=>{ 
      txnContent.innerHTML = '<div class="txn-error">Failed to load transactions.</div>'; 
      updateTxnModalTheme();
    });
}
function closeTxnModal(){ if (txnModal) txnModal.style.display='none'; }
function printPatientTransactions(){
  if (!currentPatientId) return;
  window.open('print_patient_transactions.php?patient_id=' + encodeURIComponent(currentPatientId), '_blank');
}
function escapeHtml(str){ return String(str).replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
</script>
