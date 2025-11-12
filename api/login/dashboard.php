<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/QueryModel.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    session_unset();
    session_destroy();
    header("Location: /bdc_ims/api/login/login.php");
    exit();
}

// Get filter from query parameter, default to 'available'
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '1';
$componentType = isset($_GET['component']) ? $_GET['component'] : 'all';

// Check if we need to open add modal on load
$autoOpenAdd = isset($_GET['action']) && $_GET['action'] == 'add' && isset($_GET['component']);
$addComponentType = $autoOpenAdd ? $_GET['component'] : '';

// Status mapping
$statusMap = [
    '0' => 'Failed/Decommissioned',
    '1' => 'Available',
    '2' => 'In Use'
];

// Function to get component counts
function getComponentCounts($pdo, $statusFilter = null) {
    $counts = [
        'cpu' => 0,
        'ram' => 0,
        'storage' => 0,
        'motherboard' => 0,
        'nic' => 0,
        'caddy' => 0,
        'total' => 0
    ];
    
    $tables = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    foreach ($tables as $key => $table) {
        try {
            $query = "SELECT COUNT(*) as count FROM $table";
            if ($statusFilter !== null && $statusFilter !== 'all') {
                $query .= " WHERE Status = :status";
            }
            
            $stmt = $pdo->prepare($query);
            if ($statusFilter !== null && $statusFilter !== 'all') {
                $stmt->bindParam(':status', $statusFilter, PDO::PARAM_INT);
            }
            $stmt->execute();
            $result = $stmt->fetch();
            $counts[$key] = $result['count'];
            $counts['total'] += $result['count'];
        } catch (PDOException $e) {
            error_log("Error counting $key: " . $e->getMessage());
        }
    }
    
    return $counts;
}

// Get counts for current filter
$componentCounts = getComponentCounts($pdo, $statusFilter);

// Get counts by status for overview
$statusCounts = [
    'available' => getComponentCounts($pdo, '1')['total'],
    'in_use' => getComponentCounts($pdo, '2')['total'],
    'failed' => getComponentCounts($pdo, '0')['total'],
    'total' => getComponentCounts($pdo, 'all')['total']
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - BDC IMS</title>
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info span {
            font-size: 14px;
            opacity: 0.9;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Status Overview Cards */
        .status-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .status-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .status-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .status-card.active {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .status-card h3 {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-card .count {
            color: #1f2937;
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
        }

        .status-card.available h3 { color: #10b981; }
        .status-card.in-use h3 { color: #3b82f6; }
        .status-card.failed h3 { color: #ef4444; }
        .status-card.total h3 { color: #8b5cf6; }

        /* Component Grid */
        .component-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .component-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .component-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .component-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .component-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .component-icon {
            width: 24px;
            height: 24px;
            color: #667eea;
        }

        .component-count {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .component-status {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 1rem;
        }

        .component-actions {
            display: flex;
            gap: 0.5rem;
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

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-icon {
            width: 16px;
            height: 16px;
        }

        /* Filters */
        .filters {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .filters-header {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
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

        /* Modal */
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

        /* Loading Spinner */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
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

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .navbar-content {
                flex-direction: column;
                gap: 1rem;
            }

            .status-overview {
                grid-template-columns: repeat(2, 1fr);
            }

            .component-grid {
                grid-template-columns: 1fr;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
        }

        /* Empty State */
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

        .empty-state p {
            font-size: 16px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <h1>BDC Inventory Management System</h1>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                <a href="/bdc_ims/api/login/logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Status Overview -->
        <div class="status-overview">
            <div class="status-card available <?php echo $statusFilter == '1' ? 'active' : ''; ?>" onclick="filterByStatus('1')">
                <h3>Available</h3>
                <div class="count"><?php echo $statusCounts['available']; ?></div>
            </div>
            <div class="status-card in-use <?php echo $statusFilter == '2' ? 'active' : ''; ?>" onclick="filterByStatus('2')">
                <h3>In Use</h3>
                <div class="count"><?php echo $statusCounts['in_use']; ?></div>
            </div>
            <div class="status-card failed <?php echo $statusFilter == '0' ? 'active' : ''; ?>" onclick="filterByStatus('0')">
                <h3>Failed/Decommissioned</h3>
                <div class="count"><?php echo $statusCounts['failed']; ?></div>
            </div>
            <div class="status-card total <?php echo $statusFilter == 'all' ? 'active' : ''; ?>" onclick="filterByStatus('all')">
                <h3>Total Components</h3>
                <div class="count"><?php echo $statusCounts['total']; ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <div class="filters-header">Component Filters</div>
            <div class="filter-group">
                <span class="filter-label">View:</span>
                <button class="filter-btn <?php echo $componentType == 'all' ? 'active' : ''; ?>" onclick="filterByComponent('all')">All Components</button>
                <button class="filter-btn <?php echo $componentType == 'cpu' ? 'active' : ''; ?>" onclick="filterByComponent('cpu')">CPUs</button>
                <button class="filter-btn <?php echo $componentType == 'ram' ? 'active' : ''; ?>" onclick="filterByComponent('ram')">RAM</button>
                <button class="filter-btn <?php echo $componentType == 'storage' ? 'active' : ''; ?>" onclick="filterByComponent('storage')">Storage</button>
                <button class="filter-btn <?php echo $componentType == 'motherboard' ? 'active' : ''; ?>" onclick="filterByComponent('motherboard')">Motherboards</button>
                <button class="filter-btn <?php echo $componentType == 'nic' ? 'active' : ''; ?>" onclick="filterByComponent('nic')">NICs</button>
                <button class="filter-btn <?php echo $componentType == 'caddy' ? 'active' : ''; ?>" onclick="filterByComponent('caddy')">Caddies</button>
            </div>
        </div>

        <!-- Component Grid -->
        <div class="component-grid">
            <?php if ($componentType == 'all' || $componentType == 'cpu'): ?>
            <div class="component-card">
                <div class="component-header">
                    <div class="component-title">
                        <svg class="component-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M13 7H7v6h6V7z"/>
                            <path fill-rule="evenodd" d="M7 2a1 1 0 012 0v1h2V2a1 1 0 112 0v1h2a2 2 0 012 2v2h1a1 1 0 110 2h-1v2h1a1 1 0 110 2h-1v2a2 2 0 01-2 2h-2v1a1 1 0 11-2 0v-1H9v1a1 1 0 11-2 0v-1H5a2 2 0 01-2-2v-2H2a1 1 0 110-2h1V9H2a1 1 0 010-2h1V5a2 2 0 012-2h2V2zM5 5h10v10H5V5z" clip-rule="evenodd"/>
                        </svg>
                        CPUs
                    </div>
                </div>
                <div class="component-count"><?php echo $componentCounts['cpu']; ?></div>
                <div class="component-status">
                    Status: <?php echo $statusFilter == 'all' ? 'All' : $statusMap[$statusFilter]; ?>
                </div>
                <div class="component-actions">
                    <button class="btn btn-primary" onclick="showAddModal('cpu')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Add CPU
                    </button>
                    <button class="btn btn-secondary" onclick="viewComponents('cpu')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        View All
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($componentType == 'all' || $componentType == 'ram'): ?>
            <div class="component-card">
                <div class="component-header">
                    <div class="component-title">
                        <svg class="component-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 2a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V4a2 2 0 00-2-2H5zm0 2h10v12H5V4zm3 2a1 1 0 000 2h4a1 1 0 100-2H8zm0 3a1 1 0 000 2h4a1 1 0 100-2H8zm0 3a1 1 0 000 2h4a1 1 0 100-2H8z" clip-rule="evenodd"/>
                        </svg>
                        RAM Modules
                    </div>
                </div>
                <div class="component-count"><?php echo $componentCounts['ram']; ?></div>
                <div class="component-status">
                    Status: <?php echo $statusFilter == 'all' ? 'All' : $statusMap[$statusFilter]; ?>
                </div>
                <div class="component-actions">
                    <button class="btn btn-primary" onclick="showAddModal('ram')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Add RAM
                    </button>
                    <button class="btn btn-secondary" onclick="viewComponents('ram')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        View All
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($componentType == 'all' || $componentType == 'storage'): ?>
            <div class="component-card">
                <div class="component-header">
                    <div class="component-title">
                        <svg class="component-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                            <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                            <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                        </svg>
                        Storage Devices
                    </div>
                </div>
                <div class="component-count"><?php echo $componentCounts['storage']; ?></div>
                <div class="component-status">
                    Status: <?php echo $statusFilter == 'all' ? 'All' : $statusMap[$statusFilter]; ?>
                </div>
                <div class="component-actions">
                    <button class="btn btn-primary" onclick="showAddModal('storage')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Add Storage
                    </button>
                    <button class="btn btn-secondary" onclick="viewComponents('storage')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        View All
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($componentType == 'all' || $componentType == 'motherboard'): ?>
            <div class="component-card">
                <div class="component-header">
                    <div class="component-title">
                        <svg class="component-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V5zm11 1H6v8h8V6z" clip-rule="evenodd"/>
                        </svg>
                        Motherboards
                    </div>
                </div>
                <div class="component-count"><?php echo $componentCounts['motherboard']; ?></div>
                <div class="component-status">
                    Status: <?php echo $statusFilter == 'all' ? 'All' : $statusMap[$statusFilter]; ?>
                </div>
                <div class="component-actions">
                    <button class="btn btn-primary" onclick="showAddModal('motherboard')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Add Motherboard
                    </button>
                    <button class="btn btn-secondary" onclick="viewComponents('motherboard')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        View All
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($componentType == 'all' || $componentType == 'nic'): ?>
            <div class="component-card">
                <div class="component-header">
                    <div class="component-title">
                        <svg class="component-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 10 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                        Network Cards
                    </div>
                </div>
                <div class="component-count"><?php echo $componentCounts['nic']; ?></div>
                <div class="component-status">
                    Status: <?php echo $statusFilter == 'all' ? 'All' : $statusMap[$statusFilter]; ?>
                </div>
                <div class="component-actions">
                    <button class="btn btn-primary" onclick="showAddModal('nic')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Add NIC
                    </button>
                    <button class="btn btn-secondary" onclick="viewComponents('nic')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        View All
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($componentType == 'all' || $componentType == 'caddy'): ?>
            <div class="component-card">
                <div class="component-header">
                    <div class="component-title">
                        <svg class="component-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/>
                        </svg>
                        Drive Caddies
                    </div>
                </div>
                <div class="component-count"><?php echo $componentCounts['caddy']; ?></div>
                <div class="component-status">
                    Status: <?php echo $statusFilter == 'all' ? 'All' : $statusMap[$statusFilter]; ?>
                </div>
                <div class="component-actions">
                    <button class="btn btn-primary" onclick="showAddModal('caddy')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/>
                        </svg>
                        Add Caddy
                    </button>
                    <button class="btn btn-secondary" onclick="viewComponents('caddy')">
                        <svg class="btn-icon" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                        </svg>
                        View All
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($componentCounts['total'] == 0): ?>
        <div class="empty-state">
            <svg viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5 2a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V4a2 2 0 00-2-2H5zm5 4a1 1 0 011 1v4a1 1 0 11-2 0V7a1 1 0 011-1zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            <p>No components found with the current filter.</p>
            <button class="btn btn-primary" onclick="filterByStatus('all')">View All Components</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Generic Modal for Components -->
    <div id="componentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Component</h2>
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
        // Filter functions
        function filterByStatus(status) {
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('status', status);
            window.location.search = currentParams.toString();
        }

        function filterByComponent(component) {
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('component', component);
            window.location.search = currentParams.toString();
        }

        // Modal functions
        function showAddModal(componentType) {
            const modal = document.getElementById('componentModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            
            modalTitle.textContent = `Add ${componentType.toUpperCase()}`;
            modalContent.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
            modal.classList.add('active');
            
            // Load component-specific form
            fetch(`/bdc_ims/api/components/add_form.php?type=${componentType}`)
                .then(response => response.text())
                .then(html => {
                    modalContent.innerHTML = html;
                })
                .catch(error => {
                    modalContent.innerHTML = `<p class="error">Error loading form: ${error.message}</p>`;
                });
        }

        function showEditModal(componentType, componentId) {
            const modal = document.getElementById('componentModal');
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

        function viewComponents(componentType) {
            window.location.href = `/bdc_ims/api/components/list.php?type=${componentType}&status=<?php echo $statusFilter; ?>`;
        }

        function closeModal() {
            const modal = document.getElementById('componentModal');
            modal.classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('componentModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Handle form submissions
        function submitComponentForm(event, componentType) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            formData.append('action', `${componentType}-add_${componentType}`);
            formData.append('session_id', '<?php echo session_id(); ?>');
            
            // Add the selected component UUID
            const selectedComponent = form.querySelector('input[name="component_uuid"]:checked');
            if (selectedComponent) {
                formData.append('component_uuid', selectedComponent.value);
            }
            
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
                    // Success message
                    form.innerHTML = `
                        <div class="success-message">
                            <svg width="48" height="48" viewBox="0 0 20 20" fill="currentColor" style="color: #10b981;">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <h3>Success!</h3>
                            <p>${componentType.toUpperCase()} added successfully.</p>
                            <button class="btn btn-primary" onclick="location.reload()">Refresh Page</button>
                        </div>
                    `;
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
                    // Success message
                    form.innerHTML = `
                        <div class="success-message">
                            <svg width="48" height="48" viewBox="0 0 20 20" fill="currentColor" style="color: #10b981;">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <h3>Success!</h3>
                            <p>${componentType.toUpperCase()} updated successfully.</p>
                            <button class="btn btn-primary" onclick="location.reload()">Refresh Page</button>
                        </div>
                    `;
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

        // Delete component function
        function deleteComponent(componentType, componentId) {
            if (!confirm(`Are you sure you want to delete this ${componentType}?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', `${componentType}-remove_${componentType}`);
            formData.append('session_id', '<?php echo session_id(); ?>');
            formData.append('id', componentId);
            
            fetch('/bdc_ims/api/api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove the row from the table
                    const row = document.getElementById(`row-${componentId}`);
                    if (row) {
                        row.style.animation = 'fadeOut 0.3s ease-out';
                        setTimeout(() => {
                            row.remove();
                            // Check if table is empty
                            const tbody = document.querySelector('tbody');
                            if (tbody && tbody.children.length === 0) {
                                tbody.innerHTML = `
                                    <tr>
                                        <td colspan="10" class="empty-state">
                                            <p>No components found.</p>
                                        </td>
                                    </tr>
                                `;
                            }
                        }, 300);
                    }
                } else {
                    alert(data.message || 'Failed to delete component.');
                }
            })
            .catch(error => {
                alert('Network error. Please try again.');
            });
        }

        // Auto-open add modal if specified in URL
        <?php if ($autoOpenAdd): ?>
        window.onload = function() {
            showAddModal('<?php echo $addComponentType; ?>');
        };
        <?php endif; ?>
    </script>
</body>
</html>