# Chakra mobile app: design system

The app should feel like the portal (same brand, words and statuses), redrawn as a calm,
fast native app for people who are on shoots, editing, or between the two. Dark navy is home.

**Principles**

1. **Today first.** Open the app and see what matters now: the shoot you're on, what's
   overdue, hours logged. Everything else is one tap away.
2. **One thumb.** Primary actions sit in the bottom half. Tap targets are at least 44 × 44 pt.
3. **The server's words.** Show `message`, labels and names as the API sends them. Don't
   rephrase statuses.
4. **Quiet until it matters.** Colour is for status and the one primary action per screen.
   Everything else is navy, white and grey.
5. **Never lose work.** Optimistic UI only for reversible actions (mark read). Everything
   else shows a spinner on the button and the server's answer.

---

## 1. Colour

### Brand scale (identical to the portal's Tailwind `brand`)

| Token | Hex | Use |
|---|---|---|
| `brand50` | `#F2F9FC` | Light-mode page tint |
| `brand100` | `#E4F2F7` | Light-mode selected row |
| `brand200` | `#ABDAE7` | Light-mode chips |
| `brand300` | `#8ACCE0` | Dark-mode links, secondary icons |
| `brand400` | `#67BCD4` | Dark-mode primary text accents |
| `brand500` | `#4FA9C4` | **Primary** (buttons, active tab, progress) |
| `logo` | `#4298B5` | The logo blue. Logo and icon only |
| `brand600` | `#3D8CA6` | Primary pressed |
| `brand700` | `#2F6E84` | Light-mode primary text-on-white |
| `brand800` | `#284250` | Dark-mode raised surface / card |
| `brand900` | `#132A38` | **Navy**: dark background, splash, icon background |

### Surfaces

| Token | Dark (default) | Light |
|---|---|---|
| `bg` | `#0E202B` | `#F6F9FB` |
| `surface` (cards) | `#132A38` | `#FFFFFF` |
| `surfaceRaised` (sheets, menus) | `#1B3646` | `#FFFFFF` + shadow |
| `border` | `#284250` | `#E2E8EC` |
| `textPrimary` | `#F4F8FA` | `#0F1F29` |
| `textSecondary` | `#A7BAC5` | `#52646F` |
| `textMuted` | `#6E8794` | `#8597A1` |

Dark mode is the default look (brand navy). Light mode follows the system setting.

### Status colours

Use them on 12–16% tinted backgrounds with the solid colour for text, dot or icon.

| Meaning | Colour | Hex | Used for |
|---|---|---|---|
| Live / urgent | Red | `#EF4444` | shoot `live`, overdue, `blocked`, late inbox checks |
| Attention | Amber | `#F59E0B` | due today, `under review`, waiting on client |
| Done / good | Emerald | `#10B981` | `completed`, posted, approved, all checks done |
| In progress | Sky | `#38BDF8` | `started`, `edit in progress`, `confirmed` |
| Planned / neutral | Slate | `#94A3B8` | `planned`, `waiting`, `cancelled` (strikethrough) |

**Status mapping**

| Domain | Value → colour |
|---|---|
| Shoot | `live` red (pulsing dot) · `confirmed` sky · `planned` slate · `completed` emerald |
| To-do | `waiting` slate · `started` sky · `blocked` red · `completed` emerald · `cancelled` slate + strike |
| Reel (planner) | `To Be Edited` slate · `Edit in Progress` sky · `Under Review` amber · `Published` emerald |
| Inbox check | `done` emerald · `none`/left slate · `late` red |

---

## 2. Typography

**Poppins** (same as the portal). Bundle the TTFs (400, 500, 600, 700) in `assets/fonts`
rather than downloading at runtime: the widget extension and first launch need them offline.
Numbers use tabular figures (`FontFeature.tabularFigures()`) wherever they update in place
(timers, hours, counts).

| Style | Size / line | Weight | Use |
|---|---|---|---|
| `display` | 34 / 40 | 700 | Big number on Today ("2h 15m") |
| `title1` | 26 / 32 | 700 | Screen titles (large title, collapses on scroll) |
| `title2` | 20 / 26 | 600 | Section headers ("Shoots today") |
| `headline` | 17 / 22 | 600 | Card titles, list primary text |
| `body` | 15 / 21 | 400 | Body, notification body |
| `callout` | 14 / 19 | 500 | Buttons, chips |
| `footnote` | 13 / 18 | 400 | Secondary lines (client · location) |
| `caption` | 11 / 14 | 500, +0.4 tracking, UPPERCASE | Labels above numbers ("THIS WEEK") |

Support Dynamic Type up to XXL. Large accessibility sizes may wrap; nothing truncates
mid-word in titles.

---

## 3. Spacing, shape, elevation

- **Grid:** 4 pt. Common steps: 4, 8, 12, 16, 20, 24, 32.
- **Screen padding:** 16 horizontal (20 on Pro Max / tablets).
- **Card:** radius **16**, padding 16, gap between cards 12.
- **Buttons / inputs:** radius **12**, height 50 (primary), 40 (secondary/compact).
- **Chips / pills / avatars:** fully rounded.
- **Sheets:** top radius 20, drag handle 36 × 5.
- **Elevation:** dark mode separates with surface colour + 1 px `border`, no shadows. Light
  mode uses `0 1px 2px rgba(15,31,41,.06), 0 4px 12px rgba(15,31,41,.06)`.
- **Icons:** SF Symbols on iOS (Cupertino), Material Symbols Rounded on Android. 22 pt in
  lists, 24 pt in tab bar. Line weight regular.

---

## 4. Motion and haptics

- Standard 220 ms ease-out; sheets 300 ms spring.
- Live dot pulses (opacity 1 → .35, 1.2 s loop). Respect Reduce Motion: no pulse.
- Pull-to-refresh on every list.
- Haptics: light impact on status change success, warning notification on refusal, success
  notification on "Logged 2h".

---

## 5. Components

| Component | Spec |
|---|---|
| **StatCard** | caption label, display number, footnote delta ("21h this week"). Tap → detail. |
| **ShootCard** | left: time (headline) or "All day"; middle: title, `client · location` footnote; right: status pill. Live shoots get a 3 px red leading bar and a "Start timer" button (Live Activity). |
| **TodoRow** | leading circular check (tap → `completed`), title, due chip (red if overdue), swipe left → Blocked (asks for a note) / Cancel; swipe right → Started. |
| **EditingCard** | client logo (24 pt rounded), video title, status pill, primary action button per state (Start → Done → Ready → Post). Server decides who may act; hide disabled actions. |
| **NotificationRow** | type icon in tinted circle, title (semibold if unread), body two lines, relative time ("12m"), unread dot. Swipe → mark read. |
| **StatusPill** | 22 pt high, 8 h-padding, tinted background + solid dot + label. |
| **EmptyState** | `glyph-navy`/`glyph-white` at 64 pt, one line ("Nothing due today 🎬"), optional action. |
| **ErrorBanner** | inline on top of the list, server `message`, Retry. Never a blocking dialog for reads. |
| **ConfirmSheet** | for `messages_client` / destructive actions: who it goes to, what it says, red or primary button, Cancel. |
| **ActionForm** | auto-built from `input_schema` for less-used actions: string → text field, `enum` → segmented / picker, `format: date` → date picker, integer → stepper, boolean → switch, `description` as helper text. |

Notification type → icon (SF Symbol / Material):

| type | iOS | Android |
|---|---|---|
| `todo_*` | `checklist` | `checklist` |
| `shoot_*` | `video.fill` | `videocam` |
| `video_not_published`, `content_depletion_warning` | `exclamationmark.triangle.fill` | `warning` |
| `script_*` | `doc.text` | `description` |
| `proposal_commented`, `brief_submitted` | `bubble.left.and.text.bubble.right` | `forum` |
| `enquiry_received_push` | `person.crop.circle.badge.plus` | `person_add` |
| `timesheet_day_rejected` | `clock.badge.xmark` | `history` |
| `announcement_posted` | `megaphone.fill` | `campaign` |
| `daily_digest_ready` | `sun.max.fill` | `wb_sunny` |
| unknown | `bell.fill` | `notifications` |

---

## 6. Navigation

Bottom tab bar (4 tabs + More). Show a tab only if its `features.*` allows it:

| Tab | Icon | Shows |
|---|---|---|
| **Today** | `house.fill` | Home (§7.2) |
| **Editing** | `film.stack` | My Editing board (`/editing`) |
| **To-dos** | `checklist` | `list_todos` |
| **Inbox** | `bell.fill` + badge | Notifications |
| **More** | `ellipsis` | Shoots, Timesheet, Planner (if `features.planner`), Actions, Profile, Sign out |

Deep links: `chakra://today`, `chakra://editing`, `chakra://todos`, `chakra://inbox`,
`chakra://shoots/{id}`, plus every notification `path`. Unknown paths open the portal
page in an in-app browser (SFSafariViewController). The person may need to sign in on
the web the first time; the app token doesn't carry over to the browser.

---

## 7. Screens

### 7.1 Sign in
Navy background, `chakra-wordmark` centred upper third, "PRODUCTIONS" wordmark small beneath.
Email, password (show/hide), primary "Sign in". Errors inline under the button, in the
server's words. Footer: "For the Chakra Productions team". No sign-up, no "forgot password"
(link to `https://chakragroups.in/forgot-password` in the browser instead).

### 7.2 Today (home)
Large title "Good morning, {first_name}" + `date_label`. Avatar top-right → Profile.
Order, each section only if present in `/today`:
1. **Live now**: any shoot with `status: live` as a full-width ShootCard with red bar + timer button.
2. **Stat row** (horizontal scroll of StatCards): Hours today (`hours.today_label`, footnote
   `week_label` this week), To-dos (`todos.count`, red "{overdue} overdue"), Team hours (admins).
3. **Shoots**: day switcher `‹ Wed, 4 Nov ›` using `day.prev/next`, then ShootCards.
   Empty: "No shoots {today|on this day}".
4. **To-dos**: first 3 titles, "See all" → To-dos tab.
5. **Inbox checks** (if `inbox`): progress ring `done/total`, accounts with left/late chips.
6. **Reels today** (admins): four counters (to edit / editing / review / posted), list below.
Floating "+" (bottom-right): Log hours · New to-do · Add video to edit.

### 7.3 Inbox
Segmented: All / Unread. NotificationRows grouped by Today / Yesterday / Earlier. Top-right
"Mark all read". Tap → mark read → route. Empty: "You're all caught up".

### 7.4 Editing
Sections from `/editing`: My cards, Ready to post (team), Review (admins). Each EditingCard
shows exactly the actions the board allows. "Add video" sheet: search `suggestions.items`
or type a title.

### 7.5 To-dos
Date strip (week) at top, list of TodoRows (`list_todos` with `open_only`). Toggle
"Show done". "+" → New to-do sheet (`create_todo`: title, person (managers), client, due).

### 7.6 Shoots
`my_shoots` (or `shoots_between` for people with `shoots_all`). Upcoming first, grouped by
day. Detail: title, client, time, location (tap → Maps), crew, call-sheet link (in-app browser),
"Start timer" when live.

### 7.7 Log hours
Sheet: Task (text, suggestions from `timesheet_hint`), Client (searchable, `find_client`),
Type (segmented: Shooting / Editing / Posting / Other), Start–End time pickers **or**
Duration stepper, Date (defaults today), Notes. Primary "Log". Success toast "Logged 2h 15m".

### 7.8 More → Actions
Every action from `GET /actions` grouped by `group`, searchable. Tap → ActionForm → result
shown as a readable card (key/value list; arrays as lists). `messages_client` → ConfirmSheet.

### 7.9 Profile
Avatar, name, role, email. Notifications (permission state, button to open iOS Settings),
Widgets help, App version, this device's name (`session.device_name`), Sign out (red).

---

## 8. Home-screen widgets (WidgetKit)

Background: `brand900` navy with a subtle radial lift (`#1F4A61` at top-centre → navy) in
dark mode. In light mode use white. Text uses the surface tokens. Corner content padding
16 (system margins). Every widget shows `generated_at` as "Updated 10:12" in `caption` at
the bottom, so stale data is visible.

| Widget | Size | Content |
|---|---|---|
| **Today** | Small | Camera glyph 16 pt + "Today"; big `hours.today_label`; footnote "{todos.count} to-dos · {shoots.count} shoots" |
| **Today** | Medium | Left column: hours + week; right: next shoot (time, title, client) or "No shoots"; bottom row: to-dos count, overdue in red |
| **Today** | Large | Header with date; next 3 shoots; 3 to-dos from `todos.open` with check buttons (iOS 17 App Intent → `set_todo_status`); inbox progress bar |
| **Next shoot** | Small | Time (display), title (2 lines), client · location; red LIVE pill when live |
| **Lock screen: rectangular** | `accessoryRectangular` | "11:30 · Squad Architects" / location / "LIVE" |
| **Lock screen: circular** | `accessoryCircular` | Gauge of hours today vs 8h, label "2h" |
| **Lock screen: inline** | `accessoryInline` | "4 to-dos · 1 shoot" |

Lock-screen widgets render in the system tint. Use the monochrome glyph only, no colours.

Tap targets: whole widget → `chakra://today`; shoot row → `chakra://shoots/{id}`;
to-do check → App Intent (no app launch).

---

## 9. Live Activity: shoot timer

**Lock screen / banner** (height ~ 160):
```
[camera glyph]  SQUAD ARCHITECTS                     ● LIVE
                Office reel – Anna Nagar
                01:42:17  (Text(timerInterval:), display, tabular)
                [ Wrap shoot ]  (deep link chakra://shoots/41)
```
Navy background (`activityBackgroundTint(#132A38)`), white text, red LIVE dot.

**Dynamic Island**
- *Compact leading:* red dot + camera glyph. *Compact trailing:* `1:42:17` timer (monospaced).
- *Minimal:* red dot.
- *Expanded:* leading = client logo/glyph, trailing = timer, centre = title, bottom =
  location + "Wrap" link.

---

## 10. App icon and brand assets

Files and how to install them: README §9. Rules:

- Icon = white camera (from the studio logo) on navy, with a faint logo-blue ring and baseline.
  Don't add text to the icon or recolour the camera.
- Wordmarks ("CHAKRA", "PRODUCTIONS") are the logo-blue outline style. Use them on navy
  only, never on photos, at least 120 pt wide.
- Clear space around any mark: ¼ of its height.

---

## 11. Flutter theme starter

```dart
class ChakraColors {
  static const navy = Color(0xFF132A38), bg = Color(0xFF0E202B), raised = Color(0xFF1B3646);
  static const border = Color(0xFF284250), primary = Color(0xFF4FA9C4), primaryPressed = Color(0xFF3D8CA6);
  static const text = Color(0xFFF4F8FA), text2 = Color(0xFFA7BAC5), muted = Color(0xFF6E8794);
  static const live = Color(0xFFEF4444), attention = Color(0xFFF59E0B), done = Color(0xFF10B981);
  static const progress = Color(0xFF38BDF8), neutral = Color(0xFF94A3B8);
}

ThemeData chakraDark() => ThemeData(
  useMaterial3: true,
  brightness: Brightness.dark,
  fontFamily: 'Poppins',
  scaffoldBackgroundColor: ChakraColors.bg,
  colorScheme: const ColorScheme.dark(
    primary: ChakraColors.primary, onPrimary: Colors.white,
    surface: ChakraColors.navy, onSurface: ChakraColors.text,
    error: ChakraColors.live, outline: ChakraColors.border,
  ),
  cardTheme: CardTheme(
    color: ChakraColors.navy, elevation: 0, margin: EdgeInsets.zero,
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16),
      side: const BorderSide(color: ChakraColors.border)),
  ),
  filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(
    minimumSize: const Size.fromHeight(50),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
  )),
  navigationBarTheme: const NavigationBarThemeData(
    backgroundColor: ChakraColors.navy, indicatorColor: Color(0x334FA9C4)),
);
```

Swift (widgets / Live Activity) uses the same hex values: put them in the widget target's
asset catalog as named colours (`Navy`, `Primary`, `Live`, …) with dark/light appearances.
