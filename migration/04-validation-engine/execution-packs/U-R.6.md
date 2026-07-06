# U-R.6 — Network rule family
RULE_MAP: net.*. Invariants: INV-2, INV-7, INV-11, PD-1.

## Inputs
ServerBuilder 4251–4368 (validateSFPAddition); NICPortTracker.php 1–150 (port occupancy contract);
SFPCompatibilityResolver.php 1–120 (type matching entry); tests/nic_sfp_authority_unit.php (port cases).

## Files Created
rules/{NetSfpPortRule, NetNicRequirementsRule}.php + tests/unit/rules/net_rules_test.php.
SfpPort: parent NIC row exists in TargetState; port slot_ref 'port_N' free in resources(sfp_port);
module type compatible per resolver's matching table (port the TABLE as const). NicRequirements:
NIC-specific bits from checkPCIeDecentralizedCompatibility not covered by U-R.3 (SR-IOV lane note: W).

## Tests / Checklist
Port unit cases; onboard-NIC ports included (parent rows exist since U-B.2).
- [ ] Port-type table is a const with source comment - [ ] SFP without parent NIC blocks (E)
