<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/QueryModel.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    header("Location: /bdc_ims/api/login/login.php");
    exit();
}

// Get parameters
$componentType = isset($_GET['type']) ? $_GET['type'] : 'cpu';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Validate component type
$validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (!in_array($componentType, $validTypes)) {
    $componentType = 'cpu';
}

// Table mapping
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Status mapping
$statusMap = [
    '0' => ['label' => 'Failed/Decommissioned', 'color' => '#ef4444'],
    '1' => ['label' => 'Available', 'color' => '#10b981'],
    '2' => ['label' => 'In Use', 'color' => '#3b82f6']
];

// Get components
$components = [];
try {
    $query = "SELECT * FROM {$tableMap[$componentType]}";
    if ($statusFilter !== 'all') {
        $query .= " WHERE Status = :status";
    }
    $query .= " ORDER BY CreatedAt DESC";
    
    $stmt = $pdo->prepare($query);
    if ($statusFilter !== 'all') {
        $stmt->bindParam(':status', $statusFilter, PDO::PARAM_INT);
    }
    $stmt->execute();
    $components = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching components: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($componentType); ?> Inventory - BDC IMS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .navbar-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-white {
            background: white;
            color: #667eea;
        }

        .btn-white:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
        }

        .page-subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-top: 0.25rem;
        }

        .filters {
            display: flex;
            gap: 0.5rem;
        }

        .filter-btn {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            font-size: 14px;
            color: #374151;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            padding: 12px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.in-use {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 3rem;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e5e7eb;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; transform: translateX(-20px); }
        }

        .text-muted {
            color: #6b7280;
            font-size: 14px;
        }

        .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .uuid-display {
            font-family: monospace;
            font-size: 12px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .server-uuid {
            color: #667eea;
            font-weight: 500;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
        }

        .modal-close {
            width: 24px;
            height: 24px;
            cursor: pointer;
            color: #6b7280;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: #374151;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
            }

            .table-container {
                overflow-x: auto;
            }

            table {
                min-width: 800px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <h1><?php echo ucfirst($componentType); ?> Inventory</h1>
            <div class="navbar-actions">
                <a href="/bdc_ims/api/login/dashboard.php" class="btn btn-white">
                    <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a11 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
                    </svg>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <div>
                <h2 class="page-title"><?php echo ucfirst($componentType); ?> Components</h2>
                <p class="page-subtitle">Total: <?php echo count($components); ?> items</p>
            </div>
            <div class="filters">
                <button class="filter-btn <?php echo $statusFilter == 'all' ? 'active' : ''; ?>" 
                        onclick="filterByStatus('all')">All</button>
                <button class="filter-btn <?php echo $statusFilter == '1' ? 'active' : ''; ?>" 
                        onclick="filterByStatus('1')">Available</button>
                <button class="filter-btn <?php echo $statusFilter == '2' ? 'active' : ''; ?>" 
                        onclick="filterByStatus('2')">In Use</button>
                <button class="filter-btn <?php echo $statusFilter == '0' ? 'active' : ''; ?>" 
                        onclick="filterByStatus('0')">Failed</button>
            </div>
        </div>

        <div class="table-container">
            <?php if (empty($components)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5 2a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V4a2 2 0 00-2-2H5zm5 4a1 1 0 011 1v4a1 1 0 11-2 0V7a1 1 0 011-1zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                    </svg>
                    <p>No <?php echo $componentType; ?> components found.</p>
                    <button class="btn btn-primary" onclick="showAddModal('<?php echo $componentType; ?>')">
                        Add First <?php echo ucfirst($componentType); ?>
                    </button>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Serial Number / UUID</th>
                            <th>Status</th>
                            <th>Server UUID</th>
                            <th>Location</th>
                            <th>Purchase Date</th>
                            <th>Warranty End</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($components as $component): ?>
                            <tr id="row-<?php echo $component['ID']; ?>">
                                <td><?php echo htmlspecialchars($component['ID']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($component['SerialNumber'] ?? 'N/A'); ?></strong>
                                    <br>
                                    <span class="uuid-display" title="<?php echo htmlspecialchars($component['UUID']); ?>">
                                        UUID: <?php echo substr($component['UUID'], 0, 8); ?>...
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $status = $component['Status'];
                                    $statusClass = $status == 1 ? 'available' : ($status == 2 ? 'in-use' : 'failed');
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusMap[$status]['label']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($component['ServerUUID'])): ?>
                                        <span class="server-uuid" title="<?php echo htmlspecialchars($component['ServerUUID']); ?>">
                                            <?php echo substr($component['ServerUUID'], 0, 8); ?>...
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($component['Location'] ?? 'N/A'); ?>
                                    <?php if (!empty($component['RackPosition'])): ?>
                                        <br><span class="text-muted"><?php echo htmlspecialchars($component['RackPosition']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $component['PurchaseDate'] ? date('M d, Y', strtotime($component['PurchaseDate'])) : 'N/A'; ?></td>
                                <td>
                                    <?php 
                                    if ($component['WarrantyEndDate']) {
                                        $warrantyDate = strtotime($component['WarrantyEndDate']);
                                        $today = time();
                                        $isExpired = $warrantyDate < $today;
                                        echo '<span style="color: ' . ($isExpired ? '#ef4444' : '#10b981') . '">';
                                        echo date('M d, Y', $warrantyDate);
                                        echo '</span>';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="text-truncate" title="<?php echo htmlspecialchars($component['Notes'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($component['Notes'] ?? 'No notes'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-warning btn-sm" onclick="editComponent('<?php echo $componentType; ?>', <?php echo $component['ID']; ?>)">
                                            Edit
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="deleteComponent('<?php echo $componentType; ?>', <?php echo $component['ID']; ?>)">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Edit Component</h2>
                <svg class="modal-close" onclick="closeModal()" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div id="modalContent" class="modal-body">
                <!-- Dynamic content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        function filterByStatus(status) {
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('status', status);
            window.location.search = currentParams.toString();
        }

        function showAddModal(componentType) {
            // Redirect to dashboard with add modal
            window.location.href = `/bdc_ims/api/login/dashboard.php?action=add&component=${componentType}`;
        }

        function editComponent(componentType, componentId) {
            const modal = document.getElementById('editModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            
            modalTitle.textContent = `Edit ${componentType.toUpperCase()}`;
            modalContent.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            modal.classList.add('active');
            
            // Load edit form
            fetch(`/bdc_ims/api/components/edit_form.php?type=${componentType}&id=${componentId}`)
                .then(response => response.text())
                .then(html => {
                    modalContent.innerHTML = html;
                })
                .catch(error => {
                    modalContent.innerHTML = `<p class="error">Error loading form: ${error.message}</p>`;
                });
        }

        function closeModal() {
            const modal = document.getElementById('editModal');
            modal.classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        function submitEditForm(event, componentType, componentId) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', `${componentType}-edit_${componentType}`);
            formData.append('session_id', '<?php echo session_id(); ?>');
            formData.append('id', componentId);
            
            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="loading"><span class="spinner"></span></span>';
            
            fetch('/bdc_ims/api/api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success - reload page to show changes
                    location.reload();
                } else {
                    // Error message
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'error-message';
                    errorDiv.textContent = data.message || 'An error occurred. Please try again.';
                    form.insertBefore(errorDiv, form.firstChild);
                    
                    setTimeout(() => {
                        errorDiv.remove();
                    }, 5000);
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.textContent = originalText;
                
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                errorDiv.textContent = 'Network error. Please check your connection.';
                form.insertBefore(errorDiv, form.firstChild);
            });
        }

        function deleteComponent(componentType, componentId) {
            if (!confirm(`Are you sure you want to delete this ${componentType}?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', `${componentType}-remove_${componentType}`);
            formData.append('session_id', '<?php echo session_id(); ?>');
            formData.append('id', componentId);
            
            // Show loading state
            const row = document.getElementById(`row-${componentId}`);
            if (row) {
                row.style.opacity = '0.5';
                row.style.pointerEvents = 'none';
            }
            
            fetch('/bdc_ims/api/api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove the row with animation
                    if (row) {
                        row.style.animation = 'fadeOut 0.3s ease-out';
                        setTimeout(() => {
                            row.remove();
                            // Check if table is empty
                            const tbody = document.querySelector('tbody');
                            if (tbody && tbody.children.length === 0) {
                                location.reload(); // Reload to show empty state
                            }
                        }, 300);
                    }
                } else {
                    // Restore row state
                    if (row) {
                        row.style.opacity = '1';
                        row.style.pointerEvents = 'auto';
                    }
                    alert(data.message || 'Failed to delete component.');
                }
            })
            .catch(error => {
                // Restore row state
                if (row) {
                    row.style.opacity = '1';
                    row.style.pointerEvents = 'auto';
                }
                alert('Network error. Please try again.');
            });
        }
    </script>
</body>
</html>