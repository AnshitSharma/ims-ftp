# 05-command-layer — Phase P6
Objective: atomic commands become the only mutation path (shadow → enforce), running UNDER the old
APIs; legacy endpoints keep working unchanged. Adds the first-ever Replace operation.
Prerequisites: P5 gate open. Affected files: core/models/commands/** (new), ServerBuilder dispatch
sites, server_api dispatch. Affected DB tables: writes via repository only.
Order: U-C.1→U-C.6. Rollback: COMMAND_LAYER_ENABLED=off any time before U-C.6; after U-C.6 revert
commits (U-C.6 is the only destructive unit here).
Verification: parity + equivalence + regression + performance (Δp95 ≤ +20%).
Risks: dual persistence during enforce (commands write rows AND legacy JSON via the same
updateServerConfigurationTable internals) — commands call the LEGACY persistence internals as a
library during the window; JSON remains updated until U-D.3.
Duration: 6 sessions + soak. Handoff after U-C.6: next U-A.1. Context ~35k.
