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

// Validate component type
$validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (!in_array($componentType, $validTypes)) {
    http_response_code(400);
    echo "Invalid component type";
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

$jsonData = loadJSONData($componentType);

// Generate unique form ID to avoid conflicts
$formId = 'componentAddForm_' . uniqid();
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

    .form-input:disabled {
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

    .cascade-section {
        background: #f8fafc;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #e2e8f0;
    }

    .cascade-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #e5e7eb;
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

    .error-message {
        color: #ef4444;
        font-size: 12px;
        margin-top: 0.25rem;
    }

    .component-spec-preview {
        background: #f0f9ff;
        border: 1px solid #0ea5e9;
        border-radius: 6px;
        padding: 1rem;
        margin-top: 1rem;
        font-size: 14px;
    }

    .spec-item {
        display: flex;
        justify-content: space-between;
        padding: 0.25rem 0;
        border-bottom: 1px solid #e0f2fe;
    }

    .spec-item:last-child {
        border-bottom: none;
    }

    .uuid-display {
        font-family: 'Courier New', monospace;
        background: #f8fafc;
        padding: 0.5rem;
        border-radius: 4px;
        border: 1px solid #e2e8f0;
        font-size: 12px;
        color: #4b5563;
    }

    @media (max-width: 768px) {
        .form-row, .form-row-three {
            grid-template-columns: 1fr;
        }
    }
</style>

<form id="<?php echo $formId; ?>" method="POST" class="component-form">
    
    <!-- Component Selection Section (Cascading Dropdowns) -->
    <?php if (in_array($componentType, ['cpu', 'motherboard'])): ?>
    <div class="cascade-section">
        <h3 class="cascade-title">Component Specification</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label required">Brand</label>
                <select id="brandSelect" name="Brand" class="form-select" required>
                    <option value="">Select Brand</option>
                </select>
            </div>
            
            <?php if ($componentType === 'cpu'): ?>
            <div class="form-group">
                <label class="form-label required">Series</label>
                <select id="seriesSelect" name="Series" class="form-select" required disabled>
                    <option value="">Select Series</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="form-group">
            <label class="form-label required">Model</label>
            <select id="modelSelect" name="Model" class="form-select" required disabled>
                <option value="">Select Model</option>
            </select>
        </div>
        
        <!-- Component Specifications Preview -->
        <div id="specPreview" class="component-spec-preview hidden">
            <h4 style="margin: 0 0 0.5rem 0; color: #0ea5e9;">Selected Component Specifications</h4>
            <div id="specContent"></div>
        </div>
    </div>
    <?php elseif (in_array($componentType, ['ram', 'storage', 'caddy'])): ?>
    <div class="cascade-section">
        <h3 class="cascade-title">Component Selection</h3>
        
        <div class="form-group">
            <label class="form-label required">Select Component</label>
            <select id="componentSelect" name="ComponentModel" class="form-select" required>
                <option value="">Choose <?php echo ucfirst($componentType); ?></option>
            </select>
        </div>
        
        <!-- Component Specifications Preview -->
        <div id="specPreview" class="component-spec-preview hidden">
            <h4 style="margin: 0 0 0.5rem 0; color: #0ea5e9;">Selected Component Specifications</h4>
            <div id="specContent"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- UUID Section -->
    <div class="cascade-section">
        <h3 class="cascade-title">Component Identification</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Component UUID</label>
                <input type="text" id="componentUUID" name="UUID" class="form-input" readonly>
                <div class="uuid-display" id="uuidDisplay">UUID will be auto-filled after component selection</div>
            </div>
            
            <div class="form-group">
                <label class="form-label required">Serial Number</label>
                <input type="text" id="serialNumber" name="SerialNumber" class="form-input" required 
                       placeholder="Enter unique serial number">
            </div>
        </div>
    </div>

    <!-- Status and Server Assignment -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label required">Status</label>
            <select id="status" name="Status" class="form-select" required>
                <option value="">Select Status</option>
                <option value="1">Available</option>
                <option value="2">In Use</option>
                <option value="0">Failed</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Server UUID</label>
            <input type="text" id="serverUUID" name="ServerUUID" class="form-input" 
                   placeholder="Enter if component is assigned to a server">
            <small style="color: #6b7280; font-size: 12px;">Required when status is "In Use"</small>
        </div>
    </div>

    <!-- Location Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" id="location" name="Location" class="form-input" 
                   placeholder="e.g., Data Center A, Warehouse East">
        </div>

        <div class="form-group">
            <label class="form-label">Rack Position</label>
            <input type="text" id="rackPosition" name="RackPosition" class="form-input" 
                   placeholder="e.g., Rack B4, Shelf A2">
        </div>
    </div>

    <!-- Component-specific fields -->
    <?php if ($componentType === 'nic'): ?>
    <div class="cascade-section">
        <h3 class="cascade-title">Network Interface Details</h3>
        
        <div class="form-row-three">
            <div class="form-group">
                <label class="form-label">MAC Address</label>
                <input type="text" id="macAddress" name="MacAddress" class="form-input" 
                       placeholder="00:1A:2B:3C:4D:5F" pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$">
            </div>
            
            <div class="form-group">
                <label class="form-label">IP Address</label>
                <input type="text" id="ipAddress" name="IPAddress" class="form-input" 
                       placeholder="192.168.1.100">
            </div>
            
            <div class="form-group">
                <label class="form-label">Network Name</label>
                <input type="text" id="networkName" name="NetworkName" class="form-input" 
                       placeholder="Production-Network">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($componentType === 'storage'): ?>
    <div class="cascade-section">
        <h3 class="cascade-title">Storage Details</h3>
        
        <div class="form-row-three">
            <div class="form-group">
                <label class="form-label">Capacity</label>
                <input type="text" id="capacity" name="Capacity" class="form-input" 
                       placeholder="e.g., 1TB, 500GB">
            </div>
            
            <div class="form-group">
                <label class="form-label">Type</label>
                <select id="storageType" name="Type" class="form-select">
                    <option value="">Select Type</option>
                    <option value="HDD">HDD</option>
                    <option value="SSD">SSD</option>
                    <option value="NVMe">NVMe</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Interface</label>
                <select id="interface" name="Interface" class="form-select">
                    <option value="">Select Interface</option>
                    <option value="SATA">SATA</option>
                    <option value="SAS">SAS</option>
                    <option value="NVMe">NVMe</option>
                    <option value="PCIe">PCIe</option>
                </select>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Date Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Purchase Date</label>
            <input type="date" id="purchaseDate" name="PurchaseDate" class="form-input">
        </div>

        <div class="form-group">
            <label class="form-label">Installation Date</label>
            <input type="date" id="installationDate" name="InstallationDate" class="form-input">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Warranty End Date</label>
            <input type="date" id="warrantyEndDate" name="WarrantyEndDate" class="form-input">
        </div>

        <div class="form-group">
            <label class="form-label">Flag</label>
            <select id="flag" name="Flag" class="form-select">
                <option value="">No Flag</option>
                <option value="Backup">Backup</option>
                <option value="Critical">Critical</option>
                <option value="Maintenance">Maintenance</option>
                <option value="Testing">Testing</option>
                <option value="Production">Production</option>
            </select>
        </div>
    </div>

    <!-- Notes -->
    <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea id="notes" name="Notes" class="form-textarea" 
                  placeholder="Additional notes, specifications, or remarks..."></textarea>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn btn-primary" id="submitBtn">
            <span id="submitText">Add <?php echo ucfirst($componentType); ?></span>
            <span id="submitLoader" class="loading hidden"></span>
        </button>
    </div>
</form>

<script>
// Store JSON data and component info globally
window.componentJSONData = <?php echo json_encode($jsonData); ?>;
window.componentType = '<?php echo $componentType; ?>';
window.formId = '<?php echo $formId; ?>';

// Initialize component data structure
let componentData = {
    level1: window.componentJSONData.level1 || [],
    level2: window.componentJSONData.level2 || [],
    level3: window.componentJSONData.level3 || [],
    selectedComponent: null
};

// Initialize form when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializeComponentForm();
});

function initializeComponentForm() {
    console.log('Initializing form for component type:', window.componentType);
    console.log('Available JSON data:', componentData);
    
    switch(window.componentType) {
        case 'cpu':
        case 'motherboard':
            initializeCascadingDropdowns();
            break;
        case 'ram':
        case 'storage':
        case 'caddy':
            initializeSingleDropdown();
            break;
        case 'nic':
            // NIC has simple form, no JSON integration needed
            break;
    }
    
    setupFormValidation();
    setupFormSubmission();
}

// Initialize cascading dropdowns for CPU and Motherboard
function initializeCascadingDropdowns() {
    const brandSelect = document.getElementById('brandSelect');
    const seriesSelect = document.getElementById('seriesSelect');
    const modelSelect = document.getElementById('modelSelect');
    
    if (!brandSelect) return;
    
    // Populate brands from level1 JSON
    if (componentData.level1.length > 0) {
        componentData.level1.forEach(brand => {
            const option = document.createElement('option');
            option.value = brand.brand || brand.name;
            option.textContent = brand.brand || brand.name;
            brandSelect.appendChild(option);
        });
    }
    
    // Brand selection handler
    brandSelect.addEventListener('change', function() {
        const selectedBrand = this.value;
        resetDropdowns(['seriesSelect', 'modelSelect']);
        
        if (selectedBrand && window.componentType === 'cpu') {
            populateSeries(selectedBrand);
        } else if (selectedBrand && window.componentType === 'motherboard') {
            populateMotherboardModels(selectedBrand);
        }
    });
    
    // Series selection handler (CPU only)
    if (seriesSelect) {
        seriesSelect.addEventListener('change', function() {
            const selectedSeries = this.value;
            const selectedBrand = brandSelect.value;
            resetDropdowns(['modelSelect']);
            
            if (selectedSeries && selectedBrand) {
                populateModels(selectedBrand, selectedSeries);
            }
        });
    }
    
    // Model selection handler
    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            const selectedModel = this.value;
            if (selectedModel) {
                handleModelSelection(selectedModel);
            }
        });
    }
}

// Initialize single dropdown for RAM, Storage, Caddy
function initializeSingleDropdown() {
    const componentSelect = document.getElementById('componentSelect');
    if (!componentSelect) return;
    
    if (componentData.level3.length > 0) {
        componentData.level3.forEach(item => {
            if (item.models && Array.isArray(item.models)) {
                item.models.forEach(model => {
                    const option = document.createElement('option');
                    const modelName = model.model || model.name || model.part_number;
                    const brand = item.brand || item.manufacturer || '';
                    
                    option.value = JSON.stringify(model);
                    option.textContent = brand ? `${brand} - ${modelName}` : modelName;
                    componentSelect.appendChild(option);
                });
            }
        });
    }
    
    componentSelect.addEventListener('change', function() {
        if (this.value) {
            try {
                const selectedModel = JSON.parse(this.value);
                handleModelSelection(selectedModel);
            } catch (e) {
                console.error('Error parsing selected model:', e);
            }
        }
    });
}

// Populate series dropdown for CPU
function populateSeries(selectedBrand) {
    const seriesSelect = document.getElementById('seriesSelect');
    if (!seriesSelect) return;
    
    const brandData = componentData.level2.find(item => 
        (item.brand === selectedBrand || item.name === selectedBrand)
    );
    
    if (brandData && brandData.series) {
        brandData.series.forEach(series => {
            const option = document.createElement('option');
            option.value = series.name || series.series;
            option.textContent = series.name || series.series;
            seriesSelect.appendChild(option);
        });
        seriesSelect.disabled = false;
    }
}

// Populate models for CPU
function populateModels(selectedBrand, selectedSeries) {
    const modelSelect = document.getElementById('modelSelect');
    if (!modelSelect) return;
    
    const brandData = componentData.level3.find(item => 
        (item.brand === selectedBrand || item.name === selectedBrand)
    );
    
    if (brandData && brandData.models) {
        const filteredModels = brandData.models.filter(model => {
            const modelSeries = model.series || model.family;
            return modelSeries === selectedSeries;
        });
        
        filteredModels.forEach(model => {
            const option = document.createElement('option');
            option.value = JSON.stringify(model);
            option.textContent = model.model || model.name;
            modelSelect.appendChild(option);
        });
        
        modelSelect.disabled = false;
    }
}

// Populate motherboard models directly
function populateMotherboardModels(selectedBrand) {
    const modelSelect = document.getElementById('modelSelect');
    if (!modelSelect) return;
    
    const brandData = componentData.level3.find(item => 
        (item.brand === selectedBrand || item.name === selectedBrand)
    );
    
    if (brandData && brandData.models) {
        brandData.models.forEach(model => {
            const option = document.createElement('option');
            option.value = JSON.stringify(model);
            option.textContent = model.model || model.name;
            modelSelect.appendChild(option);
        });
        
        modelSelect.disabled = false;
    }
}

// Handle model selection and update UUID
function handleModelSelection(modelData) {
    let model;
    
    if (typeof modelData === 'string') {
        try {
            model = JSON.parse(modelData);
        } catch (e) {
            console.error('Error parsing model data:', e);
            return;
        }
    } else {
        model = modelData;
    }
    
    componentData.selectedComponent = model;
    
    // Extract UUID from various possible locations
    let uuid = '';
    if (model.UUID) {
        uuid = model.UUID;
    } else if (model.uuid) {
        uuid = model.uuid;
    } else if (model.inventory && model.inventory.UUID) {
        uuid = model.inventory.UUID;
    } else if (model.id) {
        uuid = model.id;
    }
    
    // Update UUID field
    const uuidField = document.getElementById('componentUUID');
    const uuidDisplay = document.getElementById('uuidDisplay');
    
    if (uuidField) {
        uuidField.value = uuid;
    }
    
    if (uuidDisplay) {
        uuidDisplay.textContent = uuid || 'No UUID available for this component';
    }
    
    // Show specifications preview
    displayComponentSpecs(model);
}

// Display component specifications
function displayComponentSpecs(model) {
    const specPreview = document.getElementById('specPreview');
    const specContent = document.getElementById('specContent');
    
    if (!specPreview || !specContent) return;
    
    let specs = [];
    
    // Extract specifications based on component type
    switch(window.componentType) {
        case 'cpu':
            if (model.cores) specs.push(['Cores', model.cores]);
            if (model.threads) specs.push(['Threads', model.threads]);
            if (model.base_frequency) specs.push(['Base Frequency', model.base_frequency]);
            if (model.boost_frequency) specs.push(['Boost Frequency', model.boost_frequency]);
            if (model.tdp) specs.push(['TDP', model.tdp + 'W']);
            if (model.cache && model.cache.l3) specs.push(['L3 Cache', model.cache.l3]);
            break;
            
        case 'motherboard':
            if (model.socket) specs.push(['Socket', typeof model.socket === 'object' ? model.socket.type : model.socket]);
            if (model.chipset) specs.push(['Chipset', model.chipset]);
            if (model.form_factor) specs.push(['Form Factor', model.form_factor]);
            if (model.memory && model.memory.max_capacity) specs.push(['Max Memory', model.memory.max_capacity]);
            break;
            
        case 'ram':
            if (model.capacity) specs.push(['Capacity', model.capacity]);
            if (model.type) specs.push(['Type', model.type]);
            if (model.frequency) specs.push(['Frequency', model.frequency]);
            if (model.form_factor) specs.push(['Form Factor', model.form_factor]);
            break;
            
        case 'storage':
            if (model.capacity) specs.push(['Capacity', model.capacity]);
            if (model.type) specs.push(['Type', model.type]);
            if (model.interface) specs.push(['Interface', model.interface]);
            if (model.form_factor) specs.push(['Form Factor', model.form_factor]);
            break;
            
        case 'caddy':
            if (model.size) specs.push(['Size', model.size]);
            if (model.compatibility) specs.push(['Compatibility', model.compatibility]);
            break;
    }
    
    // Generic specs
    if (model.model) specs.push(['Model', model.model]);
    if (model.part_number) specs.push(['Part Number', model.part_number]);
    
    if (specs.length > 0) {
        specContent.innerHTML = specs.map(([label, value]) => 
            `<div class="spec-item"><strong>${label}:</strong> <span>${value}</span></div>`
        ).join('');
        specPreview.classList.remove('hidden');
    }
}

// Reset dropdown options
function resetDropdowns(dropdownIds) {
    dropdownIds.forEach(id => {
        const dropdown = document.getElementById(id);
        if (dropdown) {
            dropdown.innerHTML = '<option value="">Select...</option>';
            dropdown.disabled = true;
        }
    });
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
                formData.append('action', `${window.componentType}-add`);
                
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
                        window.utils.showAlert(`${window.componentType.toUpperCase()} component added successfully`, 'success');
                    } else {
                        alert(`${window.componentType.toUpperCase()} component added successfully`);
                    }
                    
                    // Close modal and refresh dashboard
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
                    throw new Error(result.message || 'Failed to add component');
                }
                
            } catch (error) {
                console.error('Error submitting form:', error);
                
                if (window.utils && window.utils.showAlert) {
                    window.utils.showAlert(error.message || 'Failed to add component', 'error');
                } else {
                    alert(error.message || 'Failed to add component');
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

// Utility function to generate UUID (fallback if not provided by JSON)
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c == 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

console.log('Component Add Form initialized for:', window.componentType);