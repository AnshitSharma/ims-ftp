<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: dependency.blocked_removal (E). Legacy audit: ONLY nic->sfp
 * dependents were ever enforced on removal (ServerBuilder::removeComponent,
 * ~987 — a hard-coded "detach orphaned SFPs" special case). This rule is
 * every DEPENDS_ON edge from the target-design's dependency map (quoted in
 * the pack, no other source needed):
 *   cpu->motherboard; ram->motherboard; riser->motherboard;
 *   sfp->nic; pciecard|hbacard|nic->motherboard|riser; caddy->chassis;
 *   storage->hbacard|backplane|motherboard(m2,u2); motherboard->chassis.
 *
 * Fires on REMOVE and REPLACE(outgoing side): TargetStateBuilder::withRemove()
 * (called by the ADD/REMOVE/REPLACE hook, U-V.3 style) already produced the
 * post-removal TargetState this rule evaluates against — RuleInterface only
 * ever sees ONE state, never a before/after diff. Two mechanisms detect a
 * "removal broke a dependent" condition purely from that single state:
 *
 *   1. DANGLING PARENT: a live row whose parent_id is set but does not
 *      resolve via TargetState::find() -- its parent was the row just
 *      removed. parent_id is populated for sfp->nic AND for every
 *      board-hosted type -> motherboard (cpu/ram/pciecard/hbacard/nic; see
 *      ConfigComponentWriter::BOARD_HOSTED_TYPES and seeder 2026_07_21_002
 *      which aligned pre-existing rows). Anchors (motherboard, chassis) and
 *      types with no structural parent in this schema (storage, caddy) stay
 *      NULL and are covered only by mechanism 2.
 *   2. STRUCTURAL ORPHAN: a live row whose component_type is a
 *      DEPENDS_ON key, where NONE of its allowed provider types exist
 *      anywhere in the (post-removal) state at all. Since motherboard and
 *      chassis are enforced singletons (system.singleton, U-R.7), "no
 *      provider type left" unambiguously means "the one that existed was
 *      just removed" for those two anchor types.
 *
 * DOCUMENTED SIMPLIFICATION (flagged, not hidden): mechanism 2 is type-level
 * presence, not per-row attachment. storage->hbacard|backplane|motherboard
 * is satisfied by ANY hbacard/chassis/motherboard existing in the config,
 * not specifically the one a given drive was physically wired to (no
 * parent_id or slot_ref linkage exists for storage->hbacard in this schema
 * today — the same class of gap as storage.interface_path's SAS-backplane
 * simplification, U-R.5). A config that keeps a chassis but removes its
 * only HBA will NOT block storage removal under mechanism 2 alone if that
 * chassis also satisfies "backplane". Flagged for a human decision on
 * whether per-attachment tracking belongs in a follow-up unit once
 * slot_ref/parent_id coverage extends to storage (mirrors U-R.5's own
 * flagged gap).
 *
 * With cascade=true, TargetStateBuilder::withRemove() already removed the
 * whole parent_id subtree before this rule ever runs, so mechanism 1 finds
 * nothing (matches the pack: "rule passes, the CASCADED state is what all
 * other rules evaluate"). Mechanism 2 can still fire under cascade=true if
 * a structurally-required type-level provider (e.g. the only motherboard)
 * was itself the row removed while OTHER, non-descendant rows of a
 * dependent type remain — this is correct: cascade only removes the
 * parent_id subtree, not every type-level dependent in the config.
 */
final class DependencyBlockedRemovalRule implements RuleInterface
{
    /** dependent component_type -> list of provider types that satisfy it (OR semantics). */
    const DEPENDS_ON = [
        'cpu' => ['motherboard'],
        'ram' => ['motherboard'],
        'riser' => ['motherboard'],
        'pciecard' => ['motherboard', 'riser'],
        'hbacard' => ['motherboard', 'riser'],
        'nic' => ['motherboard', 'riser'],
        'caddy' => ['chassis'],
        'storage' => ['hbacard', 'chassis', 'motherboard'], // chassis stands in for "backplane"; motherboard for onboard m2/u2
        'motherboard' => ['chassis'],
    ];

    public function id(): string
    {
        return 'dependency.blocked_removal';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::REMOVE, Trigger::REPLACE];
    }

    public function scope(): string
    {
        return self::SCOPE_CONFIG;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $dependents = [];

        // Mechanism 1: dangling parent_id (parent was just removed).
        foreach ($state->components() as $c) {
            if ($c['parent_id'] !== null && $state->find($c['parent_id']) === null) {
                $dependents[] = $c;
            }
        }

        // Mechanism 2: structural orphan (no provider type left at all).
        foreach (self::DEPENDS_ON as $dependentType => $providerTypes) {
            $rows = $dependentType === 'riser' ? $this->riserRows($state) : $state->byType($dependentType);
            if (empty($rows)) {
                continue;
            }
            $anyProviderPresent = false;
            foreach ($providerTypes as $providerType) {
                if ($this->providerTypePresent($state, $providerType)) {
                    $anyProviderPresent = true;
                    break;
                }
            }
            if (!$anyProviderPresent) {
                foreach ($rows as $row) {
                    $dependents[] = $row;
                }
            }
        }

        if (!empty($dependents)) {
            // de-duplicate (a row could match both mechanisms in principle)
            $byId = [];
            foreach ($dependents as $d) {
                $byId[$d['id']] = $d;
            }
            $dependentsList = array_values(array_map(function ($d) {
                return ['id' => $d['id'], 'component_type' => $d['component_type'], 'spec_uuid' => $d['spec_uuid']];
            }, $byId));

            $summary = implode(', ', array_map(function ($d) {
                return "{$d['component_type']}#{$d['id']}";
            }, $dependentsList));
            return new RuleResult($this->id(), $this->severity(), false,
                "Removal blocked: component(s) still depend on the removed component: $summary",
                ['dependents' => $dependentsList]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No dependents block this removal');
    }

    /**
     * "riser" is a distinct component_type in config_components' ENUM, but
     * NO code path in this migration actually produces rows with that type
     * today — real riser cards are stored as component_type='pciecard' with
     * spec-level component_subtype='Riser Card' (confirmed: ResourceCatalog::
     * providesPciecard()'s own subtype check). This helper resolves "is a
     * riser present" against that reality rather than against an ENUM value
     * nothing populates, by asking each live pciecard row's own spec.
     * Every other provider type is a direct byType() presence check.
     */
    private function providerTypePresent(TargetState $state, string $providerType): bool
    {
        if ($providerType !== 'riser') {
            return !empty($state->byType($providerType));
        }
        return !empty($this->riserRows($state));
    }

    /** @return array[] live pciecard rows whose spec is a riser card */
    private function riserRows(TargetState $state): array
    {
        require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
        static $dataUtils = null;
        $dataUtils = $dataUtils ?? new DataExtractionUtilities();

        $risers = [];
        foreach ($state->byType('pciecard') as $card) {
            $spec = $dataUtils->getPCIeCardByUUID($card['spec_uuid']);
            if (is_array($spec) && ($spec['component_subtype'] ?? '') === 'Riser Card') {
                $risers[] = $card;
            }
        }
        return $risers;
    }
}
