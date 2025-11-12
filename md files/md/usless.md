
# Analysis of Unused Files and Functions

This document lists files and functions that appear to be unused or redundant within the `api` and `includes` directories.

## Unused or Redundant Files

The following files seem to be unused, empty, or replaced by newer implementations in `api/api.php`.

### Legacy API Endpoints

These files appear to be legacy API endpoints that have been replaced by the centralized routing in `api/api.php`.

- `api/auth/change_password_api.php`
- `api/auth/check_session_api.php`
- `api/auth/forgot_password_api.php`
- `api/auth/login_api.php`
- `api/auth/logout_api.php`
- `api/auth/register_api.php`
- `api/components/components_api.php`
- `api/dashboard/dashboard_api.php`
- `api/search/search_api.php`

### Empty or Placeholder Files

The entire `api/functions` directory and its subdirectories contain empty or placeholder files that don't have any functionality.

- `api/functions/caddy/add_caddy.php`
- `api/functions/caddy/list_caddy.php`
- `api/functions/caddy/remove_caddy.php`
- `api/functions/cpu/add_cpu.php`
- `api/functions/cpu/list_cpu.php`
- `api/functions/cpu/remove_cpu.php`
- `api/functions/motherboard/add_motherboard.php`
- `api/functions/motherboard/list_motherboard.php`
- `api/functions/motherboard/remove_motherboard.php`
- `api/functions/nic/add_nic.php`
- `api/functions/nic/list_nic.php`
- `api/functions/nic/remove_nic.php`
- `api/functions/ram/add_ram.php`
- `api/functions/ram/list_ram.php`
- `api/functions/ram/remove_ram.php`
- `api/functions/storage/add_storage.php`
- `api/functions/storage/list_storage.php`
- `api/functions/storage/remove_storage.php`

### Unused `includes` Files

- `includes/config.env`: This file is empty.
- `includes/models/ComponentDataService.php`: This class is not referenced in the codebase.
- `includes/QueryModel.php`: This file contains a single `test()` function that is not used anywhere.
- `includes/SimpleACL.php`: This appears to be an older, simpler version of `ACL.php` and is not currently in use.

## Unused Functions

The following functions are defined but do not appear to be called from anywhere in the analyzed files.

### `includes/BaseFunctions.php`

- `safeSessionStart()`: The application has moved to JWT for authentication, making session-based functions obsolete.
- `isUserLoggedIn()`: A legacy session-based function.
- `get_user_role()`: A legacy ACL function.
- `check_permission()`: A legacy ACL function.
- `log_action()`: A legacy logging function.

### `includes/QueryModel.php`

- `test()`: This function is not called anywhere.
