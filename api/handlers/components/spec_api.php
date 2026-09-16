<?php
/**
 * Spec handler -- run the ims-data -> component_models projection over HTTP.
 *
 * Included by api/api.php for the `spec` module. It lives in handlers/components/ because an
 * identical file at api/handlers/spec/spec_api.php never reached the server -- five minutes of
 * polling, still 503 from api.php's guarded require -- while this copy went live within
 * seconds. The cause is NOT simply "new directories do not deploy": core/models/components/
 * schemas/ is also a new directory and its twelve files arrived fine. Whatever the real cause,
 * the working location is a directory that already existed. Do not move this file back.
 *
 * WHY THIS EXISTS. database/spec_build.php is the CLI entry point and refuses the web
 * outright, which was correct and also unusable: this deployment has no shell, so the script
 * that "must run after every ims-data upload" could not be run by the person doing the
 * uploading. This handler is the authenticated way in. Both entry points call
 * SpecBuildRunner, so the gate, the diff and the write have one implementation.
 *
 * PERMISSION. Admin/super_admin by role, checked here rather than through permission_map.php,
 * for the same reason the acl, users and dashboard modules check in-handler: the answer is a
 * role gate, not a flat name lookup. Deliberately NOT a new ACL permission -- an ACL row
 * needs a seeder, seeders here are applied by hand, and a maintenance action nobody can run
 * until someone runs a seeder is the exact problem this file is solving.
 *
 * Actions:
 *   spec-check   report drift, write nothing   (read-only)
 *   spec-build   project and write             (one transaction)
 */

function handleSpecOperations($operation, $user) {
    global $pdo;

    $acl = $GLOBALS['acl'] ?? null;
    if (!$acl instanceof ACL) {
        $acl = new ACL($pdo);
    }

    if (!$acl->hasRole($user['id'], ['admin', 'super_admin'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: admin role required");
    }

    switch ($operation) {
        case 'check':
        case 'build':
            // Guarded requires: these files deploy independently of this handler, and code
            // reaches production before anything referencing it is guaranteed to be there. A
            // hard require would fatal the whole API for the window in between.
            $projectorFile = __DIR__ . '/../../../core/models/components/SpecProjector.php';
            $runnerFile = __DIR__ . '/../../../core/models/components/SpecBuildRunner.php';
            if (!is_readable($projectorFile) || !is_readable($runnerFile)) {
                send_json_response(0, 1, 503, "Spec build is not available on this deployment");
            }
            require_once $projectorFile;
            require_once $runnerFile;

            $checkOnly = ($operation === 'check');

            try {
                $result = SpecBuildRunner::run($pdo, $checkOnly);
            } catch (Throwable $e) {
                error_log("Spec build failed: " . $e->getMessage());
                send_json_response(0, 1, 500, "Spec build failed");
            }

            // The runner's refusals are answers, not faults: a duplicate uuid or a
            // not-yet-applied seeder is a state the caller has to act on, and each carries
            // the detail needed to act. They are reported as 409/503 with the full result
            // rather than flattened into a 500.
            switch ($result['status']) {
                case 'missing_table':
                    send_json_response(0, 1, 503, $result['message'], $result);
                    break;
                case 'refused':
                    send_json_response(0, 1, 409, $result['message'], $result);
                    break;
                case 'projection_failed':
                case 'write_failed':
                    send_json_response(0, 1, 500, $result['message'], $result);
                    break;
                case 'stale':
                    // Drift is the honest answer to a check, not an error -- the caller
                    // asked whether the table matches and it does not.
                    send_json_response(1, 1, 200, $result['message'], $result);
                    break;
                default:
                    send_json_response(1, 1, 200, $result['message'], $result);
            }
            break;

        default:
            send_json_response(0, 1, 400, "Invalid spec operation: $operation");
    }
}
