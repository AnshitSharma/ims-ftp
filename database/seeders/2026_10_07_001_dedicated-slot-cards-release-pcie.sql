-- =============================================================================
-- 2026_10_07_001_dedicated-slot-cards-release-pcie.sql
--
-- Date:     2026-10-07
-- Purpose:  Release the PCIe slots wrongly held by cards that sit in their own
--           connector -- Dell rNDC/bNDC, HPE FlexibleLOM / embedded LOM, OCP 3.0,
--           PERC Mini / front PERC, HPE 'ar' controller. Clears slot_ref on the
--           live config_components rows of those 18 spec models.
-- Tables:   config_components (slot_ref only; no rows added or removed)
-- Feature:  tasks/dedicated-slot-cards.md. The engine now gives these cards no
--           PCIe slot (SlotPlanner::dedicatedSlotKind) and caps them at one per
--           connector (rule card.dedicated_slot). Cards installed before that
--           change still carry the slot_ref they were given -- 24 on 2026-10-07
--           (9x HSTNS-BN80, 9 Dell rNDCs, 4 OCP 3.0, 2 HPE FLR) -- which is what
--           keeps e.g. the R630s at CtrlS 12U/13U/19U/20U from taking a PERC Mini.
--
-- Run at any time, any number of times. Before it runs those cards simply keep
-- holding their slot, exactly as they always have; after, the slot shows free.
-- Standard PCIe cards are not in the list and are untouched.
-- =============================================================================

UPDATE config_components
   SET slot_ref = NULL
 WHERE removed_at IS NULL
   AND slot_ref IS NOT NULL
   AND component_type IN ('nic', 'hbacard')
   AND spec_uuid IN (
    'a4d9f271-6c30-4e85-b1f7-08e2643ca90d', -- I350-t rNDC (rndc)
    'f3a5c2e8-1b4d-4f9a-82c7-3e6d0b9f1a4c', -- X520-k bNDC (bndc)
    '3b81e5ca-72d6-4f09-a4b3-de5170c8629f', -- X520/I350 rNDC (rndc)
    '17c4a0be-9382-4d5f-86ea-b04d92f3517c', -- X710/I350 rNDC (rndc)
    'd3088d8b-1338-46ea-bf41-a1152ceeb88d', -- BCM5720 4P rNDC (rndc)
    'e40b7c96-2f58-4d13-b6a0-85c317ed92f4', -- BCM57800S rNDC (rndc)
    'ae692529-f6eb-4bf1-bbda-62495aa64fba', -- BCM5720 4P OCP 3.0 (ocp3)
    '5e0c7a3b-91d4-4f6e-a2b8-3c7d19e04f52', -- BCM57416 2P 10GBASE-T OCP 3.0 (ocp3)
    'ddcc4c44-ea86-4479-8f0c-f63fba34649b', -- BCM57416 2-port BASE-T OCP3 for HPE (ocp3)
    '1b5e93af-c268-4f70-9d34-a706c2e81b5d', -- BCM57504 Quad 25G OCP 3.0 (ocp3)
    '6f8a0b2c-4d6e-4f8a-0b2c-4d6e8f0a2b4c', -- HP Ethernet 1Gb 4-port 331i (embedded_lom)
    'b32ff113-a672-4f13-a45b-a6704cea61eb', -- HSTNS-BN80 / 544+FLR-QSFP (flexlom)
    'a405061d-c5d3-4fa5-a352-56859bb179d6', -- EDC:0F-5349 FLR (flexlom)
    '0e9d4c37-6a5b-4f28-b1c9-3d7e8a204f56', -- PERC H745 (front_perc)
    'da3fcd7a-057c-4034-a1c3-91b6c1d1e039', -- PERC H755 Front (front_perc)
    'a28949ae-1da9-4186-a962-581240549ae0', -- PERC H730 Mini (mini_perc)
    'f5a6f00a-66df-4650-9283-5afa23fa1f5d', -- PERC H330 Mini (mini_perc)
    '72fb2218-2085-4631-af92-d5b6c13aaf52'  -- Smart HBA H240ar (ar_slot)
   );
