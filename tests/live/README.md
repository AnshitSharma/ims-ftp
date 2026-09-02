# tests/live — black-box suites against a running deployment

Everything else under `tests/` is either a unit test with fakes or a DB-backed
suite that self-skips without a scratch MariaDB. Neither can answer the question
these suites exist for: **does the deployed system actually behave this way?**

That question is not answerable from source, because the Requests engine's real
behaviour depends on facts no file contains — which seeders have been run, which
request types exist, which inventory rows carry a `location_uuid`, where the
servers are. `tests/regression/location_aware_requests_test.php` greps the
shipped code for the strings the plan called for; it passes just as happily when
the seeders behind them have never been applied.

Not deployed — `tests/` is on the SFTP ignore list.

## The suites

| File | What it covers |
|---|---|
| `_client.py` | Shared HTTP client, the ims-data catalogue loader, PASS/FAIL recorder. Not a suite. |
| `requests_module_test.py` | The Requests module end to end: envelope and auth, request types and their ceilings, the form-feeding read endpoints, all 12 component types, create-time validation, lifecycle, parent/child freezing, separation of duties. |
| `install_location_test.py` | The two rules that govern fitting hardware: the part must be **where the server is**, and it must be **in inventory first**. Plus the Hardware Handover that fixes a mismatch. |

## Running them

```bash
pip install requests           # the only dependency

python tests/live/requests_module_test.py              # read-only
python tests/live/requests_module_test.py --writes     # + create/cancel
python tests/live/install_location_test.py --writes
python tests/live/requests_module_test.py --writes --json out.json
```

Exit code is non-zero if anything FAILed. `--json` writes every assertion for
diffing between runs.

Environment: `IMS_API_URL`, `IMS_USER`, `IMS_PASS` override the defaults.

## What `--writes` does, and what nothing here does

`--writes` **creates requests** and cancels every one of them in a `finally`
block, then re-reads each row to confirm the closed state rather than trusting
the cancel response. Creating is the only way to test create-time validation at
all; a request is a `tickets` row and cancelling is its designed terminal state.
Any survivor is printed with `!! LEFT OPEN` and its id.

**Neither suite ever approves anything.** Completing an effect-bearing step
performs real work — installs hardware, flips inventory status, moves stock
between sites — and neither suite can undo that. The one exception is the
separation-of-duties probe, which deliberately uses `server.config.update`
writing a server's own name back over itself, so that even a total guard failure
is a no-op.

## The approval-time gap, and how to close it

`RequestActionExecutor::locationGate()` — the refusal that stops a Noida drive
being fitted into a Jaipur server — **cannot be reached from a single account**.
`applyStageEffect()` Guard 3 refuses a self-approval before the executor is
called at all, so the only way to exercise the gate is:

1. account **A** raises the cross-site install (`install_location_test.py`
   section 5 builds exactly this request and prints its id),
2. account **B**, holding `admin` or `super_admin`, approves step 1,
3. the approval is refused with `error_code = location_mismatch` and the whole
   transaction rolls back — the request stays open and nothing is installed.

Give the suite a second account and that step can be automated; until then
section 5 raises the request, prints the id, and says plainly that the gate was
not exercised. It never claims a pass it did not earn.

## Rate limiting

The API rate-limits `auth-login`. Each suite logs in once, but running both back
to back several times will trip it; wait a minute and re-run.
