# Chakra Planner: brief for the app session

You are building **Chakra Planner**, a desktop app for **Windows and Mac** that
lets the Chakra Productions team plan content the way they do in Notion. It is a
**separate app** (its own repository) that is **connected to the Chakra Portal**
through an API that already exists and is live.

Read this file first, then [`API.md`](API.md). Everything the app needs from the
server is in API.md. **Do not change the portal**; it belongs to another
session. If the API is missing something, write down what you need and ask the
user.

---

## 1. Context

- Chakra Productions is a video and social-media studio in India. The **Chakra
  Portal** (`https://chakragroups.in`, Laravel) runs the studio: timesheets,
  shoots, invoices, and **My Editing** (each editor's video board).
- Content is planned in **Notion**, in four planner databases: **Reel**,
  **Post**, **Story**, **YouTube**. The portal keeps a copy of all four
  (~2,400 items) and syncs them nightly, on a Refresh button, and within about a
  minute through Notion webhooks.
- The long-term goal is a "Notion of Chakra" so the team can stop depending on
  Notion. **For now Notion stays the master copy.** The app shows the Notion
  data and moves publish dates. The portal writes every date change back to
  Notion.

## 2. Decisions already made by the user

| Topic | Decision |
|---|---|
| Who uses it | Every teammate whose portal account has the **Chakra Planner** permission. `view` = see everything; `edit` = move publish dates; `manage` = admin. |
| How it feels | Like Notion: databases with views, coloured status chips, items opening in a side panel. |
| Live | Everyone sees changes at once, like Notion. Live updates go through Ably (see API.md). Show who is online. |
| Offline | Version 1: **read** offline from a local copy. Changes need a connection. Changes made offline and synced later come in a later version. |
| Install warnings | Unsigned builds are fine. Windows "More info → Run anyway" and Mac "Open Anyway" are acceptable. **No paid signing certificates.** |
| Updates | The app updates itself from the portal (Tauri updater, signed with a free Tauri key). |
| Name / icon | **Chakra Planner**, Chakra logo (`https://chakragroups.in/icon-512.png`; maskable variant `icon-maskable-512.png`). Brand colour `#132A38`. |

## 3. Version 1 scope (build this)

1. **Sign in** with portal email and password (`POST /login`). Keep the token in
   the OS keychain. Show the API's messages for `no_access` and wrong password
   as they are.
2. **Local copy** of all items (first full load, then deltas). Opens instantly
   on later starts and works read-only offline.
3. **Two views**, as tabs in the header, Notion-style:
   - **Table**: columns Title, Status (chip), Client, Type (source), Publish
     date, Editor / assigned, Editing (who, and how far), Shoot.
     Sort by clicking headers. Frozen title column. Virtualised rows.
   - **Calendar**: a month grid by `published_date`, with each item as a small
     card coloured by status. Drag a card to another day to move it; this is
     `PATCH /items/{id}` with `from`. A tray of "No date" items that can be
     dragged onto a day. Week/month toggle if cheap.
4. **Filters** shared by both views: Type (Reel/Post/Story/YouTube), Status,
   Client, Person, a date range, and search. Default: everything not
   Published/Canceled from 7 days ago onward. Filter chips stay per user and
   per computer.
5. **Side panel** ("side peek"): clicking an item slides a panel in from the
   right with every property, the script (plain text), linked shoot, who is
   editing, the change history, "Open in Notion" and "Open script in portal".
   The publish date can be edited there (date picker + clear).
6. **Refresh** button (`POST /refresh`): a spinner for 10–20 s, then "Updated
   just now". Show `last_synced_at` as "Synced 5 min ago".
7. **Live**: subscribe to `item.updated` / `items.refreshed`. When someone else
   moves an item, update it in place and briefly highlight it ("Gokul moved this
   to Tue 15 Oct"). Show avatars of who is online at the top right. Show an
   avatar on the item someone has open (presence `viewing`). Fall back to
   polling when `realtime.enabled` is false.
8. **Date moves**: optimistic. Turn the change back and show the API's
   `message` on `409 conflict` (and apply the returned item) or
   `502 notion_failed`. Hide drag handles and pickers when `abilities.edit` is
   false.
9. **Offline banner** ("Offline: showing your last copy, changes are paused") and
   a delta on reconnect.
10. **Auto-update** check on start (see §7) and **Sign out** (`POST /logout`).

Not in version 1: board view, saved/shared views, editing anything other than
the publish date, creating items, comments, edits made offline.

### Acceptance checks
- A person **without** the permission sees the API's "no access" message and
  cannot get in.
- Two computers signed in as different people: moving a date on one shows on
  the other within about 2 s (with Ably on). Both see each other's avatar.
- Moving a date is visible in Notion right after (check the Notion page).
- Changing a date **in Notion** shows in the app within about a minute (with the
  webhook set up), or after Refresh.
- Two people drag the same item at the same time: the second gets the conflict
  message and sees the first person's date. No silent overwrite.
- Pull the network: the app still opens and shows everything, with drag
  disabled and the banner shown. Reconnect and it catches up.
- A viewer (no `edit`) cannot drag or pick dates.
- Installing on Windows and on an Apple-silicon Mac works with the
  one-time warning, and a newer upload makes the app offer the update.

## 4. Recommended stack

| Part | Choice | Why |
|---|---|---|
| Shell | **Tauri v2** | Real `.exe` / `.dmg`, about 10 MB, built-in signed updater. Electron is ~100 MB for no gain here. |
| UI | **React + TypeScript + Vite** | |
| Table | **TanStack Table + TanStack Virtual** | 2,400 rows, sortable, frozen column |
| Calendar | Your own month grid + **dnd-kit** (or FullCalendar's free tier, if it can be styled to look like Notion) | Drag to another day |
| Server state | **TanStack Query** | |
| Local copy | **IndexedDB via Dexie** in the webview (or `tauri-plugin-sql` SQLite) | Offline reads, instant start |
| Realtime | **`ably`** npm SDK with `authUrl` | See API.md |
| Token | OS keychain through a small Rust command using the `keyring` crate (or `tauri-plugin-stronghold`) | Not plain files |
| Updater | `tauri-plugin-updater` + `tauri-plugin-process` (relaunch) | |
| HTTP | `fetch` from the webview (CORS is open), or `tauri-plugin-http` | |

Keep the API base URL in one config value (`https://chakragroups.in`). Nothing
else is configurable, and **never** put Notion or Ably keys in the app. The app
only ever holds its own planner token.

## 5. Notion-like UI notes

What makes Notion feel like Notion, to copy:

- **Quiet chrome.** White (light) / `#191919` (dark) canvas, thin grey dividers,
  14 px UI text, no heavy borders, hover reveals actions. Follow the system
  light/dark setting.
- **View tabs** above the data (`Table · Calendar`), with the filter/sort/search
  controls on the same row on the right.
- **Status chips**: rounded rectangles with Notion's tag colours, close to:

  | `color` | Light bg / text | Dark bg / text |
  |---|---|---|
  | default | `#E3E2E080` / `#32302C` | `#373737` / `#D4D4D4` |
  | gray | `#E3E2E0` / `#32302C` | `#5A5A5A` / `#FFFFFFCF` |
  | brown | `#EEE0DA` / `#442A1E` | `#603B2C` / `#FFFFFFCF` |
  | orange | `#FADEC9` / `#49290E` | `#854C1D` / `#FFFFFFCF` |
  | yellow | `#FDECC8` / `#402C1B` | `#89632A` / `#FFFFFFCF` |
  | green | `#DBEDDB` / `#1C3829` | `#2B593F` / `#FFFFFFCF` |
  | blue | `#D3E5EF` / `#183347` | `#28456C` / `#FFFFFFCF` |
  | purple | `#E8DEEE` / `#412454` | `#492F64` / `#FFFFFFCF` |
  | pink | `#F5E0E9` / `#4C2337` | `#69314C` / `#FFFFFFCF` |
  | red | `#FFE2DD` / `#5D1715` | `#6E3630` / `#FFFFFFCF` |

- **Side peek** about 45% wide, the list still visible behind it, Esc closes,
  ↑/↓ moves to the previous/next item while it is open.
- **Calendar**: today's date in a red circle, items as single-line chips with a
  coloured dot, "+N more" when a day overflows.
- **Presence**: small round avatars (initials on a colour from the user id) at
  the top right; the same avatar on the row or card someone has open.
- **Shortcuts**: `Ctrl/⌘+K` search, `Ctrl/⌘+R` refresh, `Enter` opens the
  selected row, `Esc` closes the panel, `T`/`C` switch views.
- **Empty and error states** in plain words, e.g. "Nothing scheduled this week."

## 6. Data flow

```
start ──► token in keychain? ──no──► Sign in
             │yes
             ▼
   GET /me ──► abilities, realtime
             ▼
   local copy empty? ──yes──► GET /items?per_page=1000 (all pages)
             │no
             ▼
   GET /items?updated_since=<saved server_time>  ──► upsert, drop missing_from_notion
             ▼
   Ably connect (authUrl) ──► item.updated → upsert   items.refreshed → delta
             ▼                connection 'connected' (re)→ delta
   UI reads only from the local copy
```

- Save `meta.server_time` after every list call and use it as the next
  `updated_since`.
- Remove items whose `missing_from_notion` is true.
- `GET /meta` on start and after `items.refreshed`: statuses, colours, clients,
  people.
- `GET /items/{id}` when the side panel opens (script and history aren't in
  the list).

## 7. Releases and updates

1. `npm run tauri signer generate -- -w ~/.tauri/chakra-planner.key` once. Put
   the **public** key in `tauri.conf.json` → `plugins.updater.pubkey`. Keep the
   private key and its password as GitHub secrets
   `TAURI_SIGNING_PRIVATE_KEY` / `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`.
2. `tauri.conf.json`: `bundle.createUpdaterArtifacts: true`; updater endpoint
   `https://chakragroups.in/api/planner/app/update/{{target}}/{{arch}}/{{current_version}}`.
   Windows target: NSIS.
3. GitHub Actions on a version tag (`v*`): matrix `windows-latest`,
   `macos-latest` (`--target aarch64-apple-darwin`) and `macos-latest`
   (`--target x86_64-apple-darwin`); build with `tauri-apps/tauri-action`.
4. Then upload each platform to the portal (`POST /api/planner/app/releases`,
   see API.md) with `curl -F`. The token comes from a `POST /login` with an
   account that has **Manage**, saved as secret `PLANNER_RELEASE_TOKEN`
   (device name "GitHub Actions"):
   - Windows: `file=@…-setup.exe`, `signature=<…-setup.exe.sig>`.
   - Mac: `file=@….app.tar.gz`, `signature=<….app.tar.gz.sig>`,
     `installer=@….dmg`.
5. People download from `https://chakragroups.in/planner`. Installed apps see
   the update on their next start.

Mac without a paid Apple account: no notarisation, so the first open shows
"cannot be verified". The person goes to System Settings → Privacy & Security
→ **Open Anyway**. Make sure the bundle is at least ad-hoc signed (Tauri does
this by default) so macOS doesn't call it "damaged".

## 8. What the user must set up (tell them; you can't do it)

1. **Permissions:** Users → (person) → Permissions → *Chakra Planner*: tick
   View / Edit / Manage.
2. **Ably** (free plan is enough): create an app at ably.com, copy the root
   API key, and paste it on `https://chakragroups.in/planner` → *Live updates*.
   Until then the app polls.
3. **Notion webhook:** on the same page, follow the four steps under *Notion
   webhooks* (create the subscription, copy the token, Verify in Notion).
4. **Notion integration capabilities:** it must have **Update content**
   (it already writes shoot status, so it should).
5. **GitHub:** a repository for the app, plus the secrets in §7.

## 9. Don'ts

- Don't call Notion or Ably's REST API with keys from the app. Everything goes
  through the portal API (Ably only through `authUrl` tokens).
- Don't edit any field other than `published_date`; the API ignores other fields.
- Don't hardcode accounts or tokens. Ask the user for a test account.
- Don't assume the planners share statuses; use `meta.statuses[item.source]`.
- Don't trust a live event as the record. Deltas are the record; events are
  the trigger.

## 10. Later (not now)

Board view by status · saved and shared views · creating items and editing
status, client and editor (the API would need write endpoints, and those
writes would also go to Notion) · comments and @mentions · edits made offline
and synced later · finally switching off Notion (the portal becomes the master
copy).
