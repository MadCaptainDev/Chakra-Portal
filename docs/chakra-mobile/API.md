# Chakra Mobile API

The API the Chakra mobile app (Flutter, iOS + Android) talks to. It lives in the
Chakra Portal (Laravel), routes in `routes/mobile-api.php`. Tests:
`tests/Feature/MobileApiTest.php`. **Change this file in the same commit as the API.**

- **Base URL:** `https://chakragroups.in/api/mobile`
- **Format:** JSON in, JSON out, errors included. Send `Content-Type: application/json`
  and `Accept: application/json`.
- **Auth:** `Authorization: Bearer <token>` from `POST /login`. No cookies, no CSRF.
  Tokens start with `chakraplanner_` (they are Planner session tokens, so the same token
  also works on `/api/planner/*`; see [Planner screens](#planner-screens-same-token)).
- **Optional header:** `X-App-Version: 1.0.0`. Shown on the portal's device list.
- **Time:** the studio runs on `Asia/Kolkata` (`timezone` in `/me`). Dates are
  `YYYY-MM-DD`; timestamps are ISO 8601 with offset (`2026-11-04T19:42:10+05:30`).
  Display times exactly as given; don't convert them to the phone's zone.
- **Money:** rupees. Amounts come back as numbers or as pre-formatted strings
  (`"₹45,000"`). Show the formatted string when there is one.
- **Rate limit:** 240 requests a minute per token. Sign-in: 5 tries a minute per email + IP.

---

## Errors

```json
{ "message": "Shown to the person as-is.", "code": "machine_readable" }
```

Validation errors (422) use Laravel's shape: `{ "message": "...", "errors": { "field": ["..."] } }`.
Action errors (`/actions/*`) use `{ "ok": false, "message": "..." }`.

| Status | `code` | What the app does |
|---|---|---|
| 401 | `unauthenticated` | Token missing, wrong, revoked or signed out. Wipe the token, show sign-in. |
| 403 | `no_access` | A client account, or a feature this person lacks. Hide the feature. |
| 404 | — | Not found, or an action this person can't use. |
| 422 | `invalid_credentials` / validation / action refusal | Show `message` (and field errors). |
| 429 | `throttled` | Too many tries. `message` says how long. Back off. |
| 500 | — | Studio-side bug, already logged. Show "Something went wrong" and offer retry. |

---

## Sign in / out

### `POST /login`

```json
{
  "email": "sanjai@chakragroups.in",
  "password": "••••••",
  "device_name": "Sanjai's iPhone",
  "platform": "ios",
  "app_version": "1.0.0"
}
```

`platform` is `ios` or `android`. `device_name` is what the person sees on the device
list (use `device_info_plus` → `name`, or `"iPhone"`).

**201** returns the token **once**, plus the profile (same shape as `GET /me`):

```json
{
  "token": "chakraplanner_7kRsbh…",
  "user": {
    "id": 4, "name": "Sanjai Kumar", "first_name": "Sanjai",
    "email": "sanjai@chakragroups.in", "role": "employee",
    "avatar_url": "https://chakragroups.in/uploads/avatars/4.jpg",
    "is_admin": false, "logs_work": true
  },
  "features": {
    "today": true, "notifications": true,
    "planner": false, "planner_edit": false,
    "editing": true, "editor_updates": false, "review": false,
    "team_hours": false, "shoots_all": false,
    "admin_area": false, "manages_team": false
  },
  "actions": ["create_todo", "list_todos", "log_timesheet_entry", "my_shoots", "…"],
  "permissions": { "dashboard": ["view"], "shoots": ["view"] },
  "unread_notifications": 3,
  "session": { "id": 12, "device_name": "Sanjai's iPhone" },
  "realtime": { "enabled": false, "provider": "ably", "channel": "planner", "client_id": "user-4", "token_url": "…" },
  "portal_url": "https://chakragroups.in",
  "server_time": "2026-11-04T10:12:00+05:30",
  "timezone": "Asia/Kolkata"
}
```

Store the token in the Keychain/Keystore (`flutter_secure_storage`). It never expires.
It ends on logout, when someone revokes it on the portal (**chakragroups.in/planner/setup**: "Signed in on" for your own devices if you have Planner access; admins see and can sign out every device, so a lost phone is an admin job),
or when the account becomes a client.

- `features.*` decides which tabs and buttons to show. Don't show what is `false`.
- `actions` lists the action names this person may run (see [Actions](#actions)).
  Hide any button whose action isn't in the list.
- `permissions` is module → abilities, exactly as the portal's gates answer (an admin gets
  every module and ability). Abilities: `view`, `create`, `edit`, `delete`, plus
  module-specific ones such as `approve` (invoices), `comment` (proposals) and `manage`.
- **Admin area:** show it when `features.admin_area` is true (admins, or anyone who can
  see invoices or edit shoots). `user.is_admin` is the full-admin flag. Gate each
  admin screen on its own action being in `actions`, not on `is_admin`.
- `features.manages_team` is true for managers who have people reporting to them.

Errors: `422 invalid_credentials`, `403 no_access` (client account), `429 throttled`.

### `GET /me`

Same as the login body without `token`. Call it on app start and resume to refresh
`features`, `actions` and the unread badge.

### `POST /logout`

Body (optional): `{ "push_token": "<FCM token>" }`, which also stops push to this phone.
**204.** Then wipe the token locally.

---

## Today (home screen + widgets)

### `GET /today` · `GET /today?date=2026-11-05`

The home screen and every home-screen widget come from this. `?date=` moves
**shoots** and **reels** to another day (±92 days). Hours and to-dos are always today's.
`?fresh=1` (admins) syncs the reel board from Notion first. That can take 20 s, so send it
only from a pull-to-refresh, never from a widget.

Sections are present **only if the person may see them**. Treat every section as optional.

```json
{
  "date": "2026-11-04",
  "date_label": "Wed, 4 Nov",
  "name": "Sanjai",
  "generated_at": "10:12 AM",
  "portal_url": "https://chakragroups.in/my",

  "hours": {
    "today_minutes": 135, "today_label": "2h 15m",
    "week_minutes": 1260, "week_label": "21h",
    "entries": 3, "url": "https://chakragroups.in/my/timesheet"
  },
  "team_hours": { "today_minutes": 840, "today_label": "14h", "people": 6 },

  "day": { "date": "2026-11-04", "label": "Wed, 4 Nov", "is_today": true,
           "prev": "2026-11-03", "next": "2026-11-05" },

  "shoots": {
    "count": 1,
    "items": [{ "id": 41, "starts_at": "2026-11-04T11:30:00+05:30",
                "title": "Squad Architects – office reel", "client": "Squad Architects",
                "time": "11:30 AM", "location": "Anna Nagar", "status": "live" }],
    "url": "https://chakragroups.in/shoots"
  },
  "todos": {
    "count": 4, "overdue": 1,
    "items": ["Edit SVA reel v2", "…"],
    "open": [{ "id": 311, "title": "Edit SVA reel v2", "status": "started", "due_on": "2026-11-03", "overdue": true }],
    "url": "…/my/todos"
  },

  "inbox": {
    "done": 6, "total": 12, "left": 6, "late": 1, "accounts_left": 2,
    "accounts": [{ "handle": "@squad", "routine": "Morning check", "left": 2, "late": false,
                   "checks": [{ "name": "DMs", "short": "DM", "state": "done", "late": false, "new": 3 }] }],
    "last": { "text": "Priya · DMs · @squad" }
  },

  "reels": {
    "date": "2026-11-04", "total_posting": 5,
    "counts": { "to_be_edited": 1, "edit_in_progress": 2, "under_review": 1, "posted": 1 },
    "items": [{ "title": "Diwali offer", "editor": "Sanjai", "status": "Edit in Progress" }],
    "url": "…/content-dashboard"
  }
}
```

| Section | Who gets it |
|---|---|
| `hours` | People who log work (`user.logs_work`) |
| `team_hours`, `reels` | Admins |
| `shoots` | Everyone. All shoots with `features.shoots_all`, otherwise only shoots they are crew on |
| `todos` | Everyone (their own) |
| `inbox` | Instagram DM/comment checkers, and admins |

`shoots.items[].status`: `planned` · `confirmed` · `live` (rolling now) · `completed`.
`time` is `null` for all-day shoots. Show "All day" in that case.
`todos.open` is the first three open to-dos with ids (tick one off with `set_todo_status`);
`todos.items` is the same three as plain titles (kept for the Scriptable widget).

---

## Notifications (the inbox)

Every notification the portal raises for a person is stored here, **whether or not push is
set up**. On an iPhone built with a free Apple account there is no remote push, so
this list is how notifications reach the person. The app polls it and shows a *local*
notification for anything new (see README → Notifications).

### `GET /notifications`

| Query | Meaning |
|---|---|
| `after=<id>` | Only newer than this id. Poll with the newest id you have. An empty `data` means nothing new. |
| `before=<id>` | Paging backwards (infinite scroll). Use `next_before` from the last page. |
| `unread=1` | Unread only. |
| `limit` | 1–100, default 30. |

```json
{
  "data": [
    {
      "id": 812,
      "type": "shoot_reminder_due",
      "title": "Shoot in 30 minutes",
      "body": "Squad Architects · Anna Nagar",
      "url": "https://chakragroups.in/shoots/41/call-sheet",
      "path": "/shoots/41/call-sheet",
      "tag": "shoot-41",
      "read": false,
      "read_at": null,
      "created_at": "2026-11-04T11:00:02+05:30"
    }
  ],
  "unread": 3,
  "latest_id": 812,
  "next_before": 783
}
```

Newest first. `tag` groups updates about the same thing (use it as the local
notification's thread id). Tapping one opens `path`. Map the paths you have
screens for (table below). For anything else, open `url` in an in-app browser.

`type` values (switch icons on these; unknown types get a generic bell):

| `type` | When | `path` |
|---|---|---|
| `todo_assigned` | Someone gave you a to-do | `/my/todos` |
| `todo_sent_back` | Your finished to-do was sent back | `/my/todos` |
| `shoot_crew_added` | You were put on a shoot | `/shoots/{id}/call-sheet` |
| `shoot_reminder_due` | A shoot you're on starts soon | `/shoots/{id}/call-sheet` |
| `shoot_requested` | A client requested a shoot | `/shoots/{id}` |
| `shoot_content_missing` | A shoot has no content linked | `/shoots/{id}` |
| `video_not_published` | A video was due to post and hasn't | `/my/editing` (admins: `/editor-updates`) |
| `content_depletion_warning` | A client is running out of ready content | `/forecast/{client}` |
| `script_approved` · `script_changes_requested` | Client reviewed a script | `/writing/{id}` |
| `brief_submitted` | Client filled their brand brief | `/clients/{id}` |
| `proposal_commented` | Client commented on a proposal | `/proposals/{id}` |
| `enquiry_received_push` | New website enquiry | `/enquiries/{id}` |
| `timesheet_day_rejected` | Manager rejected a timesheet day | `/my/timesheet` |
| `announcement_posted` | New studio announcement | `/announcements` |
| `daily_digest_ready` | Morning brief is ready | `/dashboard` |

New types get added over time. Always handle an unknown `type` and `path`.

### `POST /notifications/read`

`{ "ids": [812, 811] }` marks those read. An empty body marks **all** read.
Returns `{ "marked": 2, "unread": 1 }`. Set the app badge to `unread`.

---

## Push notifications (needs a paid Apple account on iOS)

### `POST /push-token`

```json
{ "token": "<firebase_messaging getToken()>", "platform": "ios", "device_name": "Sanjai's iPhone" }
```

**201** `{ "id": 9, "push_configured": true }`. `push_configured: false` means the
portal's Firebase keys aren't saved yet (portal → `/push`). Register anyway: pushes
start as soon as they are.

Call it after sign-in, and again whenever `onTokenRefresh` fires. A token moves to whoever
registered it last, so a phone handed to a colleague notifies them, not you.

### `DELETE /push-token`

`{ "token": "…" }` → **204**. Call it on logout (or send `push_token` to `/logout`).

**What a push looks like:** FCM `notification` = `{title, body}` (iOS/Android draw the
banner), plus `data` = `{title, body, url, tag}` with string values. On tap, route by
`data.url`. It is a full URL; take its path (`/shoots/41/call-sheet`). Every push also lands in `/notifications`, so
after a tap call `GET /notifications?after=…` to sync.

---

## My Editing (video editing board)

Exactly the endpoints and shapes in [`../chakra-planner/API.md` → Editing widget](../chakra-planner/API.md#editing-widget),
mounted here too:

| Method | Path | |
|---|---|---|
| `GET` | `/editing` | Your cards, team ready-to-post, review queue, suggestions |
| `POST` | `/editing` | Add a video to edit (`title` or `content_item_id`) |
| `POST` | `/editing/{id}/act` | `{ "action": "start" \| "done" \| "hold" \| "ready" \| "post" \| "deliver" \| … }` |

Who may do what is decided by the server (start/hold/done = the editor; post/deliver =
anyone; review = admins). Show the server's `message` on 403/422.

---

## Actions

Everything else the portal can do (to-dos, timesheet, shoots, invoices, scripts,
proposals, expenses and about 80 more) goes through **one** pair of endpoints. They run the
same tool classes as the portal's Claude connector and WhatsApp assistant, under the person's
own role and permissions. One rulebook, no duplicated logic.

### `GET /actions`

```json
{
  "data": [
    {
      "name": "set_todo_status",
      "title": "Set todo status",
      "group": "General",
      "description": "Move one of your to-dos to waiting/started/blocked/completed/cancelled…",
      "input_schema": {
        "type": "object",
        "properties": {
          "id": { "type": "integer", "description": "The to-do's id, from list_todos." },
          "status": { "type": "string", "enum": ["waiting", "started", "blocked", "completed", "cancelled"] },
          "note": { "type": "string", "description": "Required when marking something blocked." }
        },
        "required": ["id", "status"]
      },
      "read_only": false,
      "destructive": false,
      "messages_client": false
    }
  ]
}
```

Only actions this person may run are listed. `input_schema` is JSON Schema. You can
build forms from it, but hand-built screens for the everyday ones are nicer.

> **`messages_client: true` sends a WhatsApp message to a real client.** Always show a
> confirmation sheet ("Send to Squad Architects on WhatsApp?") before running it.

### `POST /actions/{name}`

The body is the arguments object (`{}` if none).

```http
POST /api/mobile/actions/set_todo_status
{ "id": 311, "status": "completed" }
```

- **200** `{ "ok": true, "result": <the action's own JSON> }`. `result` is an object,
  array or string, depending on the action.
- **422** `{ "ok": false, "message": "That to-do isn't yours." }`: the action refused.
  Show `message`. **Never auto-retry a write.**
- **404** `{ "ok": false, "message": "No such action, or your account cannot use it: …" }`
- **500** `{ "ok": false, "message": "That did not work at the studio's end. It has been logged." }`

Every call is logged on the portal (Developer → Activity).

### Admin actions

The full list with arguments and result shapes is in **[ADMIN-ACTIONS.md](ADMIN-ACTIONS.md)**.
These were added for the app's admin area (2026-11-04):

| Action | Permission | Arguments | Notes |
|---|---|---|---|
| `list_invoices` | invoices.view | `status` (`open`·`overdue`·`partial`·`unpaid`·`pending_approval`·`paid`·`all`), `client`, `from`, `to`, `search`, `limit` | Rows with total/paid/balance, newest first. Default `open` |
| `record_invoice_payment` | invoices.edit | `invoice*`, `amount` (default = full balance), `paid_on`, `method`, `note` | "Mark paid" = just `invoice`. Refuses overpaying. Doesn't message anyone |
| `approve_invoice` | invoices.approve | `invoice*` (id) | Pending draft → numbered, unpaid |
| `send_invoice_whatsapp` ✉️ | invoices.approve | `invoice*`, `phone` | Template + PDF link to the client |
| `update_shoot` | shoots.edit | `shoot_id*`, `title`, `starts_at`, `ends_at`, `client`, `location`, `status` (`planned`·`confirmed`·`completed`·`cancelled`), `notes`, `remove_crew` | Only given fields change; `""` clears optional fields |
| `run_shoot` | crew on the shoot, or admin | `shoot_id*`, `action*` (`on_the_way`·`reached`·`start`·`wrap`) | `start` makes it live; `wrap` completes it. **"live" is not a status.** |
| `get_shoot` | shoots.view or on the crew | `shoot_id*` | Full detail incl. crew, roles, call times, run times |
| `assign_shoot_crew` | shoots.edit | `shoot_id*`, `person*`, `role`, `call_time` | Adds or updates; pushes the person |
| `list_team` | any staff | — | For crew / assignee pickers |
| `list_clients` | clients.view | `search`, `include_inactive` | For client pickers |

### The everyday ones

| Screen | Action | Arguments |
|---|---|---|
| To-dos list | `list_todos` | `date?`, `scope?` (`own`/`assigned`), `open_only?` |
| To-do status | `set_todo_status` | `id`, `status`, `note?` (required for `blocked`) |
| New to-do | `create_todo` | `title`, `person?`, `client?`, `starts_on?`, `due_on?`, `notes?` |
| My shoots | `my_shoots` | none |
| Shoots in a range | `shoots_between` | `from`, `to` (needs shoots.view) |
| Rolling now | `shoots_rolling_now` | none |
| Log hours | `log_timesheet_entry` | `task`, `date?`, `type?` (`shooting`/`editing`/`posting`/`other`), `client?`, `start?`+`end?` **or** `minutes?`, `notes?` |
| My timesheet | `list_timesheet` | see `/actions` |
| Timesheet hint | `timesheet_hint` | see `/actions` (suggests what to log from today's activity) |
| Find a client | `find_client` | `query` |
| Money due (admin) | `money_due` | none |
| Needs attention (admin) | `needs_attention` | none |
| Who am I | `whoami` | none |
| Team picker | `list_team` | none |
| Shoot detail | `get_shoot` | `shoot_id` |
| Shoot day buttons | `run_shoot` | `shoot_id`, `action` (`on_the_way`/`reached`/`start`/`wrap`) |

Read exact argument names and descriptions from `GET /actions`. That list is
always current; this table is a guide.

---

## Planner screens (same token)

The content-planner calendar is the Chakra Planner API. The same token works on it:
`https://chakragroups.in/api/planner/items`, `/items/{id}`, `/meta`, `PATCH /items/{id}`.
See [`../chakra-planner/API.md`](../chakra-planner/API.md). Show the Planner tab only when
`features.planner` is true. Date moves need `features.planner_edit`.

---

## Live updates

The host can't hold websockets. Options, best first:

1. **Push** (paid Apple account): instant.
2. **Ably** for the planner, when `realtime.enabled` (see the Planner API doc).
3. **Polling** while the app is open: `/notifications?after=` every 60 s, `/today` every
   2–5 min and on resume. Stay well under the rate limit.

---

## Portal side (reference)

- `routes/mobile-api.php`, `app/Http/Controllers/Api/Mobile/*`, `app/Http/Middleware/AuthenticateMobileToken.php`
- Inbox: `app_notifications` table, `App\Models\AppNotification`, written by `App\Notifications\Channels\FcmChannel`
- Native push payload: `App\Services\Push\FcmTransport::buildMessage(..., native: true)`; tokens with `device_kind` `ios_app`/`android_app`
- Actions: `App\Mcp\Server::runForApp()` → `app/Tools/*`
- Today: `App\Http\Controllers\Api\WidgetController::today`
