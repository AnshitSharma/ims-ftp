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
 * SINCE BLADE ENCLOSURES (seeder 2026_09_03_003) a placement has two shapes.
 * A DIRECT placement occupies its own U range in the rack, as it always has. A
 * SLOTTED one sits in a bay of a `rack_enclosures` row, and the enclosure owns
 * the U range — the sled MIRRORS its start_u/u_height so every reader below and
 * in LocationResolver keeps working unchanged.
 *
 * occupancy() is the single answer to "what is physically in the way in this
 * rack", and counts direct servers plus enclosures. Slotted rows are excluded
 * from it: their U is already claimed by the enclosure they sit in, and
 * counting it twice is what would make four FX2s sleds read as 8U.
 *
 * Used by api/handlers/rack/rack_api.php, core/models/rack/ServerRelocation.php,
 * core/models/rack/RackEnclosure.php and core/models/server/ServerBuilder.php.
 */

require_once __DIR__ . '/../chassis/ChassisManager.php';
require_once __DIR__ . '/../../helpers/SchemaHelper.php';

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
     * Is the enclosure schema present yet? Code deploys ~20s after save and the
     * seeder is run by hand afterwards, so every enclosure read is behind this.
     * Answers false until 2026_09_03_003 has been applied, which makes the whole
     * feature inert rather than fatal.
     */
    public static function enclosuresAvailable($pdo)
    {
        return SchemaHelper::hasTable($pdo, 'rack_enclosures')
            && SchemaHelper::hasColumn($pdo, 'rack_servers', 'enclosure_uuid');
    }

    /**
     * Current placement row for a config, or null when the server isn't racked.
     * Carries enclosure_uuid / slot_index once the seeder has run; both are NULL
     * for a direct placement, and absent entirely before it.
     */
    public static function getPlacement($pdo, $configUuid)
    {
        $cols = "rack_uuid, start_u, u_height";
        if (self::enclosuresAvailable($pdo)) {
            $cols .= ", enclosure_uuid, slot_index";
        }
        $stmt = $pdo->prepare("SELECT {$cols} FROM rack_servers WHERE config_uuid = ? LIMIT 1");
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Normalise the shape so callers never have to test which era they are in.
        $row['enclosure_uuid'] = isset($row['enclosure_uuid']) ? $row['enclosure_uuid'] : null;
        $row['slot_index']     = isset($row['slot_index']) ? (int)$row['slot_index'] : null;
        return $row;
    }

    /**
     * The bare U-range text for a placement — "U12" or "U12-U13", with "/S3"
     * appended for a sled so two servers in the same enclosure do not read as
     * being in identical positions.
     *
     * `server_configurations.rack_position` is varchar(20), so this stays a
     * short ASCII string and never embeds the rack or enclosure name. The worst
     * case, "U100-U103/S8", is 12 characters.
     */
    public static function positionText($startU, $height, $slotIndex = null)
    {
        $startU = (int)$startU;
        $height = max(1, (int)$height);
        $text = $height > 1 ? "U{$startU}-U" . ($startU + $height - 1) : "U{$startU}";
        if ($slotIndex !== null && (int)$slotIndex > 0) {
            $text .= '/S' . (int)$slotIndex;
        }
        return $text;
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
                $text = self::positionText(
                    $placement['start_u'],
                    $placement['u_height'],
                    $placement['slot_index']
                );
            }

            $stmt = $pdo->prepare("UPDATE server_configurations SET rack_position = ? WHERE config_uuid = ?");
            $stmt->execute([$text, $configUuid]);
        } catch (Throwable $e) {
            error_log("RackPlacement::syncPositionText error: " . $e->getMessage());
        }
    }

    /**
     * Everything physically occupying U space in a rack, as
     * [['start_u','end_u','label','kind','ref'], ...].
     *
     * The ONE definition of "in the way". Both callers that place something in a
     * rack — ServerRelocation for a server, RackEnclosure for an enclosure —
     * test against this, so a sled and a chassis can never disagree about
     * whether U20 is free.
     *
     * Slotted servers are deliberately absent: the enclosure they sit in is
     * already listed, and its U range is theirs.
     *
     * @param array $exclude ['config_uuid' => ?string, 'enclosure_uuid' => ?string]
     *                       — the thing being moved, which must not block itself.
     */
    public static function occupancy($pdo, $rackUuid, array $exclude = [])
    {
        $excludeConfig    = isset($exclude['config_uuid'])    ? $exclude['config_uuid']    : null;
        $excludeEnclosure = isset($exclude['enclosure_uuid']) ? $exclude['enclosure_uuid'] : null;
        $hasEnclosures    = self::enclosuresAvailable($pdo);

        $out = [];

        // ---- direct server placements ----
        $sql = "SELECT rs.config_uuid, rs.start_u, rs.u_height, sc.server_name
                  FROM rack_servers rs
                  LEFT JOIN server_configurations sc ON sc.config_uuid = rs.config_uuid
                 WHERE rs.rack_uuid = ?";
        $params = [$rackUuid];
        if ($hasEnclosures) {
            $sql .= " AND rs.enclosure_uuid IS NULL";
        }
        if ($excludeConfig !== null) {
            $sql .= " AND rs.config_uuid <> ?";
            $params[] = $excludeConfig;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start  = (int)$row['start_u'];
            $height = max(1, (int)$row['u_height']);
            $out[] = [
                'kind'    => 'server',
                'ref'     => $row['config_uuid'],
                'label'   => $row['server_name'] !== null ? $row['server_name'] : 'a server',
                'start_u' => $start,
                'end_u'   => $start + $height - 1,
            ];
        }

        if (!$hasEnclosures) {
            return $out;
        }

        // ---- enclosures ----
        $sql = "SELECT enclosure_uuid, name, model, start_u, u_height
                  FROM rack_enclosures WHERE rack_uuid = ?";
        $params = [$rackUuid];
        if ($excludeEnclosure !== null) {
            $sql .= " AND enclosure_uuid <> ?";
            $params[] = $excludeEnclosure;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start  = (int)$row['start_u'];
            $height = max(1, (int)$row['u_height']);
            $out[] = [
                'kind'    => 'enclosure',
                'ref'     => $row['enclosure_uuid'],
                'label'   => $row['name'] . ($row['model'] ? " ({$row['model']})" : ''),
                'start_u' => $start,
                'end_u'   => $start + $height - 1,
            ];
        }

        return $out;
    }

    /**
     * The first entry of occupancy() that intersects [$startU, $endU], or null.
     */
    public static function findCollision(array $occupancy, $startU, $endU)
    {
        foreach ($occupancy as $item) {
            if ($startU <= $item['end_u'] && $endU >= $item['start_u']) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Total U actually consumed in a rack — enclosures counted ONCE, however
     * many sleds they hold. rack-list and rack-get both report occupancy from
     * this; summing u_height across rack_servers (as they did before enclosures
     * existed) would count an FX2s four times over.
     */
    public static function usedU($pdo, $rackUuid)
    {
        $covered = [];
        foreach (self::occupancy($pdo, $rackUuid) as $item) {
            for ($u = $item['start_u']; $u <= $item['end_u']; $u++) {
                $covered[$u] = true;
            }
        }
        return count($covered);
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

            // A sled does not own its U range — the enclosure does, and its
            // height comes from the enclosure's own spec. Re-deriving from the
            // sled's chassis here would shrink an FX2s bay to the 1U that
            // ceil(0.5) produces and make the elevation lie. Changing the
            // chassis of a slotted server is a bay-fit question, answered in
            // RackEnclosure::validateSlotFit(), not a resize.
            if (!empty($placement['enclosure_uuid'])) {
                return ['success' => true, 'changed' => false, 'message' => 'Server is in an enclosure bay; height is the enclosure\'s'];
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

            $occupancy = self::occupancy($pdo, $rackUuid, ['config_uuid' => $configUuid]);
            $hit = self::findCollision($occupancy, $startU, $endU);
            if ($hit !== null) {
                return [
                    'success' => false,
                    'changed' => false,
                    'message' => "This server is installed in rack \"{$rack['name']}\" at U{$startU}. A {$newHeight}U chassis "
                        . "(U{$startU}-U{$endU}) would overlap {$hit['label']}, already at "
                        . "U{$hit['start_u']}-U{$hit['end_u']}. Move one of them in Rack View first."
                ];
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
