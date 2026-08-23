<?php
/**
 * TemporaryAccessManager.php — time-limited per-user permission grants.
 *
 * A grant is a row in `user_permissions` (the table that loadUserPermissionData()
 * already unions with role permissions) carrying an `expires_at`. Because every
 * request re-reads permissions from the DB — the JWT holds only user_id and
 * username — a grant is live on the user's very next API call, and dead the
 * moment it expires. There is no job to run and nothing to revoke by hand.
 *
 * The only caller today is the pipeline approval path
 * (PipelineManager::completeStage(), effect_type = 'grant_temporary_permission').
 */

require_once(__DIR__ . '/../helpers/SchemaHelper.php');

class TemporaryAccessManager
{
    /**
     * Permissions that may be handed out by a temporary grant. This is a hard
     * ceiling, checked on every grant regardless of what a Request Type's
     * effect_config or a requester's requested_access asks for -- neither a
     * request-type editor nor a requester may author a grant of users.delete,
     * acl.manage or system.backup.
     *
     * DELIBERATELY EXCLUDED, and not to be added without a decision:
     *   - every *.delete (server.delete, cpu.delete, ...). Deletion is
     *     irreversible and inventory rows are referenced by server
     *     configurations, so a 24-hour grant could do permanent damage that
     *     outlives it.
     *   - users.* roles.* acl.* permissions.* system.* -- privilege
     *     administration is never temporary.
     *   - every *_all / *_finalized escalation permission. server.edit_all is
     *     the exact thing per-configuration scoping exists to avoid granting.
     */
    const GRANTABLE_PERMISSIONS = [
        // Build and change server configurations
        'server.create',
        'server.view',
        'server.edit',
        'server.edit_details',
        'server.replace',
        'server.transition',
        // Add to and correct the component inventory (11 types x create/edit)
        'cpu.create',         'cpu.edit',
        'ram.create',         'ram.edit',
        'storage.create',     'storage.edit',
        'motherboard.create', 'motherboard.edit',
        'nic.create',         'nic.edit',
        'caddy.create',       'caddy.edit',
        'chassis.create',     'chassis.edit',
        'pciecard.create',    'pciecard.edit',
        'risercard.create',   'risercard.edit',
        'hbacard.create',     'hbacard.edit',
        'sfp.create',         'sfp.edit',
    ];

    /**
     * Scope discriminator for a grant limited to one server configuration.
     * scope_id then holds that configuration's config_uuid.
     */
    const SCOPE_SERVER_CONFIG = 'server_config';

    /** The empty scope: a normal, system-wide grant. */
    const SCOPE_GLOBAL = '';

    /**
     * Permissions that are MEANINGFUL when scoped to a single configuration.
     *
     * server.create is in this list, which looks wrong until you check
     * permission_map.php: `server-add-component` is gated on server.create, not
     * server.edit. Adding a part to a build is THE thing "let me change server X"
     * means, so leaving it out would make a scoped grant nearly useless.
     *
     * It is safe because a scoped grant is only ever consulted when the request
     * names a config_uuid (see hasScopedPermissionForRequest). `server-create-start`
     * sends none, so a grant scoped to server X can never be used to create a new
     * server. `server-finalize-config` is also gated on server.create but is
     * additionally blocked at the fine gate (userCanActOnConfig with
     * allowScoped = false) -- a temporary grant lets you change a build, not lock
     * it. `server-clone-config` is reachable, producing a new configuration the
     * requester owns; that is a copy, not a change to anyone else's work.
     *
     * Inventory permissions are absent because inventory is not owned by a
     * configuration -- a request needing both a scoped server change and
     * inventory access gets the inventory half granted globally.
     */
    const SCOPABLE_PERMISSIONS = [
        'server.view',
        'server.create',
        'server.edit',
        'server.edit_details',
        'server.replace',
        'server.transition',
    ];

    /** Bounds on how long a temporary grant may last. */
    const MIN_DURATION_HOURS = 1;
    const MAX_DURATION_HOURS = 168; // 7 days

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Shared deploy-ordering probe: does $table have $column yet?
     *
     * Code reaches production by FTP ~20s after a save; seeders are applied to the
     * database by hand, afterwards. Every read of a newly-added column goes
     * through this so that window is harmless rather than a site-wide 500.
     * Results are cached for the life of the request — one SHOW COLUMNS per
     * table/column at most.
     *
     * Static because BaseFunctions::loadUserPermissionData(),
     * ACL::loadUserPermissions() and the pipeline engine all need it without
     * owning an instance.
     */
    public static function hasColumn(PDO $pdo, $table, $column)
    {
        // RETIRED 2026-08-23 -- the implementation moved to SchemaHelper so it
        // could outlive this class. Delegating rather than duplicating keeps the
        // two provably identical while both names are still in use.
        return SchemaHelper::hasColumn($pdo, $table, $column);
    }

    /**
     * Has 2026_08_20_001 been applied? Without it there is no way to make a grant
     * end, so temporary access is refused rather than issued as permanent.
     */
    public static function schemaSupportsExpiry(PDO $pdo)
    {
        return self::hasColumn($pdo, 'user_permissions', 'expires_at');
    }

    /**
     * SQL fragment restricting `user_permissions` to grants that are live right
     * now. Returns '' when the expiry columns are not deployed yet, which
     * reproduces the historical behaviour exactly (every row permanent).
     *
     * Callers append this inside their existing WHERE clause; it introduces no
     * bound parameters, so it is safe to concatenate.
     */
    public static function activeGrantClause(PDO $pdo, $alias = '', $globalOnly = true)
    {
        if (!self::schemaSupportsExpiry($pdo)) {
            return '';
        }

        $prefix = $alias === '' ? '' : $alias . '.';

        $clause = " AND {$prefix}revoked_at IS NULL"
                . " AND ({$prefix}expires_at IS NULL OR {$prefix}expires_at > NOW())";

        // SCOPED grants must not reach the flat permission list. A grant limited
        // to one configuration would otherwise satisfy every check for that
        // permission -- "edit server X" would become "edit anything", which is
        // the precise thing scoping exists to prevent. Callers that want a
        // specific scope go through hasScopedPermission() instead.
        if ($globalOnly && self::schemaSupportsScopes($pdo)) {
            $clause .= " AND {$prefix}scope_type = ''";
        }

        return $clause;
    }

    /**
     * Has 2026_08_21_001 been applied? Until it has, there are no scoped grants
     * and every row in user_permissions is global.
     */
    public static function schemaSupportsScopes(PDO $pdo)
    {
        return self::hasColumn($pdo, 'user_permissions', 'scope_type');
    }

    /**
     * Does this user hold a live grant for $permission limited to $scopeId?
     *
     * This is the fine-grained half of the check. The coarse half (does the user
     * hold the permission at all) is hasPermission()/permission_map; this answers
     * "...and may they use it on THIS configuration".
     *
     * A GLOBAL grant of the same permission also satisfies this: someone allowed
     * to edit every configuration is necessarily allowed to edit this one.
     */
    public function hasScopedPermission($userId, $permission, $scopeId, $scopeType = self::SCOPE_SERVER_CONFIG)
    {
        if (!self::schemaSupportsScopes($this->pdo) || $scopeId === null || $scopeId === '') {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1
                 FROM user_permissions up
                 JOIN permissions p ON p.id = up.permission_id
                 WHERE up.user_id = ?
                   AND p.name = ?
                   AND up.scope_type = ?
                   AND up.scope_id = ?
                   AND up.revoked_at IS NULL
                   AND (up.expires_at IS NULL OR up.expires_at > NOW())
                 LIMIT 1"
            );
            $stmt->execute([(int)$userId, $permission, $scopeType, (string)$scopeId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("TemporaryAccessManager::hasScopedPermission error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Does this user hold ANY live grant scoped to $scopeId?
     *
     * This is the fine half of the two-layer gate that per-configuration access
     * uses:
     *
     *   coarse (permission_map / requireModulePermission)
     *       "do you hold server.edit at all — globally, or scoped to some config?"
     *   fine (this method, called from server_api.php's ownership checks)
     *       "...and is THIS the configuration you were given access to?"
     *
     * Composing the two is what makes the model correct without teaching every
     * handler which permission it was gated on. Holding server.replace scoped to
     * config X and then attempting server-update-config fails at the COARSE gate
     * (server.edit_details is held nowhere); attempting to replace a part in
     * config Y fails here.
     *
     * That example USED to say server.edit, and was wrong: Edit and Remove
     * Hardware both grant server.edit (remove-component is gated on it), so
     * until 2026-08-23 a hardware grant also opened server-update-config and
     * could rename or finalize the build. server.edit_details exists to keep the
     * coarse gate honest -- see permission_map.php.
     *
     * @return bool
     */
    public function hasAnyScopedGrant($userId, $scopeId, $scopeType = self::SCOPE_SERVER_CONFIG)
    {
        if (!self::schemaSupportsScopes($this->pdo) || $scopeId === null || $scopeId === '') {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1
                 FROM user_permissions
                 WHERE user_id = ?
                   AND scope_type = ?
                   AND scope_id = ?
                   AND revoked_at IS NULL
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1"
            );
            $stmt->execute([(int)$userId, $scopeType, (string)$scopeId]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("TemporaryAccessManager::hasAnyScopedGrant error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Grant a set of permissions to one user for a fixed number of hours.
     *
     * Assumes the caller has already established that the grant is authorised —
     * this class enforces WHAT may be granted, not WHO may grant it. It also
     * assumes an open transaction when called from the pipeline path, so a
     * failure here rolls the approval back with it.
     *
     * @param int    $userId    who receives the access
     * @param array  $names     permission names; each must be in GRANTABLE_PERMISSIONS
     * @param int    $hours     window length
     * @param int    $grantedBy users.id of the approver
     * @param int    $ticketId  tickets.id the grant came from (audit trail)
     * @param string $scopeId   limit the grant to this config_uuid; '' = global
     * @param string $scopeType scope discriminator; only SCOPE_SERVER_CONFIG today
     * @return array {success, errors[], granted[], scoped[], expires_at, scope_id}
     */
    public function grant($userId, array $names, $hours, $grantedBy, $ticketId = null,
                          $scopeId = self::SCOPE_GLOBAL, $scopeType = self::SCOPE_SERVER_CONFIG)
    {
        $result = [
            'success' => false, 'errors' => [], 'granted' => [], 'scoped' => [],
            'expires_at' => null, 'scope_id' => null
        ];

        // A scope was asked for but the migration that stores it is not applied.
        // Granting globally instead would be strictly WIDER than what was
        // approved, so refuse rather than over-grant.
        $wantsScope = ($scopeId !== null && $scopeId !== self::SCOPE_GLOBAL);
        if ($wantsScope && !self::schemaSupportsScopes($this->pdo)) {
            $result['errors'][] = 'Per-server access is not available until the '
                . 'scoped-permission migration has been applied';
            return $result;
        }

        if (!self::schemaSupportsExpiry($this->pdo)) {
            // Without expires_at a "temporary" grant would be permanent. Refuse
            // outright rather than quietly hand out forever-access.
            $result['errors'][] = 'Temporary access is not available until the '
                . 'permission-expiry migration has been applied';
            return $result;
        }

        $userId = (int)$userId;
        if ($userId <= 0) {
            $result['errors'][] = 'A valid recipient is required';
            return $result;
        }

        $hours = (int)$hours;
        if ($hours < self::MIN_DURATION_HOURS || $hours > self::MAX_DURATION_HOURS) {
            $result['errors'][] = 'Duration must be between ' . self::MIN_DURATION_HOURS
                . ' and ' . self::MAX_DURATION_HOURS . ' hours';
            return $result;
        }

        $requested = array_values(array_unique(array_filter(array_map('trim', $names))));
        if (empty($requested)) {
            $result['errors'][] = 'At least one permission is required';
            return $result;
        }

        foreach ($requested as $name) {
            if (!in_array($name, self::GRANTABLE_PERMISSIONS, true)) {
                error_log("TemporaryAccessManager: refused non-grantable permission '$name'");
                $result['errors'][] = "'$name' cannot be granted as temporary access";
                return $result;
            }
        }

        try {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

            // Resolve names against the `permissions` table — the same table
            // loadUserPermissionData() reads. (assignPermissionToUser() once used
            // the legacy acl_permissions table, whose IDs belong to a different
            // sequence, so grants landed on the wrong permission entirely.)
            $placeholders = implode(',', array_fill(0, count($requested), '?'));
            $lookup = $this->pdo->prepare(
                "SELECT id, name FROM permissions WHERE name IN ($placeholders)"
            );
            $lookup->execute($requested);
            $found = $lookup->fetchAll(PDO::FETCH_KEY_PAIR); // id => name

            $foundNames = array_values($found);
            $missing = array_diff($requested, $foundNames);
            if (!empty($missing)) {
                $result['errors'][] = 'Unknown permission(s): ' . implode(', ', $missing);
                return $result;
            }

            $hasScopes = self::schemaSupportsScopes($this->pdo);

            // A row that is already permanent must never be downgraded to a
            // 24-hour one — that would silently take access away from someone who
            // legitimately holds it forever. Only GLOBAL permanent grants count:
            // holding server.edit on config A says nothing about config B.
            $existing = $this->pdo->prepare(
                "SELECT permission_id FROM user_permissions
                 WHERE user_id = ? AND expires_at IS NULL AND revoked_at IS NULL"
                . ($hasScopes ? " AND scope_type = ''" : "")
            );
            $existing->execute([$userId]);
            $permanent = $existing->fetchAll(PDO::FETCH_COLUMN);

            // UNIQUE (user_id, permission_id, scope_type, scope_id) means a repeat
            // grant extends the existing row instead of stacking duplicates, while
            // still allowing the same permission on two configurations at once.
            $insert = $hasScopes
                ? $this->pdo->prepare(
                    "INSERT INTO user_permissions
                        (user_id, permission_id, scope_type, scope_id, expires_at, revoked_at, granted_by, source_ticket_id)
                     VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        expires_at = VALUES(expires_at),
                        revoked_at = NULL,
                        granted_by = VALUES(granted_by),
                        source_ticket_id = VALUES(source_ticket_id)")
                : $this->pdo->prepare(
                    "INSERT INTO user_permissions
                        (user_id, permission_id, expires_at, revoked_at, granted_by, source_ticket_id)
                     VALUES (?, ?, ?, NULL, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        expires_at = VALUES(expires_at),
                        revoked_at = NULL,
                        granted_by = VALUES(granted_by),
                        source_ticket_id = VALUES(source_ticket_id)");

            foreach ($found as $permissionId => $name) {
                // Which scope does THIS permission land in? Only the server
                // permissions in SCOPABLE_PERMISSIONS can be tied to a
                // configuration. Inventory is not owned by a server, so inventory
                // permissions are granted globally even on a targeted request.
                $useScope = $wantsScope && in_array($name, self::SCOPABLE_PERMISSIONS, true);
                $rowScopeType = $useScope ? $scopeType : self::SCOPE_GLOBAL;
                $rowScopeId   = $useScope ? (string)$scopeId : self::SCOPE_GLOBAL;

                if (in_array($permissionId, $permanent)) {
                    // Already held globally and permanently — nothing to add.
                    continue;
                }

                $params = [$userId, (int)$permissionId];
                if ($hasScopes) {
                    $params[] = $rowScopeType;
                    $params[] = $rowScopeId;
                }
                $params[] = $expiresAt;
                $params[] = $grantedBy !== null ? (int)$grantedBy : null;
                $params[] = $ticketId !== null ? (int)$ticketId : null;

                $insert->execute($params);

                $result['granted'][] = $name;
                if ($useScope) {
                    $result['scoped'][] = $name;
                }
            }

            $result['scope_id'] = $wantsScope ? (string)$scopeId : null;

            // The recipient's permissions may already be cached for this request.
            if (function_exists('clearPermissionCache')) {
                clearPermissionCache($userId);
            }

            $result['success'] = true;
            $result['expires_at'] = $expiresAt;
            return $result;

        } catch (Exception $e) {
            error_log("TemporaryAccessManager::grant error: " . $e->getMessage());
            $result['errors'][] = 'Failed to record the access grant';
            return $result;
        }
    }

    /**
     * Every temporary grant currently live for a user, soonest expiry first.
     * Permanent rows (expires_at IS NULL) are excluded — they are not
     * "temporary access" and showing them as such would be misleading.
     *
     * @return array [{permission, expires_at, source_ticket_id}, ...]
     */
    public function listActive($userId)
    {
        if (!self::schemaSupportsExpiry($this->pdo)) {
            return [];
        }

        // scope_* arrive with 2026_08_21_001; report them only once they exist.
        $scopeSelect = self::schemaSupportsScopes($this->pdo)
            ? ', up.scope_type, up.scope_id'
            : ", '' AS scope_type, '' AS scope_id";

        try {
            $stmt = $this->pdo->prepare(
                "SELECT p.name AS permission, up.expires_at, up.source_ticket_id{$scopeSelect}
                 FROM user_permissions up
                 JOIN permissions p ON p.id = up.permission_id
                 WHERE up.user_id = ?
                   AND up.revoked_at IS NULL
                   AND up.expires_at IS NOT NULL
                   AND up.expires_at > NOW()
                 ORDER BY up.expires_at ASC"
            );
            $stmt->execute([(int)$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("TemporaryAccessManager::listActive error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * End a temporary grant early. Only ever touches temporary rows — a
     * permanent grant is not this class's to remove (that is acl-revoke_permission).
     *
     * @return bool true if a row was revoked
     */
    public function revoke($userId, $permissionName)
    {
        if (!self::schemaSupportsExpiry($this->pdo)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE user_permissions up
                 JOIN permissions p ON p.id = up.permission_id
                 SET up.revoked_at = NOW()
                 WHERE up.user_id = ?
                   AND p.name = ?
                   AND up.expires_at IS NOT NULL
                   AND up.revoked_at IS NULL"
            );
            $stmt->execute([(int)$userId, $permissionName]);

            if (function_exists('clearPermissionCache')) {
                clearPermissionCache($userId);
            }

            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("TemporaryAccessManager::revoke error: " . $e->getMessage());
            return false;
        }
    }
}
