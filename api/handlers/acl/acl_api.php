<?php
/**
 * ACL handler — user permission/role assignment operations.
 *
 * Included by api/api.php for the `acl` module. All operations require the
 * acl.manage permission (checked here, since every operation shares it).
 */

function handleACLOperations($operation, $user) {
    global $pdo;

    error_log("ACL operation: $operation");

    // Check if user has ACL management permissions
    if (!hasPermission($pdo, 'acl.manage', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for ACL operations");
    }

    switch ($operation) {
        case 'get_user_permissions':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }

            $permissions = getUserPermissions($pdo, $targetUserId);
            $roles = getUserRoles($pdo, $targetUserId);

            send_json_response(1, 1, 200, "User permissions retrieved", [
                'user_id' => (int)$targetUserId,
                'permissions' => $permissions,
                'roles' => $roles
            ]);
            break;

        case 'assign_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';

            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }

            $success = assignPermissionToUser($pdo, $targetUserId, $permission);

            if ($success) {
                send_json_response(1, 1, 200, "Permission assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign permission");
            }
            break;

        // E.3 (audit §4.1): five acl-* operations removed — revoke_permission,
        // assign_role, revoke_role, check_permission, get_all_permissions. Zero
        // occurrences across all IMS-Frontend JS and HTML, re-verified 2026-09-21, and
        // every one of them duplicated something that IS called: roles-assign /
        // roles-remove_user own role assignment (with the GrantPolicy guards),
        // permissions-get_all owns the catalogue, and a permission check is not
        // something a client should be asking the server to perform one at a time.
        //
        // KEPT deliberately: assign_permission above, because user_permissions is still
        // read for non-admins and this is now the ONLY lever that can write a direct
        // per-user grant; and get_user_permissions, which the frontend does call.

        case 'get_all_roles':
            $roles = getAllRoles($pdo);
            send_json_response(1, 1, 200, "Roles retrieved successfully", ['roles' => $roles]);
            break;

        // D.1 (audit §2.2/§10 of the 2026-09-21 backend audit) — READ ONLY.
        //
        // The temporary/scoped-access subsystem has no writer left: the only
        // three INSERTs into user_permissions are inside TemporaryAccessManager::
        // grant(), which has no caller, and assignPermissionToUser(), reached
        // only by acl-assign_permission, which no client calls. But the READING
        // half is wired into the hot path of every request, and a previous
        // session recorded that leftover GLOBAL grants from before the
        // retirement silently satisfy permission checks and cannot be revoked
        // through any UI.
        //
        // There is no shell on this host and the database is not reachable
        // remotely, so this is the only way to see those rows before deciding
        // what to do with them. It lists; it never writes. Revocation is a
        // seeder, reviewed and run by hand.
        case 'list_scoped_grants':
            // Every column below is guarded: this handler deploys ~20s after
            // save while a schema change is applied by hand whenever the
            // operator gets to it, so a column may legitimately not be there.
            if (!SchemaHelper::hasTable($pdo, 'user_permissions')) {
                send_json_response(1, 1, 200, "No user_permissions table on this database", [
                    'supported' => false, 'grants' => [], 'total' => 0,
                ]);
            }

            $hasExpires = SchemaHelper::hasColumn($pdo, 'user_permissions', 'expires_at');
            $hasScope   = SchemaHelper::hasColumn($pdo, 'user_permissions', 'scope_type');
            $hasScopeId = SchemaHelper::hasColumn($pdo, 'user_permissions', 'scope_id');
            $hasGranted = SchemaHelper::hasColumn($pdo, 'user_permissions', 'granted_at');

            if (!$hasExpires && !$hasScope) {
                send_json_response(1, 1, 200, "No temporary or scoped columns on this database", [
                    'supported' => false, 'grants' => [], 'total' => 0,
                ]);
            }

            $cols = ['up.id', 'up.user_id', 'u.username', 'p.name AS permission'];
            $conds = [];
            if ($hasExpires) {
                $cols[] = 'up.expires_at';
                $conds[] = 'up.expires_at IS NOT NULL';
            }
            if ($hasScope) {
                $cols[] = 'up.scope_type';
                $conds[] = 'up.scope_type IS NOT NULL';
            }
            if ($hasScopeId) { $cols[] = 'up.scope_id'; }
            if ($hasGranted) { $cols[] = 'up.granted_at'; }

            // A GLOBAL grant — one with no scope and no expiry — is the dangerous
            // shape, so it is reported too rather than filtered out. The whole
            // table is small enough that listing all of it is the honest answer.
            $sql = 'SELECT ' . implode(', ', $cols) . '
                      FROM user_permissions up
                      LEFT JOIN users u ON u.id = up.user_id
                      LEFT JOIN permissions p ON p.id = up.permission_id
                  ORDER BY up.id';

            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $scoped = [];
            foreach ($rows as $row) {
                $isScoped = ($hasExpires && $row['expires_at'] !== null)
                         || ($hasScope && $row['scope_type'] !== null);
                if ($isScoped) {
                    $scoped[] = $row;
                }
            }

            send_json_response(1, 1, 200, "Scoped and temporary grants listed", [
                'supported'           => true,
                'grants'              => $scoped,
                'total'               => count($scoped),
                'all_rows'            => count($rows),
                'global_direct_grants' => count($rows) - count($scoped),
            ]);
            break;

        default:
            send_json_response(0, 1, 400, "Invalid ACL operation: $operation");
    }
}
