# Prompt: Chakra Planner top-bar editing widget

Paste this into a Claude Code session in the **Chakra Planner app repo**.

---

Build a **top-bar editing widget** for Chakra Planner. It's a tray icon on Windows and a menu-bar icon on Mac. Clicking it opens a small panel where an editor handles their videos without opening the main window.

## Who uses it and why

Our editors edit 6–10 short videos a day. Every move they make is recorded: adding a video, starting it, putting it on hold, finishing it, posting it. The admin's live "Editor Updates" screen and the timesheet hints are built from those records. Today editors have to open the portal in a browser to do this, so they forget. The widget makes each move one or two clicks from the top bar.

## The API (already live on the portal)

The full contract is in the portal repo at `docs/chakra-planner/API.md`, section **"Editing widget"**. Read it first. The short version:

- **Sign in:** every teammate can now sign in. The profile's `abilities.editing` is always `true`. `abilities.view` says whether they also get the planners.
  - When `view` is false, show **only the widget** and hide the planner window and its menu items.
  - `403 no_access` on `/items`, `/meta` or `/refresh` means "widget-only account". It does **not** mean sign out.
- **`GET /editing`** returns three lists:
  - `cards`: my videos today
  - `team_ready`: other people's approved videos that anyone can post
  - `suggestions`: planner videos to add
- **`POST /editing`** adds a video: `{ "title" }` or `{ "content_item_id" }`.
- **`POST /editing/{id}/act`** takes `{ "action", ... }`. The actions:
  - `start`
  - `hold` with a `note`
  - `done`
  - `next` and `today`
  - `remove`
  - `post` with `kind`: `reel`, `post` or `story`
  - `deliver`
  - `unpost`

  On `422`, show the response's `message` exactly as sent.
- **Stage of a card:**
  - `waiting` → To start
  - `started` → Editing
  - `blocked` → On hold (`held` = the reason)
  - `completed` with `ready:false` and no `posted_at` → Under review
  - `ready:true` → Ready to post (`publish_label`)
  - `posted_at` set → Out
- **Who may do what** (the server enforces it; the UI should match it):
  - **Editing is the editor's:** start, hold, done, next day and remove apply to the person's own cards only.
  - **Under review is the admin's:** the widget shows the state and has **no** approve or correction buttons. If `sent_back` has text, show it as "Correction: …".
  - **Posting is anyone's:** Post or Deliver appears on any card or `team_ready` item that is `ready:true`.

## The panel (about 380 × 560, opens under the icon, closes on blur)

1. **Header**
   - "Today", plus counts: editing · on hold · in review · ready.
   - A ⟳ button and the time of the last refresh.
   - A ⚙ menu: Open Chakra Planner (only if `view`), Sign out, Launch at login.
2. **Now editing.** Shown only when a card is `started`:
   - the title and client
   - a live timer from `working_since`
   - two big buttons: **⏸ Hold** and **✓ Done**
     - Hold asks for a one-line reason, with quick chips: Waiting for client · Waiting for footage · Music/voice · Other.
     - Done is blocked, with a message, while a correction is open.
3. **Add a video.** One input: "Add a video…". Typing filters `suggestions` (typeahead). Enter on a suggestion sends its `content_item_id`; free text sends a `title`.
4. **My videos.** A compact list grouped To start / On hold / Under review / Ready / Out today. Each row shows:
   - the title, the client, and a stage chip
   - one primary action: ▶ Start, ▶ Resume or Post
   - a ⋯ menu: Next day, Remove (with a confirm step), Copy title
5. **Ready to post · team.** The `team_ready` items, late ones first, in red with "was due". Each row has:
   - a Reel / Post / Story selector
   - **Posted** and **Delivered** buttons
6. **Tray icon state**, so an editor can tell at a glance without opening the panel:
   - **dot:** amber while editing, violet if anything is on hold, red if a ready video is late
   - **tooltip:** what I'm editing and for how long
   - **Mac:** the menu-bar title shows the timer while editing, e.g. `▶ 42m`

## Behaviour

- **Polling:** every 30 s while the panel is open and every 2 min while closed. Use the existing Ably subscription to refresh right away on any `planner` event.
- **Instant feedback:** buttons update the row at once. On error, roll the row back and show the server's `message` in a small banner.
- **Keyboard:** global shortcut **Ctrl/Cmd+Shift+E** toggles the panel. Inside it: `/` focuses Add, Enter starts the top "To start" card, Esc closes.
- **Notifications:**
  - a Windows/Mac notification when a correction comes back on one of my videos (a new `sent_back`)
  - one when a team video becomes ready to post
- **Offline:** show the last data, read-only, with an "Offline" chip. Don't queue actions.
- **Look:** the portal's colours (navy `#132A38` and `#15303f`, accent `#67BCD4`, Poppins). Every click target at least 36 px. No emoji in the UI chrome.

## Done means

- Works on Windows and Mac. On Linux the tray is best effort.
- A widget-only account (no `view`) can sign in and use everything above.
- Tests cover:
  - stage mapping from card fields
  - which buttons show for own vs. someone else's ready video
  - rollback on a `422`
- `APP-HANDOFF.md` is updated with the widget, and a release is cut through the existing update flow.

---

I've written the portal side in full. Ask me before changing any API shape. The portal's `API.md` is the contract.
