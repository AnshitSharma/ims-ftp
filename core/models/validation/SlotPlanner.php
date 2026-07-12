<?php

/**
 * SlotPlanner — pure function over TargetState resources (no PDO). Ported
 * for U-R.3 (pcie.slot_placement) from:
 *   - UnifiedSlotTracker::assignSlot()/assignRiserSlotBySize() (was
 *     core/models/compatibility/UnifiedSlotTracker.php:255-345) — the
 *     "smallest compatible slot first" preference order (a card asking for
 *     x4 tries free x4 slots before x8, then x16).
 *   - ServerBuilder::extractPCIeSlotSize() (was lines 4788-4808) — the
 *     interface/slot_type/pcie_interface field probing, ported verbatim as
 *     extractCardWidth() (legacy keeps its own copy until U-D.2 dead-code
 *     cleanup; both must stay in sync until then).
 *
 * Two intentional diffs vs. legacy assignComponentSlot() (RULE_MAP.md):
 *   - A-7: a manual slot request is honored (validated exists+free+wide
 *     enough) — legacy's $manualSlotPosition parameter was accepted but
 *     never read anywhere in the method body (confirmed by reading the full
 *     method).
 *   - A-8: an unparseable card width is now an ERROR (pcie.unknown_width)
 *     instead of legacy's fail-open "added without slot assignment".
 */
final class SlotPlanner
{
    /** Ported verbatim from UnifiedSlotTracker::$slotCompatibility. */
    const SLOT_COMPATIBILITY = [
        'x1' => ['x1', 'x4', 'x8', 'x16'],
        'x4' => ['x4', 'x8', 'x16'],
        'x8' => ['x8', 'x16'],
        'x16' => ['x16'],
    ];

    /**
     * Ported verbatim from ServerBuilder::extractPCIeSlotSize().
     * @return string|null e.g. 'x16', or null if unparseable
     */
    public static function extractCardWidth(array $specs): ?string
    {
        foreach (['interface', 'slot_type', 'pcie_interface'] as $field) {
            $value = $specs[$field] ?? '';
            if (is_string($value) && preg_match('/x(\d+)/i', $value, $m)) {
                return 'x' . $m[1];
            }
        }
        return null;
    }

    private static function widthOf(array $providerRow): ?string
    {
        if (preg_match('/_x(\d+)$/i', (string)$providerRow['slot_ref'], $m)) {
            return 'x' . $m[1];
        }
        return null;
    }

    /**
     * @param string $resource 'pcie_slot' (regular cards/nic/hba) or
     *                         'riser_slot' (riser cards themselves)
     * @param string|null $cardWidth from extractCardWidth(), or null if unparseable
     * @param string|null $manualSlotRef a specific slot_ref the caller requests
     * @return array{ok:bool, slot_ref:?string, error:?string, error_code:?string}
     */
    public static function plan(TargetState $state, string $resource, ?string $cardWidth, ?string $manualSlotRef = null): array
    {
        if ($manualSlotRef !== null) {
            return self::planManual($state, $resource, $cardWidth, $manualSlotRef);
        }

        if ($cardWidth === null) {
            return ['ok' => false, 'slot_ref' => null, 'error' => 'Could not determine PCIe slot width', 'error_code' => 'unknown_width'];
        }

        $compatibleWidths = self::SLOT_COMPATIBILITY[$cardWidth] ?? [$cardWidth];
        $free = $state->freeSlots($resource);

        foreach ($compatibleWidths as $width) {
            foreach ($free as $row) {
                if (self::widthOf($row) === $width) {
                    return ['ok' => true, 'slot_ref' => $row['slot_ref'], 'error' => null, 'error_code' => null];
                }
            }
        }

        return ['ok' => false, 'slot_ref' => null,
            'error' => "No free {$cardWidth}-compatible $resource available",
            'error_code' => 'no_slots_available'];
    }

    private static function planManual(TargetState $state, string $resource, ?string $cardWidth, string $manualSlotRef): array
    {
        $all = $state->byResource($resource);
        $target = null;
        foreach ($all as $row) {
            if ($row['slot_ref'] === $manualSlotRef) {
                $target = $row;
                break;
            }
        }
        if ($target === null) {
            return ['ok' => false, 'slot_ref' => null, 'error' => "Slot $manualSlotRef does not exist", 'error_code' => 'invalid_slot'];
        }

        $free = $state->freeSlots($resource);
        $isFree = false;
        foreach ($free as $row) {
            if ($row['slot_ref'] === $manualSlotRef) {
                $isFree = true;
                break;
            }
        }
        if (!$isFree) {
            return ['ok' => false, 'slot_ref' => null, 'error' => "Slot $manualSlotRef is already occupied", 'error_code' => 'slot_occupied'];
        }

        if ($cardWidth === null) {
            return ['ok' => false, 'slot_ref' => null, 'error' => 'Could not determine PCIe slot width', 'error_code' => 'unknown_width'];
        }

        $slotWidth = self::widthOf($target);
        $compatibleWidths = self::SLOT_COMPATIBILITY[$cardWidth] ?? [$cardWidth];
        if ($slotWidth === null || !in_array($slotWidth, $compatibleWidths, true)) {
            return ['ok' => false, 'slot_ref' => null,
                'error' => "Slot $manualSlotRef ($slotWidth) is not wide enough for a $cardWidth card",
                'error_code' => 'slot_too_narrow'];
        }

        return ['ok' => true, 'slot_ref' => $manualSlotRef, 'error' => null, 'error_code' => null];
    }
}
