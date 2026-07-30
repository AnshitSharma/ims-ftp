<?php
/**
 * RackPlacement — shared logic for where a server physically sits in a rack.
 * File: core/models/rack/RackPlacement.php
 *
 * `rack_servers` is the single source of truth for placement. Two things used to
 * drift away from it and are kept in sync here instead:
 *
 *   1. `server_configurations.rack_position` — previously a free-text field typed
 *      by hand at server-creation time, unrelated to any real placement. It is now
 *      DERIVED from `rack_servers` (see syncPositionText) so the servers list and
 *      the Rack View can never disagree. Column is varchar(20), so the text stays
 *      a bare U-range ("U12" / "U12-U13") and never embeds the rack name.
 *
 *   2. `rack_servers.u_height` — snapshotted from the chassis at placement time.
 *      A server is normally racked before its chassis is picked (1U default), so
 *      syncHeightFromChassis re-derives it whenever the chassis changes.
 *
 * Used by api/handlers/rack/rack_api.php and core/models/server/ServerBuilder.php.
 */

require_once __DIR__ . '/../chassis/ChassisManager.php';

class RackPlacement
{
    /**
     * U-height a server occupies, derived from its chassis spec.
     * 1U when no chassis is set or the spec can't be resolved; blades / fractional
     * U round up to a minimum of 1U.
     */
    public static function deriveUHeight($chassisUuid)
    {
        if (empty($chassisUuid)) {
            return 1;
        }
        try {
            $specs = self::chassisManager()->loadChassisSpecsByUUID($chassisUuid);
            if (!empty($specs['found']) && isset($specs['specifications']['u_size'])) {
                $u = (int)ceil((float)$specs['specifications']['u_size']);
                return $u >= 1 ? $u : 1;
            }
        } catch (Throwable $e) {
            error_log("RackPlacement::deriveUHeight error: " . $e->getMessage());
        }
        return 1;
    }

    /**
     * Chassis display name for a server (best effort, for labels only).
     */
    public static function chassisName($chassisUuid)
    {
        if (empty($chassisUuid)) {
            return null;
        }
        try {
            $specs = self::chassisManager()->loadChassisSpecsByUUID($chassisUuid);
            if (!empty($specs['found'])) {
                return $specs['specifications']['model'] ?? null;
            }
        } catch (Throwable $e) {
            // best effort only
        }
        return null;
    }

    /**
     * Current placement row for a config, or null when the server isn't racked.
     */
    public static function getPlacement($pdo, $configUuid)
    {
        $stmt = $pdo->prepare("SELECT rack_uuid, start_u, u_height FROM rack_servers WHERE config_uuid = ? LIMIT 1");
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Rewrite server_configurations.rack_position from the real placement.
     * Clears it to NULL when the server is not in any rack.
     */
    public static function syncPositionText($pdo, $configUuid)
    {
        try {
            $placement = self::getPlacement($pdo, $configUuid);

            $text = null;
            if ($placement) {
                $startU = (int)$placement['start_u'];
                $height = max(1, (int)$placement['u_height']);
                $text = $height > 1 ? "U{$startU}-U" . ($startU + $height - 1) : "U{$startU}";
            }

            $stmt = $pdo->prepare("UPDATE server_configurations SET rack_position = ? WHERE config_uuid = ?");
            $stmt->execute([$text, $configUuid]);
        } catch (Throwable $e) {
            error_log("RackPlacement::syncPositionText error: " . $e->getMessage());
        }
    }

    /**
     * Re-derive a racked server's u_height after its chassis changed.
     *
     * A server is usually placed in a rack before the chassis exists, so the
     * placement starts at 1U; adding a 2U chassis has to grow the sled or the
     * rack elevation lies. Growing can collide, and silently keeping the wrong
     * height is worse than refusing: the caller aborts the chassis change and
     * tells the user to move the server first.
     *
     * @return array{success:bool, changed:bool, message:string}
     */
    public static function syncHeightFromChassis($pdo, $configUuid)
    {
        try {
            $placement = self::getPlacement($pdo, $configUuid);
            if (!$placement) {
                return ['success' => true, 'changed' => false, 'message' => 'Server is not racked'];
            }

            $cfgStmt = $pdo->prepare("SELECT chassis_uuid FROM server_configurations WHERE config_uuid = ? LIMIT 1");
            $cfgStmt->execute([$configUuid]);
            $chassisUuid = $cfgStmt->fetchColumn();

            $newHeight = self::deriveUHeight($chassisUuid ?: null);
            $currentHeight = max(1, (int)$placement['u_height']);
            if ($newHeight === $currentHeight) {
                return ['success' => true, 'changed' => false, 'message' => 'Placement height unchanged'];
            }

            $rackUuid = $placement['rack_uuid'];
            $startU = (int)$placement['start_u'];
            $endU = $startU + $newHeight - 1;

            $rackStmt = $pdo->prepare("SELECT name, total_u FROM racks WHERE rack_uuid = ? LIMIT 1");
            $rackStmt->execute([$rackUuid]);
            $rack = $rackStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rack) {
                // Placement points at a rack that no longer exists — leave it alone
                // rather than blocking an unrelated chassis change.
                return ['success' => true, 'changed' => false, 'message' => 'Rack not found'];
            }

            if ($endU > (int)$rack['total_u']) {
                return [
                    'success' => false,
                    'changed' => false,
                    'message' => "This server is installed in rack \"{$rack['name']}\" at U{$startU}. A {$newHeight}U chassis "
                        . "would run past the top of the rack ({$rack['total_u']}U). Move the server down in Rack View first."
                ];
            }

            $othersStmt = $pdo->prepare("SELECT config_uuid, start_u, u_height FROM rack_servers WHERE rack_uuid = ? AND config_uuid <> ?");
            $othersStmt->execute([$rackUuid, $configUuid]);
            foreach ($othersStmt->fetchAll(PDO::FETCH_ASSOC) as $other) {
                $otherStart = (int)$other['start_u'];
                $otherEnd = $otherStart + max(1, (int)$other['u_height']) - 1;
                if ($startU <= $otherEnd && $endU >= $otherStart) {
                    return [
                        'success' => false,
                        'changed' => false,
                        'message' => "This server is installed in rack \"{$rack['name']}\" at U{$startU}. A {$newHeight}U chassis "
                            . "(U{$startU}-U{$endU}) would overlap the server already at U{$otherStart}-U{$otherEnd}. "
                            . "Move one of them in Rack View first."
                    ];
                }
            }

            $updStmt = $pdo->prepare("UPDATE rack_servers SET u_height = ?, updated_at = NOW() WHERE config_uuid = ?");
            $updStmt->execute([$newHeight, $configUuid]);
            self::syncPositionText($pdo, $configUuid);

            return [
                'success' => true,
                'changed' => true,
                'message' => "Rack placement resized to {$newHeight}U"
            ];
        } catch (Throwable $e) {
            error_log("RackPlacement::syncHeightFromChassis error: " . $e->getMessage());
            // Never let a placement bookkeeping failure decide a component operation.
            return ['success' => true, 'changed' => false, 'message' => 'Placement height could not be re-derived'];
        }
    }

    /**
     * Shared ChassisManager instance (spec loads are request-cached inside it).
     */
    private static function chassisManager()
    {
        static $manager = null;
        if ($manager === null) {
            $manager = new ChassisManager();
        }
        return $manager;
    }
}
