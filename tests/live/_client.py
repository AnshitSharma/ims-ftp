"""
_client.py — shared live-API client for tests/live/*.

Black-box HTTP client for the single action endpoint. Unlike tests/api/*.php,
which drive a scratch tree behind IMS_HTTP_HARNESS_URL, these suites are written
to run against a REAL deployment: the Requests engine's interesting behaviour
(location gating, stock deferral, ceilings, separation of duties) depends on
seeded request types and real inventory rows, none of which a scratch DB has.

Nothing here is deployed — tests/ is on the SFTP ignore list (CLAUDE.md).

Environment:
  IMS_API_URL   defaults to the production endpoint
  IMS_USER      defaults to superadmin
  IMS_PASS      defaults to the documented test password

Leading underscore keeps it out of any future suite glob, same convention as
tests/api/_http_harness.php.
"""

import json
import os

import requests

DEFAULT_API = "https://ims.bdcms.bharatdatacenter.com/Ims_backend/api/api.php"

# The 12 component types. Canonical list: core/helpers/BaseFunctions.php:31.
COMPONENT_TYPES = [
    "cpu", "ram", "storage", "motherboard", "nic", "caddy",
    "chassis", "pciecard", "risercard", "hbacard", "sfp", "serverplatform",
]

# ims-data spec files, mirroring core/models/components/ComponentSpecPaths.php.
# The filenames are irregular on purpose ('chasis' is a load-bearing typo);
# never guess one.
SPEC_PATHS = {
    "cpu": "cpu/Cpu-details-level-3.json",
    "motherboard": "motherboard/motherboard-level-3.json",
    "ram": "ram/ram_detail.json",
    "storage": "storage/storage-level-3.json",
    "nic": "nic/nic-level-3.json",
    "caddy": "caddy/caddy_details.json",
    "pciecard": "pciecard/pci-level-3.json",
    "risercard": "risercard/riser-level-3.json",
    "hbacard": "hbacard/hbacard-level-3.json",
    "sfp": "sfp/sfp-level-3.json",
    "chassis": "chassis/chasis-level-3.json",
    "serverplatform": "serverplatform/server-platform-level-3.json",
}

# Every action type RequestActionExecutor::ACTION_TYPES declares, with the
# required payload keys. Mirrored here so the suite can assert the live API
# still refuses a payload missing any one of them.
ACTION_REQUIRED = {
    "server.component.add": ["config_uuid", "component_type", "component_uuid"],
    "server.component.remove": ["config_uuid", "component_type", "component_uuid"],
    "server.component.replace": ["config_uuid", "component_type",
                                 "old_component_uuid", "new_component_uuid"],
    "server.config.create": ["server_name"],
    "server.config.update": ["config_uuid", "fields"],
    "server.config.transition": ["config_uuid", "to_status"],
    "server.relocate": ["config_uuid", "location_uuid"],
    "inventory.component.add": ["component_type", "data"],
    "inventory.component.edit": ["component_type", "inventory_id", "data"],
    "inventory.component.relocate": ["component_type", "inventory_id", "location_uuid"],
}


class ApiError(RuntimeError):
    pass


class Client:
    """One authenticated session against the action endpoint."""

    def __init__(self, user=None, password=None, url=None):
        self.url = url or os.environ.get("IMS_API_URL", DEFAULT_API)
        self.user = user or os.environ.get("IMS_USER", "superadmin")
        self.password = password or os.environ.get("IMS_PASS", "password123")
        self.session = requests.Session()
        self.token = None
        self.user_id = None
        self.roles = []
        self.permissions = []

    # ------------------------------------------------------------------ auth

    def login(self):
        body = self._post("auth-login", auth=False,
                          username=self.user, password=self.password)[1]
        if not body.get("success"):
            raise ApiError(f"login failed for {self.user}: {body.get('message')}")
        data = body["data"]
        self.token = data["tokens"]["access_token"]
        self.user_id = data["user"]["id"]
        self.roles = [r if isinstance(r, str) else r.get("name")
                      for r in data["user"].get("roles") or []]
        self.permissions = data["user"].get("permissions") or []
        return self

    # ------------------------------------------------------------- transport

    def _post(self, action, auth=True, **params):
        """Returns (http_status, parsed_body). Never raises on a 4xx/5xx."""
        files = {"action": (None, action)}
        for key, value in params.items():
            if value is None:
                continue
            if isinstance(value, (list, dict)):
                value = json.dumps(value)
            elif not isinstance(value, str):
                value = str(value)
            files[key] = (None, value)

        headers = {}
        if auth and self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        response = self.session.post(self.url, files=files, headers=headers, timeout=120)
        try:
            return response.status_code, response.json()
        except ValueError:
            return response.status_code, {"__nonjson__": response.text[:800]}

    def call(self, action, **params):
        """Body only — the common case."""
        return self._post(action, **params)[1]

    def raw(self, action, **params):
        """(http_status, body), for the tests that assert on the status line."""
        return self._post(action, **params)

    def anon(self, action, **params):
        """Unauthenticated call, for the gate tests."""
        return self._post(action, auth=False, **params)[1]


# ------------------------------------------------------------------ catalogue


def load_catalogue(ims_data_root):
    """
    Every model UUID ims-data describes, per component type.

    The spec files disagree about key casing ('UUID' in cpu/pciecard/riser/hba,
    'uuid' elsewhere) and about their top-level shape (list vs dict), so this
    walks the whole tree rather than assuming either.

    @return dict type -> {uuid: label}
    """
    catalogue = {}
    for ctype, relative in SPEC_PATHS.items():
        path = os.path.join(ims_data_root, relative)
        if not os.path.isfile(path):
            catalogue[ctype] = {}
            continue
        with open(path, encoding="utf-8") as handle:
            try:
                doc = json.load(handle)
            except ValueError:
                catalogue[ctype] = {}
                continue
        found = {}
        _walk_uuids(doc, found)
        catalogue[ctype] = found
    return catalogue


def _walk_uuids(node, out):
    if isinstance(node, dict):
        uuid = node.get("uuid") or node.get("UUID")
        if isinstance(uuid, str) and len(uuid) >= 32:
            label = (node.get("model") or node.get("name")
                     or node.get("model_name") or node.get("series") or "")
            out[uuid] = str(label)
        for value in node.values():
            _walk_uuids(value, out)
    elif isinstance(node, list):
        for value in node:
            _walk_uuids(value, out)


# -------------------------------------------------------------------- report


class Suite:
    """Minimal recorder. Mirrors the PASS/FAIL line format of tests/regression/*."""

    def __init__(self, name):
        self.name = name
        self.results = []
        self.section = "(unsectioned)"

    def head(self, title):
        self.section = title
        print(f"\n-- {title} --")

    def check(self, label, condition, detail=""):
        ok = bool(condition)
        print(f"  {'PASS' if ok else 'FAIL'}  {label}"
              + (f"\n          {detail}" if detail and not ok else ""))
        self.results.append({"section": self.section, "label": label,
                             "ok": ok, "detail": detail, "status": "PASS" if ok else "FAIL"})
        return ok

    def skip(self, label, why):
        print(f"  SKIP  {label}\n          {why}")
        self.results.append({"section": self.section, "label": label,
                             "ok": True, "detail": why, "status": "SKIP"})

    def warn(self, label, detail):
        """A real observation about the deployment that is not a code defect."""
        print(f"  WARN  {label}\n          {detail}")
        self.results.append({"section": self.section, "label": label,
                             "ok": True, "detail": detail, "status": "WARN"})

    def counts(self):
        out = {"PASS": 0, "FAIL": 0, "SKIP": 0, "WARN": 0}
        for row in self.results:
            out[row["status"]] += 1
        return out

    def summary(self):
        counts = self.counts()
        print(f"\n=== {self.name}: {counts['PASS']} passed, {counts['FAIL']} failed, "
              f"{counts['SKIP']} skipped, {counts['WARN']} warnings ===")
        if counts["FAIL"]:
            print("\nFailures:")
            for row in self.results:
                if row["status"] == "FAIL":
                    print(f"  [{row['section']}] {row['label']}"
                          + (f"\n      {row['detail']}" if row["detail"] else ""))
        if counts["WARN"]:
            print("\nWarnings:")
            for row in self.results:
                if row["status"] == "WARN":
                    print(f"  [{row['section']}] {row['label']}\n      {row['detail']}")
        return counts["FAIL"]
