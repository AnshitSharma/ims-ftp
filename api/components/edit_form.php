<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    http_response_code(401);
    echo "Unauthorized";
    exit();
}

$componentType = isset($_GET['type']) ? $_GET['type'] : '';
$componentId = isset($_GET['id']) ? $_GET['id'] : '';

// Validate component type
$validTypes = ['chassis', 'cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (!in_array($componentType, $validTypes)) {
    http_response_code(400);
    echo "Invalid component type";
    exit();
}

// Table mapping
$tableMap = [
    'chassis' => 'chassisinventory',
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Get component data
$component = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM {$tableMap[$componentType]} WHERE ID = :id");
    $stmt->bindParam(':id', $componentId, PDO::PARAM_INT);
    $stmt->execute();
    $component = $stmt->fetch();
    
    if (!$component) {
        http_response_code(404);
        echo "Component not found";
        exit();
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database error";
    exit();
}

// Load JSON data for cascading dropdowns
function loadJSONData($type) {
    $jsonPaths = [
        'cpu' => [
            'level1' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu base level 1.json',
            'level2' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu family level 2.json',
            'level3' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu-details-level-3.json'
        ],
        'motherboard' => [
            'level1' => __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard level 1.json',
            'level3' => __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json'
        ],
        'ram' => [
            'level3' => __DIR__ . '/../../All-JSON/Ram-jsons/ram_detail.json'
        ],
        'storage' => [
            'level3' => __DIR__ . '/../../All-JSON/storage-jsons/storagedetail.json'
        ],
        'caddy' => [
            'level3' => __DIR__ . '/../../All-JSON/caddy-jsons/caddy_details.json'
        ],
        'nic' => [
            'level3' => __DIR__ . '/../../All-JSON/nic-jsons/nic-level-3.json'
        ]
    ];
    
    $data = [];
    if (isset($jsonPaths[$type])) {
        foreach ($jsonPaths[$type] as $level => $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $data[$level] = json_decode($content, true);
            }
        }
    }
    
    return $data;
}

// Find current component details from JSON based on UUID
function findComponentInJSON($uuid, $jsonData) {
    if (empty($uuid) || empty($jsonData['level3'])) {
        return null;
    }
    
    foreach ($jsonData['level3'] as $brandData) {
        if (isset($brandData['models'])) {
            foreach ($brandData['models'] as $model) {
                $modelUUID = $model['UUID'] ?? $model['uuid'] ?? $model['inventory']['UUID'] ?? '';
                if ($modelUUID === $uuid) {
                    return [
                        'brand' => $brandData['brand'],
                        'series' => $brandData['series'] ?? '',
                        'model' => $model,
                        'modelName' => $model['model'] ?? $model['name'] ?? ''
                    ];
                }
            }
        }
    }
    return null;
}

$jsonData = loadJSONData($componentType);
$currentComponentData = findComponentInJSON($component['UUID'] ?? '', $jsonData);

// Generate unique form ID
$formId = 'componentEditForm_' . uniqid();
?>

<style>
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 0.5rem;
    }

    .required::after {
        content: " *";
        color: #ef4444;
    }

    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: white;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .form-input:disabled, .form-select:disabled {
        background: #f3f4f6;
        color: #6b7280;
        cursor: not-allowed;
    }

    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .form-row-three {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-primary {
        background: #667eea;
        color: white;
    }

    .btn-primary:hover:not(:disabled) {
        background: #5a67d8;
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #374151;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    .info-section {
        background: #f0f9ff;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #0ea5e9;
    }

    .info-title {
        font-size: 16px;
        font-weight: 600;
        color: #0c4a6e;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #7dd3fc;
    }

    .loading {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #ffffff;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 1s ease-in-out infinite;
    }

    .hidden {
        display: none !important;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .uuid-display {
        font-family: 'Courier New', monospace;
        background: #f8fafc;
        padding: 0.5rem;
        border-radius: 4px;
        border: 1px solid #e2e8f0;
        font-size: 12px;
        color: #4b5563;
        margin-top: 0.25rem;
    }

    .readonly-field {
        background: #f9fafb !important;
        color: #6b7280;
    }

    .component-info {
        background: #fef3c7;
        border: 1px solid #f59e0b;
        border-radius: 6px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .component-info h4 {
        margin: 0 0 0.5rem 0;
        color: #92400e;
    }

    @media (max-width: 768px) {
        .form-row, .form-row-three {
            grid-template-columns: 1fr;
        }
    }
</style>

<form id="<?php echo $formId; ?>" method="POST" class="component-form">
    
    <!-- Current Component Information -->
    <?php if ($currentComponentData): ?>
    <div class="component-info">
        <h4>Current Component: <?php echo htmlspecialchars($currentComponentData['brand'] . ' ' . $currentComponentData['modelName']); ?></h4>
        <p style="margin: 0; font-size: 14px; color: #92400e;">
            <?php if ($currentComponentData['series']): ?>
                Series: <?php echo htmlspecialchars($currentComponentData['series']); ?> | 
            <?php endif; ?>
            UUID: <?php echo htmlspecialchars($component['UUID']); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Component Identification (Read-only in edit mode) -->
    <div class="info-section">
        <h3 class="info-title">Component Identification</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Component UUID</label>
                <input type="text" id="componentUUID" name="UUID" class="form-input readonly-field" 
                       value="<?php echo htmlspecialchars($component['UUID'] ?? ''); ?>" readonly>
                <div class="uuid-display">UUID cannot be changed after creation</div>
            </div>
            
            <div class="form-group">
                <label class="form-label required">Serial Number</label>
                <input type="text" id="serialNumber" name="SerialNumber" class="form-input readonly-field" 
                       value="<?php echo htmlspecialchars($component['SerialNumber']); ?>" readonly>
                <div class="uuid-display">Serial number cannot be changed after creation</div>
            </div>
        </div>
    </div>

    <!-- Status and Server Assignment -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label required">Status</label>
            <select id="status" name="Status" class="form-select" required>
                <option value="">Select Status</option>
                <option value="1" <?php echo $component['Status'] == 1 ? 'selected' : ''; ?>>Available</option>
                <option value="2" <?php echo $component['Status'] == 2 ? 'selected' : ''; ?>>In Use</option>
                <option value="0" <?php echo $component['Status'] == 0 ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Server UUID</label>
            <input type="text" id="serverUUID" name="ServerUUID" class="form-input" 
                   value="<?php echo htmlspecialchars($component['ServerUUID'] ?? ''); ?>"
                   placeholder="Enter if component is assigned to a server">
            <small style="color: #6b7280; font-size: 12px;">Required when status is "In Use"</small>
        </div>
    </div>

    <!-- Location Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" id="location" name="Location" class="form-input" 
                   value="<?php echo htmlspecialchars($component['Location'] ?? ''); ?>"
                   placeholder="e.g., Data Center A, Warehouse East">
        </div>

        <div class="form-group">
            <label class="form-label">Rack Position</label>
            <input type="text" id="rackPosition" name="RackPosition" class="form-input" 
                   value="<?php echo htmlspecialchars($component['RackPosition'] ?? ''); ?>"
                   placeholder="e.g., Rack B4, Shelf A2">
        </div>
    </div>

    <!-- Component-specific fields -->
    <?php if ($componentType === 'nic'): ?>
    <div class="info-section">
        <h3 class="info-title">Network Interface Details</h3>
        
        <div class="form-row-three">
            <div class="form-group">
                <label class="form-label">MAC Address</label>
                <input type="text" id="macAddress" name="MacAddress" class="form-input" 
                       value="<?php echo htmlspecialchars($component['MacAddress'] ?? ''); ?>"
                       placeholder="00:1A:2B:3C:4D:5F" pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$">
            </div>
            
            <div class="form-group">
                <label class="form-label">IP Address</label>
                <input type="text" id="ipAddress" name="IPAddress" class="form-input" 
                       value="<?php echo htmlspecialchars($component['IPAddress'] ?? ''); ?>"
                       placeholder="192.168.1.100">
            </div>
            
            <div class="form-group">
                <label class="form-label">Network Name</label>
                <input type="text" id="networkName" name="NetworkName" class="form-input" 
                       value="<?php echo htmlspecialchars($component['NetworkName'] ?? ''); ?>"
                       placeholder="Production-Network">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($componentType === 'storage'): ?>
    <div class="info-section">
        <h3 class="info-title">Storage Details</h3>
        
        <div class="form-row-three">
            <div class="form-group">
                <label class="form-label">Capacity</label>
                <input type="text" id="capacity" name="Capacity" class="form-input" 
                       value="<?php echo htmlspecialchars($component['Capacity'] ?? ''); ?>"
                       placeholder="e.g., 1TB, 500GB">
            </div>
            
            <div class="form-group">
                <label class="form-label">Type</label>
                <select id="storageType" name="Type" class="form-select">
                    <option value="">Select Type</option>
                    <option value="HDD" <?php echo ($component['Type'] ?? '') == 'HDD' ? 'selected' : ''; ?>>HDD</option>
                    <option value="SSD" <?php echo ($component['Type'] ?? '') == 'SSD' ? 'selected' : ''; ?>>SSD</option>
                    <option value="NVMe" <?php echo ($component['Type'] ?? '') == 'NVMe' ? 'selected' : ''; ?>>NVMe</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Interface</label>
                <select id="interface" name="Interface" class="form-select">
                    <option value="">Select Interface</option>
                    <option value="SATA" <?php echo ($component['Interface'] ?? '') == 'SATA' ? 'selected' : ''; ?>>SATA</option>
                    <option value="SAS" <?php echo ($component['Interface'] ?? '') == 'SAS' ? 'selected' : ''; ?>>SAS</option>
                    <option value="NVMe" <?php echo ($component['Interface'] ?? '') == 'NVMe' ? 'selected' : ''; ?>>NVMe</option>
                    <option value="PCIe" <?php echo ($component['Interface'] ?? '') == 'PCIe' ? 'selected' : ''; ?>>PCIe</option>
                </select>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Date Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Purchase Date</label>
            <input type="date" id="purchaseDate" name="PurchaseDate" class="form-input"
                   value="<?php echo htmlspecialchars($component['PurchaseDate'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Installation Date</label>
            <input type="date" id="installationDate" name="InstallationDate" class="form-input"
                   value="<?php echo htmlspecialchars($component['InstallationDate'] ?? ''); ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Warranty End Date</label>
            <input type="date" id="warrantyEndDate" name="WarrantyEndDate" class="form-input"
                   value="<?php echo htmlspecialchars($component['WarrantyEndDate'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Flag</label>
            <select id="flag" name="Flag" class="form-select">
                <option value="">No Flag</option>
                <option value="Backup" <?php echo ($component['Flag'] ?? '') == 'Backup' ? 'selected' : ''; ?>>Backup</option>
                <option value="Critical" <?php echo ($component['Flag'] ?? '') == 'Critical' ? 'selected' : ''; ?>>Critical</option>
                <option value="Maintenance" <?php echo ($component['Flag'] ?? '') == 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                <option value="Testing" <?php echo ($component['Flag'] ?? '') == 'Testing' ? 'selected' : ''; ?>>Testing</option>
                <option value="Production" <?php echo ($component['Flag'] ?? '') == 'Production' ? 'selected' : ''; ?>>Production</option>
            </select>
        </div>
    </div>

    <!-- Notes -->
    <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea id="notes" name="Notes" class="form-textarea" 
                  placeholder="Additional notes, specifications, or remarks..."><?php echo htmlspecialchars($component['Notes'] ?? ''); ?></textarea>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn btn-primary" id="submitBtn">
            <span id="submitText">Update <?php echo ucfirst($componentType); ?></span>
            <span id="submitLoader" class="loading hidden"></span>
        </button>
    </div>
</form>

<script>
// Store component data and info globally
window.componentType = '<?php echo $componentType; ?>';
window.componentId = '<?php echo $componentId; ?>';
window.formId = '<?php echo $formId; ?>';
window.currentComponentData = <?php echo json_encode($currentComponentData); ?>;

// Initialize form when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeEditForm();
});

function initializeEditForm() {
    console.log('Initializing edit form for:', window.componentType, 'ID:', window.componentId);
    
    setupFormValidation();
    setupFormSubmission();
}

// Form validation
function setupFormValidation() {
    const statusSelect = document.getElementById('status');
    const serverUUIDInput = document.getElementById('serverUUID');
    
    if (statusSelect && serverUUIDInput) {
        statusSelect.addEventListener('change', function() {
            if (this.value === '2') { // In Use
                serverUUIDInput.required = true;
                serverUUIDInput.parentElement.querySelector('label').classList.add('required');
            } else {
                serverUUIDInput.required = false;
                serverUUIDInput.parentElement.querySelector('label').classList.remove('required');
            }
        });
        
        // Trigger validation check on page load
        statusSelect.dispatchEvent(new Event('change'));
    }
    
    // MAC Address validation for NIC
    const macAddressInput = document.getElementById('macAddress');
    if (macAddressInput) {
        macAddressInput.addEventListener('input', function() {
            const value = this.value;
            const macPattern = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/;
            
            if (value && !macPattern.test(value)) {
                this.setCustomValidity('Please enter a valid MAC address (e.g., 00:1A:2B:3C:4D:5F)');
            } else {
                this.setCustomValidity('');
            }
        });
    }
}

// Form submission
function setupFormSubmission() {
    const form = document.getElementById(window.formId);
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const submitLoader = document.getElementById('submitLoader');
    const cancelBtn = document.getElementById('modalCancel');
    
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Disable submit button and show loading
            submitBtn.disabled = true;
            submitText.classList.add('hidden');
            submitLoader.classList.remove('hidden');
            
            try {
                const formData = new FormData(this);
                formData.append('action', `${window.componentType}-update`);
                formData.append('id', window.componentId);
                
                // Get auth token
                const token = localStorage.getItem('bdc_token');
                if (!token) {
                    throw new Error('Authentication token not found. Please login again.');
                }
                
                const response = await fetch('/bdc_ims/api/api.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Authorization': 'Bearer ' + token
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Show success message
                    if (window.utils && window.utils.showAlert) {
                        window.utils.showAlert(`${window.componentType.toUpperCase()} component updated successfully`, 'success');
                    } else {
                        alert(`${window.componentType.toUpperCase()} component updated successfully`);
                    }
                    
                    // Close modal and refresh
                    if (window.closeModal) {
                        window.closeModal();
                    }
                    
                    // Refresh component list or dashboard
                    if (window.loadComponentList) {
                        window.loadComponentList(window.componentType);
                    } else if (window.loadDashboard) {
                        window.loadDashboard();
                    }
                    
                } else {
                    throw new Error(result.message || 'Failed to update component');
                }
                
            } catch (error) {
                console.error('Error submitting form:', error);
                
                if (window.utils && window.utils.showAlert) {
                    window.utils.showAlert(error.message || 'Failed to update component', 'error');
                } else {
                    alert(error.message || 'Failed to update component');
                }
                
            } finally {
                // Re-enable submit button
                submitBtn.disabled = false;
                submitText.classList.remove('hidden');
                submitLoader.classList.add('hidden');
            }
        });
    }
    
    // Cancel button handler
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            if (window.closeModal) {
                window.closeModal();
            }
        });
    }
}

console.log('Component Edit Form initialized for:', window.componentType, 'ID:', window.componentId);
</script>