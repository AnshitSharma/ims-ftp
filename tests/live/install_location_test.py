"""
install_location_test.py — live suite for the two rules that govern fitting
hardware into a server through a Request:

  1. THE PART MUST BE WHERE THE SERVER IS.
     An SSD sitting in Noida cannot be fitted into a rack in Jaipur. Before the
     location work, the approval simply performed the install and re-stamped the
     drive with the server's address, inventing a record of hardware in a rack
     nobody had carried it to. LocationResolver::checkComponentForConfig() now
     answers three-valued (true / false / null-means-cannot-tell) and
     RequestActionExecutor::locationGate() refuses ONLY on a confirmed mismatch.

  2. THE PART MUST BE IN INVENTORY BEFORE IT CAN BE INSTALLED.
     A model with no unit on the shelf is not a refusal — it is a not-yet. The
     request is created and the gap is reported as `stock_missing`, so the
     requester can raise the Add Inventory Record prerequisite in the same flow.
     A model ims-data has never heard of IS a refusal, because no inventory
     record could be created for it either.

WHAT THIS SUITE PROVES, AND WHAT IT CANNOT
------------------------------------------
Detection is proven directly: every match=true / match=false / match=null case
below is a real answer from the deployed endpoint about real inventory.

The GATE — the refusal at approval time — is not exercised, and cannot be from
one account. applyStageEffect() Guard 3 refuses a self-approval before the
executor is ever reached, so the only way to reach locationGate() is a second
admin approving a request this account raised. Everything that CAN be checked
without that is checked: that a cross-site request is created rather than
blocked (the handover has to stay reachable), that the gap is reported, and how
much of the live inventory the gate is actually able to judge.

Usage:
    python tests/live/install_location_test.py            # read-only
    python tests/live/install_location_test.py --writes   # + create/cancel
"""

import argparse
import json
import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from _client import COMPONENT_TYPES, Client, Suite, load_catalogue  # noqa: E402

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
IMS_DATA = os.path.join(os.path.dirname(REPO_ROOT), "ims-data")


def errors_of(body):
    out = [str(body.get("message") or "")]
    data = body.get("data") or {}
    if isinstance(data, dict):
        for item in data.get("errors") or []:
            out.append(str(item))
    return " | ".join(x for x in out if x)


def refused(body):
    return not body.get("success")


class Context:
    def __init__(self, api, suite, writes):
        self.api = api
        self.suite = suite
        self.writes = writes
        self.created = []
        self.templates = {}
        self.servers = []
        self.located_servers = []      # servers whose location_uuid resolves
        self.unlocated_servers = []
        self.catalogue = {}
        self.stock = {}
        self.records = {}
        self.locations = []
        self.matrix = {}               # (type, uuid, config) -> location answer

    def gather(self):
        body = self.api.call("pipeline-template-list")
        for row in (body.get("data") or {}).get("templates") or []:
            self.templates[row["name"]] = row

        self.servers = [s for s in (self.api.call("pipeline-servers").get("data") or {}).get("servers") or []
                        if not s.get("is_virtual")]

        self.locations = (self.api.call("location-list").get("data") or {}).get("locations") or []

        self.catalogue = load_catalogue(IMS_DATA)
        for ctype in COMPONENT_TYPES:
            for source, sink in (("stock", self.stock), ("records", self.records)):
                body = self.api.call("pipeline-component-options",
                                     component_type=ctype, source=source)
                sink[ctype] = (body.get("data") or {}).get("models") or []

        # Resolve each server's location through the same endpoint the request
        # form uses, so the suite and the UI cannot disagree about where a
        # server is.
        probe = self._any_stocked_model()
        for server in self.servers:
            if probe is None:
                break
            ctype, uuid = probe
            body = self.api.call("pipeline-component-location",
                                 config_uuid=server["config_uuid"],
                                 component_type=ctype, component_uuid=uuid)
            resolved = (body.get("data") or {}).get("server") or {}
            server["_location_uuid"] = resolved.get("location_uuid")
            server["_location_name"] = resolved.get("location_name")
            (self.located_servers if resolved.get("location_uuid")
             else self.unlocated_servers).append(server)

    def _any_stocked_model(self):
        for ctype in ("storage", "cpu", "ram"):
            for model in self.stock.get(ctype) or []:
                uuid = model.get("component_uuid")
                if uuid and not uuid.startswith("onboard-"):
                    return ctype, uuid
        return None

    def check_location(self, config_uuid, ctype, uuid, serial=None):
        body = self.api.call("pipeline-component-location", config_uuid=config_uuid,
                             component_type=ctype, component_uuid=uuid,
                             serial_number=serial)
        return body.get("data") or {}

    def template_id(self, name):
        row = self.templates.get(name)
        return row["id"] if row else None

    def find_installed_unit_with_serial(self):
        """
        One unit that is fitted in a server AND carries a serial number.

        Needed to reach the 'component_unavailable' branch with a serial named:
        a serial matching no row at all lands in 'inventory_component_not_found'
        instead, which is deferrable by design.

        @return (component_type, model_uuid, serial, config_uuid, server_name)
        """
        for server in (self.located_servers or self.servers)[:8]:
            for ctype in ("storage", "cpu", "ram", "nic", "pciecard", "hbacard"):
                body = self.api.call("pipeline-component-options", component_type=ctype,
                                     source="installed", config_uuid=server["config_uuid"])
                for unit in (body.get("data") or {}).get("units") or []:
                    if unit.get("serial_number") and not unit.get("is_onboard"):
                        return (ctype, unit["component_uuid"], unit["serial_number"],
                                server["config_uuid"], server.get("server_name"))
        return None

    def create(self, template_name, title, **extra):
        tid = self.template_id(template_name)
        if tid is None:
            return {"success": False, "message": f"no such request type: {template_name}"}
        body = self.api.call("pipeline-create", pipeline_template_id=str(tid),
                             title=title, **extra)
        pid = (body.get("data") or {}).get("pipeline_id")
        if pid:
            self.created.append(pid)
        return body

    def cleanup(self):
        """Cancel everything, then confirm the closed state by re-reading it."""
        if not self.created:
            return
        print(f"\n-- cleanup: closing {len(self.created)} request(s) created by this run --")
        leaked = []
        for pid in self.created:
            self.api.call("pipeline-cancel", pipeline_id=str(pid), ticket_id=str(pid),
                          reason="automated live suite cleanup")
            detail = self.api.call("pipeline-get", pipeline_id=str(pid), ticket_id=str(pid))
            status = ((detail.get("data") or {}).get("pipeline") or {}).get("status")
            if status not in ("cancelled", "rejected", "completed"):
                leaked.append((pid, status))
            print(f"   #{pid}: {status}")
        if leaked:
            print(f"   !! {len(leaked)} request(s) LEFT OPEN and need cancelling by hand: {leaked}")


# ==================================================== 1. is the feature live?


def test_feature_live(ctx):
    s = ctx.suite
    s.head("1. Is the location feature actually live on this deployment?")

    s.check("the locations table is seeded", len(ctx.locations) > 0,
            f"{len(ctx.locations)} locations")

    probe = ctx._any_stocked_model()
    if not probe or not ctx.servers:
        s.skip("supported flag", "no server or no stocked model to probe with")
        return
    ctype, uuid = probe
    data = ctx.check_location(ctx.servers[0]["config_uuid"], ctype, uuid)
    s.check("checkComponentForConfig() reports supported=true "
            "(seeders 2026_08_26_001/003 have been run)",
            data.get("supported") is True,
            f"supported={data.get('supported')} — the gate is INERT if this is false")

    s.check("the Hardware Handover request type exists",
            "Hardware Handover" in ctx.templates)

    s.check("at least one server resolves to a location",
            len(ctx.located_servers) > 0,
            f"{len(ctx.located_servers)} of {len(ctx.servers)} servers have a location")


# ================================================== 2. three-valued detection


def test_three_valued_match(ctx):
    s = ctx.suite
    s.head("2. The location check is three-valued, and only false is a mismatch")

    seen = {True: None, False: None, None: None}

    for ctype in COMPONENT_TYPES:
        for model in ctx.stock.get(ctype) or []:
            uuid = model.get("component_uuid")
            if not uuid or uuid.startswith("onboard-"):
                continue
            for server in ctx.located_servers:
                data = ctx.check_location(server["config_uuid"], ctype, uuid)
                match = data.get("match")
                ctx.matrix[(ctype, uuid, server["config_uuid"])] = data
                if seen.get(match) is None:
                    seen[match] = (ctype, uuid, server, data)
            if all(v is not None for v in seen.values()):
                break
        if all(v is not None for v in seen.values()):
            break

    # --- match = true --------------------------------------------------------
    if seen[True]:
        ctype, uuid, server, data = seen[True]
        s.check("match=true is reachable — stock at the server's own site",
                True, "")
        s.check("  ...and it reports at least one unit here",
                (data.get("units_here") or 0) > 0, f"units_here={data.get('units_here')}")
        s.check("  ...with reason 'same_location'",
                data.get("reason") == "same_location", f"reason={data.get('reason')}")
        print(f"          e.g. {ctype} {uuid[:8]} into {server['_location_name']}: "
              f"{data.get('units_here')} unit(s) here")
    else:
        s.warn("no match=true case found",
               "no model in stock is co-located with any server — every install would "
               "either be blocked or unjudgeable")

    # --- match = false -------------------------------------------------------
    if seen[False]:
        ctype, uuid, server, data = seen[False]
        s.check("MISMATCH DETECTED: match=false when the only stock is at another site",
                True, "")
        s.check("  ...with reason 'different_location'",
                data.get("reason") == "different_location", f"reason={data.get('reason')}")
        s.check("  ...and units_elsewhere names where the part actually is",
                len(data.get("units_elsewhere") or []) > 0
                and any(u.get("location_name") for u in data["units_elsewhere"]),
                f"units_elsewhere={data.get('units_elsewhere')}")
        s.check("  ...and reports zero units at the server's site",
                (data.get("units_here") or 0) == 0, f"units_here={data.get('units_here')}")
        elsewhere = sorted({u.get("location_name") for u in data["units_elsewhere"]
                            if u.get("location_name")})
        print(f"          e.g. {ctype} {uuid[:8]}: server at {server['_location_name']}, "
              f"stock at {elsewhere}")
    else:
        s.warn("no match=false case found",
               "nothing in this deployment's stock is at a different site from a server, "
               "so the gate has nothing to refuse today")

    # --- match = null --------------------------------------------------------
    if seen[None]:
        ctype, uuid, server, data = seen[None]
        s.check("match=null ('cannot tell') is returned rather than a guess",
                True, "")
        s.check("  ...with a reason that explains why",
                data.get("reason") in ("component_location_unknown", "server_location_unknown",
                                       "unit_not_found", "server_not_found", "unknown"),
                f"reason={data.get('reason')}")

    # A server with no location must never produce a mismatch.
    if ctx.unlocated_servers and ctx.stock.get("storage"):
        server = ctx.unlocated_servers[0]
        uuid = next((m["component_uuid"] for m in ctx.stock["storage"]
                     if not m["component_uuid"].startswith("onboard-")), None)
        if uuid:
            data = ctx.check_location(server["config_uuid"], "storage", uuid)
            s.check("a server with NO location never yields a mismatch (null, not false)",
                    data.get("match") is not False,
                    f"match={data.get('match')} reason={data.get('reason')}")
            s.check("  ...and says so with reason 'server_location_unknown'",
                    data.get("reason") == "server_location_unknown",
                    f"reason={data.get('reason')}")


# ============================================ 3. serial-targeted and no-server


def test_serial_and_unit_modes(ctx):
    s = ctx.suite
    s.head("3. Naming one exact unit, and the units-only mode")

    probe = ctx._any_stocked_model()
    if not probe:
        s.skip("serial-targeted check", "no stocked model")
        return
    ctype, uuid = probe

    # units-only mode: no config named.
    body = ctx.api.call("pipeline-component-location", component_type=ctype,
                        component_uuid=uuid)
    data = body.get("data") or {}
    s.check("component-location answers without a config_uuid (the handover picker)",
            body.get("success"), errors_of(body))
    s.check("  ...with match=null, because nothing was compared",
            data.get("match") is None, f"match={data.get('match')}")
    s.check("  ...reason 'no_server_named'",
            data.get("reason") == "no_server_named", f"reason={data.get('reason')}")
    s.check("  ...and still lists the units, which is what the picker needs",
            isinstance(data.get("units"), list), f"units={type(data.get('units')).__name__}")

    units = data.get("units") or []
    if units:
        unit = units[0]
        s.check("a unit option carries an inventory_id (a UUID names a model, not an object)",
                unit.get("inventory_id") is not None, f"unit={unit}")
        leaked = set(unit.keys()) - {"inventory_id", "serial_number", "asset_tag",
                                     "location_uuid", "location_name", "store_location",
                                     "address_text"}
        s.check("a unit option leaks no specs, purchase data or notes",
                not leaked, f"unexpected keys: {sorted(leaked)}")

    # A serial that names no unit must be 'cannot tell', never a mismatch.
    if ctx.located_servers:
        config = ctx.located_servers[0]["config_uuid"]
        data = ctx.check_location(config, ctype, uuid, serial="NO-SUCH-SERIAL-XYZ-999")
        s.check("an unknown serial yields match=null, not a mismatch",
                data.get("match") is None, f"match={data.get('match')}")
        s.check("  ...with reason 'unit_not_found'",
                data.get("reason") == "unit_not_found", f"reason={data.get('reason')}")

    # A serial that names a real unit at a known site gives an answer about
    # THAT unit alone.
    targeted = None
    for (ct, cu, cfg), data in ctx.matrix.items():
        for unit in data.get("units_elsewhere") or []:
            if unit.get("serial_number"):
                targeted = (ct, cu, cfg, unit)
                break
        if targeted:
            break
    if targeted:
        ct, cu, cfg, unit = targeted
        data = ctx.check_location(cfg, ct, cu, serial=unit["serial_number"])
        s.check("naming the serial of a unit at another site still reports the mismatch",
                data.get("match") is False, f"match={data.get('match')} reason={data.get('reason')}")
    else:
        s.skip("serial-targeted mismatch", "no cross-site unit in this deployment carries a serial")


# ================================== 4. how much of the shelf can the gate see?


def test_gate_coverage(ctx):
    """
    The gate refuses only on match=false. Everything it cannot judge passes
    through. That is the correct design — but it makes the gate's real strength
    a DATA question, not a code question, so measure it.
    """
    s = ctx.suite
    s.head("4. Gate coverage — how much of this deployment can it actually judge?")

    s.warn("servers without a location",
           f"{len(ctx.unlocated_servers)} of {len(ctx.servers)} real servers have no "
           f"location_uuid. Every install into one of them is unjudgeable, so the gate "
           f"passes it through: "
           f"{[x.get('server_name') for x in ctx.unlocated_servers][:8]}")

    # Available units with no location, per type. These are the parts the gate
    # cannot vouch for.
    unlocated_units = {}
    total_units = 0
    total_unlocated = 0
    for ctype in COMPONENT_TYPES:
        here = 0
        blind = 0
        for model in ctx.stock.get(ctype) or []:
            uuid = model.get("component_uuid")
            if not uuid or uuid.startswith("onboard-"):
                continue
            body = ctx.api.call("pipeline-component-location",
                                component_type=ctype, component_uuid=uuid)
            for unit in (body.get("data") or {}).get("units") or []:
                here += 1
                if not unit.get("location_uuid"):
                    blind += 1
        unlocated_units[ctype] = (blind, here)
        total_units += here
        total_unlocated += blind

    for ctype, (blind, here) in unlocated_units.items():
        if here and blind:
            s.warn(f"{ctype}: available units with no location",
                   f"{blind} of {here} free units carry no location_uuid — an install "
                   f"naming one of these models cannot be judged")

    if total_units:
        pct = 100.0 * total_unlocated / total_units
        s.check("more than half of free stock carries a location",
                pct < 50.0,
                f"{total_unlocated} of {total_units} free units ({pct:.0f}%) have no "
                f"location_uuid, so the gate cannot judge them")
        print(f"          coverage: {total_units - total_unlocated}/{total_units} "
              f"free units located ({100 - pct:.0f}%)")

    verdicts = {}
    for data in ctx.matrix.values():
        verdicts[data.get("match")] = verdicts.get(data.get("match"), 0) + 1
    if verdicts:
        total = sum(verdicts.values())
        judgeable = verdicts.get(True, 0) + verdicts.get(False, 0)
        print(f"          of {total} (server, model) pairs probed: "
              f"{verdicts.get(True, 0)} co-located, {verdicts.get(False, 0)} cross-site, "
              f"{verdicts.get(None, 0)} unjudgeable")
        s.check("the gate can reach a verdict on some real install combinations",
                judgeable > 0, f"0 of {total} pairs were judgeable")


# =========================================== 5. cross-site request behaviour


def test_cross_site_request(ctx):
    s = ctx.suite
    s.head("5. Raising an install request for a part at the wrong site")

    if not ctx.writes:
        s.skip("cross-site request creation", "--writes not given")
        return

    candidates = [(ctype, uuid, config, data)
                  for (ctype, uuid, config), data in ctx.matrix.items()
                  if data.get("match") is False]
    if not candidates:
        s.skip("cross-site request creation", "no cross-site (server, model) pair exists here")
        return

    # Most cross-site pairs are ALSO incompatible with the target board — a
    # random EPYC does not fit a random LGA2011-3 server, and preflight refuses
    # that long before location is considered. Keep trying until a pair turns up
    # whose only problem is geography, which is the case under test.
    body = None
    chosen = None
    rejected_for_compatibility = 0
    for ctype, uuid, config, data in candidates:
        attempt = ctx.create("Install Hardware",
                             "LIVE SUITE — cross-site install (safe to cancel)",
                             target_server_uuid=config,
                             actions=[{"action_type": "server.component.add",
                                       "payload": {"config_uuid": config,
                                                   "component_type": ctype,
                                                   "component_uuid": uuid}}])
        if attempt.get("success"):
            body, chosen = attempt, (ctype, uuid, config, data)
            break
        if "would be rejected" in errors_of(attempt):
            rejected_for_compatibility += 1
            continue
        body, chosen = attempt, (ctype, uuid, config, data)
        break

    if chosen is None:
        s.skip("cross-site request creation",
               f"all {len(candidates)} cross-site pairs were refused on compatibility first")
        return

    ctype, uuid, config, data = chosen
    # By design this is CREATED, not refused: refusing at submit time would make
    # the Hardware Handover that fixes the mismatch unreachable.
    s.check("a cross-site install request is CREATED, not refused at submit time "
            "(otherwise the handover is unreachable)",
            body.get("success"), errors_of(body))
    if rejected_for_compatibility:
        print(f"          ({rejected_for_compatibility} earlier cross-site pairs were refused "
              f"on hardware compatibility before one reached the location question)")

    if body.get("success"):
        pid = (body.get("data") or {}).get("pipeline_id")
        detail = ctx.api.call("pipeline-get", pipeline_id=str(pid), ticket_id=str(pid))
        pipeline = (detail.get("data") or {}).get("pipeline") or {}
        gaps = pipeline.get("location_gaps") or pipeline.get("location_gap") or []
        if gaps:
            s.check("the open request reports the location gap to the approver",
                    True, "")
        else:
            s.warn("the open request does not surface a location gap",
                   f"pipeline-get for #{pid} carries no location_gaps key; the approver only "
                   f"learns about the mismatch when the approval is refused. Keys: "
                   f"{sorted(pipeline.keys())}")

        s.warn("the location gate itself was NOT exercised",
               f"request #{pid} is the case that must be refused at approval. "
               f"applyStageEffect() Guard 3 (separation of duties) refuses a self-approval "
               f"before locationGate() is reached, so proving the refusal needs a SECOND "
               f"admin account approving this request.")


# ================================== 6. inventory-first: the stock_missing path


def test_inventory_first(ctx):
    """
    'Add it to inventory first, then install it.' A model with no unit is a
    not-yet, reported as stock_missing so the requester can raise the Add
    Inventory Record prerequisite. A model ims-data does not describe is a flat
    refusal, because no inventory record could be created for it either.
    """
    s = ctx.suite
    s.head("6. Inventory-first — a part must exist in inventory before it is installed")

    if not ctx.writes:
        s.skip("stock_missing deferral", "--writes not given")
        return
    if not ctx.located_servers and not ctx.servers:
        s.skip("stock_missing deferral", "no server to install into")
        return

    config = (ctx.located_servers or ctx.servers)[0]["config_uuid"]

    # --- a catalogued model with no unit at all: DEFERRED --------------------
    zero_stock = None
    for ctype in ("cpu", "ram", "storage", "nic", "sfp", "caddy"):
        held = {m["component_uuid"] for m in ctx.records.get(ctype) or []}
        for uuid in sorted(ctx.catalogue.get(ctype) or {}):
            if uuid not in held:
                zero_stock = (ctype, uuid, (ctx.catalogue[ctype] or {}).get(uuid))
                break
        if zero_stock:
            break

    if zero_stock:
        ctype, uuid, label = zero_stock
        body = ctx.create("Install Hardware",
                          f"LIVE SUITE — {ctype} with no stock (safe to cancel)",
                          target_server_uuid=config,
                          actions=[{"action_type": "server.component.add",
                                    "payload": {"config_uuid": config,
                                                "component_type": ctype,
                                                "component_uuid": uuid}}])
        s.check("a request for a real model we hold NO unit of is CREATED, not refused",
                body.get("success"), errors_of(body))
        missing = (body.get("data") or {}).get("stock_missing") or []
        s.check("  ...and reports the gap as stock_missing so the record can be raised first",
                len(missing) > 0, f"stock_missing={missing}")
        if missing:
            gap = missing[0]
            s.check("  ...naming the component type and uuid the record must be for",
                    gap.get("component_type") == ctype and gap.get("component_uuid") == uuid,
                    f"gap={gap}")
            s.check("  ...with reason 'stock_missing'",
                    gap.get("reason") == "stock_missing", f"reason={gap.get('reason')}")
            s.check("  ...and a held count, so 'we own none' reads differently from "
                    "'we own two and both are busy'",
                    "held" in gap, f"gap keys={sorted(gap.keys())}")
            print(f"          e.g. {ctype} {label or uuid[:8]}: held={gap.get('held')}")
    else:
        s.skip("zero-stock deferral", "every catalogued model has at least one inventory row")

    # --- a model whose units are ALL held: also deferred ----------------------
    all_held = None
    for ctype in ("cpu", "ram", "storage", "nic", "sfp"):
        free = {m["component_uuid"] for m in ctx.stock.get(ctype) or []}
        for model in ctx.records.get(ctype) or []:
            uuid = model.get("component_uuid")
            if uuid and uuid not in free and not uuid.startswith("onboard-") \
                    and uuid in (ctx.catalogue.get(ctype) or {}):
                all_held = (ctype, uuid)
                break
        if all_held:
            break

    if all_held:
        ctype, uuid = all_held
        body = ctx.create("Install Hardware",
                          f"LIVE SUITE — {ctype} all units in use (safe to cancel)",
                          target_server_uuid=config,
                          actions=[{"action_type": "server.component.add",
                                    "payload": {"config_uuid": config,
                                                "component_type": ctype,
                                                "component_uuid": uuid}}])
        s.check("a request for a model whose every unit is in use is CREATED and deferred",
                body.get("success"), errors_of(body))
        missing = (body.get("data") or {}).get("stock_missing") or []
        if missing:
            s.check("  ...and its held count is non-zero (we own some, none are free)",
                    (missing[0].get("held") or 0) > 0, f"gap={missing[0]}")
    else:
        s.skip("all-units-held deferral", "no model has units where none are free")

    # --- a NAMED SERIAL, and the two different answers it gets ---------------
    #
    # The rule is narrower than "a serial is always a refusal", and the two
    # halves are worth pinning separately because they come from different
    # branches of preflight():
    #
    #   we own NO unit of this model at all  -> errorType inventory_component_
    #       not_found, which is deferrable unconditionally. Deferring is right:
    #       the record about to be raised is FOR that serial (stockGap() carries
    #       it through for exactly this reason).
    #   we own units but none are free       -> errorType component_unavailable,
    #       and isPureStockShortage() refuses once a serial is named. Recording a
    #       different unit does not hand them the one they asked for.
    if zero_stock:
        ctype, uuid, _ = zero_stock
        body = ctx.create("Install Hardware",
                          "LIVE SUITE — named serial, model not stocked (safe to cancel)",
                          target_server_uuid=config,
                          actions=[{"action_type": "server.component.add",
                                    "payload": {"config_uuid": config,
                                                "component_type": ctype,
                                                "component_uuid": uuid,
                                                "serial_number": "SN-LIVE-SUITE-001"}}])
        s.check("naming a serial for a model we hold NO unit of is deferred, "
                "because the record raised will carry that serial",
                body.get("success"), errors_of(body))
        missing = (body.get("data") or {}).get("stock_missing") or []
        if missing:
            s.check("  ...and the gap carries the serial through to the record",
                    missing[0].get("serial_number") == "SN-LIVE-SUITE-001",
                    f"gap={missing[0]}")

    # The narrowing only bites when the serial names a unit that EXISTS and is
    # unavailable. lockAndCheckComponent() queries `WHERE UUID = ? AND
    # SerialNumber = ?`, so a serial matching nothing falls into the
    # not-found branch above instead — which is why the case below has to be
    # built from a unit that is genuinely installed somewhere else.
    taken = ctx.find_installed_unit_with_serial()
    if taken:
        ctype, uuid, serial, holder, holder_name = taken
        elsewhere = next((x["config_uuid"] for x in (ctx.located_servers or ctx.servers)
                          if x["config_uuid"] != holder), None)
        if elsewhere:
            body = ctx.create("Install Hardware",
                              "LIVE SUITE — serial of a unit installed elsewhere "
                              "(should be refused)",
                              target_server_uuid=elsewhere,
                              actions=[{"action_type": "server.component.add",
                                        "payload": {"config_uuid": elsewhere,
                                                    "component_type": ctype,
                                                    "component_uuid": uuid,
                                                    "serial_number": serial}}])
            s.check("naming the SERIAL of a unit installed in another server is REFUSED, "
                    "not deferred (a new record does not hand them that one physical unit)",
                    refused(body), errors_of(body))
            s.check("  ...and it is not offered as a stock gap",
                    not ((body.get("data") or {}).get("stock_missing")),
                    f"stock_missing={(body.get('data') or {}).get('stock_missing')}")
            print(f"          probed {ctype} serial {serial}, fitted in {holder_name}")
    else:
        s.skip("named-serial refusal", "no installed unit carries a serial number")

    # --- a uuid ims-data has never heard of: REFUSED -------------------------
    body = ctx.create("Install Hardware",
                      "LIVE SUITE — uncatalogued model (should be refused)",
                      target_server_uuid=config,
                      actions=[{"action_type": "server.component.add",
                                "payload": {"config_uuid": config,
                                            "component_type": "cpu",
                                            "component_uuid": "deadbeef-0000-4000-8000-000000000000"}}])
    s.check("a model ims-data has never heard of is REFUSED, never deferred "
            "(no inventory record could be created for it either)",
            refused(body), errors_of(body))
    s.check("  ...and it is not reported as a stock gap",
            not ((body.get("data") or {}).get("stock_missing")),
            f"stock_missing={(body.get('data') or {}).get('stock_missing')}")

    # --- an onboard NIC identity: also uncatalogued --------------------------
    onboard = next((m["component_uuid"] for m in ctx.stock.get("nic") or []
                    if str(m.get("component_uuid", "")).startswith("onboard-")), None)
    if onboard:
        body = ctx.create("Install Hardware",
                          "LIVE SUITE — onboard NIC port (should be refused)",
                          target_server_uuid=config,
                          actions=[{"action_type": "server.component.add",
                                    "payload": {"config_uuid": config,
                                                "component_type": "nic",
                                                "component_uuid": onboard}}])
        s.check("an onboard NIC port offered by the stock dropdown cannot be fitted "
                "as loose hardware",
                refused(body), errors_of(body))


# ================================ 7. the Add Inventory Record prerequisite


def test_add_inventory_record(ctx):
    s = ctx.suite
    s.head("7. The Add Inventory Record prerequisite itself")

    if not ctx.writes:
        s.skip("inventory.component.add", "--writes not given")
        return

    # An uncatalogued model. addComponent() runs validateComponentUuid() and
    # refuses it at approval, so nothing bad is ever written — but preflight()
    # builds no command for an inventory-scope action, so there is no dry run
    # and nothing notices at submit time.
    body = ctx.create("Add Inventory Record",
                      "LIVE SUITE — uncatalogued model (safe to cancel)",
                      actions=[{"action_type": "inventory.component.add",
                                "payload": {"component_type": "cpu",
                                            "data": {"UUID": "deadbeef-0000-4000-8000-000000000000",
                                                     "SerialNumber": "LIVE-SUITE-001"}}}])
    if body.get("success"):
        s.warn("an inventory record for an UNCATALOGUED model is accepted at submit time",
               f"request #{(body.get('data') or {}).get('pipeline_id')} was created. "
               f"addComponent()'s validateComponentUuid() refuses it at approval, so no bad "
               f"row is ever written — but the requester is told it worked and the approver "
               f"inherits a request that can never succeed. The SAME check is applied at "
               f"submit time for server.component.add (isCataloguedModel(), which refuses "
               f"outright rather than deferring), and inventory.component.edit checks its "
               f"row exists via inventoryRecordExists(). inventory.component.add does neither.")
    else:
        s.check("an inventory record for an uncatalogued model is refused at submit time",
                True, "")

    body = ctx.create("Add Inventory Record",
                      "LIVE SUITE — missing data (should be refused)",
                      actions=[{"action_type": "inventory.component.add",
                                "payload": {"component_type": "cpu"}}])
    s.check("inventory.component.add without 'data' is refused", refused(body), errors_of(body))

    body = ctx.create("Add Inventory Record",
                      "LIVE SUITE — bogus type (should be refused)",
                      actions=[{"action_type": "inventory.component.add",
                                "payload": {"component_type": "widget", "data": {"UUID": "x"}}}])
    s.check("inventory.component.add with an unknown component type is refused",
            refused(body), errors_of(body))

    body = ctx.create("Update Inventory Record",
                      "LIVE SUITE — missing row (should be refused)",
                      actions=[{"action_type": "inventory.component.edit",
                                "payload": {"component_type": "cpu",
                                            "inventory_id": 99999999,
                                            "data": {"Notes": "live suite"}}}])
    s.check("editing an inventory record that no longer exists is refused at submit time",
            refused(body), errors_of(body))
    s.check("  ...and says the record is gone, not something generic",
            "no longer exists" in errors_of(body).lower(), errors_of(body))

    # The whole point of the deferral: the record request can be raised as a
    # prerequisite of the install, which freezes the install until it is done.
    zero_stock = None
    for ctype in ("cpu", "ram", "storage"):
        held = {m["component_uuid"] for m in ctx.records.get(ctype) or []}
        for uuid in sorted(ctx.catalogue.get(ctype) or {}):
            if uuid not in held:
                zero_stock = (ctype, uuid)
                break
        if zero_stock:
            break

    servers = ctx.located_servers or ctx.servers
    if zero_stock and servers:
        ctype, uuid = zero_stock
        parent = ctx.create("Install Hardware",
                            "LIVE SUITE — install awaiting stock (safe to cancel)",
                            target_server_uuid=servers[0]["config_uuid"],
                            actions=[{"action_type": "server.component.add",
                                      "payload": {"config_uuid": servers[0]["config_uuid"],
                                                  "component_type": ctype,
                                                  "component_uuid": uuid}}])
        pid = (parent.get("data") or {}).get("pipeline_id")
        if pid:
            child = ctx.create("Add Inventory Record",
                               "LIVE SUITE — the stock it is waiting for (safe to cancel)",
                               parent_ticket_id=str(pid),
                               actions=[{"action_type": "inventory.component.add",
                                         "payload": {"component_type": ctype,
                                                     "data": {"UUID": uuid,
                                                              "SerialNumber": "LIVE-SUITE-NEVER-APPROVED"}}}])
            s.check("the Add Inventory Record can be raised as a PREREQUISITE of the install",
                    child.get("success"), errors_of(child))
            cid = (child.get("data") or {}).get("pipeline_id")
            if cid:
                detail = ctx.api.call("pipeline-get", pipeline_id=str(pid), ticket_id=str(pid))
                pipeline = (detail.get("data") or {}).get("pipeline") or {}
                children = pipeline.get("children") or pipeline.get("child_requests") or []
                s.check("  ...and the install is frozen behind it",
                        any(str(c.get("id")) == str(cid) for c in children),
                        f"children={[c.get('id') for c in children]}")


# ========================================= 8. the Hardware Handover that fixes it


def test_handover(ctx):
    s = ctx.suite
    s.head("8. Hardware Handover — moving the part to the site that needs it")

    handover = ctx.templates.get("Hardware Handover")
    s.check("the Hardware Handover request type exists", handover is not None)
    if not handover or not ctx.writes:
        if not ctx.writes:
            s.skip("handover action validation", "--writes not given")
        return

    destination = next((x["location_uuid"] for x in ctx.locations if x.get("is_active")), None)
    if not destination:
        s.skip("handover action validation", "no active location to move to")
        return

    # ComponentRelocation::move() refuses all three of these at approval — a
    # missing row, a missing/retired destination, and anything that is not loose
    # stock. None of them is checked at submit time, because preflight() builds
    # no command for an inventory-scope action.
    deferred_checks = []

    body = ctx.create("Hardware Handover",
                      "LIVE SUITE — bogus inventory id (safe to cancel)",
                      actions=[{"action_type": "inventory.component.relocate",
                                "payload": {"component_type": "storage",
                                            "inventory_id": 99999999,
                                            "location_uuid": destination}}])
    if body.get("success"):
        deferred_checks.append("an inventory row that does not exist")
    else:
        s.check("a handover naming an inventory row that does not exist is refused at submit time",
                True, "")

    body = ctx.create("Hardware Handover",
                      "LIVE SUITE — bogus destination (safe to cancel)",
                      actions=[{"action_type": "inventory.component.relocate",
                                "payload": {"component_type": "storage",
                                            "inventory_id": 1,
                                            "location_uuid": "00000000-0000-0000-0000-000000000000"}}])
    if body.get("success"):
        deferred_checks.append("a destination location that does not exist")
    else:
        s.check("a handover to a location that does not exist is refused at submit time",
                True, "")

    for missing in ("component_type", "inventory_id", "location_uuid"):
        payload = {"component_type": "storage", "inventory_id": 1,
                   "location_uuid": destination}
        payload.pop(missing)
        body = ctx.create("Hardware Handover", f"LIVE SUITE — missing {missing}",
                          actions=[{"action_type": "inventory.component.relocate",
                                    "payload": payload}])
        s.check(f"a handover without '{missing}' is refused", refused(body), errors_of(body))

    # component_uuid names a MODEL; only inventory_id names the object somebody
    # has to physically carry.
    body = ctx.create("Hardware Handover",
                      "LIVE SUITE — model uuid instead of a unit (should be refused)",
                      actions=[{"action_type": "inventory.component.relocate",
                                "payload": {"component_type": "storage",
                                            "component_uuid": "58f9bfb0-dfe8-4833-ac47-9222de9cbcc4",
                                            "location_uuid": destination}}])
    s.check("a handover cannot name a MODEL instead of a unit "
            "(a uuid names a model, not the object being carried)",
            refused(body), errors_of(body))

    # An installed part is not loose stock and cannot be handed over.
    installed_unit = None
    for server in (ctx.located_servers or ctx.servers)[:6]:
        for ctype in ("storage", "ram", "cpu", "nic"):
            body = ctx.api.call("pipeline-component-options", component_type=ctype,
                                source="installed", config_uuid=server["config_uuid"])
            for unit in (body.get("data") or {}).get("units") or []:
                if unit.get("inventory_id") and not unit.get("is_onboard"):
                    installed_unit = (ctype, unit["inventory_id"], server)
                    break
            if installed_unit:
                break
        if installed_unit:
            break

    if installed_unit:
        ctype, inv_id, server = installed_unit
        body = ctx.create("Hardware Handover",
                          "LIVE SUITE — installed part (safe to cancel)",
                          actions=[{"action_type": "inventory.component.relocate",
                                    "payload": {"component_type": ctype,
                                                "inventory_id": inv_id,
                                                "location_uuid": destination}}])
        if body.get("success"):
            deferred_checks.append(f"an INSTALLED component ({ctype} id {inv_id}, fitted in "
                                   f"{server.get('server_name')})")
        else:
            s.check("a handover naming an INSTALLED component is refused at submit time "
                    "(not loose stock)", True, "")
        print(f"          probed {ctype} inventory_id={inv_id} inside "
              f"{server.get('server_name')}")
    else:
        s.skip("installed-component handover refusal", "no non-onboard installed unit found")

    if deferred_checks:
        s.warn("a Hardware Handover is accepted at submit time whatever it names",
               "these were all created and sat in the queue as ordinary requests: "
               + "; ".join(deferred_checks)
               + ". ComponentRelocation::move() refuses every one of them at approval, so no "
                 "bad move is ever performed — but the requester is told it worked. Compare "
                 "inventory.component.edit, which checks inventoryRecordExists() at preflight "
                 "precisely so the requester is told while still looking at the form.")


# ===================================================================== runner


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--writes", action="store_true")
    parser.add_argument("--json")
    parser.add_argument("--user")
    parser.add_argument("--password")
    args = parser.parse_args()

    api = Client(user=args.user, password=args.password).login()
    suite = Suite("Install location + inventory-first — live")
    print(f"Endpoint : {api.url}")
    print(f"User     : {api.user} (id {api.user_id}, roles {api.roles})")
    print(f"Writes   : {'ON — requests will be created and cancelled' if args.writes else 'OFF (read-only)'}")

    ctx = Context(api, suite, args.writes)
    started = time.time()
    try:
        ctx.gather()
        test_feature_live(ctx)
        test_three_valued_match(ctx)
        test_serial_and_unit_modes(ctx)
        test_gate_coverage(ctx)
        test_cross_site_request(ctx)
        test_inventory_first(ctx)
        test_add_inventory_record(ctx)
        test_handover(ctx)
    finally:
        ctx.cleanup()

    failures = suite.summary()
    print(f"\nElapsed: {time.time() - started:.1f}s")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump({"suite": suite.name, "endpoint": api.url, "user": api.user,
                       "writes": args.writes, "counts": suite.counts(),
                       "results": suite.results}, handle, indent=2)
        print(f"JSON written to {args.json}")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
