<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.caddy_pairing (VF). Legacy:
 * ServerBuilder::getConfigurationWarnings() caddy section (was lines
 * 2041-2113+) -- a READ-TIME warning only ('caddy_shortage'), never blocks.
 * RULE_MAP: "read-time -> VF".
 *
 * CORRECTED (F-29, caddy/bay contradiction): this rule previously paired
 * caddies against the DRIVE's form factor -- a 2.5" drive was held to require a
 * 2.5" caddy. That is the same defect F-29 recorded on the legacy side, and the
 * two together made a routine build unfinishable:
 *   - The ADD gate (StorageConnectionValidator::checkBayAvailability /
 *     checkCaddyRequirement) sizes the caddy to the CHASSIS BAY, because the
 *     caddy is the part that slots into the bay, and requires one ONLY for the
 *     adapter case -- a 2.5" drive seated in a 3.5" bay.
 *   - Pairing by drive form factor demanded the opposite part. A 3.5" caddy
 *     cleared the add gate and failed pairing; a 2.5" caddy did the reverse.
 * F-29 was deliberately left unfixed while BOTH sides shared the defect, on the
 * grounds that correcting one alone would manufacture divergence mid-parity.
 * This unit corrects both together: legacy
 * (ServerBuilder::validateStorageConnections) and this rule now agree that a
 * caddy is required only when a smaller drive lands in a larger bay, and that
 * the caddy is sized to the bay.
 *
 * Bay allocation mirrors StorageBayCapacityRule exactly: 3.5" drives have no
 * fallback and take 3.5" bays; 2.5" drives fill 2.5" bays first and only then
 * spill into whatever 3.5" bays remain. Each spilled drive needs one 3.5" caddy.
 *
 * NON-BLOCKING, but REAL (2026-09-01). A shortage used to be returned as a
 * PASSING result carrying details -- so EVERY branch of this rule returned
 * passed=true and the check could not fire. Verdict::failures() is what
 * ValidateConfigService::warnings() lists, so a passing result is invisible:
 * server-get-config never surfaced a caddy shortage, and
 * BuildAffordances::caddyOption()'s docblock still pointed at the
 * `caddy_shortage` warning in the long-deleted getConfigurationWarnings().
 * A validation that cannot fail is not a validation.
 *
 * The shortage branch now returns passed=false at Severity::WARNING. That is
 * the "make them real, as warnings" posture: Verdict::blocking() ignores
 * WARNING under every trigger, so nothing that finalizes today stops
 * finalizing -- the legacy-parity concern the old comment recorded is kept --
 * while the signal finally reaches the operator. The RESULT severity is what
 * blocking() reads, not the rule's declared severity(), so overriding it
 * per-result is the supported way to say "real finding, advisory only".
 */
final class StorageCaddyPairingRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'storage.caddy_pairing';
    }

    public function severity(): string
    {
        return Severity::VALIDATION_FAILURE;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        // No chassis means no bays, so nothing seats in a bay and no adapter tray
        // is implied. Matches StorageBayCapacityRule's own short-circuit.
        if (empty($state->byType('chassis'))) {
            return new RuleResult($this->id(), $this->severity(), true,
                'No chassis -- caddy pairing does not apply');
        }

        $storageCounts = ['2.5' => 0, '3.5' => 0];
        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            $formFactor = strtolower((string)(is_array($spec) ? ($spec['form_factor'] ?? '') : ''));
            if (strpos($formFactor, '2.5') !== false) {
                $storageCounts['2.5']++;
            } elseif (strpos($formFactor, '3.5') !== false) {
                $storageCounts['3.5']++;
            }
            // M.2 / PCIe AIC attach to the board, never to a bay -- no caddy.
        }

        $bayCapacity = ['2.5' => 0, '3.5' => 0];
        foreach ($state->byResource('drive_bay_2_5') as $row) {
            $bayCapacity['2.5'] += (int)$row['capacity'];
        }
        foreach ($state->byResource('drive_bay_3_5') as $row) {
            $bayCapacity['3.5'] += (int)$row['capacity'];
        }

        // 3.5" drives first: they have no fallback pool to be displaced into.
        $remaining35Bays = max(0, $bayCapacity['3.5'] - $storageCounts['3.5']);

        // 2.5" drives take native bays first; the rest spill into 3.5" bays and
        // each spilled drive needs one 3.5"-bodied adapter tray.
        $spilled = max(0, $storageCounts['2.5'] - $bayCapacity['2.5']);
        $adaptedDrives = min($spilled, $remaining35Bays);

        if ($adaptedDrives === 0) {
            return new RuleResult($this->id(), $this->severity(), true,
                'No drive requires a caddy adapter (every bay-seated drive matches its bay)');
        }

        // Only 3.5"-bodied caddies can carry a drive into a 3.5" bay.
        $caddies35 = 0;
        foreach ($state->byType('caddy') as $caddy) {
            $spec = $this->dataUtils->getCaddyByUUID($caddy['spec_uuid']);
            $size = strtolower((string)(is_array($spec)
                ? ($spec['compatibility']['size'] ?? $spec['form_factor'] ?? $spec['type'] ?? '')
                : ''));
            if (strpos($size, '3.5') !== false) {
                $caddies35++;
            }
        }

        if ($caddies35 >= $adaptedDrives) {
            return new RuleResult($this->id(), $this->severity(), true,
                "Caddy pairing sufficient: {$adaptedDrives} adapted drive(s), {$caddies35} 3.5\" caddy(ies)");
        }

        $missing = $adaptedDrives - $caddies35;

        // A real failure at WARNING severity: it reaches warnings() and the add
        // response, and blocks nothing (see class docblock).
        return new RuleResult($this->id(), Severity::WARNING, false,
            "Caddy shortage: {$adaptedDrives} drive(s) seat in a 3.5\" bay via an adapter but only {$caddies35} 3.5\" caddy(ies) are present",
            [
                'adapted_drives' => $adaptedDrives,
                'caddy_count' => $caddies35,
                'missing' => $missing,
                'required_caddy_size' => '3.5',
                'recommendation' => $missing === 1
                    ? 'Add 1 more 3.5" caddy so every adapted drive has a tray.'
                    : "Add {$missing} more 3.5\" caddies so every adapted drive has a tray.",
            ]);
    }
}
