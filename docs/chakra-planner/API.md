# Chakra Planner API

The API the Chakra Planner desktop app (Windows / Mac) talks to. It lives in the
Chakra Portal (Laravel), routes in `routes/planner-api.php`.

- **Base URL:** `https://chakragroups.in/api/planner`
- **Format:** JSON in, JSON out, errors included. Send `Content-Type: application/json`.
- **Auth:** `Authorization: Bearer <token>` from `POST /login`. No cookies, no CSRF.
- **CORS:** open for `/api/*`, so `fetch` straight from the Tauri webview works
  (`tauri://localhost`, `http://tauri.localhost`, `http://localhost:1420` in dev).
- **Optional header:** `X-Planner-Version: 0.1.0`. The portal shows it on the
  signed-in-computers list.
- **Time:** the studio runs on `Asia/Kolkata`. Dates are `YYYY-MM-DD`;
  timestamps are ISO 8601 with offset (`2026-10-11T19:42:10+05:30`).
- **Rate limit:** 300 requests a minute per token (per IP before sign-in).
  Sign-in has its own limit: 5 tries a minute per email + IP.

## Errors

```json
{ "message": "Shown to the person as-is.", "code": "machine_readable" }
```

Validation errors (422) are Laravel's shape:
`{ "message": "...", "errors": { "published_date": ["..."] } }`.

| Status | `code` | Meaning / what the app does |
|---|---|---|
| 401 | `unauthenticated` | Token missing, wrong or signed out. Show the sign-in screen. |
| 403 | `no_access` | The person lost the Chakra Planner permission. Sign out and show the message. |
| 403 | `no_edit` | Viewer tried to change something. Should not happen if the UI respects `abilities.edit`. |
| 403 | `no_manage` | Release upload without Manage. |
| 404 | — | Item does not exist (or is not a planner item). |
| 404 | `realtime_off` | No Ably key saved. Use polling. |
| 409 | `conflict` | Someone else changed it first. The body has the current `item`. Show it and the message. |
| 422 | `invalid_credentials` / validation | Wrong password, bad input. |
| 429 | `throttled` | Too many sign-in attempts (`message` says how long), or the general rate limit. |
| 502 | `notion_failed` | Notion refused or is down. **Nothing was changed.** Show the message and keep the old value. |

## Permissions

Set per person in the portal (Users → Permissions → Chakra Planner). Admins have
all three.

| Ability | Gives |
|---|---|
| `view` | Sign in, see every planner, Refresh, live updates |
| `edit` | Move publish dates (written to Notion) |
| `manage` | Upload releases. In the portal: Ably key, Notion webhook, everyone's computers |

The token is checked against the permission on **every** request, so taking it
away locks every device out at once (`403 no_access`).

---

## Sign in / out

### `POST /login`

```json
{ "email": "sanjai@…", "password": "…", "device_name": "Sanjai's MacBook Air",
  "platform": "darwin-aarch64", "app_version": "0.1.0" }
```

`201`, the profile (same as `GET /me`) plus the token:

```json
{
  "token": "chakraplanner_…",
  "user": { "id": 7, "name": "Sanjai", "email": "sanjai@…", "role": "employee" },
  "abilities": { "view": true, "edit": true, "manage": false },
  "session": { "id": 12, "device_name": "Sanjai's MacBook Air" },
  "realtime": {
    "enabled": true, "provider": "ably", "channel": "planner",
    "client_id": "user:7",
    "token_url": "https://chakragroups.in/api/planner/realtime/token"
  },
  "notion": { "connected": true, "last_synced_at": "2026-10-11T13:14:54+05:30" },
  "server_time": "2026-10-11T19:42:10+05:30"
}
```

The token never expires on its own. Store it in the OS keychain. It ends when the
person signs out, an admin signs that computer out in the portal, or the
permission is removed.

### `GET /me`
The profile above, without `token`. Call it on start to refresh `abilities`
and `realtime`.

### `POST /logout`
`204`. The token stops working.

---

## Items

A planner item is one row from one of the four Notion content planners:
`reel`, `post`, `story`, `youtube`. About 2,400 exist; most are old and
Published.

### Item (list / event shape)

```json
{
  "id": 1834,
  "source": "reel",
  "title": "SVA - Sharara Kurti Set",
  "client": "SVA Silks",
  "status": "To Be Edited",
  "status_color": "orange",
  "published_date": "2026-10-14",
  "shoot_date": "2026-10-09",
  "editor": "Sanjai",
  "assigned_user": { "id": 7, "name": "Sanjai" },
  "tier": "Tier 1",
  "post_type": null,
  "shoot": { "title": "SVA Diwali shoot", "date": "2026-10-09" },
  "editing": [ { "user": { "id": 7, "name": "Sanjai" }, "status": "started" } ],
  "has_script": true,
  "notion_url": "https://www.notion.so/…",
  "missing_from_notion": false,
  "last_change": { "field": "published_date", "from": "2026-10-12", "to": "2026-10-14",
                   "origin": "planner", "by": { "id": 7, "name": "Sanjai" },
                   "at": "2026-10-11T19:40:02+05:30" },
  "updated_at": "2026-10-11T19:40:02+05:30"
}
```

| Field | Notes |
|---|---|
| `client` | Notion's Venture text. Use it as the client name and filter value. |
| `status` | Exactly as in Notion. Can be `null`. Each planner has its own options, see `GET /meta` → `statuses`. |
| `status_color` | Notion's colour for that option: `default gray brown orange yellow green blue purple pink red`. |
| `published_date` | The date the planner is about. `null` = not scheduled. **The only editable field for now.** |
| `editor` | Notion's free-text editor name. `assigned_user` is the portal person (may be null). |
| `editing` | My Editing cards made from this item: who is editing and how far (`waiting` / `started` / `blocked` = on hold / `completed` / `posted`). |
| `missing_from_notion` | The page was deleted in Notion. Only appears in `updated_since` deltas, so the app can remove it. |
| `last_change.origin` | `planner` (moved in this app) or `notion` (changed in Notion, via webhook). |

### `GET /items`

| Query | Meaning |
|---|---|
| `source` | Comma list: `reel,post` |
| `status` | Comma list, exact Notion names |
| `client` | Exact client name |
| `assigned_user_id` | Portal user id |
| `from`, `to` | `published_date` range, inclusive, `YYYY-MM-DD` |
| `undated` | `1` = only items without a publish date |
| `q` | Title contains (case-insensitive) |
| `updated_since` | ISO timestamp. Delta: everything changed at or after it, **including** items now `missing_from_notion` |
| `page`, `per_page` | Default 500, max 1000 |

Sorted by `published_date` (undated last), then `id`.

```json
{ "data": [ …items… ],
  "meta": { "page": 1, "per_page": 500, "total": 2364, "last_page": 5,
            "server_time": "2026-10-11T19:42:10+05:30" } }
```

**Keeping a local copy (recommended):** on first start, page through
`GET /items?per_page=1000` and store everything. After that, call
`GET /items?updated_since=<meta.server_time of the previous call>` on start, on
reconnect, and after an `items.refreshed` event. Keep the new `server_time` each
time. `server_time` is taken before the query runs, so nothing falls in the gap.
Rows may repeat; upsert by `id`. A full Notion sync touches every row, so the
delta after a sync is everything. That's fine at this size.

### `GET /items/{id}`

The item plus the heavy fields, for the side panel:

```json
{ "item": { …item…,
  "script": "Plain text from Notion's Script property",
  "show_notes": null, "ssd": null, "effort_hours": null,
  "insta_csv_link": null, "yt_csv_link": null,
  "notion_created_at": "2026-09-01T10:00:00+05:30",
  "portal_script_url": "https://chakragroups.in/writing/42",
  "history": [ { "field": "published_date", "from": "…", "to": "…",
                 "origin": "planner", "by": { "id": 7, "name": "Sanjai" }, "at": "…" } ]
} }
```

`history` is the newest 50 changes. `field` is `published_date` or `status`.
`by` is `null` for changes made in Notion.

### `PATCH /items/{id}`: move the publish date (needs `edit`)

```json
{ "published_date": "2026-10-16", "from": "2026-10-14" }
```

- `published_date`: required key; `YYYY-MM-DD` or `null` to unschedule.
- `from`: the date the app was showing. **Always send it.** With it, the move
  is refused (409) if anyone changed the date first, in the app or in Notion.
  Without it, the move overwrites whatever is there.

What happens: the portal reads the page from Notion, checks `from`, writes the
new date **to Notion**, then rebuilds the portal row from what Notion returns,
records the change, and broadcasts it. A time of day, an end date or a time zone
already on the Notion date is kept; only the day moves. Takes about 1–2 seconds.

| Result | Body |
|---|---|
| `200` | `{ "item": {…detail…} }` |
| `409 conflict` | `{ "message": "Someone already moved this to Tue 15 Oct.", "code": "conflict", "item": {…current…} }`. Put the returned item in the cache and tell the person. |
| `502 notion_failed` | `{ "message": "Notion is busy (rate limited). Try again in a few seconds.", "code": "notion_failed" }`. Nothing changed. Undo the optimistic move. |

Do the move optimistically in the UI (drag on the calendar, change the cell),
then reconcile with the response. Turn the optimistic change back on 409 or 502.

---

## Vocabulary

### `GET /meta`

```json
{
  "sources": [ { "key": "reel", "label": "Reel", "count": 1217 }, … ],
  "statuses": {
    "reel": [ { "name": "Idea", "color": "brown" }, { "name": "To Be Shooted", "color": "yellow" }, … ],
    "post": [ … ], "story": [ … ], "youtube": [ … ]
  },
  "status_groups": {
    "published": ["Published"], "scheduled": ["Scheduled"],
    "in_progress": ["Video Ready", "Under Review", "Edit in Progress", "To Be Edited", "To Be Shooted"],
    "idea": ["Idea"], "canceled": ["Canceled"]
  },
  "colors": ["default", "gray", "brown", "orange", "yellow", "green", "blue", "purple", "pink", "red"],
  "clients": [ { "name": "SVA Silks", "count": 312 }, … ],
  "people": [ { "id": 7, "name": "Sanjai" }, … ],
  "notion": { "last_synced_at": "2026-10-11T13:14:54+05:30" }
}
```

`statuses` comes from the Notion databases themselves, in Notion's order with
Notion's colours. It is cached for 6 hours and refreshed by every sync. The
planners don't share one vocabulary: Reel has `Hold` and `Delivered`, and the
two Post databases differ. Use `statuses[item.source]` for that item's options.

---

## Refresh

### `POST /refresh`

Pulls all planners from Notion now. Takes **10–20 seconds** when it runs, so show
a spinner. If a sync finished in the last minute, or one is already running, it
returns at once with `refreshed: false`. Several people pressing it together
run it once.

```json
{ "refreshed": true, "last_synced_at": "…", "message": "Refreshed from Notion." }
```

Afterwards every open app gets an `items.refreshed` event (see below). Fetch the
delta then, not from this response.

Notion is also synced every night at 1:00 AM. With Notion webhooks set up,
single edits made in Notion arrive within about a minute without anyone
pressing Refresh.

---

## Live updates (Ably)

The host can't run a websocket server, so live updates go through **Ably**.
When `realtime.enabled` is false (no key saved yet), poll
`GET /items?updated_since=` every 10 seconds while the window is focused, and
every 60 seconds when it isn't.

### `POST /realtime/token`

Returns a **signed Ably TokenRequest** for this person (`subscribe` and
`presence` on channel `planner` only, as `clientId` `user:{id}`, for 1 hour).
Don't call it yourself. Hand it to the Ably SDK as `authUrl`:

```ts
import * as Ably from 'ably';

const ably = new Ably.Realtime({
  authUrl: `${BASE}/api/planner/realtime/token`,
  authMethod: 'POST',
  authHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
});
const channel = ably.channels.get('planner');

channel.subscribe('item.updated', (msg) => upsertItem(msg.data.item, msg.data.by));
channel.subscribe('items.refreshed', () => fetchDelta());
channel.subscribe('settings.updated', () => {/* ignore, or re-read /me */});

// Who is here, and what they have open (Notion's avatars):
await channel.presence.enter({ name: me.name, viewing: null, view: 'calendar' });
channel.presence.update({ name: me.name, viewing: itemId, view: 'table' });
channel.presence.subscribe(() => channel.presence.get().then(setPeople));
```

The SDK renews the token through `authUrl` before it expires.

### Events on `planner`

| Name | `data` | When |
|---|---|---|
| `item.updated` | `{ "item": {…item…}, "by": { "id": 7, "name": "Sanjai" } \| null }` | A date moved, a Notion webhook changed it, it went missing in Notion, someone assigned it, or its My Editing card moved. `by` is null when the change came from Notion. Sent once per item per change, right after the change. |
| `items.refreshed` | `{ "at": "…", "message": "…" }` | A full Notion sync finished (Refresh button or nightly). Fetch the delta. |
| `settings.updated` | `{ "at": "…" }` | An admin saved the Ably key (test message). |

Events are hints, not the record. If one is missed (offline, asleep), the next
`updated_since` delta catches up. Always do a delta on reconnect
(`ably.connection.on('connected', fetchDelta)`).

---

## App updates (public, no token)

### `GET /app/update/{target}/{arch}/{current_version}`

Tauri v2's dynamic updater format. Configure
`https://chakragroups.in/api/planner/app/update/{{target}}/{{arch}}/{{current_version}}`.

- `204` when there is nothing newer.
- `200` when there is:

```json
{ "version": "0.2.0", "notes": "Calendar drag", "pub_date": "2026-10-20T08:00:00Z",
  "url": "https://chakragroups.in/uploads/planner/0.2.0/….app.tar.gz",
  "signature": "<contents of the .sig file>" }
```

`{target}-{arch}` is one of `windows-x86_64`, `darwin-aarch64`, `darwin-x86_64`.

### `GET /app/latest`
`{ "platforms": { "darwin-aarch64": { "version", "label", "download_url", "notes", "pub_date" }, … } }`

### `GET /app/download/{platform}`
Redirects to the newest installer for that platform (`.exe`, or `.dmg` on Mac).
The portal's Chakra Planner page links here.

### `POST /app/releases` (token with `manage`; used by CI)

`multipart/form-data`:

| Field | |
|---|---|
| `version` | `0.2.0` (semver) |
| `platform` | `windows-x86_64` / `darwin-aarch64` / `darwin-x86_64` |
| `file` | The **updater** artifact: Windows NSIS `…-setup.exe`; Mac `….app.tar.gz` |
| `signature` | Contents of that artifact's `.sig` file |
| `installer` | Optional. What people download: the Mac `.dmg`. Leave it out on Windows (the setup .exe is both). |
| `notes` | Release notes shown in the update prompt |

`201 { "release": { "id", "version", "platform", "url", "installer_url" } }`.
Uploading the same version and platform again replaces it.

---

## Portal side (for reference, not called by the app)

- **Chakra Planner page:** `https://chakragroups.in/planner`. Downloads,
  install steps, your signed-in computers. With Manage, also the Ably key,
  the Notion webhook token and everyone's computers.
- **Notion webhook receiver:** `POST https://chakragroups.in/webhooks/notion`.
- **Tables:** `planner_sessions`, `planner_settings`, `planner_releases`,
  `content_item_changes`; items are `content_items`.
- **Tests:** `tests/Feature/PlannerApiTest.php`.
