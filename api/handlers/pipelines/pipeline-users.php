<?php
/**
 * pipeline-users.php
 * Action: pipeline-users
 * Permission: pipeline.create | pipeline.manage  (identical to pipeline-create)
 *
 * The "who is transferring this hardware?" picker on the Hardware Handover form.
 *
 * WHY THIS EXISTS AND IS NOT users-list
 * -------------------------------------
 * `users-list` is gated on `users.view` (users_api.php). The person raising a
 * handover is typically a technician with no user-administration permissions at
 * all -- so they could never call it, and the form would have to ask them to type
 * a numeric user id from memory. That is the same failure pipeline-servers.php
 * was written to fix for the server picker, and the same bargain applies: if you
 * may raise a request that names a person to carry hardware, you may see the list
 * of people who could be named.
 *
 * WHY THE LIST IS FILTERED, NOT COMPLETE
 * --------------------------------------
 * Only users who can actually COMPLETE a step appear -- those holding
 * `pipeline.act` or `pipeline.manage` through any of their roles. Naming anyone
 * else would create a request whose confirmation step is owned by somebody who
 * cannot open it, freezing the parent install with no visible cause. It is also
 * the narrower answer: this is not a directory dump, it is the set of people who
 * are set up to sign for hardware.
 *
 * An EMPTY list is a real answer, not an error. It means nobody holds
 * `pipeline.act` yet, which is what seeder 2026_08_26_008 exists to fix -- and
 * the UI says so rather than showing an inexplicably empty dropdown.
 *
 * WHAT IT DELIBERATELY DOES NOT RETURN
 * ------------------------------------
 * id, username and a display name. No email, no role list, no permission dump,
 * no status history. Enough to pick a colleague out of a dropdown and nothing
 * that would make this a way around `users.view`.
 *
 * Params:
 * - search (optional): matches username, first name, last name
 * - limit  (optional): default 100, hard max 200
 */

try {
    if (!$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.create required", null);
        exit;
    }

    $search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
    $limit  = (int)($_POST['limit'] ?? $_GET['limit'] ?? 100);
    $limit  = min(200, max(1, $limit));

    $where  = ["u.status = 'active'"];
    $params = ['pipeline.act', 'pipeline.manage'];

    if ($search !== '') {
        $where[] = "(u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // DISTINCT because a user holding the permission through two roles is still
    // one person. $limit is an int clamped above, never request text.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname
           FROM users u
           JOIN user_roles ur       ON ur.user_id = u.id
           JOIN role_permissions rp ON rp.role_id = ur.role_id AND rp.granted = 1
           JOIN permissions p       ON p.id = rp.permission_id
          WHERE p.name IN (?, ?)
            AND " . implode(' AND ', $where) . "
          ORDER BY u.username ASC
          LIMIT " . $limit
    );
    $stmt->execute($params);

    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $full = trim(((string)$row['firstname']) . ' ' . ((string)$row['lastname']));
        $users[] = [
            'id'           => (int)$row['id'],
            'username'     => $row['username'],
            'display_name' => $full !== '' ? $full : $row['username'],
            // So the form can point out that naming yourself as the carrier is
            // allowed but unusual -- you are the one asking for the part.
            'is_self'      => (int)$row['id'] === (int)$user_id,
        ];
    }

    send_json_response(true, true, 200, "Users retrieved successfully", [
        'users' => $users,
        'total' => count($users),
    ]);
} catch (Exception $e) {
    error_log("pipeline-users error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve users");
}
