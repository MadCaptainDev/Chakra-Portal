# Backup + license API — integration guide

For anyone writing the client side of this: the backup/restore and AMC-licensing script that runs
**on the client-built software's own server** (DJ Thangamaaligai's ERP is the first). Chakra Portal
is the server here; that other codebase is the client. Nothing in this document requires changing
anything in Chakra Portal itself.

## Getting a token

An admin creates the product under **SaaS Products** in Chakra Portal (`/saas-products`) and is shown
a token exactly once, on screen, right after creation — e.g. `saas_aB3x...`. Copy it into wherever
that server's backup/license script reads its own configuration from (an env var, a config file —
your choice, Chakra Portal has no opinion on this). It cannot be retrieved again; if it's lost, delete
and re-create the product to get a new one (this also retires the old token immediately).

Every request below sends it as a bearer token:

```
Authorization: Bearer saas_aB3x...
```

There is no session, no cookie, no CSRF token — just this header. All endpoints are rate-limited to
20 requests/minute per token, which is generous for a handful of scheduled backups and license checks
a day; a script hammering the endpoint in a retry loop is the case that limit exists to catch.

## Uploading a backup

Run this twice a day (or however often makes sense) from a cron job on the ERP's own server:

```bash
curl -X POST https://chakragroups.in/api/saas/backups \
  -H "Authorization: Bearer saas_aB3x..." \
  -F "file=@/path/to/backup.sql.gz" \
  -F "taken_at=2026-08-22T02:00:00+05:30"
```

- `file` — required. Any file, up to 1 GB. Nothing about its name is trusted or preserved as the
  storage path — send whatever the backup tool actually produced.
- `taken_at` — optional, any format `strtotime()` understands. Defaults to the moment the request
  arrives if omitted. This is what backups are sorted and pruned by, so if the backup itself took a
  while to produce, send the time it started, not the time the upload happened to run.

Response (`201 Created`):

```json
{
  "id": 42,
  "taken_at": "2026-08-22T02:00:00+05:30",
  "size_bytes": 18874368,
  "checksum": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b85"
}
```

`checksum` is a SHA-256 of the exact bytes Chakra Portal received — compare it against a local
checksum of the file you sent if the upload script wants to confirm nothing got mangled in transit.

**Retention is automatic.** Each product keeps a configured number of most-recent backups (20 by
default, set per product in Chakra Portal); once a new upload pushes the count over that, the oldest
is deleted — both the database record and the file itself. Nothing needs to call a cleanup endpoint.

## Listing and restoring a version

```bash
curl https://chakragroups.in/api/saas/backups \
  -H "Authorization: Bearer saas_aB3x..."
```

```json
{
  "data": [
    { "id": 42, "taken_at": "2026-08-22T02:00:00+05:30", "size_bytes": 18874368, "checksum": "e3b0..." },
    { "id": 41, "taken_at": "2026-08-21T02:00:00+05:30", "size_bytes": 18811904, "checksum": "9f2a..." }
  ]
}
```

Newest first. A restore script picks the `id` it wants (usually the newest, or a specific date after
something went wrong) and downloads it:

```bash
curl https://chakragroups.in/api/saas/backups/42/download \
  -H "Authorization: Bearer saas_aB3x..." \
  -o restored-backup.sql.gz
```

A backup id that belongs to a *different* product (wrong token) comes back as a plain `404`, not
`403` — the token has no way to learn that id even exists, deliberately.

## Checking the license

This is the endpoint the ERP itself calls — on startup, or on a timer — to decide whether to keep
running normally, show a "please pay" notice, or refuse to serve requests:

```bash
curl https://chakragroups.in/api/saas/license \
  -H "Authorization: Bearer saas_aB3x..."
```

```json
{
  "status": "active",
  "message": "Active.",
  "amc_paid_until": "2027-08-22"
}
```

`status` is one of:

| status | meaning | what the ERP should do |
|---|---|---|
| `active` | AMC is paid up, or has never lapsed | run normally |
| `overdue` | the AMC paid-until date has passed | keep running, but show `message` somewhere visible in the ERP's own UI (a top banner is the obvious place) |
| `suspended` | an admin at Chakra explicitly suspended it | Chakra Portal's stance stops here — **it has no way to reach into your server and stop anything itself.** What "suspended" actually does (show a hard block screen, refuse writes, shut down entirely) is entirely up to how the ERP itself is written to react to this status. This is a cooperative model, the same as any commercial license-key SDK: the software has to call this endpoint and honour the answer. |

`message` is plain text meant to be shown to the ERP's own users verbatim — it already says the right
thing for `overdue` and `suspended` and changes if Chakra's wording changes, so don't hardcode your
own copy of these strings on the client side.

`amc_paid_until` is `null` if AMC has never been billed for this product yet (treated the same as
`active` — nothing is overdue if nothing has ever been due).

**Suggested integration**: check this once when the ERP starts up, and again on a timer (hourly is
plenty — this is not meant to be checked per-request). Cache the last known answer somewhere so a
momentary network blip against Chakra Portal doesn't itself look like a suspension.

## What Chakra Portal can't do

Worth being explicit about, since "Admin can suspend their website" undersells how this actually
works: Chakra Portal can only ever answer the license check *truthfully*. It has no access to the
ERP's own server, database, or process, and can't forcibly stop anything running there. Suspension is
enforced entirely by the ERP's own software choosing to honour what `/api/saas/license` tells it —
which is why the endpoint exists at all, and why it's worth calling on a schedule rather than once at
install time and never again.
