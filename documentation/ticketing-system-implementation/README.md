# Ticketing System Implementation Plan

## 📋 Overview

A lightweight, permission-based ticketing system that enables users to request server configuration changes (component additions/removals) when they lack direct modification permissions. The system facilitates approval workflows and tracks deployment status.

---

## 🎯 Core Features

### Essential Features
1. **Ticket Creation** - Users without edit permissions can raise tickets for server modifications
2. **Approval Workflow** - Authorized users can approve/reject tickets with comments
3. **Component Modification** - Post-approval, authorized users add/remove components
4. **Status Tracking** - Track ticket lifecycle: Pending → Approved/Rejected → Deployed/Done
5. **Notifications** - Email/in-app notifications for ticket status changes
6. **Audit Trail** - Complete history of all ticket actions

### Optional Features (Phase 2)
- Bulk operations (multiple components in one ticket)
- Ticket templates for common requests
- Priority levels (Low/Medium/High/Urgent)
- Due dates and SLA tracking
- Attachment support (images, specs, purchase orders)
- Ticket reassignment to different approvers

---

## 🗄️ Database Schema Changes

### New Tables

#### 1. `tickets` (Main Ticket Table)
```sql
CREATE TABLE `tickets` (
  `ticket_id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_number` VARCHAR(50) UNIQUE NOT NULL,  -- Format: TKT-YYYYMMDD-XXXXX
  `server_config_id` INT NOT NULL,
  `created_by` INT NOT NULL,
  `assigned_to` INT DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `status` ENUM('pending', 'approved', 'rejected', 'in_progress', 'deployed', 'cancelled') DEFAULT 'pending',
  `ticket_type` ENUM('add_component', 'remove_component', 'modify_config') DEFAULT 'add_component',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_at` TIMESTAMP NULL,
  `deployed_at` TIMESTAMP NULL,
  `approved_by` INT DEFAULT NULL,
  `deployed_by` INT DEFAULT NULL,

  FOREIGN KEY (`server_config_id`) REFERENCES `server_configurations`(`config_id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  FOREIGN KEY (`deployed_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,

  INDEX idx_status (`status`),
  INDEX idx_created_by (`created_by`),
  INDEX idx_assigned_to (`assigned_to`),
  INDEX idx_server_config (`server_config_id`),
  INDEX idx_created_at (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 2. `ticket_items` (Component Requests)
```sql
CREATE TABLE `ticket_items` (
  `item_id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `component_type` VARCHAR(50) NOT NULL,  -- cpu, ram, storage, etc.
  `component_uuid` VARCHAR(255) NOT NULL,
  `quantity` INT DEFAULT 1,
  `action` ENUM('add', 'remove') DEFAULT 'add',
  `serial_number` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT,
  `status` ENUM('pending', 'completed', 'failed') DEFAULT 'pending',

  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`ticket_id`) ON DELETE CASCADE,

  INDEX idx_ticket_id (`ticket_id`),
  INDEX idx_component_type (`component_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 3. `ticket_comments` (Activity Log & Comments)
```sql
CREATE TABLE `ticket_comments` (
  `comment_id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `comment_type` ENUM('comment', 'status_change', 'approval', 'rejection', 'deployment') DEFAULT 'comment',
  `comment` TEXT,
  `old_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`ticket_id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,

  INDEX idx_ticket_id (`ticket_id`),
  INDEX idx_created_at (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 4. `ticket_notifications` (Notification Queue)
```sql
CREATE TABLE `ticket_notifications` (
  `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `notification_type` ENUM('ticket_created', 'ticket_approved', 'ticket_rejected', 'ticket_deployed', 'comment_added') NOT NULL,
  `message` TEXT,
  `is_read` TINYINT(1) DEFAULT 0,
  `sent_via_email` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `read_at` TIMESTAMP NULL,

  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`ticket_id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,

  INDEX idx_user_id (`user_id`),
  INDEX idx_is_read (`is_read`),
  INDEX idx_created_at (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Modified Tables

#### Update `server_configurations` table
```sql
-- Add field to track if config has pending tickets
ALTER TABLE `server_configurations`
ADD COLUMN `has_pending_tickets` TINYINT(1) DEFAULT 0,
ADD INDEX idx_pending_tickets (`has_pending_tickets`);
```

---

## 🔐 ACL Permissions

### New Permission Nodes
```
ticket.create         - Create new tickets
ticket.view           - View own tickets
ticket.view_all       - View all tickets in system
ticket.approve        - Approve/reject tickets
ticket.deploy         - Mark tickets as deployed
ticket.comment        - Add comments to tickets
ticket.delete         - Delete tickets (admin only)
ticket.reassign       - Reassign tickets to other approvers
```

### Permission Mapping
| User Role | Permissions |
|-----------|-------------|
| **Viewer** | ticket.create, ticket.view, ticket.comment |
| **Editor** | ticket.create, ticket.view, ticket.approve, ticket.deploy, ticket.comment |
| **Manager** | ticket.*, ticket.view_all, ticket.reassign |
| **Admin** | ticket.* (all permissions) |

---

## 🛠️ Backend Implementation

### API Endpoints

#### 1. Ticket Management

##### `POST /api.php?action=ticket-create`
**Description:** Create a new ticket for server component modification

**Request Payload:**
```json
{
  "server_config_id": 123,
  "title": "Add 2x Samsung 870 EVO SSDs to Production Server",
  "description": "Need to increase storage capacity for database expansion",
  "priority": "high",
  "ticket_type": "add_component",
  "assigned_to": 5,
  "items": [
    {
      "component_type": "storage",
      "component_uuid": "samsung-870-evo-1tb",
      "quantity": 2,
      "action": "add",
      "notes": "Install in RAID 1 configuration"
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "code": 201,
  "message": "Ticket created successfully",
  "data": {
    "ticket_id": 1001,
    "ticket_number": "TKT-20251114-00001",
    "status": "pending",
    "created_at": "2025-11-14 10:30:00"
  }
}
```

**Implementation Location:** `api/ticket/ticket-create.php`

**Logic Flow:**
1. Validate JWT and check `ticket.create` permission
2. Verify server_config_id exists
3. Check user doesn't have direct edit permission (if they do, suggest direct edit)
4. Validate all component UUIDs exist in JSON specs
5. Generate unique ticket_number (TKT-YYYYMMDD-XXXXX)
6. Insert into `tickets` table
7. Insert items into `ticket_items` table
8. Update `server_configurations.has_pending_tickets = 1`
9. Create notification for assigned_to user
10. Return ticket details

---

##### `GET /api.php?action=ticket-list`
**Description:** List tickets (filtered by permissions)

**Query Parameters:**
- `status` (optional): pending|approved|rejected|deployed
- `server_config_id` (optional): Filter by server
- `created_by` (optional): Filter by creator
- `assigned_to` (optional): Filter by assignee
- `page` (default: 1)
- `limit` (default: 20)

**Response:**
```json
{
  "success": true,
  "code": 200,
  "data": {
    "tickets": [
      {
        "ticket_id": 1001,
        "ticket_number": "TKT-20251114-00001",
        "title": "Add 2x Samsung 870 EVO SSDs",
        "server_config_id": 123,
        "server_name": "Production DB Server",
        "status": "pending",
        "priority": "high",
        "created_by": "john.doe",
        "assigned_to": "admin.user",
        "created_at": "2025-11-14 10:30:00",
        "item_count": 2
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 45,
      "total_pages": 3
    }
  }
}
```

**Implementation Location:** `api/ticket/ticket-list.php`

**Logic Flow:**
1. Check `ticket.view` or `ticket.view_all` permission
2. If only `ticket.view`, filter by created_by = current_user
3. Build SQL query with filters
4. Join with users table for creator/assignee names
5. Paginate results
6. Return ticket list

---

##### `GET /api.php?action=ticket-details`
**Description:** Get detailed ticket information

**Query Parameters:**
- `ticket_id` (required)

**Response:**
```json
{
  "success": true,
  "code": 200,
  "data": {
    "ticket": {
      "ticket_id": 1001,
      "ticket_number": "TKT-20251114-00001",
      "server_config_id": 123,
      "server_name": "Production DB Server",
      "title": "Add 2x Samsung 870 EVO SSDs",
      "description": "Need to increase storage capacity",
      "priority": "high",
      "status": "pending",
      "ticket_type": "add_component",
      "created_by": {
        "user_id": 3,
        "username": "john.doe",
        "full_name": "John Doe"
      },
      "assigned_to": {
        "user_id": 5,
        "username": "admin.user",
        "full_name": "Admin User"
      },
      "created_at": "2025-11-14 10:30:00",
      "updated_at": "2025-11-14 10:30:00"
    },
    "items": [
      {
        "item_id": 1,
        "component_type": "storage",
        "component_uuid": "samsung-870-evo-1tb",
        "component_name": "Samsung 870 EVO 1TB SATA SSD",
        "quantity": 2,
        "action": "add",
        "notes": "Install in RAID 1 configuration",
        "status": "pending"
      }
    ],
    "comments": [
      {
        "comment_id": 1,
        "user": "john.doe",
        "comment_type": "comment",
        "comment": "Ticket created",
        "created_at": "2025-11-14 10:30:00"
      }
    ]
  }
}
```

**Implementation Location:** `api/ticket/ticket-details.php`

---

##### `POST /api.php?action=ticket-approve`
**Description:** Approve a pending ticket

**Request Payload:**
```json
{
  "ticket_id": 1001,
  "comment": "Approved for deployment. Please install SSDs in slots 3-4."
}
```

**Response:**
```json
{
  "success": true,
  "code": 200,
  "message": "Ticket approved successfully",
  "data": {
    "ticket_id": 1001,
    "status": "approved",
    "approved_at": "2025-11-14 11:00:00",
    "approved_by": "admin.user"
  }
}
```

**Implementation Location:** `api/ticket/ticket-approve.php`

**Logic Flow:**
1. Check `ticket.approve` permission
2. Verify ticket exists and status is 'pending'
3. Update ticket: status='approved', approved_by, approved_at
4. Insert comment in ticket_comments
5. Create notification for ticket creator
6. Return success

---

##### `POST /api.php?action=ticket-reject`
**Description:** Reject a pending ticket

**Request Payload:**
```json
{
  "ticket_id": 1001,
  "reason": "Storage quota exceeded. Please submit budget request first."
}
```

**Implementation Location:** `api/ticket/ticket-reject.php`

---

##### `POST /api.php?action=ticket-deploy`
**Description:** Mark ticket as deployed after physical installation

**Request Payload:**
```json
{
  "ticket_id": 1001,
  "items": [
    {
      "item_id": 1,
      "serial_number": "S123456789",
      "status": "completed"
    }
  ],
  "comment": "SSDs installed and configured in RAID 1. System tested successfully."
}
```

**Response:**
```json
{
  "success": true,
  "code": 200,
  "message": "Ticket marked as deployed",
  "data": {
    "ticket_id": 1001,
    "status": "deployed",
    "deployed_at": "2025-11-14 14:00:00",
    "components_added": 2
  }
}
```

**Implementation Location:** `api/ticket/ticket-deploy.php`

**Logic Flow:**
1. Check `ticket.deploy` permission
2. Verify ticket status is 'approved'
3. For each item with action='add':
   - Call existing component-add API internally
   - Update server configuration
   - Update item.status = 'completed'
4. For each item with action='remove':
   - Call component-remove API
5. Update ticket: status='deployed', deployed_by, deployed_at
6. Update server_configurations.has_pending_tickets if no more pending tickets
7. Insert comment
8. Create notification for creator
9. Return success

---

##### `POST /api.php?action=ticket-comment`
**Description:** Add comment to a ticket

**Request Payload:**
```json
{
  "ticket_id": 1001,
  "comment": "Installation scheduled for tomorrow at 2 PM during maintenance window."
}
```

**Implementation Location:** `api/ticket/ticket-comment.php`

---

##### `GET /api.php?action=ticket-stats`
**Description:** Get ticket statistics for dashboard

**Response:**
```json
{
  "success": true,
  "code": 200,
  "data": {
    "total_tickets": 150,
    "pending": 12,
    "approved": 5,
    "rejected": 3,
    "deployed": 130,
    "my_tickets": 8,
    "assigned_to_me": 4,
    "avg_approval_time_hours": 4.5,
    "avg_deployment_time_hours": 24.3
  }
}
```

**Implementation Location:** `api/ticket/ticket-stats.php`

---

#### 2. Notification Management

##### `GET /api.php?action=notification-list`
**Description:** Get user notifications

**Query Parameters:**
- `is_read` (optional): 0|1
- `limit` (default: 50)

**Response:**
```json
{
  "success": true,
  "code": 200,
  "data": {
    "notifications": [
      {
        "notification_id": 1,
        "ticket_number": "TKT-20251114-00001",
        "notification_type": "ticket_approved",
        "message": "Your ticket for 'Add 2x Samsung SSDs' has been approved",
        "is_read": 0,
        "created_at": "2025-11-14 11:00:00"
      }
    ],
    "unread_count": 5
  }
}
```

**Implementation Location:** `api/ticket/notification-list.php`

---

##### `POST /api.php?action=notification-mark-read`
**Description:** Mark notification as read

**Request Payload:**
```json
{
  "notification_id": 1
}
```

**Implementation Location:** `api/ticket/notification-mark-read.php`

---

### File Structure

```
api/
├── ticket/
│   ├── ticket-create.php          # Create new ticket
│   ├── ticket-list.php            # List tickets
│   ├── ticket-details.php         # Get ticket details
│   ├── ticket-approve.php         # Approve ticket
│   ├── ticket-reject.php          # Reject ticket
│   ├── ticket-deploy.php          # Mark as deployed
│   ├── ticket-comment.php         # Add comment
│   ├── ticket-stats.php           # Get statistics
│   ├── notification-list.php      # List notifications
│   └── notification-mark-read.php # Mark notification read
│
includes/
├── models/
│   ├── TicketManager.php          # Core ticket operations
│   ├── TicketValidator.php        # Ticket validation logic
│   └── NotificationService.php    # Notification management
```

---

### Core Classes

#### `TicketManager.php`
```php
<?php
class TicketManager {
    private $db;

    public function createTicket($data) {
        // Generate ticket number
        // Validate components
        // Insert ticket and items
        // Create notifications
    }

    public function approveTicket($ticketId, $userId, $comment) {
        // Update status
        // Log action
        // Notify creator
    }

    public function deployTicket($ticketId, $userId, $items, $comment) {
        // Validate status
        // Add components to server
        // Update ticket status
        // Create notifications
    }

    public function getTicketDetails($ticketId) {
        // Fetch ticket, items, comments
    }

    public function listTickets($filters, $userId, $hasViewAll) {
        // Build query based on permissions
        // Apply filters
        // Return paginated results
    }
}
```

#### `TicketValidator.php`
```php
<?php
class TicketValidator {
    public function validateTicketCreation($data) {
        // Check server exists
        // Validate component UUIDs
        // Check user permissions
    }

    public function canUserApprove($userId, $ticketId) {
        // Check ticket.approve permission
        // Verify ticket status
    }

    public function canUserDeploy($userId, $ticketId) {
        // Check ticket.deploy permission
        // Verify ticket is approved
    }
}
```

#### `NotificationService.php`
```php
<?php
class NotificationService {
    public function createNotification($ticketId, $userId, $type, $message) {
        // Insert notification
        // Optionally send email
    }

    public function getUnreadCount($userId) {
        // Count unread notifications
    }

    public function markAsRead($notificationId, $userId) {
        // Update notification
    }
}
```

---

## 🎨 Frontend Implementation

### UI Components

#### 1. Ticket List View
**Location:** `/tickets` or `/dashboard/tickets`

**Features:**
- Tabbed view: My Tickets | Assigned to Me | All Tickets (if permission)
- Filter by status, priority, date range
- Search by ticket number or title
- Color-coded status badges
- Quick actions: View, Approve, Reject

**Layout:**
```
┌─────────────────────────────────────────────────────────┐
│  Tickets                                    [+ New Ticket]
├─────────────────────────────────────────────────────────┤
│  [My Tickets] [Assigned to Me] [All Tickets]            │
│                                                          │
│  Status: [All ▼] Priority: [All ▼] [Search...]          │
├─────────────────────────────────────────────────────────┤
│ TKT-001  Add 2x SSDs          [Pending] High  2h ago    │
│          → Production Server    John Doe → Admin        │
│          [View] [Approve] [Reject]                      │
├─────────────────────────────────────────────────────────┤
│ TKT-002  Remove NIC Card      [Approved] Med  1d ago    │
│          → Test Server          Jane Smith → You        │
│          [View] [Deploy]                                │
├─────────────────────────────────────────────────────────┤
│ TKT-003  Add RAM Module       [Deployed] Low  3d ago    │
│          → Dev Server           Bob Wilson → Admin      │
│          [View]                                         │
└─────────────────────────────────────────────────────────┘
```

---

#### 2. Create Ticket Form
**Location:** `/tickets/create`

**Form Fields:**
- Server Configuration (dropdown with search)
- Title (text input, required)
- Description (textarea)
- Priority (dropdown: Low/Medium/High/Urgent)
- Assign To (user dropdown, filtered by ticket.approve permission)
- Component Items (dynamic list):
  - Component Type (dropdown)
  - Component UUID (searchable dropdown)
  - Quantity (number input)
  - Action (Add/Remove)
  - Notes (textarea)
  - [+ Add Another Component] button

**Layout:**
```
┌─────────────────────────────────────────────────────────┐
│  Create New Ticket                                       │
├─────────────────────────────────────────────────────────┤
│  Server Configuration: *                                 │
│  [Select Server...                                    ▼] │
│                                                          │
│  Title: *                                                │
│  [Add 2x Samsung 870 EVO SSDs                          ] │
│                                                          │
│  Description:                                            │
│  ┌────────────────────────────────────────────────────┐ │
│  │ Need to increase storage capacity for database    │ │
│  │ expansion project.                                 │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  Priority: [Medium ▼]     Assign To: [Admin User    ▼]  │
│                                                          │
│  Components to Add/Remove:                               │
│  ┌────────────────────────────────────────────────────┐ │
│  │ Type: [Storage ▼]  UUID: [Samsung 870 EVO 1TB  ▼] │ │
│  │ Qty: [2]  Action: [Add ▼]                         │ │
│  │ Notes: [Install in RAID 1 configuration          ]│ │
│  │                                         [Remove]   │ │
│  └────────────────────────────────────────────────────┘ │
│  [+ Add Another Component]                               │
│                                                          │
│  [Cancel]                        [Create Ticket]         │
└─────────────────────────────────────────────────────────┘
```

---

#### 3. Ticket Details View
**Location:** `/tickets/{ticket_id}`

**Sections:**
- **Header:** Ticket number, status badge, priority, timestamps
- **Details:** Title, description, server info, creator, assignee
- **Component Items:** Table of requested components with specs
- **Action Buttons:** Approve, Reject, Deploy (based on status & permissions)
- **Activity Timeline:** All comments and status changes
- **Add Comment Box:** For authorized users

**Layout:**
```
┌─────────────────────────────────────────────────────────┐
│  ← Back to Tickets                                       │
├─────────────────────────────────────────────────────────┤
│  TKT-20251114-00001              [Pending] High Priority │
│  Add 2x Samsung 870 EVO SSDs                             │
├─────────────────────────────────────────────────────────┤
│  Server: Production DB Server (Config #123)              │
│  Created by: John Doe           Assigned to: Admin User  │
│  Created: Nov 14, 2025 10:30 AM                          │
│                                                          │
│  Description:                                            │
│  Need to increase storage capacity for database          │
│  expansion project scheduled for Q1 2026.                │
├─────────────────────────────────────────────────────────┤
│  Requested Components:                                   │
│  ┌────────────────────────────────────────────────────┐ │
│  │ Component      UUID              Qty  Action       │ │
│  ├────────────────────────────────────────────────────┤ │
│  │ Storage SSD    samsung-870-...   2    Add          │ │
│  │ Samsung 870 EVO 1TB SATA SSD                       │ │
│  │ Notes: Install in RAID 1 configuration             │ │
│  └────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────┤
│  [Approve Ticket] [Reject Ticket]                        │
├─────────────────────────────────────────────────────────┤
│  Activity Timeline:                                      │
│  ┌────────────────────────────────────────────────────┐ │
│  │ ● John Doe created ticket      Nov 14, 10:30 AM   │ │
│  │   "Ticket created for production server upgrade"   │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  Add Comment:                                            │
│  ┌────────────────────────────────────────────────────┐ │
│  │ [Enter your comment...                            ]│ │
│  └────────────────────────────────────────────────────┘ │
│  [Post Comment]                                          │
└─────────────────────────────────────────────────────────┘
```

---

#### 4. Approval Modal
**Triggered by:** Approve/Reject buttons

**Approve Modal:**
```
┌─────────────────────────────────────────┐
│  Approve Ticket TKT-00001               │
├─────────────────────────────────────────┤
│  Add comment (optional):                │
│  ┌───────────────────────────────────┐ │
│  │ Approved for deployment.          │ │
│  │ Please install in slots 3-4.      │ │
│  └───────────────────────────────────┘ │
│                                         │
│  [Cancel]              [Approve]        │
└─────────────────────────────────────────┘
```

**Reject Modal:**
```
┌─────────────────────────────────────────┐
│  Reject Ticket TKT-00001                │
├─────────────────────────────────────────┤
│  Reason for rejection: *                │
│  ┌───────────────────────────────────┐ │
│  │ Storage quota exceeded.           │ │
│  │ Submit budget request first.      │ │
│  └───────────────────────────────────┘ │
│                                         │
│  [Cancel]              [Reject]         │
└─────────────────────────────────────────┘
```

---

#### 5. Deploy Modal
**Triggered by:** Deploy button (after approval)

```
┌─────────────────────────────────────────────────┐
│  Mark Ticket as Deployed                        │
├─────────────────────────────────────────────────┤
│  Confirm physical installation of components:   │
│                                                 │
│  ☑ Samsung 870 EVO 1TB SSD #1                   │
│     Serial: [S123456789                       ] │
│                                                 │
│  ☑ Samsung 870 EVO 1TB SSD #2                   │
│     Serial: [S987654321                       ] │
│                                                 │
│  Deployment notes:                              │
│  ┌───────────────────────────────────────────┐ │
│  │ SSDs installed and configured in RAID 1.  │ │
│  │ System tested successfully.               │ │
│  └───────────────────────────────────────────┘ │
│                                                 │
│  [Cancel]              [Mark as Deployed]       │
└─────────────────────────────────────────────────┘
```

---

#### 6. Notification Bell
**Location:** Top navigation bar

**Features:**
- Badge showing unread count
- Dropdown with recent notifications
- Click to mark as read
- Click notification to go to ticket

**Dropdown:**
```
┌────────────────────────────────────────┐
│  Notifications (3 unread)              │
├────────────────────────────────────────┤
│  ● Ticket TKT-001 approved             │
│    Your ticket has been approved        │
│    2 hours ago          [Mark Read]    │
├────────────────────────────────────────┤
│  ○ New ticket assigned to you          │
│    TKT-005 - Add RAM to Server         │
│    1 day ago           [Mark Read]     │
├────────────────────────────────────────┤
│  [View All Notifications]              │
└────────────────────────────────────────┘
```

---

#### 7. Dashboard Widget
**Location:** Main dashboard

**Widget:**
```
┌────────────────────────────────────────┐
│  My Tickets                            │
├────────────────────────────────────────┤
│  Pending: 3     Approved: 1            │
│  Deployed: 12   Rejected: 2            │
│                                        │
│  Assigned to Me: 5 tickets             │
│  [View All Tickets]                    │
└────────────────────────────────────────┘
```

---

## 📊 Visual Flow Diagrams

### 1. Overall Ticket Workflow

```
┌──────────────────────────────────────────────────────────────────┐
│                     TICKETING SYSTEM WORKFLOW                     │
└──────────────────────────────────────────────────────────────────┘

┌─────────────┐
│   USER      │ (No edit permission on server)
│ (Requester) │
└──────┬──────┘
       │
       │ 1. Create Ticket
       │    - Select server config
       │    - Add components (type, UUID, qty)
       │    - Assign to approver
       ↓
┌──────────────────┐
│  TICKET CREATED  │ ← Notification sent to approver
│  Status: PENDING │
└──────┬───────────┘
       │
       ├─────────────────────────────────────────┐
       │                                         │
       ↓                                         ↓
┌─────────────────┐                    ┌──────────────────┐
│   APPROVER      │                    │   APPROVER       │
│ (Has permission)│                    │ (Has permission) │
└──────┬──────────┘                    └────────┬─────────┘
       │                                         │
       │ 2a. APPROVE                             │ 2b. REJECT
       │    - Add comment                        │    - Add reason
       │    - Components validated               │    - Ticket closed
       ↓                                         ↓
┌──────────────────┐                    ┌──────────────────┐
│ Status: APPROVED │                    │ Status: REJECTED │
│ Notification → Requester              │ Notification → Requester
└──────┬───────────┘                    └──────────────────┘
       │                                         │
       │                                         ↓
       │                                    ┌─────────┐
       │                                    │   END   │
       │                                    └─────────┘
       │
       │ 3. DEPLOY
       │    - Physical installation
       │    - Add serial numbers
       │    - Components auto-added to config
       ↓
┌──────────────────┐
│ Status: DEPLOYED │
│ Notification → Requester
│ Components added to server_components
└──────┬───────────┘
       │
       ↓
┌─────────────────┐
│   COMPLETED     │
│ Config updated  │
│ Ticket closed   │
└─────────────────┘
```

---

### 2. Database Relationship Diagram

```
┌────────────────────┐
│     users          │
│────────────────────│
│ PK user_id         │
│    username        │
│    email           │
└──────┬─────────────┘
       │
       │ ┌──────────────────────────────┐
       │ │                              │
       ↓ ↓                              ↓
┌──────────────────────┐    ┌────────────────────────┐
│ server_configurations│    │       tickets          │
│──────────────────────│    │────────────────────────│
│ PK config_id         │←──→│ PK ticket_id           │
│    config_name       │    │ FK server_config_id    │
│    chassis_uuid      │    │ FK created_by          │
│    has_pending_      │    │ FK assigned_to         │
│    tickets           │    │ FK approved_by         │
└──────────────────────┘    │ FK deployed_by         │
                            │    ticket_number       │
                            │    title               │
                            │    description         │
                            │    status              │
                            │    priority            │
                            │    ticket_type         │
                            │    created_at          │
                            │    approved_at         │
                            │    deployed_at         │
                            └──────┬─────────────────┘
                                   │
                     ┌─────────────┼──────────────┐
                     ↓             ↓              ↓
          ┌──────────────┐ ┌──────────────┐ ┌──────────────────┐
          │ticket_items  │ │ticket_       │ │ticket_           │
          │              │ │comments      │ │notifications     │
          │──────────────│ │──────────────│ │──────────────────│
          │ PK item_id   │ │ PK comment_id│ │ PK notification  │
          │ FK ticket_id │ │ FK ticket_id │ │    _id           │
          │    component │ │ FK user_id   │ │ FK ticket_id     │
          │    _type     │ │    comment   │ │ FK user_id       │
          │    component │ │    comment   │ │    notification  │
          │    _uuid     │ │    _type     │ │    _type         │
          │    quantity  │ │    old_status│ │    message       │
          │    action    │ │    new_status│ │    is_read       │
          │    serial_   │ │    created_at│ │    sent_via_email│
          │    number    │ └──────────────┘ │    created_at    │
          │    notes     │                  │    read_at       │
          │    status    │                  └──────────────────┘
          └──────────────┘
```

---

### 3. Permission Check Flow

```
┌──────────────────────────────────────────────────────────┐
│              PERMISSION VALIDATION FLOW                   │
└──────────────────────────────────────────────────────────┘

API Request: ticket-create
         ↓
    ┌─────────┐
    │ JWT     │
    │ Valid?  │
    └────┬────┘
         │ Yes
         ↓
    ┌──────────────┐
    │ Extract      │
    │ user_id from │
    │ JWT payload  │
    └────┬─────────┘
         ↓
    ┌──────────────────┐
    │ Check ACL:       │
    │ ticket.create?   │
    └────┬─────────────┘
         │ Yes
         ↓
    ┌──────────────────┐
    │ Check server     │  ───No──→ [403 Forbidden]
    │ config exists?   │
    └────┬─────────────┘
         │ Yes
         ↓
    ┌──────────────────┐
    │ Check user has   │  ───Yes──→ [Suggest direct edit]
    │ edit permission  │
    │ on this config?  │
    └────┬─────────────┘
         │ No (Good - needs ticket)
         ↓
    ┌──────────────────┐
    │ Validate all     │  ───Invalid──→ [400 Bad Request]
    │ component UUIDs  │
    │ exist in JSON?   │
    └────┬─────────────┘
         │ Valid
         ↓
    ┌──────────────────┐
    │ Create ticket    │
    │ Insert items     │
    │ Send notification│
    └────┬─────────────┘
         ↓
    [201 Created]
```

---

### 4. Ticket Lifecycle State Machine

```
          ┌─────────────┐
          │   CREATED   │
          │  (pending)  │
          └──────┬──────┘
                 │
        ┌────────┴────────┐
        │                 │
        ↓                 ↓
┌───────────────┐  ┌──────────────┐
│   APPROVED    │  │   REJECTED   │
│               │  │   (closed)   │
└───────┬───────┘  └──────────────┘
        │                 │
        │                 ↓
        │            ┌─────────┐
        │            │   END   │
        │            └─────────┘
        │
        ↓
┌───────────────┐
│ IN_PROGRESS   │ (Optional intermediate state)
│ (deploying)   │
└───────┬───────┘
        │
        ↓
┌───────────────┐
│   DEPLOYED    │
│   (closed)    │
└───────┬───────┘
        │
        ↓
   ┌─────────┐
   │   END   │
   └─────────┘

TRANSITIONS:
- pending → approved   (Approver action)
- pending → rejected   (Approver action)
- approved → deployed  (Deployer action)
- any → cancelled      (Creator or Admin)
```

---

### 5. Component Addition Flow (Post-Approval)

```
Ticket Status: APPROVED
         ↓
    ┌─────────────────┐
    │ User clicks     │
    │ "Deploy" button │
    └────┬────────────┘
         ↓
    ┌─────────────────────┐
    │ Check permission:   │
    │ ticket.deploy?      │
    └────┬────────────────┘
         │ Yes
         ↓
    ┌─────────────────────┐
    │ Show Deploy Modal:  │
    │ - Enter serial #s   │
    │ - Add notes         │
    └────┬────────────────┘
         ↓
    ┌──────────────────────┐
    │ Submit deployment    │
    └────┬─────────────────┘
         ↓
    ┌──────────────────────────────┐
    │ FOR EACH ticket_item:        │
    │                              │
    │ IF action = 'add':           │
    │   1. Validate component UUID │
    │   2. Check compatibility     │
    │   3. Call server-add-        │
    │      component API           │
    │   4. Insert into server_     │
    │      components table        │
    │   5. Update item.status =    │
    │      'completed'             │
    │                              │
    │ IF action = 'remove':        │
    │   1. Call server-remove-     │
    │      component API           │
    │   2. Update component status │
    │   3. Update item.status =    │
    │      'completed'             │
    └────┬─────────────────────────┘
         ↓
    ┌──────────────────────┐
    │ Update ticket:       │
    │ - status = 'deployed'│
    │ - deployed_by = user │
    │ - deployed_at = now  │
    └────┬─────────────────┘
         ↓
    ┌──────────────────────┐
    │ Update server_config:│
    │ - Recalculate        │
    │   has_pending_tickets│
    └────┬─────────────────┘
         ↓
    ┌──────────────────────┐
    │ Create notification  │
    │ to ticket creator    │
    └────┬─────────────────┘
         ↓
    ┌──────────────────────┐
    │ Return success       │
    │ with updated ticket  │
    └──────────────────────┘
```

---

## 🔔 Notification System

### Notification Triggers

| Event | Trigger | Recipients | Type |
|-------|---------|------------|------|
| Ticket Created | ticket-create API | Assigned approver | ticket_created |
| Ticket Approved | ticket-approve API | Ticket creator | ticket_approved |
| Ticket Rejected | ticket-reject API | Ticket creator | ticket_rejected |
| Ticket Deployed | ticket-deploy API | Ticket creator | ticket_deployed |
| Comment Added | ticket-comment API | Ticket creator + Assignee | comment_added |
| Ticket Reassigned | ticket-reassign API | New assignee | ticket_reassigned |

### Email Templates (Optional)

#### Ticket Created Email
```
Subject: New Ticket Assigned: TKT-20251114-00001

Hi [Approver Name],

A new ticket has been assigned to you:

Ticket: TKT-20251114-00001
Title: Add 2x Samsung 870 EVO SSDs
Server: Production DB Server
Priority: High
Created by: John Doe

Description:
Need to increase storage capacity for database expansion.

View ticket: https://ims.example.com/tickets/1001

---
BDC Inventory Management System
```

#### Ticket Approved Email
```
Subject: Your Ticket Has Been Approved: TKT-20251114-00001

Hi [Creator Name],

Your ticket has been approved:

Ticket: TKT-20251114-00001
Title: Add 2x Samsung 870 EVO SSDs
Approved by: Admin User
Approved at: Nov 14, 2025 11:00 AM

Comment: "Approved for deployment. Please install SSDs in slots 3-4."

The components will be installed shortly.

View ticket: https://ims.example.com/tickets/1001

---
BDC Inventory Management System
```

---

## 🔒 Security Considerations

### 1. Permission Validation
- **Always** check ACL permissions before any ticket operation
- Validate user can only view tickets they created (unless ticket.view_all)
- Ensure ticket.approve permission before approval/rejection
- Verify ticket.deploy permission before marking as deployed

### 2. Component Validation
- **Always** validate component UUIDs exist in JSON specs
- Run compatibility checks before allowing ticket creation
- Prevent adding components that would violate constraints

### 3. Data Sanitization
- Sanitize all user inputs (title, description, comments)
- Validate ticket_id, component_type, component_uuid
- Prevent SQL injection using prepared statements

### 4. Audit Trail
- Log all ticket state changes in ticket_comments
- Record user_id for every action (create, approve, reject, deploy)
- Maintain timestamps for all operations

### 5. Status Validation
- Prevent invalid state transitions (e.g., deploy before approval)
- Check ticket status before operations
- Lock tickets during deployment to prevent race conditions

---

## 📈 Implementation Phases

### Phase 1: Core Functionality (Week 1-2)
**Effort: 40-50 hours**

#### Database Setup
- [ ] Create 4 new tables (tickets, ticket_items, ticket_comments, ticket_notifications)
- [ ] Add ACL permissions
- [ ] Update server_configurations table
- [ ] Create indexes for performance

#### Backend APIs
- [ ] ticket-create endpoint
- [ ] ticket-list endpoint
- [ ] ticket-details endpoint
- [ ] ticket-approve endpoint
- [ ] ticket-reject endpoint
- [ ] ticket-deploy endpoint

#### Core Classes
- [ ] TicketManager.php
- [ ] TicketValidator.php
- [ ] NotificationService.php

#### Testing
- [ ] Test ticket creation
- [ ] Test approval/rejection
- [ ] Test deployment
- [ ] Test permission checks

---

### Phase 2: User Interface (Week 3)
**Effort: 30-40 hours**

#### Frontend Pages
- [ ] Ticket list view with filters
- [ ] Create ticket form
- [ ] Ticket details page
- [ ] Approval/rejection modals
- [ ] Deploy modal

#### Dashboard Integration
- [ ] Add ticket stats widget
- [ ] Add notification bell icon
- [ ] Add quick actions

#### UX Enhancements
- [ ] Real-time status updates
- [ ] Color-coded badges
- [ ] Responsive design

---

### Phase 3: Notifications & Comments (Week 4)
**Effort: 20-30 hours**

#### Notification System
- [ ] notification-list endpoint
- [ ] notification-mark-read endpoint
- [ ] Email notification service (optional)
- [ ] In-app notification dropdown

#### Comments System
- [ ] ticket-comment endpoint
- [ ] Activity timeline UI
- [ ] Real-time comment updates

---

### Phase 4: Advanced Features (Week 5-6)
**Effort: 20-30 hours**

#### Optional Enhancements
- [ ] ticket-stats endpoint
- [ ] Ticket reassignment
- [ ] Bulk operations
- [ ] Priority levels and SLA tracking
- [ ] Attachment support
- [ ] Export to PDF/CSV
- [ ] Advanced search and filtering
- [ ] Ticket templates

---

## 📊 Performance Optimization

### Database Indexes
```sql
-- Already included in table definitions
INDEX idx_status ON tickets(status)
INDEX idx_created_by ON tickets(created_by)
INDEX idx_assigned_to ON tickets(assigned_to)
INDEX idx_server_config ON tickets(server_config_id)
INDEX idx_ticket_id ON ticket_items(ticket_id)
```

### Caching Strategy
- Cache ticket statistics (30-60 seconds TTL)
- Cache component specifications (already implemented)
- Cache user permissions (session-based)

### Query Optimization
- Use JOIN to fetch ticket creator/assignee names in single query
- Paginate ticket lists (default 20 per page)
- Lazy load comments and items on ticket details page

---

## 🧪 Testing Checklist

### Unit Tests
- [ ] TicketManager::createTicket()
- [ ] TicketManager::approveTicket()
- [ ] TicketManager::deployTicket()
- [ ] TicketValidator::validateTicketCreation()
- [ ] NotificationService::createNotification()

### Integration Tests
- [ ] Create ticket → Approve → Deploy workflow
- [ ] Create ticket → Reject workflow
- [ ] Permission checks (create, approve, deploy)
- [ ] Component validation
- [ ] Notification delivery

### API Tests
- [ ] POST /api.php?action=ticket-create (valid data)
- [ ] POST /api.php?action=ticket-create (invalid UUID)
- [ ] POST /api.php?action=ticket-create (no permission)
- [ ] GET /api.php?action=ticket-list (with filters)
- [ ] POST /api.php?action=ticket-approve (valid)
- [ ] POST /api.php?action=ticket-approve (already approved)
- [ ] POST /api.php?action=ticket-deploy (valid)
- [ ] POST /api.php?action=ticket-deploy (not approved)

### UI Tests
- [ ] Create ticket form validation
- [ ] Ticket list filtering and pagination
- [ ] Approve/reject modals
- [ ] Deploy modal with serial numbers
- [ ] Notification dropdown
- [ ] Real-time updates

---

## 📝 Sample API Test Commands

### 1. Create Ticket
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "ticket-create",
    "server_config_id": 123,
    "title": "Add 2x Samsung 870 EVO SSDs",
    "description": "Increase storage capacity",
    "priority": "high",
    "assigned_to": 5,
    "items": [
      {
        "component_type": "storage",
        "component_uuid": "samsung-870-evo-1tb",
        "quantity": 2,
        "action": "add",
        "notes": "RAID 1 configuration"
      }
    ]
  }'
```

### 2. List Tickets
```bash
curl -X GET "http://localhost:8000/api/api.php?action=ticket-list&status=pending&page=1" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### 3. Approve Ticket
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "ticket-approve",
    "ticket_id": 1001,
    "comment": "Approved for deployment"
  }'
```

### 4. Deploy Ticket
```bash
curl -X POST "http://localhost:8000/api/api.php" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "ticket-deploy",
    "ticket_id": 1001,
    "items": [
      {
        "item_id": 1,
        "serial_number": "S123456789",
        "status": "completed"
      }
    ],
    "comment": "SSDs installed and tested successfully"
  }'
```

---

## 🎯 Success Metrics

### Performance Targets
- Ticket creation: < 1 second
- Ticket list load: < 500ms (for 20 tickets)
- Approval/rejection: < 500ms
- Deployment with component addition: < 2 seconds

### User Experience Goals
- Reduce manual configuration requests by 80%
- Average approval time: < 4 hours
- Average deployment time: < 24 hours
- User satisfaction: > 90%

---

## 🚀 Deployment Steps

### 1. Database Migration
```bash
# Backup existing database
mysqldump -u root -p bdc_ims > backup_before_ticketing.sql

# Run migration script
mysql -u root -p bdc_ims < migrations/ticketing_system.sql

# Verify tables created
mysql -u root -p bdc_ims -e "SHOW TABLES LIKE 'ticket%'"
```

### 2. Backend Deployment
```bash
# Deploy new API files
cp -r api/ticket /var/www/html/bdc_ims/api/

# Deploy core classes
cp includes/models/TicketManager.php /var/www/html/bdc_ims/includes/models/
cp includes/models/TicketValidator.php /var/www/html/bdc_ims/includes/models/
cp includes/models/NotificationService.php /var/www/html/bdc_ims/includes/models/

# Set permissions
chmod 644 api/ticket/*.php
chmod 644 includes/models/Ticket*.php
```

### 3. ACL Setup
```bash
# Add permissions via SQL or admin panel
INSERT INTO permissions VALUES
  ('ticket.create', 'Create tickets'),
  ('ticket.view', 'View own tickets'),
  ('ticket.view_all', 'View all tickets'),
  ('ticket.approve', 'Approve/reject tickets'),
  ('ticket.deploy', 'Deploy tickets'),
  ('ticket.comment', 'Comment on tickets');

# Assign to roles
INSERT INTO role_permissions (role_id, permission)
SELECT role_id, 'ticket.create' FROM roles WHERE role_name IN ('viewer', 'editor', 'manager', 'admin');
```

### 4. Frontend Deployment
```bash
# Deploy UI components
cp -r frontend/tickets /var/www/html/bdc_ims/frontend/

# Update navigation menu
# Add "Tickets" link to main navigation
```

### 5. Testing
```bash
# Run test suite
php vendor/bin/phpunit tests/TicketTest.php

# Manual testing checklist
# - Create ticket as viewer
# - Approve ticket as editor
# - Deploy ticket as editor
# - Check notifications
# - Verify components added to server
```

---

## 📚 Related Documentation

- **API Reference:** All ticket endpoints detailed in API_REFERENCE.md
- **Database Schema:** Full schema in DATABASE_SCHEMA.md
- **ACL System:** Permission structure in ARCHITECTURE.md
- **Component Validation:** Compatibility engine in FlexibleCompatibilityValidator.php

---

## 🔄 Future Enhancements (Post-MVP)

### Advanced Features
1. **Batch Operations**
   - Submit multiple tickets at once
   - Bulk approve/reject

2. **Workflow Automation**
   - Auto-approve for certain component types
   - Scheduled deployments
   - Integration with calendar systems

3. **Analytics Dashboard**
   - Ticket volume trends
   - Average resolution time
   - Most requested components
   - User activity reports

4. **Integration with External Systems**
   - JIRA/ServiceNow integration
   - Slack/Teams notifications
   - Asset management systems

5. **Mobile App**
   - iOS/Android app for ticket management
   - Push notifications
   - Barcode scanning for serial numbers

---

## 📞 Support & Maintenance

### Monitoring
- Log all ticket operations to `logs/ticketing.log`
- Track failed deployments
- Monitor notification delivery

### Troubleshooting
| Issue | Possible Cause | Solution |
|-------|---------------|----------|
| Ticket creation fails | Invalid component UUID | Check JSON files exist |
| Cannot approve ticket | Missing permission | Add ticket.approve to role |
| Deploy fails | Compatibility error | Validate components manually |
| Notifications not sent | Email config issue | Check SMTP settings |

---

## ✅ Summary

This ticketing system provides a **lightweight, permission-based workflow** for managing server configuration requests in the BDC IMS:

### Key Benefits
✅ **Access Control** - Users without edit permissions can request changes
✅ **Approval Workflow** - Authorized users review and approve requests
✅ **Audit Trail** - Complete history of all changes
✅ **Notifications** - Real-time updates via email and in-app
✅ **Integration** - Seamlessly works with existing component/server APIs
✅ **Scalable** - Can handle bulk operations and future enhancements

### Implementation Effort
- **Phase 1 (Core):** 40-50 hours
- **Phase 2 (UI):** 30-40 hours
- **Phase 3 (Notifications):** 20-30 hours
- **Phase 4 (Advanced):** 20-30 hours
- **Total:** 110-150 hours (3-4 weeks with 2 developers)

### Success Criteria
✅ Users can create tickets for component additions/removals
✅ Approvers can approve/reject with comments
✅ Deployers can mark tickets as deployed after physical installation
✅ Notifications sent at each stage
✅ Complete audit trail maintained
✅ Components automatically added to server configuration

---

**Status:** ✅ **Documentation Complete - Ready for Implementation**

**Last Updated:** 2025-11-14
**Version:** 1.0
**Author:** Claude AI Assistant
