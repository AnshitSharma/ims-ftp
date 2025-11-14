# Config File Merge Plan

## 📊 Current Situation

**Two separate config files with duplicate logic:**

1. **includes/config.php** (97 lines)
   - Loads .env file
   - JWT, security, CORS, rate limiting configs
   - Helper functions

2. **includes/db_config.php** (52 lines)
   - Loads .env file (DUPLICATE!)
   - Database credentials (hardcoded)
   - Creates PDO connection

**Problem**: Duplicate .env loading, scattered configuration

---

## ✅ Proposed Solution: Merge into Single File

### Benefits

1. **No Duplication**: Load .env only once
2. **Single Source**: All config in one place
3. **Better Organization**: Grouped by concern
4. **Environment Support**: DB credentials from .env
5. **Cleaner Code**: Less require_once statements

---

## 📝 Files Currently Using These

### config.php (9 files):
- api/api.php
- api/auth/login_api.php
- api/auth/register_api.php
- api/auth/forgot_password_api.php
- api/server/server_api.php
- api/server/compatibility_api.php
- api/chassis/chassis_api.php
- api/components/components_api.php
- includes/config.php (self-reference)

### db_config.php (7 files):
- api/api.php
- api/auth/login_api.php
- api/auth/register_api.php
- api/auth/forgot_password_api.php
- api/server/server_api.php
- api/server/compatibility_api.php
- api/chassis/chassis_api.php

---

## 🎯 Migration Steps

### Step 1: Backup Current Files
```bash
cp includes/config.php includes/config.php.backup
cp includes/db_config.php includes/db_config.php.backup
```

### Step 2: Replace config.php
```bash
mv includes/config.php.new includes/config.php
```

### Step 3: Update All Files
**Replace this pattern:**
```php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_config.php';
```

**With just:**
```php
require_once __DIR__ . '/../includes/config.php';
```

### Step 4: Delete db_config.php
```bash
rm includes/db_config.php
rm includes/db_config.php.backup
```

### Step 5: Update .env (Add DB Credentials)
```bash
# Add to .env file:
DB_HOST=localhost
DB_USER=shubhams_api
DB_PASS=5C8R.wRErC_(
DB_NAME=shubhams_ims_dev
```

### Step 6: Test All Endpoints
- Test authentication
- Test component APIs
- Test server configuration
- Verify database connection

---

## 📋 Files to Update (7 files)

1. **api/api.php**
2. **api/auth/login_api.php**
3. **api/auth/register_api.php**
4. **api/auth/forgot_password_api.php**
5. **api/server/server_api.php**
6. **api/server/compatibility_api.php**
7. **api/chassis/chassis_api.php**

---

## 🔍 New config.php Structure

```
config.php
├── Environment Loading (loadEnvFile)
├── Timezone Configuration
├── Application Configuration (APP_ENV, APP_DEBUG, etc.)
├── JWT Configuration
├── Security Configuration
├── API Rate Limiting
├── CORS Configuration
├── Logging Configuration
├── Component Settings
├── Database Configuration & Connection ($pdo)
└── Helper Functions
```

**Total**: ~180 lines (vs 149 lines in 2 files)
**Benefit**: Single point of configuration

---

## ⚠️ Important Notes

1. **Environment Variables**: Move DB credentials to .env for security
2. **Backwards Compatible**: $pdo global variable still available
3. **Error Handling**: Shows detailed errors only in development mode
4. **Testing**: Test thoroughly before deploying

---

## 🎉 Expected Result

**Before:**
```php
// Every API file
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_config.php';
```

**After:**
```php
// Every API file
require_once __DIR__ . '/../includes/config.php';
// That's it! Database already connected
```

**Cleaner, simpler, better organized!**
