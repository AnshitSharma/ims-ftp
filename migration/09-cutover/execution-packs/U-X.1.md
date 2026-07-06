# U-X.1 — ConfigReadRouter (off | sample | on)
Pins baseline: yes (off = zero diffs; sample = zero diffs + comparison log). Invariants: INV-8, INV-11, INV-12.

## Inputs
ServerBuilder 61–260 (extractComponentsFromJson output contract incl. name enrichment via
getComponentNameFromSpec 262) + 2149–2295 (getConfigurationDetails incl. cache);
ConfigComponentRepository.liveRows; equivalence canonicalization consts (reuse!).

## Files Created (2) / Modified (1)
core/models/config/ConfigReadRouter.php — components($configUuid): mode off ⇒ legacy extraction;
sample ⇒ BOTH, compare canonical tuples, log divergence JSONL reports/shadow/read-<Ymd>.jsonl,
return LEGACY; on ⇒ rows mapped to the legacy output shape (field-for-field, enrichment reproduced).
MODIFY ServerBuilder: extractComponentsFromJson callers in the two read entrypoints route via router
(mutation-path callers keep direct extraction until U-D.3 — list them in a comment).
CREATE tests/regression/read_router_test.php — three modes on dual-written fixture; shape equality
field-by-field in =on vs legacy snapshot.

## Tests
off+sample: characterization ZERO diffs; sample log empty on healthy fixture, non-empty on
self-test corrupted fixture; =on: shape test PASS + full run_all --quick GREEN.

## Rollback / Checklist
Flag down-step. - [ ] Sample returns legacy always - [ ] Enrichment parity proven field-by-field - [ ] Cache layer sits ABOVE router unchanged
