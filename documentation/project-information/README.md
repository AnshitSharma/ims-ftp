# Project Information: Server Configuration & Compatibility System

## 📋 Table of Contents
1. [Server Object & Lifecycle](#server-object--lifecycle)
2. [Compatibility Query System](#compatibility-query-system)
3. [Component Addition Flow](#component-addition-flow)
4. [Validator Architecture](#validator-architecture)
5. [Component-Specific Validation Rules](#component-specific-validation-rules)

---

## 1. Server Object & Lifecycle

### What is a Server Configuration?

A **server configuration** is a **database record** that represents a planned or built server. It stores:
- **Single components** (motherboard, chassis, HBA) as UUID strings
- **Multi-component arrays** (CPUs, RAM, storage, etc.) as JSON arrays
- **Status tracking** (Draft → Validated → Built → Deployed)
- **Metadata** (creation date, power consumption, notes)

### Database Storage Structure

```
┌─────────────────────────────────────────────────────────────┐
│              SERVER_CONFIGURATIONS TABLE                     │
├─────────────────────────────────────────────────────────────┤
│ ● id, config_uuid, server_name                              │
│ ● motherboard_uuid (scalar - required at start)             │
│ ● chassis_uuid (scalar)                                     │
│ ● hbacard_uuid (scalar)                                     │
│ ● cpu_configuration (JSON array)                            │
│ ● ram_configuration (JSON array)                            │
│ ● storage_configuration (JSON array)                        │
│ ● pciecard_configurations (JSON array)                      │
│ ● nic_config (JSON object)                                  │
│ ● caddy_configuration (JSON array)                          │
│ ● configuration_status (0=Draft, 1=Validated, 2=Built...)   │
│ ● created_at, updated_at, created_by                        │
└─────────────────────────────────────────────────────────────┘
```

### Server Creation Flow

```
┌────────────────────────────────────────────────────────────────────┐
│                     SERVER-CREATE-START                            │
└────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  1. Validate Input Parameters           │
        │     • server_name (required)            │
        │     • motherboard_uuid (required)       │
        │     • description (optional)            │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  2. Check Motherboard Exists            │
        │     • Query motherboardinventory        │
        │     • Verify UUID found                 │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  3. Check Motherboard Availability      │
        │     • Status=1 (available) ✓            │
        │     • Status=2 (in_use) ✓ if same cfg   │
        │     • Status=0 (failed) ✗               │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  4. Create Database Record              │
        │     • Generate config_uuid (UUID v4)    │
        │     • INSERT into server_configurations │
        │     • Set status = 0 (Draft)            │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  5. Update Motherboard Status           │
        │     • Set Status=2 (in_use)             │
        │     • Set ServerUUID=config_uuid        │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  6. Return Configuration UUID           │
        │     • Client receives config_uuid       │
        │     • Ready for component addition      │
        └─────────────────────────────────────────┘
```

**Key Points**:
- **Motherboard is mandatory** to start a configuration
- Server config is stored **persistently in MySQL** (not session/memory)
- Created as **Draft** (status=0) and evolves through workflow
- **Motherboard is locked** to this config (Status=2, ServerUUID set)

---

## 2. Compatibility Query System

### server-get-compatible API

**Purpose**: Query which inventory components are compatible with an existing server configuration.

### Execution Flow Diagram

```
┌────────────────────────────────────────────────────────────────────┐
│               SERVER-GET-COMPATIBLE API CALL                       │
│  Parameters: config_uuid, component_type, available_only=true     │
└────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 1: Load Configuration            │
        │  • Query server_configurations by UUID  │
        │  • Check user permissions               │
        │  • Fail if not found                    │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 2: Extract Existing Components   │
        │  • Parse JSON columns                   │
        │  • Build list: [{type, uuid}]           │
        │  • Example: 2 CPUs, 4 RAM, 1 storage    │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 3: Load Full Component Data      │
        │  • Query inventory tables for each      │
        │  • Fetch specs from database            │
        │  • Build existingComponentsData array   │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 4: Query Available Inventory     │
        │  • Query {component_type}inventory      │
        │  • WHERE Status=1 (if available_only)   │
        │  • LIMIT 200 components                 │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 5: JSON Validation Pre-Filter    │
        │  • For each component UUID:             │
        │    - Check exists in All-JSON/ files    │
        │    - ComponentCompatibility::           │
        │      validateComponentExistsInJSON()    │
        │  • Filter out components without JSON   │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 6: Compatibility Checking        │
        │  • For each component with JSON:        │
        │    - Run component-specific validator   │
        │    - Calculate compatibility score      │
        │    - Collect reasons & warnings         │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  PHASE 7: Build Response                │
        │  • Return array of components with:     │
        │    - uuid, serial_number, status        │
        │    - is_compatible (true/false)         │
        │    - compatibility_score (0-100)        │
        │    - compatibility_reasons (array)      │
        │  • Include totals & filter info         │
        └─────────────────────────────────────────┘
```

### Component-Specific Compatibility Methods

| Component Type | Compatibility Method | Key Checks |
|----------------|---------------------|------------|
| **CPU** | `checkCPUDecentralizedCompatibility()` | Socket match, TDP validation, multi-socket support |
| **Motherboard** | `checkMotherboardDecentralizedCompatibility()` | CPU socket match, RAM slot compatibility, storage support |
| **RAM** | `checkRAMDecentralizedCompatibility()` | Memory type (DDR3/4/5), slot availability, ECC support |
| **Storage** | `checkStorageDecentralizedCompatibility()` | Interface match (SATA/NVMe/SAS), slot availability, HBA requirements |
| **Chassis** | `checkChassisDecentralizedCompatibility()` | Form factor match, drive bay capacity, expansion slots |
| **PCIe Card** | `checkPCIeDecentralizedCompatibility()` | Slot availability, PCIe generation, lane requirements |
| **NIC** | `checkPCIeDecentralizedCompatibility()` | PCIe slots, generation, lane requirements for speed |
| **HBA Card** | `checkHBADecentralizedCompatibility()` | PCIe slot availability, SAS generation match with storage |
| **Caddy** | No specific check (always compatible: true) | — |

### Response Format

```json
{
  "success": true,
  "data": {
    "components": [
      {
        "uuid": "cpu-001",
        "serial_number": "SN12345",
        "status": 1,
        "status_text": "Available",
        "is_compatible": true,
        "compatibility_score": 95,
        "compatibility_reasons": [
          "✓ Socket LGA1700 matches motherboard",
          "✓ TDP 125W within PSU limits",
          "⚠ High core count (16 cores) - ensure cooling"
        ]
      }
    ],
    "total_compatible": 5,
    "total_incompatible": 2,
    "total_checked": 7
  }
}
```

---

## 3. Component Addition Flow

### server-add-component API

**Purpose**: Add a component to an existing server configuration after validation.

### Execution Flow Diagram

```
┌────────────────────────────────────────────────────────────────────┐
│                   SERVER-ADD-COMPONENT API CALL                    │
│  Parameters: config_uuid, component_type, component_uuid           │
└────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║                      PHASE 1: VALIDATION                            ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  1.1 Parameter Validation               │
        │      • config_uuid required             │
        │      • component_type valid             │
        │      • component_uuid required          │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  1.2 Configuration Validation           │
        │      • Load ServerConfiguration         │
        │      • Check user ownership/permissions │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  1.3 Component Existence Check          │
        │      • Query inventory table            │
        │      • Verify UUID exists               │
        │      • Load component data              │
        └─────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║                   PHASE 2: AVAILABILITY CHECK                       ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  Check Component Status:                │
        │  • Status=0 (Failed) → ✗ BLOCKED        │
        │  • Status=1 (Available) → ✓ ALLOW       │
        │  • Status=2 (In Use):                   │
        │    - Same config → ✓ ALLOW              │
        │    - Different config → ✗ BLOCKED       │
        │    - override=true → ✓ ALLOW            │
        └─────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║                PHASE 3: COMPATIBILITY VALIDATION                    ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  3.1 Extract Existing Components        │
        │      • Parse JSON columns               │
        │      • Build component list             │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  3.2 Load Existing Component Data       │
        │      • Query inventory for each         │
        │      • Build full data array            │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  3.3 Run Component-Specific Validator   │
        │      • Call check{Type}Compatibility()  │
        │      • Calculate compatibility score    │
        │      • Collect issues & warnings        │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  3.4 Check Compatibility Result         │
        │      • If compatible=false:             │
        │        → ✗ BLOCK addition               │
        │        → Return error with details      │
        │      • If compatible=true:              │
        │        → ✓ PROCEED to Phase 4           │
        └─────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║               PHASE 4: SPECIALIZED VALIDATIONS                      ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  Component-Type Specific:               │
        │  • Riser Card → Check riser slot avail  │
        │  • Motherboard → Validate with risers   │
        │  • Chassis → Check height for risers    │
        │  • HBA Card → Check storage interfaces  │
        └─────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║                    PHASE 5: SLOT ASSIGNMENT                         ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  For PCIe devices (PCIe, NIC, HBA):     │
        │  • Detect if riser card                 │
        │    → Assign riser slot by size          │
        │  • Regular PCIe device                  │
        │    → Assign next available PCIe slot    │
        │  • Track slot allocation                │
        └─────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║                   PHASE 6: DATABASE UPDATE                          ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  6.1 Update Configuration JSON          │
        │      • Parse existing JSON column       │
        │      • Add new component entry          │
        │      • Encode back to JSON              │
        │      • UPDATE server_configurations     │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  6.2 Update Component Status            │
        │      • UPDATE inventory table:          │
        │        - Set Status=2 (in_use)          │
        │        - Set ServerUUID=config_uuid     │
        │        - Set UpdatedAt=NOW()            │
        └─────────────────────────────────────────┘
                              │
                              ▼
        ┌─────────────────────────────────────────┐
        │  6.3 Handle Special Cases               │
        │      • Motherboard: Extract onboard NICs│
        │      • Generate virtual NIC entries     │
        │      • Store in nic_config JSON         │
        └─────────────────────────────────────────┘
                              │
                              ▼
╔═════════════════════════════════════════════════════════════════════╗
║                      PHASE 7: RESPONSE                              ║
╚═════════════════════════════════════════════════════════════════════╝
        ┌─────────────────────────────────────────┐
        │  Return Success Response:               │
        │  • config_uuid                          │
        │  • component_added details              │
        │  • slot_assigned (if PCIe)              │
        │  • warnings (if any)                    │
        │  • updated_configuration                │
        └─────────────────────────────────────────┘
```

### Critical Blocking Points

| Phase | Blocking Condition | Result |
|-------|-------------------|--------|
| **Phase 1** | Component UUID not found | ✗ Error: Component does not exist |
| **Phase 2** | Status=0 (Failed) | ✗ Error: Component is marked as failed |
| **Phase 2** | Status=2 (In use by another config) | ✗ Error: Component already in use |
| **Phase 3** | `compatible=false` from validator | ✗ Error: Component incompatible with configuration |
| **Phase 4** | Riser slot unavailable | ✗ Error: No available riser slots |
| **Phase 4** | HBA missing for SAS drives | ✗ Error: SAS storage requires HBA card |
| **Phase 5** | No available PCIe slots | ✗ Error: All PCIe slots occupied |

**Key Points**:
- **Compatibility validation happens BEFORE addition** (Phase 3)
- **If incompatible, addition is BLOCKED** with detailed error message
- **Component status is updated** to lock it to this config (Phase 6)
- **Warnings are non-blocking** but returned to user

---

## 4. Validator Architecture

### Validator Hierarchy

```
                     ┌──────────────────────┐
                     │  ValidatorOrchestrator│
                     │   (Central Manager)   │
                     └──────────────────────┘
                              │
                              │ Orchestrates
                              ▼
        ┌────────────────────────────────────────────┐
        │         BaseValidator (Abstract)           │
        │  • validate()                              │
        │  • canRun()                                │
        │  • getPriority()                           │
        └────────────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │                               │
    ┌─────────▼─────────┐         ┌─────────▼─────────┐
    │ Primitive         │         │ Component          │
    │ Validators        │         │ Validators         │
    │ (Foundational)    │         │ (Per-Type)         │
    └───────────────────┘         └───────────────────┘
              │                               │
    ┌─────────┼─────────┐         ┌─────────┼─────────────────┐
    │         │         │         │         │         │       │
    ▼         ▼         ▼         ▼         ▼         ▼       ▼
  Socket   FormFactor  Slot    CPU    Motherboard  RAM  Storage ...
```

### Validator Priority System

Validators execute in **priority order** (highest first):

| Priority Range | Category | Examples |
|---------------|----------|----------|
| **100-90** | Critical | Socket compatibility (100), Form factor (95) |
| **89-80** | High | CPU (85), Motherboard (80) |
| **79-70** | Medium-High | RAM (70), Storage (65) |
| **69-50** | Medium | PCIe cards (60), specialized storage validators |
| **49-0** | Low | Chassis (20), NIC (15), HBA (10), Caddy (5) |

**Execution Logic**:
1. **Sort validators by priority** (descending)
2. For each validator:
   - Call `canRun(context)` → Skip if returns false
   - Call `validate(context)` → Collect results
3. **Aggregate results**:
   - Merge errors, warnings, infos
   - Check for blocking errors
4. **Return validation report**

### All Validator Files

#### Primitive Validators (Foundation)

| Validator | Priority | Role |
|-----------|----------|------|
| **SocketCompatibilityValidator** | 100 | CPU-Motherboard socket matching (CRITICAL) |
| **FormFactorValidator** | 95 | Physical dimensions & mounting compatibility |
| **SlotAvailabilityValidator** | 90 | RAM, PCIe, M.2, SATA slot tracking |

#### Component Validators (Per-Type)

| Validator | Priority | Component Type |
|-----------|----------|----------------|
| **CPUValidator** | 85 | CPU |
| **MotherboardValidator** | 80 | Motherboard |
| **RAMValidator** | 70 | RAM |
| **StorageValidator** | 65 | Storage |
| **PCIeCardValidator** | 60 | PCIe Card, NIC, HBA |
| **ChassisValidator** | 20 | Chassis |
| **NICValidator** | 15 | NIC |
| **HBAValidator** | 10 | HBA Card |
| **CaddyValidator** | 5 | Caddy |

#### Specialized Storage Validators

| Validator | Priority | Specific Role |
|-----------|----------|---------------|
| **ChassisBackplaneValidator** | 75 | Chassis backplane support |
| **MotherboardStorageValidator** | 72 | Motherboard storage controllers |
| **HBARequirementValidator** | 68 | When HBA is mandatory |
| **PCIeAdapterValidator** | 65 | M.2/U.2-to-PCIe adapters |
| **StorageBayValidator** | 63 | Physical bay availability |
| **FormFactorLockValidator** | 60 | Form factor consistency |
| **NVMeSlotValidator** | 58 | M.2 slot types & PCIe lanes |

---

## 5. Component-Specific Validation Rules

### 5.1 CPU Validation

**Validator**: CPUValidator (Priority: 85)

**Required Fields**:
- `model`: CPU model name
- `socket`: Socket type (LGA1700, AM4, AM5, etc.)
- `cores`: Core count (1-128)
- `tdp_watts`: Thermal Design Power

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **Socket Match** | Critical | CPU socket ≠ Motherboard socket | ✗ BLOCKED |
| **TDP Limit** | Critical | TDP > 40% PSU wattage | ✗ BLOCKED |
| **TDP Excessive** | Warning | TDP > 300W | ⚠ Warning |
| **Core Count** | Error | Cores < 1 or > 128 | ✗ BLOCKED |
| **Deprecated CPU** | Warning | Model in EOL list | ⚠ Warning |
| **High Core Count** | Info | Cores > 64 | ℹ Info |

**Compatibility Logic**:
1. **Load CPU specs** from All-JSON/cpu-jsons/{uuid}.json
2. **Load Motherboard specs** from configuration
3. **Compare socket types** (exact match required)
4. **Validate TDP** against PSU capacity
5. **Check multi-socket support** (if multiple CPUs)

---

### 5.2 Motherboard Validation

**Validator**: MotherboardValidator (Priority: 80)

**Required Fields**:
- `model`: Motherboard model
- `socket`: CPU socket type
- `form_factor`: ATX, E-ATX, Micro-ATX, Mini-ITX
- `ram_slots`: Number of RAM slots
- `pcie_slots`: Number of PCIe slots

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **CPU Socket Match** | Critical | Socket ≠ CPU socket | ✗ BLOCKED |
| **RAM Slot Overflow** | Error | RAM count > ram_slots | ✗ BLOCKED |
| **PCIe Slot Overflow** | Error | PCIe count > pcie_slots | ✗ BLOCKED |
| **Form Factor Mismatch** | Error | Motherboard won't fit in chassis | ✗ BLOCKED |
| **VRM Insufficient** | Warning | VRM phases < (CPU TDP / 10) for high TDP | ⚠ Warning |
| **No PCIe Slots** | Warning | pcie_slots < 1 | ⚠ Warning |

**Slot Tracking**:
```
Example: Motherboard has 8 RAM slots, 7 PCIe slots
  • 4 RAM modules added → 4/8 slots used ✓
  • 5 PCIe devices added → 5/7 slots used ✓
  • Try to add 4th RAM → 4/8 slots used ✓
  • Try to add 4th PCIe → 8/7 slots used ✗ BLOCKED
```

---

### 5.3 RAM Validation

**Validator**: RAMValidator (Priority: 70)

**Required Fields**:
- `capacity_gb`: Capacity in GB
- `type`: DDR3, DDR4, DDR5, RDIMM, UDIMM, SODIMM
- `form_factor`: DIMM type

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **Type Mismatch** | Critical | RAM type ≠ Motherboard support | ✗ BLOCKED |
| **Slot Overflow** | Error | RAM count > motherboard ram_slots | ✗ BLOCKED |
| **Capacity Invalid** | Error | capacity_gb <= 0 or > 192GB | ✗ BLOCKED |
| **Type Mixing** | Warning | Different RAM types in config | ⚠ Warning |
| **Speed Mismatch** | Warning | RAM speed > Motherboard max | ⚠ Warning |
| **ECC Mismatch** | Warning | ECC RAM + non-ECC motherboard | ⚠ Warning |
| **High Capacity** | Info | Total capacity > 256GB | ℹ Info |

**Compatibility Logic**:
1. **Extract motherboard RAM support** (DDR generation)
2. **Check all RAM modules** match motherboard type
3. **Verify slot availability** (count <= slots)
4. **Check ECC support** if RAM is ECC
5. **Validate frequency** (RAM speed <= MB max)

---

### 5.4 Storage Validation

**Validator**: StorageValidator (Priority: 65) + 7 specialized validators

**Required Fields**:
- `capacity_gb`: Storage capacity
- `interface`: NVMe, SATA, SAS, U.2, M.2
- `form_factor`: 2.5", 3.5", M.2

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **Interface Mismatch** | Critical | NVMe but no M.2 slots | ✗ BLOCKED |
| **SAS without HBA** | Critical | SAS drive but no HBA card | ✗ BLOCKED |
| **Slot Overflow** | Error | NVMe count > motherboard m2_slots | ✗ BLOCKED |
| **Bay Overflow** | Error | Storage count > chassis drive_bays | ✗ BLOCKED |
| **Form Factor Mismatch** | Error | NVMe interface + 2.5" form factor | ✗ BLOCKED |
| **SATA Overflow** | Warning | SATA count > sata_ports (may need adapter) | ⚠ Warning |
| **High Capacity** | Info | Total capacity > 100TB | ℹ Info |

**Connection Path Validation**:
```
Storage Device → Motherboard/HBA → Backplane → Caddy
     │                │                │          │
     │                │                │          └─ Form factor match
     │                │                └─ Port availability
     │                └─ Interface support
     └─ Physical compatibility
```

**Interface-Specific Rules**:

| Interface | Requirements | Validator |
|-----------|-------------|-----------|
| **NVMe** | M.2 slots on motherboard | NVMeSlotValidator |
| **SATA** | SATA ports on motherboard/backplane | MotherboardStorageValidator |
| **SAS** | HBA card with matching SAS generation | HBARequirementValidator |
| **U.2** | PCIe adapter or motherboard U.2 support | PCIeAdapterValidator |

---

### 5.5 NIC Validation

**Validator**: NICValidator (Priority: 15)

**Required Fields**:
- `model`: NIC model
- `speed_gbps`: Network speed (1, 10, 25, 40, 100 Gbps)
- `port_count`: Number of ports (≥1)

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **PCIe Slot Overflow** | Error | Total NICs > motherboard pcie_slots | ✗ BLOCKED |
| **PCIe Gen Mismatch** | Warning | 100Gbps NIC needs PCIe Gen4+ | ⚠ Warning |
| **Speed Invalid** | Error | speed_gbps <= 0 | ✗ BLOCKED |
| **Port Count** | Error | port_count < 1 | ✗ BLOCKED |
| **Insufficient Lanes** | Warning | NIC speed needs more PCIe lanes | ⚠ Warning |

**PCIe Lane Requirements**:

| Network Speed | PCIe Lanes Required | PCIe Gen Required |
|---------------|---------------------|-------------------|
| 1 Gbps | 1 lane | Gen 1+ |
| 10 Gbps | 4 lanes | Gen 2+ |
| 25 Gbps | 8 lanes | Gen 3+ |
| 40 Gbps | 16 lanes | Gen 3+ |
| 100 Gbps | 16 lanes | **Gen 4+** |

---

### 5.6 PCIe Card Validation

**Validator**: PCIeCardValidator (Priority: 60)

**Required Fields**:
- `model`: Card model
- `pcie_generation`: PCIe gen (3, 4, 5)
- `pcie_slots`: Number of slots consumed (1-16)

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **Slot Overflow** | Error | Total PCIe devices > motherboard pcie_slots | ✗ BLOCKED |
| **Generation Downgrade** | Warning | Card PCIe gen > Motherboard PCIe gen | ⚠ Warning |
| **Physical Fit** | Error | Card length > chassis expansion slot length | ✗ BLOCKED |
| **Slot Count Unusual** | Warning | pcie_slots < 1 or > 16 | ⚠ Warning |

**Riser Card Handling**:
- **Detection**: component_subtype="Riser Card" OR UUID starts with "riser-"
- **Slot Assignment**: Assigned to separate riser slots (x1, x4, x8, x16)
- **Does NOT consume** regular PCIe slots

---

### 5.7 HBA Card Validation

**Validator**: HBAValidator (Priority: 10)

**Required Fields**:
- `model`: HBA model
- `port_count`: Number of ports (1-32)
- `sas_generation`: SAS1/2/3/4 (3/6/12/22 Gbps)

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **PCIe Slot Overflow** | Error | HBA cards > motherboard pcie_slots | ✗ BLOCKED |
| **SAS Gen Mismatch** | Warning | Storage SAS gen > HBA SAS gen | ⚠ Warning |
| **Port Count Invalid** | Error | port_count <= 0 or > 32 | ✗ BLOCKED |
| **No Battery + RAID** | Warning | RAID support but no battery backup | ⚠ Warning |
| **No Cache Memory** | Warning | cache_memory_mb not specified | ⚠ Warning |

**SAS Generation Matching**:
```
Example: HBA with SAS3 (12 Gbps)
  • SAS1 (3 Gbps) storage → ✓ Compatible (backward compatible)
  • SAS2 (6 Gbps) storage → ✓ Compatible
  • SAS3 (12 Gbps) storage → ✓ Compatible (perfect match)
  • SAS4 (22 Gbps) storage → ⚠ Warning (storage faster than HBA)
```

---

### 5.8 Chassis Validation

**Validator**: ChassisValidator (Priority: 20)

**Required Fields**:
- `model`: Chassis model
- `form_factor`: ATX, E-ATX, Rack, etc.
- `drive_bays`: Number of drive bays

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **Form Factor Mismatch** | Error | Motherboard form factor > Chassis support | ✗ BLOCKED |
| **Bay Overflow** | Error | Storage devices > drive_bays | ✗ BLOCKED |
| **Cooling Insufficient** | Warning | CPU TDP > 150W but airflow < 200 CFM | ⚠ Warning |
| **No Drive Bays** | Warning | drive_bays < 1 | ⚠ Warning |
| **No PCIe Slots** | Warning | pcie_slots = 0 | ⚠ Warning |
| **Limited Cooling** | Warning | cooling_fans < 2 | ⚠ Warning |

**Form Factor Compatibility**:

| Motherboard | Compatible Chassis Form Factors |
|-------------|--------------------------------|
| Mini-ITX | Mini-ITX, Micro-ATX, ATX, E-ATX, Rack |
| Micro-ATX | Micro-ATX, ATX, E-ATX, Rack |
| ATX | ATX, E-ATX, Rack |
| E-ATX | E-ATX, Rack (extra-large) |

---

### 5.9 Caddy Validation

**Validator**: CaddyValidator (Priority: 5)

**Required Fields**:
- `model`: Caddy model
- `form_factor`: 2.5", 3.5", M.2, U.2

**Validation Checks**:

| Check | Type | Condition | Result |
|-------|------|-----------|--------|
| **Form Factor Mismatch** | Error | Caddy form factor ≠ Storage form factor | ✗ BLOCKED |
| **Bay Overflow** | Error | Caddies > chassis drive_bays | ✗ BLOCKED |
| **Material Poor** | Warning | material = "plastic" (not durable) | ⚠ Warning |
| **Mounting Incompatible** | Error | Caddy mounting type not supported by chassis | ✗ BLOCKED |

**Mounting Types**:
- **RAIL**: Requires chassis with rail_compatible support
- **BAY**: Standard drive bay mounting
- **BRACKET**: Requires bracket_compatible support

**Hot-Swap**:
- If `hot_swap = true`: ℹ Info (allows drive replacement without shutdown)

---

## Summary: Core Compatibility Logic

### The Big Picture

```
┌──────────────────────────────────────────────────────────────────────┐
│                   SERVER CONFIGURATION LIFECYCLE                     │
└──────────────────────────────────────────────────────────────────────┘
                              │
                              │
        ┌─────────────────────▼─────────────────────┐
        │  1. CREATE: server-create-start           │
        │     • Start with motherboard              │
        │     • Database record created             │
        │     • Status: Draft                       │
        └─────────────────────┬─────────────────────┘
                              │
                              │
        ┌─────────────────────▼─────────────────────┐
        │  2. QUERY: server-get-compatible          │
        │     • Load existing components            │
        │     • Query available inventory           │
        │     • Run compatibility checks            │
        │     • Return compatible/incompatible list │
        └─────────────────────┬─────────────────────┘
                              │
                              │
        ┌─────────────────────▼─────────────────────┐
        │  3. ADD: server-add-component             │
        │     • Validate availability               │
        │     • Run compatibility checks            │
        │     • IF COMPATIBLE:                      │
        │       → Update configuration JSON         │
        │       → Lock component (Status=2)         │
        │     • IF INCOMPATIBLE:                    │
        │       → BLOCK addition                    │
        │       → Return detailed error             │
        └─────────────────────┬─────────────────────┘
                              │
                              │
        ┌─────────────────────▼─────────────────────┐
        │  4. REPEAT: Steps 2-3 until complete      │
        │     • Add CPU, RAM, storage, etc.         │
        │     • Each addition validated             │
        └─────────────────────┬─────────────────────┘
                              │
                              │
        ┌─────────────────────▼─────────────────────┐
        │  5. FINALIZE: server-finalize-config      │
        │     • Lock configuration                  │
        │     • Status: Validated/Built             │
        └───────────────────────────────────────────┘
```

### Key Compatibility Principles

1. **JSON Specification Required**: Every component UUID must exist in All-JSON/{type}-jsons/*.json
2. **Motherboard is Foundation**: Must be selected first; defines socket, slots, form factor
3. **Blocking vs Warning**: Errors block addition; warnings are informational only
4. **Slot Tracking**: RAM, PCIe, M.2, SATA slots tracked to prevent overflow
5. **Interface Matching**: Storage interface must match motherboard/HBA capability
6. **Form Factor Hierarchy**: Chassis must accommodate motherboard size
7. **Power Validation**: CPU TDP validated against PSU capacity
8. **Priority Execution**: Critical validators (socket, form factor) run first

### Validation Result Types

| Type | Symbol | Blocks Addition? | Example |
|------|--------|-----------------|---------|
| **Error** | ✗ | YES | "Socket mismatch: CPU LGA1700 ≠ MB LGA1200" |
| **Warning** | ⚠ | NO | "High TDP (300W) - ensure adequate cooling" |
| **Info** | ℹ | NO | "16 cores detected - excellent for multithreading" |

---

## File Reference

**Core API**: [api/server/server_api.php](../../api/server/server_api.php)

**Validators**: [includes/validators/](../../includes/validators/)

**Models**: [includes/models/](../../includes/models/)

**JSON Specs**: [All-JSON/](../../All-JSON/)

---

*Document Version: 1.0*
*Last Updated: 2025-11-14*
*Purpose: Comprehensive understanding of server configuration & compatibility system*
