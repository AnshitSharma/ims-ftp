<?php
/**
 * Microsoft (Entra ID) sign-in handler — auth-microsoft_status / _start / _callback.
 *
 * Loaded on demand by auth_api.php (never hard-required: see
 * requireMicrosoftAuthHandler). MicrosoftOAuth owns the protocol; this file
 * owns the two things that are OURS — the single-use state row, and the rule
 * that decides which IMS user a verified Microsoft identity is allowed to be.
 *
 * Provisioning policy: LINK ONLY. A Microsoft account that does not already
 * match an active `users` row is refused. No row is ever created here, so
 * `users.create` remains admin-only and a directory-wide tenant does not become
 * a directory-wide IMS user list.
 *
 * The three operations are public (no JWT) exactly as `login` is; the identity
 * proof is the signed id_token Microsoft returns.
 */

/** How long an authorization attempt may sit unfinished. */
define('MS_STATE_TTL_SECONDS', 600); // 10 minutes

/**
 * Entry point called by auth_api.php.
 */
function handleMicrosoftAuthOperation($operation) {
    switch ($operation) {
        case 'microsoft_status':
            handleMicrosoftStatus();
            break;
        case 'microsoft_start':
            handleMicrosoftStart();
            break;
        case 'microsoft_callback':
            handleMicrosoftCallback();
            break;
        default:
            send_json_response(0, 0, 400, "Invalid authentication operation: $operation");
    }
}

/**
 * Load MicrosoftOAuth, or refuse. Same defensive pattern as the handler itself:
 * a new file can land after the code that references it.
 */
function msOAuthAvailable() {
    if (class_exists('MicrosoftOAuth')) {
        return true;
    }
    $path = __DIR__ . '/../../../core/auth/MicrosoftOAuth.php';
    if (is_readable($path)) {
        require_once($path);
    }
    return class_exists('MicrosoftOAuth');
}

/**
 * Has seeder 2026_09_14_002 been applied?
 *
 * Code reaches production ~20s after a save; seeders are run by hand
 * afterwards. Until the table exists the feature reports itself off and the two
 * working operations refuse, rather than throwing a PDOException at whoever
 * clicked the button.
 */
function msStateTableReady($pdo) {
    return SchemaHelper::hasColumn($pdo, 'oauth_login_states', 'state_hash');
}

/**
 * Is the whole feature usable right now? Configuration AND schema.
 */
function msSignInReady($pdo) {
    return msOAuthAvailable() && MicrosoftOAuth::isConfigured() && msStateTableReady($pdo);
}

// -----------------------------------------------------------------------------
// auth-microsoft_status
// -----------------------------------------------------------------------------

/**
 * Tells the login page whether to render the Microsoft button.
 *
 * Returns a bare boolean on purpose. Tenant id, client id and redirect URI are
 * deployment details and an anonymous caller has no business reading them back
 * off the API.
 */
function handleMicrosoftStatus() {
    global $pdo;

    $ready = false;
    try {
        $ready = msSignInReady($pdo);
        if (!$ready && msOAuthAvailable() && !MicrosoftOAuth::isConfigured()) {
            error_log('auth-microsoft_status: not configured — ' . MicrosoftOAuth::configurationProblem());
        }
    } catch (Exception $e) {
        error_log('auth-microsoft_status error: ' . $e->getMessage());
    }

    send_json_response(1, 0, 200, "OK", ['enabled' => $ready]);
}

// -----------------------------------------------------------------------------
// auth-microsoft_start
// -----------------------------------------------------------------------------

/**
 * Begin a sign-in: mint state + nonce + PKCE verifier, remember them
 * server-side, and hand back the URL to send the browser to.
 *
 * The verifier stays here. The browser only ever carries the state handle and,
 * later, the authorization code — neither is usable without this row.
 */
function handleMicrosoftStart() {
    global $pdo;

    if (!msOAuthAvailable() || !MicrosoftOAuth::isConfigured()) {
        error_log('auth-microsoft_start refused — ' .
            (msOAuthAvailable() ? MicrosoftOAuth::configurationProblem() : 'MicrosoftOAuth.php not deployed'));
        send_json_response(0, 0, 503, "Microsoft sign-in is not available");
    }

    if (!msStateTableReady($pdo)) {
        error_log('auth-microsoft_start refused — oauth_login_states missing (seeder 2026_09_14_002 not applied)');
        send_json_response(0, 0, 503,
            "Microsoft sign-in is temporarily unavailable while the server finishes updating");
    }

    try {
        // Housekeeping. Bounded so a large backlog can never turn a login into
        // a long-running delete.
        $sweep = $pdo->prepare("DELETE FROM oauth_login_states WHERE expires_at < :cutoff LIMIT 200");
        $sweep->execute(['cutoff' => date('Y-m-d H:i:s')]);

        $state = MicrosoftOAuth::randomString(32);
        $nonce = MicrosoftOAuth::randomString(32);
        $codeVerifier = MicrosoftOAuth::randomString(48); // 64 chars, within RFC 7636's 43..128

        // Only the HASH of the state is stored, so a leaked table cannot be
        // used to resume somebody's half-finished sign-in.
        //
        // Every timestamp in this table is written AND compared in PHP time.
        // Nothing here uses NOW(): the connection never issues SET time_zone,
        // so MariaDB's clock can sit at a different offset from PHP's TIMEZONE,
        // and mixing the two would silently stretch or shorten the 10-minute
        // window. password_resets settled on the same convention.
        $stmt = $pdo->prepare(
            "INSERT INTO oauth_login_states (state_hash, code_verifier, nonce, client_ip, expires_at)
             VALUES (:state_hash, :code_verifier, :nonce, :client_ip, :expires_at)"
        );
        $stmt->execute([
            'state_hash' => hash('sha256', $state),
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
            'client_ip' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            'expires_at' => date('Y-m-d H:i:s', time() + MS_STATE_TTL_SECONDS),
        ]);

        send_json_response(1, 0, 200, "Redirect to Microsoft to continue", [
            'authorization_url' => MicrosoftOAuth::buildAuthorizationUrl(
                $state,
                $nonce,
                MicrosoftOAuth::codeChallenge($codeVerifier)
            ),
            'expires_in' => MS_STATE_TTL_SECONDS,
        ]);

    } catch (Exception $e) {
        error_log('auth-microsoft_start error: ' . $e->getMessage());
        send_json_response(0, 0, 500, "Could not start Microsoft sign-in");
    }
}

// -----------------------------------------------------------------------------
// auth-microsoft_callback
// -----------------------------------------------------------------------------

/**
 * Finish a sign-in: consume the state, redeem the code, verify the id_token,
 * map it to an IMS user, and issue our own session.
 *
 * On success the response body is byte-for-byte the shape `auth-login` returns,
 * because both go through buildUserSession().
 */
function handleMicrosoftCallback() {
    global $pdo;

    $code = trim($_POST['code'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $rememberMe = filter_var($_POST['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($code === '' || $state === '') {
        send_json_response(0, 0, 400, "Missing authorization code or state");
    }

    if (!msOAuthAvailable() || !MicrosoftOAuth::isConfigured() || !msStateTableReady($pdo)) {
        send_json_response(0, 0, 503, "Microsoft sign-in is not available");
    }

    try {
        $stateHash = hash('sha256', $state);

        // Read the row for its verifier and nonce. This read does NOT authorise
        // anything — the conditional UPDATE below is the only gate, exactly as
        // in handleResetPassword.
        $stmt = $pdo->prepare(
            "SELECT code_verifier, nonce, expires_at FROM oauth_login_states
             WHERE state_hash = :state_hash AND used_at IS NULL"
        );
        $stmt->execute(['state_hash' => $stateHash]);
        $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);

        // Expiry is judged in PHP time, matching how the row was written.
        if ($stateRow && strtotime($stateRow['expires_at']) < time()) {
            $stateRow = false;
        }

        if (!$stateRow) {
            error_log('auth-microsoft_callback: unknown, used or expired state');
            send_json_response(0, 0, 400,
                "This sign-in link has expired or was already used. Please try again.");
        }

        // Whoever flips used_at from NULL wins; a replayed callback affects zero
        // rows and is turned away. No lock or isolation assumption needed.
        $consume = $pdo->prepare(
            "UPDATE oauth_login_states SET used_at = NOW()
             WHERE state_hash = :state_hash AND used_at IS NULL"
        );
        $consume->execute(['state_hash' => $stateHash]);

        if ($consume->rowCount() !== 1) {
            send_json_response(0, 0, 400,
                "This sign-in link has expired or was already used. Please try again.");
        }

        $tokens = MicrosoftOAuth::exchangeCode($code, $stateRow['code_verifier']);
        if ($tokens === null) {
            send_json_response(0, 0, 401, "Microsoft sign-in could not be completed");
        }

        $claims = MicrosoftOAuth::verifyIdToken($tokens['id_token'], $stateRow['nonce']);
        if ($claims === null) {
            send_json_response(0, 0, 401, "Microsoft sign-in could not be verified");
        }

        $oid = (string)$claims['oid'];
        $email = MicrosoftOAuth::emailFromClaims($claims);

        $user = findUserForMicrosoftIdentity($pdo, $oid, $email);

        if (!$user) {
            // Deliberately specific: this is an internal tool, the caller has
            // already proven who they are to Microsoft, and "contact an admin"
            // is the only useful next step. Nothing about IMS accounts leaks —
            // the message is the same whether the address is unknown, inactive,
            // or belongs to someone else.
            error_log('auth-microsoft_callback: no active IMS user for oid ' . $oid);
            send_json_response(0, 0, 403,
                "This Microsoft account is not linked to an IMS user. Ask an administrator to create or link your account.");
        }

        error_log("Microsoft sign-in successful for user: {$user['username']} (ID: {$user['id']})");

        send_json_response(1, 1, 200, "Login successful", buildUserSession($pdo, $user, $rememberMe));

    } catch (PDOException $e) {
        error_log('auth-microsoft_callback database error: ' . $e->getMessage());
        send_json_response(0, 0, 500, "Microsoft sign-in failed");
    } catch (Exception $e) {
        error_log('auth-microsoft_callback error: ' . $e->getMessage());
        send_json_response(0, 0, 500, "Microsoft sign-in failed");
    }
}

// -----------------------------------------------------------------------------
// Identity mapping
// -----------------------------------------------------------------------------

/**
 * Which IMS user is this verified Microsoft identity?
 *
 * Two matches, in order:
 *
 *   1. `users.azure_oid` — the Entra object id. Immutable, so it survives the
 *      person changing their name or email address.
 *   2. `users.email`, case-insensitively — the one-time bootstrap. On a
 *      successful email match the oid is written back, so every later sign-in
 *      takes path 1 and an address that is later reassigned to a new employee
 *      cannot inherit the old account.
 *
 * Both require `status = 'active'`; no row is ever created.
 *
 * `azure_oid` may not exist yet (seeder 2026_09_14_001 is run by hand after the
 * code deploys), so every touch of it is behind a schema probe and the function
 * degrades to email-only matching in that window.
 *
 * @return array|null
 */
function findUserForMicrosoftIdentity($pdo, $oid, $email) {
    $columns = "id, username, email, firstname, lastname";
    $hasOidColumn = SchemaHelper::hasColumn($pdo, 'users', 'azure_oid');

    if ($hasOidColumn && $oid !== '') {
        $stmt = $pdo->prepare(
            "SELECT $columns FROM users WHERE azure_oid = :oid AND status = 'active'"
        );
        $stmt->execute(['oid' => $oid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            return $user;
        }
    }

    if ($email === '') {
        return null;
    }

    // LOWER() on both sides rather than relying on the column's collation.
    $emailSelect = $hasOidColumn ? "$columns, azure_oid" : $columns;
    $stmt = $pdo->prepare(
        "SELECT $emailSelect FROM users
         WHERE LOWER(email) = LOWER(:email) AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    if ($hasOidColumn) {
        // Already claimed by a DIFFERENT Microsoft account: refuse rather than
        // re-point the link. Two directory accounts sharing one mailbox must
        // not become two routes into one IMS user.
        if (!empty($user['azure_oid']) && !hash_equals((string)$user['azure_oid'], (string)$oid)) {
            error_log("findUserForMicrosoftIdentity: user {$user['id']} is linked to a different Microsoft account");
            return null;
        }

        if (empty($user['azure_oid']) && $oid !== '') {
            try {
                // Conditional: only claim an unlinked row, so two concurrent
                // first sign-ins cannot overwrite each other.
                $link = $pdo->prepare(
                    "UPDATE users SET azure_oid = :oid WHERE id = :id AND azure_oid IS NULL"
                );
                $link->execute(['oid' => $oid, 'id' => $user['id']]);
            } catch (PDOException $e) {
                // Unique-index collision means this oid is already on another
                // row — a data problem an admin must resolve. Logging it and
                // letting the sign-in proceed on the verified email match is
                // right: the identity was proven either way.
                error_log('findUserForMicrosoftIdentity: could not store azure_oid: ' . $e->getMessage());
            }
        }

        unset($user['azure_oid']);
    }

    return $user;
}

