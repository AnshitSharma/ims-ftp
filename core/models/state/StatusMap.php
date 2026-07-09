<?php

/**
 * StatusMap — single source of truth for the (lossy) mapping between the new
 * status_v2 lifecycle enums (U-SM.1) and the legacy int status columns they
 * coexist with. Nothing else should hardcode these pairs.
 *
 * Legacy vocabulary is smaller, so *_V2_TO_LEGACY is many-to-one. The
 * *_LEGACY_TO_V2 maps are the inverse used only for one-shot backfills
 * (U-SM.1's seeder) — not a true inverse of the lossy map above.
 */
class StatusMap
{
    /**
     * status_v2 -> configuration_status (legacy int). Matches U-SM.3's pack
     * exactly: draft->0, building->2, validating->2, validated->1,
     * finalized->3, deployed->3, maintenance->3, retired->3 — every
     * post-finalize state still reads as "finalized" (3) to legacy code.
     */
    const CONFIG_V2_TO_LEGACY = [
        'draft'       => 0,
        'building'    => 2,
        'validating'  => 2,
        'validated'   => 1,
        'finalized'   => 3,
        'deployed'    => 3,
        'maintenance' => 3,
        'retired'     => 3,
    ];

    /** configuration_status (legacy int) -> status_v2, for U-SM.1's one-time backfill only. */
    const CONFIG_LEGACY_TO_V2 = [
        0 => 'draft',
        1 => 'validated',
        2 => 'building',
        3 => 'finalized',
    ];

    /**
     * {type}inventory.status_v2 -> Status (0 failed, 1 available, 2 in_use).
     * Judgment call (not given verbatim by any pack): every state between
     * "claimed" and "installed and running" reads as legacy in_use (2);
     * retired reads as failed (0) since legacy has no "decommissioned" concept.
     */
    const INVENTORY_V2_TO_LEGACY = [
        'available'   => 1,
        'reserved'    => 2,
        'allocated'   => 2,
        'installed'   => 2,
        'active'      => 2,
        'maintenance' => 2,
        'failed'      => 0,
        'retired'     => 0,
    ];

    /** Status (legacy int) -> status_v2, for U-SM.1's one-time backfill only. */
    const INVENTORY_LEGACY_TO_V2 = [
        0 => 'failed',
        1 => 'available',
        2 => 'installed',
    ];
}
