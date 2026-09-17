<?php
/**
 * Users.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * User account CRUD, including deleteUser()'s FK-blocker reassignment machinery
 * (isForeignKeyParentError/parseForeignKeyBlocker/reassignUserReferences exist specifically
 * for it, not as generic FK helpers). Mechanical split: function bodies unchanged, still
 * global functions.
 */

/**
 * User Management Functions
 */

/**
 * Get all users
 */
function getAllUsers($pdo) {
    try {
        // The deleted-user placeholder is bookkeeping, not an account: it only
        // exists to keep the audit rows of removed users readable. Listing it
        // would show it as a user to manage and offer it in assignee pickers.
        $stmt = $pdo->prepare("
            SELECT id, username, email, firstname, lastname, status, created_at
            FROM users
            WHERE username <> ?
            ORDER BY username
        ");
        $stmt->execute([DELETED_USER_PLACEHOLDER]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) {
            return [];
        }

        // Attach each user's assigned roles in a single extra query (no N+1).
        // Additive: existing callers ignore the new `roles` field.
        $rolesByUser = [];
        $roleStmt = $pdo->query("
            SELECT ur.user_id, r.id, r.name, r.display_name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            ORDER BY r.display_name
        ");
        foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rolesByUser[$row['user_id']][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'display_name' => $row['display_name'],
            ];
        }

        foreach ($users as &$user) {
            $user['roles'] = $rolesByUser[$user['id']] ?? [];
        }
        unset($user);

        return $users;
    } catch (Exception $e) {
        error_log("Error getting all users: " . $e->getMessage());
        return [];
    }
}

/**
 * Create user
 */
function createUser($pdo, $username, $email, $password, $firstname, $lastname) {
    try {
        // Check if username/email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return false; // User already exists
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, firstname, lastname, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");
        $result = $stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname]);

        if ($result) {
            return $pdo->lastInsertId();
        }

        return false;

    } catch (Exception $e) {
        error_log("Error creating user: " . $e->getMessage());
        return false;
    }
}

/**
 * Update user
 */
function updateUser($pdo, $userId, $data) {
    try {
        $allowedFields = ['username', 'email', 'password', 'firstname', 'lastname', 'status'];
        $data = array_intersect_key($data, array_flip($allowedFields));
        if (empty($data)) {
            return false;
        }

        // Hash password before storage
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $columns = array_keys($data);
        $setClause = implode(' = ?, ', $columns) . ' = ?';
        $values = array_values($data);
        $values[] = $userId;

        $sql = "UPDATE users SET $setClause WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);

    } catch (Exception $e) {
        error_log("Error updating user: " . $e->getMessage());
        return false;
    }
}

/**
 * Status stored on a user whose account was removed but whose row has to
 * survive because audit records point at it. Anything other than 'active'
 * already blocks login (authenticateUser() filters on status = 'active').
 */
const USER_STATUS_RETIRED = 'retired';

/**
 * Strip every row that grants the user access: role assignments, direct
 * permission grants and live tokens. Shared by both delete outcomes, so a
 * retired account is exactly as powerless as a deleted one.
 *
 * Assumes an open transaction.
 */
function revokeUserAccessRows($pdo, $userId) {
    $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);
}

/**
 * True when a PDOException is InnoDB refusing to orphan a child row
 * (ER_ROW_IS_REFERENCED / ER_ROW_IS_REFERENCED_2).
 */
function isForeignKeyParentError(PDOException $e) {
    $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
    return in_array($driverCode, [1217, 1451], true);
}

/**
 * Pull the blocking table and column out of a 1451 message. MariaDB spells the
 * whole constraint out:
 *
 *   Cannot delete or update a parent row: a foreign key constraint fails
 *   (`db`.`ticket_history`, CONSTRAINT `ticket_history_ibfk_2`
 *    FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`))
 *
 * Reading it is how deleteUser() handles a blocker in a table it doesn't know
 * about, without information_schema (denied to the app DB user).
 *
 * @return array{table:string, column:string}|null  null when the message can't
 *         be parsed or the FK doesn't point at users.
 */
function parseForeignKeyBlocker(PDOException $e) {
    $message = $e->getMessage();

    if (!preg_match('/CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY\s+\(`([^`]+)`\)\s+REFERENCES\s+`([^`]+)`/i', $message, $fk)) {
        return null;
    }
    if (strtolower($fk[2]) !== 'users') {
        return null;
    }
    if (!preg_match('/constraint fails\s+\(`[^`]+`\.`([^`]+)`/i', $message, $child)) {
        return null;
    }

    // Identifiers come from the server's own error text; only ever interpolate
    // them after confirming they are plain identifiers.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $child[1]) || !preg_match('/^[A-Za-z0-9_]+$/', $fk[1])) {
        return null;
    }
    if (strtolower($child[1]) === 'users') {
        return null;
    }

    return ['table' => $child[1], 'column' => $fk[1]];
}

/**
 * Username of the placeholder account that inherits the audit rows of a
 * deleted user. It holds no roles and its status blocks login, so it is a
 * label on history, never an account anyone can use.
 */
const DELETED_USER_PLACEHOLDER = 'deleted_user';

/**
 * Return the placeholder user's id, creating the account on first use.
 * Must be called OUTSIDE the delete transaction so a rollback can't undo it.
 */
function getDeletedUserPlaceholderId($pdo) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([DELETED_USER_PLACEHOLDER]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, firstname, lastname, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        DELETED_USER_PLACEHOLDER,
        'deleted_user@placeholder.invalid',
        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'Deleted',
        'User',
        USER_STATUS_RETIRED
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Point one blocking column at the placeholder account, keeping the audit row
 * itself. Returns the number of rows moved, or false if the update failed.
 */
function reassignUserReferences($pdo, array $blocker, $userId, $placeholderId) {
    try {
        $sql = sprintf(
            "UPDATE `%s` SET `%s` = ? WHERE `%s` = ?",
            $blocker['table'],
            $blocker['column'],
            $blocker['column']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$placeholderId, $userId]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("Could not reassign {$blocker['table']}.{$blocker['column']} for user {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Count the audit rows that make a user undeletable, for the message shown
 * to the admin. Tables are counted defensively: a missing one must not turn
 * a successful retire into an error.
 */
function countUserAuditReferences($pdo, $userId) {
    $counts = [];

    foreach ([
        'Requests raised'  => "SELECT COUNT(*) FROM tickets WHERE created_by = ?",
        'history entries'  => "SELECT COUNT(*) FROM ticket_history WHERE changed_by = ?"
    ] as $label => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $n = (int)$stmt->fetchColumn();
            if ($n > 0) {
                $counts[] = $n . ' ' . $label;
            }
        } catch (Exception $e) {
            // Table absent or not readable — the message just loses a detail.
        }
    }

    return $counts;
}

/**
 * Remove a user account for good.
 *
 * A user who raised a Request or acted on one is referenced by
 * tickets.created_by and ticket_history.changed_by — NOT NULL columns behind
 * RESTRICT foreign keys — so the plain DELETE that used to run here failed
 * with nothing but "Failed to delete user".
 *
 * So the row is deleted and its audit rows are KEPT, reattributed to the
 * 'deleted_user' placeholder account. Requests and history stay complete and
 * readable; only the link back to the removed person is gone, which is what
 * deleting the account means.
 *
 * Blockers are discovered from the FK error itself rather than a hardcoded
 * list, so a reference from a table this function doesn't know about is
 * reassigned too. If one still cannot be moved, the account is retired
 * (access revoked, row kept) instead of leaving the admin with an error.
 *
 * @return array{ok:bool, mode:string, message:string}
 *         mode: 'deleted' | 'retired' | 'missing' | 'error'
 */
function deleteUser($pdo, $userId) {
    $userId = (int)$userId;

    try {
        $stmt = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error deleting user {$userId}: " . $e->getMessage());
        return ['ok' => false, 'mode' => 'error', 'message' => "Failed to delete user"];
    }

    if (!$target) {
        return ['ok' => false, 'mode' => 'missing', 'message' => "User not found"];
    }

    // The placeholder is what makes deletion possible; deleting it would
    // orphan every audit row already reattributed to it.
    if ($target['username'] === DELETED_USER_PLACEHOLDER) {
        return [
            'ok'      => false,
            'mode'    => 'error',
            'message' => "This is the placeholder that holds the history of deleted users. "
                         . "It has no roles and cannot sign in, and it must not be removed."
        ];
    }

    $placeholderId = null;
    $reassigned    = [];

    // Delete, and each time a foreign key refuses, move that reference onto the
    // placeholder and try again. One DELETE reports one blocker, hence the loop.
    for ($attempt = 0; $attempt < 8; $attempt++) {
        try {
            $pdo->beginTransaction();
            revokeUserAccessRows($pdo, $userId);
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $pdo->commit();

            return [
                'ok'      => true,
                'mode'    => 'deleted',
                'message' => describeUserDeletion($reassigned)
            ];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!isForeignKeyParentError($e)) {
                error_log("Error deleting user {$userId}: " . $e->getMessage());
                return ['ok' => false, 'mode' => 'error', 'message' => "Failed to delete user"];
            }

            $blocker = parseForeignKeyBlocker($e);
            if (!$blocker) {
                error_log("Unparseable FK blocker deleting user {$userId}: " . $e->getMessage());
                break;
            }

            if ($placeholderId === null) {
                try {
                    $placeholderId = getDeletedUserPlaceholderId($pdo);
                } catch (Exception $inner) {
                    error_log("Could not provision deleted-user placeholder: " . $inner->getMessage());
                    break;
                }
            }

            $moved = reassignUserReferences($pdo, $blocker, $userId, $placeholderId);
            if ($moved === false) {
                break;
            }
            $reassigned[$blocker['table']] = ($reassigned[$blocker['table']] ?? 0) + $moved;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error deleting user {$userId}: " . $e->getMessage());
            return ['ok' => false, 'mode' => 'error', 'message' => "Failed to delete user"];
        }
    }

    // Fallback: a reference could not be moved. Revoke everything and retire
    // the row rather than report a bare failure.
    try {
        $pdo->beginTransaction();
        revokeUserAccessRows($pdo, $userId);
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([USER_STATUS_RETIRED, $userId]);
        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error retiring user {$userId}: " . $e->getMessage());
        return ['ok' => false, 'mode' => 'error', 'message' => "Failed to delete user"];
    }

    $references = countUserAuditReferences($pdo, $userId);
    $because = $references
        ? ' because it is referenced by ' . implode(' and ', $references)
        : ' because request history references it';

    return [
        'ok'      => true,
        'mode'    => 'retired',
        'message' => "Access revoked and the account retired. The user record could not be "
                     . "deleted" . $because . ", so it cannot sign in but its history stays readable."
    ];
}

/**
 * Message for a completed delete, naming what was reattributed so the admin
 * knows the history survived.
 */
function describeUserDeletion(array $reassigned) {
    if (!$reassigned) {
        return "User deleted successfully";
    }

    $parts = [];
    foreach ($reassigned as $table => $rows) {
        $parts[] = $rows . ' in ' . $table;
    }

    return "User deleted. Its audit records (" . implode(', ', $parts) . ") were kept and "
           . "reattributed to the '" . DELETED_USER_PLACEHOLDER . "' placeholder, so request "
           . "history stays complete.";
}

/**
 * Get user by ID
 */
function getUserById($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status, created_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting user by ID: " . $e->getMessage());
        return null;
    }
}
