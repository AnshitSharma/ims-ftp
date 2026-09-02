"""
requests_module_test.py — live black-box suite for the Requests (pipeline) module.

WHY THIS EXISTS
---------------
tests/regression/location_aware_requests_test.php and
stock_missing_prerequisite_test.php are STRUCTURAL: they grep the shipped source
for the strings the plan called for. That proves the wiring was written. It
cannot prove the deployed system behaves that way, because the behaviour depends
on things no source file contains — which seeders have actually been run, which
request types exist, which inventory rows carry a location_uuid.

This suite asks the running API instead. Every assertion is a real request over
HTTP against a real deployment, and every claim in the report it produces is one
of these lines.

WRITE POLICY
------------
Read-only by default. `--writes` additionally CREATES requests (the only way to
test create-time validation at all) and cancels every one of them in a finally
block; the ids are printed either way so a survivor can be cleaned up by hand.

It never approves anything. Completing an effect-bearing step performs real
work — installs hardware, flips inventory status, moves stock between sites —
and this suite has no way to undo that. The approval-path assertions are made by
reading the guards' preconditions instead; see test_approval_guards().

Usage:
    python tests/live/requests_module_test.py            # read-only
    python tests/live/requests_module_test.py --writes   # + create/cancel
    python tests/live/requests_module_test.py --json out.json
"""

import argparse
import json
import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from _client import (ACTION_REQUIRED, COMPONENT_TYPES, Client, Suite,  # noqa: E402
                     load_catalogue)

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
IMS_DATA = os.path.join(os.path.dirname(REPO_ROOT), "ims-data")

ENVELOPE_KEYS = {"success", "authenticated", "code", "message", "timestamp", "data"}

PIPELINE_OPERATIONS = [
    "template-list", "template-get", "template-create", "template-update",
    "template-delete", "create", "servers", "users", "component-location",
    "inventory-record", "component-options", "list", "get", "claim",
    "complete", "reject", "reassign", "cancel", "unlink-child",
]

# api.php:346 — the operations any authenticated user may reach. Everything
# else is admin/super_admin in code on top of ACL.
SELF_SERVICE = {"create", "list", "get", "template-list", "servers",
                "component-location", "users", "inventory-record",
                "component-options", "claim", "complete"}


class Context:
    """Facts about the deployment, gathered once and shared by every test."""

    def __init__(self, api, suite, writes):
        self.api = api
        self.suite = suite
        self.writes = writes
        self.created = []          # ticket ids this run made, for cleanup
        self.templates = {}        # name -> row
        self.servers = []
        self.catalogue = {}
        self.stock = {}            # type -> [model rows with free units]
        self.records = {}          # type -> [model rows we hold any unit of]

    def gather(self):
        body = self.api.call("pipeline-template-list")
        for row in (body.get("data") or {}).get("templates") or []:
            self.templates[row["name"]] = row

        body = self.api.call("pipeline-servers")
        self.servers = (body.get("data") or {}).get("servers") or []

        self.catalogue = load_catalogue(IMS_DATA)

        for ctype in COMPONENT_TYPES:
            for source, sink in (("stock", self.stock), ("records", self.records)):
                body = self.api.call("pipeline-component-options",
                                     component_type=ctype, source=source)
                sink[ctype] = (body.get("data") or {}).get("models") or []

    def template_id(self, name):
        row = self.templates.get(name)
        return row["id"] if row else None

    def real_servers(self):
        return [s for s in self.servers if not s.get("is_virtual")]

    def create(self, template_name, title, **extra):
        """Create a request and remember it for cleanup. Returns the body."""
        tid = self.template_id(template_name)
        if tid is None:
            return {"success": False, "message": f"no such request type: {template_name}"}
        body = self.api.call("pipeline-create", pipeline_template_id=str(tid),
                             title=title, **extra)
        pid = ((body.get("data") or {}).get("pipeline_id"))
        if pid:
            self.created.append(pid)
        return body

    def cleanup(self):
        """
        Cancel everything this run created, then CONFIRM the closed state by
        re-reading each row. A failed cancel is not evidence the request is
        still open — a request this suite already cancelled mid-test refuses a
        second cancel, which is the correct behaviour and not a leak.
        """
        if not self.created:
            return
        print(f"\n-- cleanup: closing {len(self.created)} request(s) created by this run --")
        leaked = []
        for pid in self.created:
            self.api.call("pipeline-cancel", pipeline_id=str(pid), ticket_id=str(pid),
                          reason="automated live suite cleanup")
            detail = self.api.call("pipeline-get", pipeline_id=str(pid), ticket_id=str(pid))
            status = ((detail.get("data") or {}).get("pipeline") or {}).get("status")
            closed = status in ("cancelled", "rejected", "completed")
            if not closed:
                leaked.append((pid, status))
            print(f"   #{pid}: {status}")
        if leaked:
            print(f"   !! {len(leaked)} request(s) LEFT OPEN and need cancelling by hand: {leaked}")


# ===================================================================== helpers


def errors_of(body):
    """Every human-readable refusal string a create response can carry."""
    out = [str(body.get("message") or "")]
    data = body.get("data") or {}
    if isinstance(data, dict):
        for item in data.get("errors") or []:
            out.append(str(item))
    return " | ".join(x for x in out if x)


def refused(body):
    return not body.get("success")


# ================================================================ 1. transport


def test_envelope_and_auth(ctx):
    s, api = ctx.suite, ctx.api
    s.head("1. Transport, envelope and authentication")

    status, body = api.raw("pipeline-template-list")
    s.check("pipeline-template-list returns HTTP 200", status == 200, f"got {status}")
    s.check("response carries the full envelope",
            ENVELOPE_KEYS.issubset(body.keys()),
            f"missing {ENVELOPE_KEYS - set(body.keys())}")
    s.check("envelope.code agrees with the HTTP status",
            body.get("code") == status, f"code={body.get('code')} http={status}")

    body = api.anon("pipeline-list")
    s.check("an unauthenticated pipeline call is refused",
            refused(body) and body.get("code") in (401, 403),
            f"code={body.get('code')} msg={body.get('message')}")
    s.check("the refusal reports authenticated=false",
            body.get("authenticated") is False, f"got {body.get('authenticated')}")

    saved = api.token
    api.token = saved[:-6] + "AAAAAA"
    body = api.call("pipeline-list")
    s.check("a tampered JWT is refused",
            refused(body) and body.get("code") in (401, 403),
            f"code={body.get('code')}")
    api.token = saved

    body = api.call("pipeline-not-a-real-operation")
    s.check("an unknown pipeline operation is refused",
            refused(body), errors_of(body))
    s.check("  ...as a 400, not a 500",
            body.get("code") == 400, f"code={body.get('code')}")

    body = api.call("pipeline_list")
    s.check("underscores are not accepted for a kebab-case module",
            refused(body), "pipeline_list was accepted")

    body = api.call("")
    s.check("an empty action is refused", refused(body), errors_of(body))


# ================================================================ 2. templates


def test_request_types(ctx):
    s, api = ctx.suite, ctx.api
    s.head("2. Request Types (pipeline templates)")

    s.check("template-list returns at least the system type",
            len(ctx.templates) > 0, f"got {len(ctx.templates)}")

    general = ctx.templates.get("General Request")
    s.check("the built-in 'General Request' exists", general is not None)
    if general:
        s.check("  ...and is flagged is_system=1",
                int(general.get("is_system") or 0) == 1,
                f"is_system={general.get('is_system')}")

        body = api.call("pipeline-template-update",
                        pipeline_template_id=str(general["id"]),
                        template_id=str(general["id"]),
                        name="RENAMED BY TEST — SHOULD BE REFUSED")
        s.check("  ...cannot be renamed", refused(body), errors_of(body))

        body = api.call("pipeline-template-delete",
                        pipeline_template_id=str(general["id"]),
                        template_id=str(general["id"]))
        s.check("  ...cannot be deleted", refused(body), errors_of(body))

    for name in ("Install Hardware", "Return Hardware to Stock", "Swap Hardware",
                 "New Server", "Update Server Details", "Change Server Status",
                 "Add Inventory Record", "Update Inventory Record",
                 "Move Server", "Hardware Handover"):
        s.check(f"request type present: {name}", name in ctx.templates)

    body = api.call("pipeline-template-get", pipeline_template_id="99999999",
                    template_id="99999999", id="99999999")
    s.check("template-get on an unknown id is refused", refused(body), errors_of(body))

    body = api.call("pipeline-template-get", pipeline_template_id="not-a-number",
                    template_id="not-a-number", id="not-a-number")
    s.check("template-get on a non-numeric id is refused", refused(body), errors_of(body))

    # Every effect-bearing type must declare a ceiling. A step that performs
    # work with an empty action_types list is refused at approval, which is a
    # failure discovered far too late.
    for name in ("Install Hardware", "Swap Hardware", "Hardware Handover",
                 "Add Inventory Record", "Move Server"):
        row = ctx.templates.get(name)
        if not row:
            continue
        body = api.call("pipeline-template-get", pipeline_template_id=str(row["id"]),
                        template_id=str(row["id"]), id=str(row["id"]))
        detail = (body.get("data") or {}).get("template") or (body.get("data") or {})
        stages = detail.get("stages") or []
        effect_stages = [x for x in stages if x.get("effect_type")]
        s.check(f"{name}: has exactly one effect-bearing step",
                len(effect_stages) == 1, f"got {len(effect_stages)}")
        for stage in effect_stages:
            cfg = stage.get("effect_config")
            if isinstance(cfg, str):
                try:
                    cfg = json.loads(cfg)
                except ValueError:
                    cfg = {}
            ceiling = (cfg or {}).get("action_types") or []
            s.check(f"{name}: its ceiling names at least one action",
                    len(ceiling) > 0, f"ceiling={ceiling}")
            for action_type in ceiling:
                s.check(f"{name}: ceiling entry '{action_type}' is a real action type",
                        action_type in ACTION_REQUIRED, f"unknown: {action_type}")

    handover = ctx.templates.get("Hardware Handover")
    if handover:
        body = api.call("pipeline-template-get", pipeline_template_id=str(handover["id"]),
                        template_id=str(handover["id"]), id=str(handover["id"]))
        detail = (body.get("data") or {}).get("template") or (body.get("data") or {})
        stages = sorted(detail.get("stages") or [], key=lambda x: x.get("position") or 0)
        s.check("Hardware Handover has two steps", len(stages) == 2, f"got {len(stages)}")
        if len(stages) == 2:
            s.check("  step 1 performs the move (execute_request)",
                    stages[0].get("effect_type") == "execute_request",
                    f"got {stages[0].get('effect_type')}")
            s.check("  step 2 has no effect (it is the carrier's signature)",
                    not stages[1].get("effect_type"),
                    f"got {stages[1].get('effect_type')}")
            s.check("  step 2 is ownerless in the type, so the named carrier owns it",
                    not stages[1].get("default_assignee"),
                    f"got {stages[1].get('default_assignee')}")


# ========================================================= 3. read endpoints


def test_read_endpoints(ctx):
    s, api = ctx.suite, ctx.api
    s.head("3. Form-feeding read endpoints")

    body = api.call("pipeline-servers")
    s.check("pipeline-servers answers for a requester", body.get("success"), errors_of(body))

    body = api.call("pipeline-users")
    s.check("pipeline-users answers", body.get("success"), errors_of(body))
    users = (body.get("data") or {}).get("users") or []
    if users:
        # is_self is deliberate (pipeline-users.php:89) — the carrier picker
        # marks the caller's own row. Everything else must be plain identity.
        leaked = set(users[0].keys()) - {"id", "username", "display_name", "name",
                                         "firstname", "lastname", "email", "is_self"}
        s.check("pipeline-users returns identity fields only",
                not leaked, f"unexpected keys: {sorted(leaked)}")

    body = api.call("pipeline-component-options", component_type="storage")
    s.check("component-options without a source is refused", refused(body), errors_of(body))

    body = api.call("pipeline-component-options", component_type="storage", source="nonsense")
    s.check("component-options with a bogus source is refused", refused(body), errors_of(body))

    body = api.call("pipeline-component-options", component_type="widget", source="stock")
    s.check("component-options with an unknown component type is refused",
            refused(body), errors_of(body))

    body = api.call("pipeline-component-options", component_type="storage", source="installed")
    s.check("component-options source=installed without a config_uuid is refused",
            refused(body), errors_of(body))

    # A SQL-ish component type must never reach the interpolated table name.
    body = api.call("pipeline-component-options",
                    component_type="storage`; DROP TABLE tickets; --", source="stock")
    s.check("component-options rejects an injection-shaped component type",
            refused(body), errors_of(body))

    body = api.call("pipeline-component-location", component_type="storage")
    s.check("component-location without a component_uuid is refused",
            refused(body), errors_of(body))

    body = api.call("pipeline-component-location", component_type="widget",
                    component_uuid="x" * 36)
    s.check("component-location with an unknown component type is refused",
            refused(body), errors_of(body))

    body = api.call("pipeline-inventory-record", component_type="storage")
    s.check("inventory-record without an id is refused", refused(body), errors_of(body))

    body = api.call("pipeline-inventory-record", component_type="storage",
                    inventory_id="99999999")
    s.check("inventory-record for a non-existent row is refused",
            refused(body), errors_of(body))


# ================================================ 4. per-component-type sweep


def test_all_component_types(ctx):
    s, api = ctx.suite, ctx.api
    s.head("4. All 12 component types — options, catalogue and location")

    servers = ctx.real_servers()
    located = [x for x in servers if x.get("config_uuid")]
    sample_config = located[0]["config_uuid"] if located else None

    for ctype in COMPONENT_TYPES:
        body = api.call("pipeline-component-options", component_type=ctype, source="stock")
        s.check(f"{ctype}: component-options source=stock answers",
                body.get("success"), errors_of(body))
        models = (body.get("data") or {}).get("models")
        s.check(f"{ctype}: stock answer carries a models list",
                isinstance(models, list), f"got {type(models).__name__}")

        body = api.call("pipeline-component-options", component_type=ctype, source="records")
        s.check(f"{ctype}: component-options source=records answers",
                body.get("success"), errors_of(body))

        if sample_config:
            body = api.call("pipeline-component-options", component_type=ctype,
                            source="installed", config_uuid=sample_config)
            s.check(f"{ctype}: component-options source=installed answers",
                    body.get("success"), errors_of(body))

        catalogue = ctx.catalogue.get(ctype) or {}
        s.check(f"{ctype}: ims-data catalogue is readable and non-empty",
                len(catalogue) > 0, f"{len(catalogue)} models found")

        # Every model the stock endpoint offers should be one ims-data
        # describes. A stocked UUID the catalogue has never heard of is a model
        # nobody can request: isCataloguedModel() fails it closed, so preflight
        # refuses the action outright instead of deferring it to an inventory
        # record.
        #
        # Onboard NIC ports are the one legitimate exception. OnboardNICHandler
        # materialises them under a synthetic "onboard-{mb}-{inv}-{n}" identity
        # that is in no spec file by design, and component-options keeps them
        # visible on purpose rather than hiding installed hardware.
        offered = {m.get("component_uuid") for m in (ctx.stock.get(ctype) or []) if m.get("component_uuid")}
        synthetic = sorted(u for u in offered if u.startswith("onboard-"))
        unknown = sorted(u for u in offered
                         if catalogue and u not in catalogue and not u.startswith("onboard-"))
        s.check(f"{ctype}: every real in-stock model is in the ims-data catalogue",
                not unknown, f"{len(unknown)} uncatalogued: {unknown[:4]}")

        if synthetic:
            s.warn(f"{ctype}: synthetic onboard identities are offered as free stock",
                   f"{len(synthetic)} of {len(offered)} entries in the 'model to fit' list are "
                   f"onboard ports (model_label null, not in ims-data). They cannot be fitted "
                   f"as loose stock, and a request naming one is refused at preflight. "
                   f"e.g. {synthetic[:3]}")

        if sample_config and offered:
            uuid = sorted(u for u in offered if u)[0]
            body = api.call("pipeline-component-location", config_uuid=sample_config,
                            component_type=ctype, component_uuid=uuid)
            s.check(f"{ctype}: component-location answers for a real model",
                    body.get("success"), errors_of(body))
            data = body.get("data") or {}
            s.check(f"{ctype}: match is three-valued (true/false/null)",
                    data.get("match") in (True, False, None),
                    f"got {data.get('match')!r}")


# ============================================== 5. create-time shape refusals


def test_create_validation(ctx):
    s, api = ctx.suite, ctx.api
    s.head("5. pipeline-create — field validation")

    if not ctx.writes:
        s.skip("create-time validation", "--writes not given; every case here posts a create")
        return

    install = ctx.template_id("Install Hardware")
    general = ctx.template_id("General Request")

    body = api.call("pipeline-create", title="no template id")
    s.check("create without a template id is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id="abc", title="non-numeric")
    s.check("create with a non-numeric template id is refused", refused(body), errors_of(body))
    s.check("  ...as a 400", body.get("code") == 400, f"code={body.get('code')}")

    body = api.call("pipeline-create", pipeline_template_id="99999999", title="unknown type")
    s.check("create against an unknown request type is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general), title="")
    s.check("create with an empty title is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general), title="   ")
    s.check("create with a whitespace-only title is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general), title="T" * 256)
    s.check("create with a 256-character title is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general),
                    title="over-long description", description="D" * 5001)
    s.check("create with a 5001-character description is refused",
            refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general),
                    title="bad priority", priority="apocalyptic")
    s.check("create with an invalid priority is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general),
                    title="unknown target server",
                    target_server_uuid="00000000-0000-0000-0000-000000000000")
    s.check("create naming a non-existent target server is refused",
            refused(body), errors_of(body))

    # --- malformed `actions` ------------------------------------------------
    # PipelineManager::normaliseActions() returns null for anything that is not
    # a list of {action_type, payload}, and createPipeline() turns that into a
    # refusal. But pipeline-create.php coerces a non-array to [] BEFORE the
    # manager sees it (pipeline-create.php:66-70), so only the shapes that
    # json_decode turns into an array actually reach the check.
    for label, raw in (("a JSON object", '{"action_type":"server.component.add"}'),
                       ("a list with a malformed entry", '[{"foo":1}]')):
        body = api.call("pipeline-create", pipeline_template_id=str(general),
                        title=f"actions as {label}", actions=raw)
        s.check(f"create with actions as {label} is refused", refused(body), errors_of(body))

    # These three decode to a scalar or to null, are coerced to [], and produce
    # a request that was accepted with no work attached to it.
    for label, raw in (("a JSON string", '"a string"'),
                       ("a JSON number", "42"),
                       ("unparseable JSON", "{{{not json")):
        body = api.call("pipeline-create", pipeline_template_id=str(install),
                        title=f"LIVE SUITE — actions as {label} (safe to cancel)",
                        actions=raw)
        pid = (body.get("data") or {}).get("pipeline_id")
        if pid:
            ctx.created.append(pid)
        if body.get("success"):
            detail = api.call("pipeline-get", pipeline_id=str(pid), ticket_id=str(pid))
            actions = ((detail.get("data") or {}).get("pipeline") or {}).get("actions")
            s.warn(f"an Install Hardware request with actions as {label} is ACCEPTED",
                   f"request #{pid} was created with actions={actions}. It looks like a normal "
                   f"install in the queue and can never be approved — applyStageEffect() "
                   f"refuses with 'This request has nothing to perform'. "
                   f"pipeline-create.php:66-70 coerces the bad value to [] before "
                   f"normaliseActions() can refuse it.")
        else:
            s.check(f"create with actions as {label} is refused", True, "")

    # The same end state, reachable without malformed input at all.
    body = api.call("pipeline-create", pipeline_template_id=str(install),
                    title="LIVE SUITE — effect type with no actions (safe to cancel)",
                    actions=[])
    pid = (body.get("data") or {}).get("pipeline_id")
    if pid:
        ctx.created.append(pid)
    if body.get("success"):
        s.warn("an effect-bearing request type accepts an EMPTY action list",
               f"request #{pid} is an 'Install Hardware' that installs nothing. It is only "
               f"refused at approval time, after it has cost an approver their attention. "
               f"Every other create-time check exists precisely to avoid that.")
    else:
        s.check("an effect-bearing request type refuses an empty action list", True, "")

    body = api.call("pipeline-create", pipeline_template_id=str(general),
                    title="too many actions",
                    actions=[{"action_type": "server.config.create",
                              "payload": {"server_name": f"x{i}"}} for i in range(51)])
    s.check("create with 51 actions is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(general),
                    title="bad parent", parent_ticket_id="not-numeric")
    s.check("create with a non-numeric parent_ticket_id is refused",
            refused(body), errors_of(body))
    s.check("  ...as a 400", body.get("code") == 400, f"code={body.get('code')}")

    body = api.call("pipeline-create", pipeline_template_id=str(general),
                    title="unknown parent", parent_ticket_id="99999999")
    s.check("create naming a non-existent parent is refused", refused(body), errors_of(body))

    # ---- action shape -------------------------------------------------------
    s.head("6. pipeline-create — action shape and ceiling")

    body = api.call("pipeline-create", pipeline_template_id=str(install),
                    title="unknown action type",
                    actions=[{"action_type": "server.component.teleport", "payload": {}}])
    s.check("an unknown action_type is refused", refused(body), errors_of(body))

    body = api.call("pipeline-create", pipeline_template_id=str(install),
                    title="action outside this type's ceiling",
                    actions=[{"action_type": "inventory.component.add",
                              "payload": {"component_type": "cpu", "data": {}}}])
    s.check("an action outside the request type's ceiling is refused at CREATE time",
            refused(body), errors_of(body))
    s.check("  ...and the message names the ceiling, not a generic failure",
            "cannot perform" in errors_of(body).lower(), errors_of(body))

    servers = ctx.real_servers()
    config = servers[0]["config_uuid"] if servers else None
    stocked = None
    for ctype in ("cpu", "ram", "storage"):
        models = ctx.stock.get(ctype) or []
        if models:
            stocked = (ctype, models[0]["component_uuid"])
            break

    if config and stocked:
        ctype, uuid = stocked
        for missing in ACTION_REQUIRED["server.component.add"]:
            payload = {"config_uuid": config, "component_type": ctype, "component_uuid": uuid}
            payload.pop(missing)
            body = api.call("pipeline-create", pipeline_template_id=str(install),
                            title=f"missing {missing}",
                            actions=[{"action_type": "server.component.add",
                                      "payload": payload}])
            s.check(f"server.component.add without '{missing}' is refused",
                    refused(body), errors_of(body))

        body = api.call("pipeline-create", pipeline_template_id=str(install),
                        title="smuggled payload key",
                        actions=[{"action_type": "server.component.add",
                                  "payload": {"config_uuid": config,
                                              "component_type": ctype,
                                              "component_uuid": uuid,
                                              "bypass_validation": True}}])
        s.check("a payload key the action does not declare is refused",
                refused(body), errors_of(body))

        body = api.call("pipeline-create", pipeline_template_id=str(install),
                        title="ticket_id smuggled into the payload",
                        actions=[{"action_type": "server.component.add",
                                  "payload": {"config_uuid": config,
                                              "component_type": ctype,
                                              "component_uuid": uuid,
                                              "ticket_id": 1}}])
        s.check("a request cannot name another request as its own authority",
                refused(body), errors_of(body))

        body = api.call("pipeline-create", pipeline_template_id=str(install),
                        title="bogus component type in the payload",
                        actions=[{"action_type": "server.component.add",
                                  "payload": {"config_uuid": config,
                                              "component_type": "widget",
                                              "component_uuid": uuid}}])
        s.check("an unknown component_type inside a payload is refused",
                refused(body), errors_of(body))

        body = api.call("pipeline-create", pipeline_template_id=str(install),
                        title="unknown config in the payload",
                        actions=[{"action_type": "server.component.add",
                                  "payload": {"config_uuid": "00000000-0000-0000-0000-000000000000",
                                              "component_type": ctype,
                                              "component_uuid": uuid}}])
        s.check("an action naming a non-existent server is refused",
                refused(body), errors_of(body))
    else:
        s.skip("server.component.add payload cases", "no server or no stocked model found")

    # server.config.update may only write the whitelisted fields.
    if config:
        body = api.call("pipeline-create", pipeline_template_id=str(ctx.template_id("Update Server Details")),
                        title="update a field outside the whitelist",
                        actions=[{"action_type": "server.config.update",
                                  "payload": {"config_uuid": config,
                                              "fields": {"configuration_status": 3}}}])
        s.check("server.config.update cannot write configuration_status",
                refused(body), errors_of(body))

        body = api.call("pipeline-create", pipeline_template_id=str(ctx.template_id("Update Server Details")),
                        title="update rack_position via the wrong door",
                        actions=[{"action_type": "server.config.update",
                                  "payload": {"config_uuid": config,
                                              "fields": {"rack_position": "U42"}}}])
        s.check("server.config.update cannot write rack_position (that is server.relocate's job)",
                refused(body), errors_of(body))

    # A transition to a status that does not exist.
    if config:
        body = api.call("pipeline-create", pipeline_template_id=str(ctx.template_id("Change Server Status")),
                        title="transition to a nonsense status",
                        actions=[{"action_type": "server.config.transition",
                                  "payload": {"config_uuid": config, "to_status": "banana"}}])
        s.check("server.config.transition to an unknown status is refused",
                refused(body), errors_of(body))


# ================================================= 7. happy path + lifecycle


def test_lifecycle(ctx):
    s, api = ctx.suite, ctx.api
    s.head("7. Lifecycle — create, read, freeze, cancel")

    if not ctx.writes:
        s.skip("lifecycle", "--writes not given")
        return

    body = ctx.create("General Request", "LIVE SUITE — lifecycle parent (safe to cancel)",
                      description="Created by tests/live/requests_module_test.py")
    s.check("a General Request can be raised", body.get("success"), errors_of(body))
    parent = (body.get("data") or {}).get("pipeline_id")
    if not parent:
        return

    body = api.call("pipeline-get", pipeline_id=str(parent), ticket_id=str(parent))
    s.check("pipeline-get returns the new request", body.get("success"), errors_of(body))
    pipeline = (body.get("data") or {}).get("pipeline") or {}
    s.check("  ...in status draft or in_progress",
            pipeline.get("status") in ("draft", "in_progress"),
            f"status={pipeline.get('status')}")
    stages = pipeline.get("stages") or pipeline.get("stage_progress") or []
    s.check("  ...with its steps snapshotted",
            len(stages) == 3, f"General Request should snapshot 3 steps, got {len(stages)}")

    body = api.call("pipeline-get", pipeline_id="99999999", ticket_id="99999999")
    s.check("pipeline-get on an unknown id is refused", refused(body), errors_of(body))

    # A child request freezes its parent.
    body = ctx.create("General Request", "LIVE SUITE — prerequisite child (safe to cancel)",
                      parent_ticket_id=str(parent))
    s.check("a prerequisite child can be raised against an open parent",
            body.get("success"), errors_of(body))
    child = (body.get("data") or {}).get("pipeline_id")

    if child:
        body = api.call("pipeline-get", pipeline_id=str(parent), ticket_id=str(parent))
        pipeline = (body.get("data") or {}).get("pipeline") or {}
        children = pipeline.get("children") or pipeline.get("child_requests") or []
        s.check("the parent reports its blocking child",
                any(str(c.get("id")) == str(child) for c in children),
                f"children={[c.get('id') for c in children]}")

        body = api.call("pipeline-complete", pipeline_id=str(parent), ticket_id=str(parent),
                        stage_progress_id=str((stages[0] or {}).get("id") or 0),
                        notes="LIVE SUITE — expected to be refused (blocking child)")
        s.check("the frozen parent cannot be completed while the child is open",
                refused(body), errors_of(body))

        body = api.call("pipeline-unlink-child", child_id=str(child), pipeline_id=str(child))
        s.check("unlink-child detaches the prerequisite", body.get("success"), errors_of(body))

    # Self-approval — Guard 3. The suite's own user raised this request, so
    # completing its effect-bearing step must be refused on separation of
    # duties whatever role that user holds.
    #
    # The action is deliberately a NO-OP: server.config.update writing the
    # server's own current name back to itself. It has to be an action that
    # survives preflight, or the request could not be raised at all — but if
    # Guard 3 ever stopped working, the worst this can do is rewrite a value
    # with itself. An install would have fitted real hardware instead.
    servers = ctx.real_servers()
    update_type = ctx.template_id("Update Server Details")
    target = next((x for x in servers if x.get("server_name")), None)
    if update_type and target:
        body = ctx.create("Update Server Details",
                          "LIVE SUITE — self-approval guard, no-op update (safe to cancel)",
                          actions=[{"action_type": "server.config.update",
                                    "payload": {"config_uuid": target["config_uuid"],
                                                "fields": {"server_name": target["server_name"]}}}])
        if body.get("success"):
            pid = (body.get("data") or {}).get("pipeline_id")
            detail = api.call("pipeline-get", pipeline_id=str(pid), ticket_id=str(pid))
            pipeline = (detail.get("data") or {}).get("pipeline") or {}
            stages = pipeline.get("stages") or pipeline.get("stage_progress") or []
            if stages:
                sid = stages[0].get("id")
                api.call("pipeline-claim", pipeline_id=str(pid), ticket_id=str(pid),
                         stage_progress_id=str(sid))
                body = api.call("pipeline-complete", pipeline_id=str(pid), ticket_id=str(pid),
                                stage_progress_id=str(sid),
                                notes="LIVE SUITE — expected refusal, separation of duties")
                s.check("SEPARATION OF DUTIES: the requester cannot approve their own request",
                        refused(body), errors_of(body))
                s.check("  ...and the refusal says so",
                        "separation of duties" in errors_of(body).lower()
                        or "own request" in errors_of(body).lower(),
                        errors_of(body))
        else:
            s.skip("separation-of-duties probe", errors_of(body))
    else:
        s.skip("separation-of-duties probe", "no Update Server Details type or named server")

    # Cancel is reversible only in the sense that it is terminal — re-cancelling
    # a closed request must be refused.
    if child:
        api.call("pipeline-cancel", pipeline_id=str(child), ticket_id=str(child),
                 reason="LIVE SUITE")
        body = api.call("pipeline-cancel", pipeline_id=str(child), ticket_id=str(child),
                        reason="LIVE SUITE — expected refusal")
        s.check("an already-cancelled request cannot be cancelled again",
                refused(body), errors_of(body))

    body = api.call("pipeline-cancel", pipeline_id="99999999", ticket_id="99999999",
                    reason="LIVE SUITE")
    s.check("cancel on an unknown request is refused", refused(body), errors_of(body))

    body = api.call("pipeline-reassign", pipeline_id="99999999", ticket_id="99999999",
                    stage_progress_id="1", assignee_type="user", assignee_id="1")
    s.check("reassign on an unknown request is refused", refused(body), errors_of(body))

    body = api.call("pipeline-claim", pipeline_id="99999999", ticket_id="99999999",
                    stage_progress_id="99999999")
    s.check("claim on an unknown step is refused", refused(body), errors_of(body))


# ============================================== 8. approval-path preconditions


def test_approval_guards(ctx):
    """
    The four guards on applyStageEffect() cannot be exercised destructively
    from here — completing an effect-bearing step installs real hardware. What
    CAN be asserted without mutating anything is that every precondition those
    guards depend on is actually true in this deployment.
    """
    s, api = ctx.suite, ctx.api
    s.head("8. Approval-path preconditions (asserted without approving anything)")

    # Guard: the executor refuses an action the request type may not perform.
    # Already proven at create time in section 6; the approval-time copy reads
    # the SNAPSHOT, so what matters here is that snapshots exist.
    body = api.call("pipeline-list", limit="100")
    rows = (body.get("data") or {}).get("pipelines") or []
    open_rows = [r for r in rows if r.get("status") == "in_progress"]
    s.check("pipeline-list returns the queue", body.get("success"), errors_of(body))
    s.warn("open requests in the queue",
           f"{len(open_rows)} in_progress of {len(rows)} listed — each one is an approval "
           f"this suite deliberately does not perform")

    effect_seen = 0
    for row in open_rows[:25]:
        detail = api.call("pipeline-get", pipeline_id=str(row["id"]), ticket_id=str(row["id"]))
        pipeline = (detail.get("data") or {}).get("pipeline") or {}
        stages = pipeline.get("stages") or pipeline.get("stage_progress") or []
        if any(x.get("effect_type") for x in stages):
            effect_seen += 1
    s.check("open requests carry their effect_type snapshot (not a live join)",
            effect_seen > 0 or not open_rows,
            f"{effect_seen} of {min(len(open_rows), 25)} sampled carry one")

    # Retired effect types must never appear on a live snapshot.
    retired = {"grant_access", "grant_permission", "temporary_grant"}
    found_retired = []
    for row in open_rows[:25]:
        detail = api.call("pipeline-get", pipeline_id=str(row["id"]), ticket_id=str(row["id"]))
        pipeline = (detail.get("data") or {}).get("pipeline") or {}
        for stage in pipeline.get("stages") or pipeline.get("stage_progress") or []:
            if stage.get("effect_type") in retired:
                found_retired.append((row["id"], stage.get("effect_type")))
    if found_retired:
        s.warn("open requests carrying a retired effect",
               f"{found_retired} — these complete as pure status tracking and grant nothing")
    else:
        s.check("no open request carries a retired grant effect", True)


# ===================================================================== runner


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--writes", action="store_true",
                        help="also create requests (cancelled afterwards)")
    parser.add_argument("--json", help="write the machine-readable result here")
    parser.add_argument("--user")
    parser.add_argument("--password")
    args = parser.parse_args()

    api = Client(user=args.user, password=args.password).login()
    suite = Suite("Requests module — live")
    print(f"Endpoint : {api.url}")
    print(f"User     : {api.user} (id {api.user_id}, roles {api.roles})")
    print(f"Writes   : {'ON — requests will be created and cancelled' if args.writes else 'OFF (read-only)'}")

    ctx = Context(api, suite, args.writes)
    started = time.time()
    try:
        ctx.gather()
        test_envelope_and_auth(ctx)
        test_request_types(ctx)
        test_read_endpoints(ctx)
        test_all_component_types(ctx)
        test_create_validation(ctx)
        test_lifecycle(ctx)
        test_approval_guards(ctx)
    finally:
        ctx.cleanup()

    failures = suite.summary()
    print(f"\nElapsed: {time.time() - started:.1f}s")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump({"suite": suite.name, "endpoint": api.url,
                       "user": api.user, "writes": args.writes,
                       "counts": suite.counts(), "results": suite.results},
                      handle, indent=2)
        print(f"JSON written to {args.json}")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
