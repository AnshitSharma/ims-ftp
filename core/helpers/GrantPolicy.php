<?php
/**
 * GrantPolicy — the rules about who may hand out access, and to whom.
 *
 * Added 2026-09-13 for findings C-01/F-14/F-15/F-21 of the security and ACL
 * reviews. Those four are one problem seen from four endpoints: every place
 * that writes a role, a permission or a security-sensitive user field checked
 * only "does the actor hold users.edit / users.manage_roles", and nothing
 * checked WHAT was being handed out or WHOSE account was being changed. So a
 * single narrow grant was enough to mint a super_admin, take over an admin
 * account by changing its email, or strip a user's last role.
 *
 * Every method either returns normally or ends the request via
 * send_json_response(), so callers read as assertions.
 *
 * These checks are DELIBERATELY conservative: they close privilege escalation
 * without narrowing anything an admin can do today. hasPermission() returns
 * true unconditionally for admin and super_admin (BaseFunctions.php), so the
 * "must hold what you grant" rules below are no-ops for them and bite only the
 * roles that could actually escalate.
 */
final class GrantPolicy
{
    /** Roles only certain roles may hand out. */
    const RESTRICTED_ROLES = [
        'super_admin' => ['super_admin'],
        'admin' => ['super_admin', 'admin'],
    ];

    /**
     * May $actor grant or revoke role $roleId?
     *
     * super_admin is grantable only by a super_admin; admin only by an admin or
     * a super_admin. Without this, users.create plus a role_id was a complete
     * privilege-escalation path: create an account, assign it super_admin, log
     * in as it.
     */
    public static function assertMayAssignRole($pdo, array $actor, $roleId, $verb = 'assign')
    {
        $stmt = $pdo->prepare("SELECT id, name FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            send_json_response(0, 1, 404, "Role not found");
        }

        $allowedHolders = self::RESTRICTED_ROLES[$role['name']] ?? null;
        if ($allowedHolders === null) {
            return; // not a privileged role -- the endpoint's own permission check governs
        }

        foreach ($allowedHolders as $holder) {
            if (userHasRole($pdo, $actor['id'], $holder)) {
                return;
            }
        }

        send_json_response(0, 1, 403,
            "You cannot $verb the {$role['name']} role");
    }

    /**
     * May $actor modify the account $targetUserId, given the fields being written?
     *
     * Two separate protections:
     *  - a privileged TARGET may only be edited by someone at least as
     *    privileged, so users.edit alone cannot be turned on an admin account;
     *  - email and status are security-sensitive, not profile text. Changing an
     *    account's email hands over its password-reset channel, and changing
     *    status is an access decision. Nobody may apply either to their own
     *    account through this endpoint.
     *
     * @param array $fields the update payload keys being written
     */
    public static function assertMayEditUser($pdo, array $actor, $targetUserId, array $fields)
    {
        $securitySensitive = array_intersect(array_keys($fields), ['email', 'status', 'username']);

        if ((string)$targetUserId === (string)$actor['id']) {
            if (!empty($securitySensitive)) {
                send_json_response(0, 1, 400,
                    "You cannot change your own " . implode(' or ', $securitySensitive)
                    . " here. Ask another administrator.");
            }
            return; // editing your own name is always fine
        }

        foreach (array_keys(self::RESTRICTED_ROLES) as $protectedRole) {
            if (!userHasRole($pdo, $targetUserId, $protectedRole)) {
                continue;
            }
            $allowedHolders = self::RESTRICTED_ROLES[$protectedRole];
            foreach ($allowedHolders as $holder) {
                if (userHasRole($pdo, $actor['id'], $holder)) {
                    return;
                }
            }
            send_json_response(0, 1, 403,
                "You cannot modify a $protectedRole account");
        }
    }

    /**
     * Refuse to remove a user's last role.
     *
     * roles-remove_user already had this check; acl-revoke_role did not, and
     * revokeRoleFromUser() deletes unconditionally. A user with no roles is not
     * "restricted" — they hold no grants at all, cannot be repaired through the
     * UI's role editor, and look identical to a misconfigured account.
     */
    public static function assertNotLastRole($pdo, $userId)
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS role_count FROM user_roles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ((int)($row['role_count'] ?? 0) <= 1) {
            send_json_response(0, 1, 409, "Cannot remove the last role from a user");
        }
    }

    /**
     * Refuse to write permissions into a role that the actor does not hold.
     *
     * You cannot grant what you do not have. A no-op for admin/super_admin,
     * whose hasPermission() bypass means they hold everything; it stops a role
     * holding only roles.edit from writing acl.* into a role and then assigning
     * that role to itself.
     *
     * @param array $permissions permission_id => granted, as roles-update_permissions sends it
     */
    public static function assertMayGrantPermissions($pdo, array $actor, array $permissions)
    {
        $wanted = [];
        foreach ($permissions as $permissionId => $granted) {
            if ($granted) {
                $wanted[] = (int)$permissionId;
            }
        }
        if (empty($wanted)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($wanted), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM permissions WHERE id IN ($placeholders)");
        $stmt->execute($wanted);

        $missing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $permission) {
            if (!hasPermission($pdo, $permission['name'], $actor['id'])) {
                $missing[] = $permission['name'];
            }
        }

        if (!empty($missing)) {
            sort($missing);
            send_json_response(0, 1, 403,
                "You cannot grant permissions you do not hold yourself: " . implode(', ', $missing));
        }
    }

    /**
     * Cut off an account that has just been deactivated.
     *
     * Access tokens are now rejected by authenticateWithJWT()'s status filter,
     * but the refresh tokens and outstanding password-reset tokens in the
     * database are independent credentials and survived deactivation untouched.
     */
    public static function revokeCredentials($pdo, $userId)
    {
        try {
            $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$userId]);
        } catch (Exception $e) {
            error_log("GrantPolicy: could not clear auth_tokens for user $userId: " . $e->getMessage());
        }

        try {
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
        } catch (Exception $e) {
            error_log("GrantPolicy: could not clear password_resets for user $userId: " . $e->getMessage());
        }
    }
}
