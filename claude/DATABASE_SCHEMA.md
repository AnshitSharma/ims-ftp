# DATABASE_SCHEMA.md

**BDC IMS Database Structure Reference**

> **Database Name**: `shubhams_bdc_ims`
>
> **Engine**: MariaDB 10.6+ / MySQL 8.0+
>
> **Charset**: utf8mb4_general_ci

## Table of Contents

- [Overview](#overview)
- [Inventory Tables](#inventory-tables)
- [Server Configuration Tables](#server-configuration-tables)
- [Authentication Tables](#authentication-tables)
- [ACL Tables](#acl-tables)
- [Table Relationships](#table-relationships)
- [Common Column Patterns](#common-column-patterns)

---

## Overview

### Table Categories

**Component Inventory** (9 tables):
- `cpuinventory`, `raminventory`, `storageinventory`, `motherboardinventory`
- `nicinventory`, `caddyinventory`, `chassisinventory`
- `pciecardinventory`, `hbacardinventory`

**Server Management** (2 tables):
- `server_configurations`, `server_components`

**Authentication** (2 tables):
- `users`, `auth_tokens`

**Access Control** (6 tables):
- `acl_permissions`, `acl_roles`
- `user_permissions`, `user_roles`
- `role_permissions`

---

## Inventory Tables

### Pattern: `{component}inventory`

All inventory tables follow the same schema structure:

```sql
CREATE TABLE cpuinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1 COMMENT '0=failed, 1=available, 2=in_use',
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_uuid_serial (UUID, SerialNumber),
    KEY idx_status (Status),
    KEY idx_server_uuid (ServerUUID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Common Columns**:

| Column | Type | Description |
|--------|------|-------------|
| `ID` | INT(11) | Auto-increment primary key |
| `UUID` | VARCHAR(255) | Component specification UUID (must exist in JSON) |
| `SerialNumber` | VARCHAR(255) | Unique physical component serial number |
| `Status` | TINYINT | 0=failed, 1=available, 2=in_use |
| `ServerUUID` | VARCHAR(255) | Reference to server configuration (if in_use) |
| `Location` | VARCHAR(255) | Physical location (rack, shelf, etc.) |
| `Notes` | TEXT | Additional notes or comments |
| `CreatedAt` | DATETIME | Record creation timestamp |
| `UpdatedAt` | DATETIME | Last update timestamp |

### cpuinventory

Stores CPU component inventory.

```sql
CREATE TABLE cpuinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/cpu-jsons/Cpu-details-level-3.json`

---

### raminventory

Stores RAM module inventory.

```sql
CREATE TABLE raminventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/Ram-jsons/ram_detail.json`

---

### storageinventory

Stores storage device inventory (HDD, SSD, NVMe).

```sql
CREATE TABLE storageinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/storage-jsons/storage-level-3.json`

---

### motherboardinventory

Stores motherboard inventory.

```sql
CREATE TABLE motherboardinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/motherboad-jsons/motherboard-level-3.json`

---

### nicinventory

Stores network interface card inventory.

```sql
CREATE TABLE nicinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/nic-jsons/nic-level-3.json`

---

### caddyinventory

Stores drive caddy/adapter inventory.

```sql
CREATE TABLE caddyinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/caddy-jsons/caddy_details.json`

---

### chassisinventory

Stores server chassis inventory.

```sql
CREATE TABLE chassisinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/chasis-jsons/chasis-level-3.json`

---

### pciecardinventory

Stores PCIe card inventory.

```sql
CREATE TABLE pciecardinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/pci-jsons/pci-level-3.json`

---

### hbacardinventory

Stores HBA (Host Bus Adapter) card inventory.

```sql
CREATE TABLE hbacardinventory (
    ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255) NOT NULL,
    Status TINYINT(4) DEFAULT 1,
    ServerUUID VARCHAR(255) DEFAULT NULL,
    Location VARCHAR(255) DEFAULT NULL,
    Notes TEXT DEFAULT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**JSON Reference**: `All-JSON/hbacard-jsons/hbacard-level-3.json`

---

## Server Configuration Tables

### server_configurations

Stores server configuration metadata.

```sql
CREATE TABLE server_configurations (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('draft', 'active', 'archived') DEFAULT 'draft',
    created_by INT(11) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finalized_at DATETIME DEFAULT NULL,

    KEY idx_created_by (created_by),
    KEY idx_status (status),
    KEY idx_uuid (uuid),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

**Columns**:
- `uuid` - Unique configuration identifier
- `name` - Human-readable configuration name
- `status` - `draft` (building), `active` (finalized), `archived` (decommissioned)
- `created_by` - User ID who created the configuration
- `finalized_at` - Timestamp when configuration was finalized

---

### server_components

Junction table linking configurations to components.

```sql
CREATE TABLE server_components (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    config_uuid VARCHAR(255) NOT NULL,
    component_type VARCHAR(50) NOT NULL COMMENT 'cpu, ram, storage, etc.',
    component_uuid VARCHAR(255) NOT NULL,
    component_id INT(11) DEFAULT NULL COMMENT 'Reference to inventory table ID',
    quantity INT(11) DEFAULT 1,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    KEY idx_config_uuid (config_uuid),
    KEY idx_component_type (component_type),
    KEY idx_component_uuid (component_uuid),
    FOREIGN KEY (config_uuid) REFERENCES server_configurations(uuid) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Relationship**: Many-to-Many between configurations and components

---

## Authentication Tables

### users

Stores user account information.

```sql
CREATE TABLE users (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed',
    firstname VARCHAR(50) DEFAULT NULL,
    lastname VARCHAR(50) DEFAULT NULL,
    status TINYINT(4) DEFAULT 1 COMMENT '0=inactive, 1=active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT NULL,

    KEY idx_username (username),
    KEY idx_email (email),
    KEY idx_status (status)
) ENGINE=InnoDB;
```

**Default Admin**:
```sql
INSERT INTO users (username, email, password, status)
VALUES ('admin', 'admin@example.com', '$2y$10$...', 1);
```

---

### auth_tokens

Stores JWT refresh tokens.

```sql
CREATE TABLE auth_tokens (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE COMMENT 'Refresh token hash',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME DEFAULT NULL,

    KEY idx_user_id (user_id),
    KEY idx_token (token),
    KEY idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Token Cleanup**: Expired tokens should be periodically deleted:
```sql
DELETE FROM auth_tokens WHERE expires_at < NOW();
```

---

## ACL Tables

### acl_permissions

Defines system permissions.

```sql
CREATE TABLE acl_permissions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    category VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_permission_name (permission_name),
    KEY idx_category (category)
) ENGINE=InnoDB;
```

**Permission Format**: `{module}.{action}`

**Examples**:
```sql
INSERT INTO acl_permissions (permission_name, description, category) VALUES
('cpu.view', 'View CPU inventory', 'inventory'),
('cpu.create', 'Add new CPU components', 'inventory'),
('server.create', 'Create server configurations', 'server_management'),
('acl.manage', 'Manage ACL system', 'system');
```

---

### acl_roles

Defines user roles.

```sql
CREATE TABLE acl_roles (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_role_name (role_name)
) ENGINE=InnoDB;
```

**Default Roles**:
```sql
INSERT INTO acl_roles (role_name, description) VALUES
('super_admin', 'Full system access'),
('admin', 'Administrator access'),
('manager', 'Management level access'),
('technician', 'Technical staff access'),
('viewer', 'Read-only access');
```

---

### user_roles

Many-to-many relationship between users and roles.

```sql
CREATE TABLE user_roles (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    role_id INT(11) NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_role (user_id, role_id),
    KEY idx_user_id (user_id),
    KEY idx_role_id (role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES acl_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

### role_permissions

Many-to-many relationship between roles and permissions.

```sql
CREATE TABLE role_permissions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_id INT(11) NOT NULL,
    permission_id INT(11) NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_role_permission (role_id, permission_id),
    KEY idx_role_id (role_id),
    KEY idx_permission_id (permission_id),
    FOREIGN KEY (role_id) REFERENCES acl_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES acl_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

### user_permissions

Direct user-specific permissions (overrides role permissions).

```sql
CREATE TABLE user_permissions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    permission_id INT(11) NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_permission (user_id, permission_id),
    KEY idx_user_id (user_id),
    KEY idx_permission_id (permission_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES acl_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## Table Relationships

### Entity Relationship Diagram

```
users
  ├─── user_roles ────> acl_roles
  │                        └─── role_permissions ────> acl_permissions
  └─── user_permissions ────> acl_permissions

users
  └─── server_configurations
          └─── server_components ────> {component}inventory tables

auth_tokens
  └─── users
```

### Permission Resolution

User permissions are resolved in this order:

1. **Direct user permissions** (`user_permissions`) - Highest priority
2. **Role permissions** (`role_permissions` via `user_roles`) - Inherited from roles
3. **Default deny** - If no match, permission denied

---

## Common Column Patterns

### Status Values

**Inventory Tables** (`Status` TINYINT):
- `0` = Failed/Defective
- `1` = Available for use (default)
- `2` = In use (assigned to server)

**User Table** (`status` TINYINT):
- `0` = Inactive/Disabled
- `1` = Active (default)

**Server Configurations** (`status` ENUM):
- `draft` = Being built
- `active` = Finalized and deployed
- `archived` = Decommissioned

### Timestamps

All tables include:
- `created_at` - Record creation (DEFAULT CURRENT_TIMESTAMP)
- `updated_at` - Last modification (ON UPDATE CURRENT_TIMESTAMP)

---

## Indexing Strategy

### Primary Indexes
- All tables have `AUTO_INCREMENT PRIMARY KEY` on `ID` or `id`

### Unique Indexes
- `users.username`, `users.email`
- `server_configurations.uuid`
- `acl_permissions.permission_name`
- `acl_roles.role_name`

### Foreign Key Indexes
- All foreign key columns automatically indexed

### Performance Indexes
- `{component}inventory.Status` - Frequent filtering by availability
- `{component}inventory.ServerUUID` - Join with server configurations
- `server_components.config_uuid` - Join performance
- `auth_tokens.expires_at` - Token cleanup queries

---

## Common Queries

### Get Available Components
```sql
SELECT * FROM cpuinventory WHERE Status = 1 ORDER BY CreatedAt DESC;
```

### Get Server Configuration with Components
```sql
SELECT
    sc.uuid, sc.name, sc.status,
    scomp.component_type, scomp.component_uuid, scomp.quantity
FROM server_configurations sc
LEFT JOIN server_components scomp ON sc.uuid = scomp.config_uuid
WHERE sc.uuid = ?;
```

### Get User Permissions
```sql
-- Direct permissions
SELECT p.permission_name
FROM user_permissions up
JOIN acl_permissions p ON up.permission_id = p.id
WHERE up.user_id = ?

UNION

-- Role-based permissions
SELECT p.permission_name
FROM user_roles ur
JOIN role_permissions rp ON ur.role_id = rp.role_id
JOIN acl_permissions p ON rp.permission_id = p.id
WHERE ur.user_id = ?;
```

---

**Last Updated**: 2025-11-05
**Schema Version**: 1.0
