# Chakra mobile app: developer guide

A Flutter app (iOS first, Android free) for the Chakra Productions studio team: today at a
glance, notifications, editing board, to-dos, shoots, timesheet, home-screen widgets and a
live shoot timer on the lock screen.

| Doc | What's in it |
|---|---|
| **README.md** (this) | Setup, architecture, notifications, widgets, Live Activities, signing |
| [API.md](API.md) | Every endpoint, request and response |
| [ADMIN-ACTIONS.md](ADMIN-ACTIONS.md) | All 86 actions: arguments, permission, result shape, client-messaging flags |
| [DESIGN.md](DESIGN.md) | Colours, type, spacing, components, every screen, widget layouts |
| [assets/](assets/) | App icons (iOS, Android, dark, tinted), logos, glyphs |

Backend: Chakra Portal (Laravel) at `https://chakragroups.in`. API base:
`https://chakragroups.in/api/mobile`.

---

## 1. What works without a paid Apple Developer account

You can build and run on **your own iPhone** with a free Apple ID in Xcode. Limits:

| Feature | Free Apple ID (Personal Team) | Paid ($99/yr) |
|---|---|---|
| Run the app on your iPhone | ✅ from Xcode, cable or Wi-Fi | ✅ + TestFlight for the team |
| App keeps working | ❌ **7 days**, then re-run from Xcode | 1 year / App Store |
| Notification inbox + badge | ✅ (`/notifications`) | ✅ |
| Local notifications (app polls, then notifies) | ✅ while open, and on iOS background refresh | ✅ |
| **Remote push** (instant, app closed) | ❌ Push capability needs a paid team | ✅ via Firebase + APNs key |
| Home-screen + lock-screen widgets | ✅ (see §6 if Xcode refuses App Groups) | ✅ |
| Live Activity / Dynamic Island, started by the app | ✅ shoot timer ticks on its own | ✅ |
| Live Activity updated **from the server** | ❌ needs push | ✅ |
| Install on teammates' phones | Only phones plugged into your Mac | TestFlight link |
| Max apps / App IDs | 3 apps per device, 10 App IDs per week | — |

**Build so the switch costs nothing:** the inbox + polling path is the base, and push is an
add-on that registers a token (`POST /push-token`) when it's available. Buying the account
later means adding the Push capability, uploading an APNs key to Firebase and
shipping. No app or API rewrite.

---

## 2. Prerequisites

- macOS with **Xcode 16+** (App Store) → open once, accept licence, install iOS platform.
- **Flutter** stable (3.24+): `brew install --cask flutter` → `flutter doctor` all green for iOS.
- **CocoaPods**: `brew install cocoapods`.
- iPhone on iOS 17+ (widgets with buttons need 17, Live Activities need 16.2+).
  Turn on **Settings → Privacy & Security → Developer Mode** (appears after the first
  Xcode run).
- Xcode → Settings → Accounts → add your Apple ID → it creates a "Personal Team".
- A portal login (any staff account) for testing.

---

## 3. Create the project

```bash
flutter create --org in.chakragroups --project-name chakra --platforms ios,android chakra_app
```

```bash
cd chakra_app
```

Bundle id: `in.chakragroups.chakra`. Widget extension: `in.chakragroups.chakra.widgets`.
App Group: `group.in.chakragroups.chakra`.

### Packages

```yaml
dependencies:
  dio: ^5.7.0                       # HTTP + interceptors
  flutter_secure_storage: ^9.2.2    # token in Keychain/Keystore
  flutter_riverpod: ^2.5.1          # state
  go_router: ^14.2.0                # routes + deep links from notification paths
  google_fonts: ^6.2.1              # Poppins (or bundle the TTFs, see DESIGN.md)
  cached_network_image: ^3.4.1      # avatars, client logos
  intl: ^0.19.0                     # ₹ formatting, dates
  flutter_local_notifications: ^17.2.2
  workmanager: ^0.5.2               # iOS background refresh → poll /notifications
  home_widget: ^0.7.0               # share token/data with the widget extension
  url_launcher: ^6.3.0              # open portal pages / maps / WhatsApp
  device_info_plus: ^10.1.2
  package_info_plus: ^8.0.2
  # Add when you have the paid account:
  # firebase_core: ^3.4.0
  # firebase_messaging: ^15.1.0
```

Versions are a starting point. Use whatever `flutter pub add` resolves.

### Suggested structure

```
lib/
  main.dart
  app/            router.dart, theme.dart (from DESIGN.md), app.dart
  api/            api_client.dart, models/*.dart, errors.dart
  auth/           session_store.dart, login_screen.dart
  features/
    today/        today_screen.dart, sections/*.dart
    notifications/inbox_screen.dart, notifier_service.dart
    editing/      editing_screen.dart
    todos/  shoots/  timesheet/  planner/  more/
  widgets_bridge/ home_widget_sync.dart, live_activity.dart
ios/
  ChakraWidgets/  (WidgetKit extension, Swift)
```

---

## 4. Talking to the API

```dart
class ApiClient {
  ApiClient(this._store)
      : dio = Dio(BaseOptions(
          baseUrl: 'https://chakragroups.in/api/mobile',
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 30),
          headers: {'Accept': 'application/json'},
        )) {
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (o, h) async {
        final token = await _store.token();
        if (token != null) o.headers['Authorization'] = 'Bearer $token';
        o.headers['X-App-Version'] = AppInfo.version;
        h.next(o);
      },
      onError: (e, h) async {
        if (e.response?.statusCode == 401) await _store.signOut(); // → router shows login
        h.next(e);
      },
    ));
  }
  final SessionStore _store;
  final Dio dio;

  Future<Map<String, dynamic>> today({String? date}) async =>
      (await dio.get('/today', queryParameters: {if (date != null) 'date': date})).data;

  /// Runs any portal action. Throws ActionRefused(message) on ok:false.
  Future<dynamic> action(String name, [Map<String, dynamic> args = const {}]) async {
    try {
      final r = await dio.post('/actions/$name', data: args);
      return r.data['result'];
    } on DioException catch (e) {
      final msg = (e.response?.data is Map) ? e.response!.data['message'] : null;
      throw ActionRefused(msg ?? 'Something went wrong');
    }
  }
}
```

Rules:
- Show the server's `message` as-is on errors. It's written for the person.
- **Never auto-retry a write** (any action with `read_only: false`, `/editing/*/act`).
- Treat every `/today` section as optional, and every unknown enum value as "other".
- Hide features where `features.x == false` and buttons whose action isn't in `actions`.
- `messages_client: true` actions WhatsApp a real client. Confirm first, then say who it went to.

### Sign-in flow

1. Login screen → `POST /login` → save `token` (secure storage) and the profile.
2. On launch: token? → `GET /me` → 401 means show login, else go home.
3. Logout → `POST /logout` with `push_token` → clear storage + widget data (§6).

---

## 5. Notifications

### Without push (free account): build this first

1. **Badge + bell**: `unread` from `/me` and `/notifications`. Set the app badge with
   `flutter_local_notifications` (`setBadgeCount` / iOS `badgeNumber`).
2. **Inbox screen**: `GET /notifications`, infinite scroll with `before=next_before`, tap
   → mark read → route by `path` (table in API.md) or open `url` in an in-app browser.
3. **Poll while open**: every 60 s and on `AppLifecycleState.resumed`, call
   `GET /notifications?after=<lastSeenId>`. For each new row, show a **local notification**
   (`title`, `body`, payload = `path`, thread = `tag`), then save `latest_id`.
4. **Poll in the background**: `workmanager` periodic task (iOS BGAppRefresh) runs the same
   `after=` check. iOS decides when: often every 15–60 min, less if the app is rarely
   opened, never if the person force-quits it. It's a nice extra, not real-time.

   Info.plist:
   ```xml
   <key>UIBackgroundModes</key><array><string>fetch</string><string>processing</string></array>
   <key>BGTaskSchedulerPermittedIdentifiers</key>
   <array><string>in.chakragroups.chakra.poll</string></array>
   ```
5. Ask notification permission **after** sign-in, with a one-line reason screen first.

### With push (paid account), later

1. Apple Developer → Keys → new key with **APNs** → download `.p8`.
2. Firebase console (same project as the portal's `/push` settings) → Project settings →
   Cloud Messaging → Apple app configuration → upload the `.p8`, Key ID, Team ID.
   Add an iOS app with bundle id `in.chakragroups.chakra`, download `GoogleService-Info.plist`
   into `ios/Runner`.
3. Xcode → Runner → Signing & Capabilities → **+ Push Notifications** and
   **+ Background Modes → Remote notifications**.
4. `firebase_messaging`: request permission → `getToken()` → `POST /push-token`
   `{token, platform:'ios', device_name}`. Re-post on `onTokenRefresh`.
5. Tap handling: `FirebaseMessaging.onMessageOpenedApp` / `getInitialMessage()` →
   take `data['url']`'s path → router. Then sync `/notifications?after=`.
6. Keep the polling code. It's still how the inbox stays current.

Every portal push also lands in the inbox, so nothing is lost when push is off.

---

## 6. Home-screen and lock-screen widgets (WidgetKit)

Widgets are a native **Swift** extension. Flutter can't draw them. The app shares the
token (and a cached copy of `/today`) through an **App Group**. The widget then fetches
`/today` itself on its timeline.

### Setup

1. Xcode → open `ios/Runner.xcworkspace` → File → New → Target → **Widget Extension** →
   name `ChakraWidgets`. Untick "Include Configuration Intent" for now. Tick
   "Include Live Activity" too (§7).
2. Runner target **and** ChakraWidgets target → Signing & Capabilities → **+ App Groups** →
   `group.in.chakragroups.chakra`.
3. Set the widget target's iOS deployment target to match Runner (17.0).
4. In Flutter, after sign-in and after each `/today` load:

```dart
await HomeWidget.setAppGroupId('group.in.chakragroups.chakra');
await HomeWidget.saveWidgetData<String>('token', token);
await HomeWidget.saveWidgetData<String>('today_json', jsonEncode(todayJson));
await HomeWidget.updateWidget(iOSName: 'ChakraWidgets');
```

On logout, save `null` for both and call `updateWidget`.

### Widget timeline (Swift)

```swift
import WidgetKit
import SwiftUI

let group = UserDefaults(suiteName: "group.in.chakragroups.chakra")

struct TodayEntry: TimelineEntry { let date: Date; let today: [String: Any]? }

struct Provider: TimelineProvider {
    func placeholder(in context: Context) -> TodayEntry { .init(date: .now, today: nil) }

    func getSnapshot(in context: Context, completion: @escaping (TodayEntry) -> Void) {
        completion(.init(date: .now, today: cached()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<TodayEntry>) -> Void) {
        Task {
            let fresh = await fetchToday() ?? cached()
            let next = Calendar.current.date(byAdding: .minute, value: 15, to: .now)!
            completion(Timeline(entries: [.init(date: .now, today: fresh)], policy: .after(next)))
        }
    }

    func cached() -> [String: Any]? {
        guard let s = group?.string(forKey: "today_json"), let d = s.data(using: .utf8) else { return nil }
        return try? JSONSerialization.jsonObject(with: d) as? [String: Any]
    }

    func fetchToday() async -> [String: Any]? {
        guard let token = group?.string(forKey: "token"),
              let url = URL(string: "https://chakragroups.in/api/mobile/today") else { return nil }
        var req = URLRequest(url: url, timeoutInterval: 12)
        req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        guard let (data, resp) = try? await URLSession.shared.data(for: req),
              (resp as? HTTPURLResponse)?.statusCode == 200,
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return nil }
        if let s = String(data: data, encoding: .utf8) { group?.set(s, forKey: "today_json") }
        return json
    }
}
```

- Ask for a refresh every 15 min. iOS gives roughly 40–70 refreshes a day and decides the
  actual timing. Don't ask more often; never send `?fresh=1` from a widget.
- Widget kinds to build (layouts in DESIGN.md §8): **Today** (small/medium/large),
  **Next shoot** (small + lock-screen rectangular), **Hours today** (small + lock-screen
  circular gauge), **To-dos** (medium, iOS 17 interactive "done" button through an App
  Intent that calls `/actions/set_todo_status`).
- Tapping a widget opens a deep link: `chakra://today`, `chakra://shoots`, `chakra://todos`.
  Add the `chakra` URL scheme in Runner → Info → URL Types and handle it in `go_router`.

### If Xcode refuses App Groups on the free team

Some free Personal Team setups can't add the App Groups capability. Fallback: the widget
uses its **own read-only widget key** from the portal (Profile → iPhone widget) and calls
`GET https://chakragroups.in/api/widget/today` with `Authorization: Bearer <widget key>`
(same JSON as `/api/mobile/today`). Make the widget configurable with an App Intent
(`WidgetConfigurationIntent`) that has a "Widget key" text parameter, which the person
pastes once by long-pressing the widget → Edit Widget.

---

## 7. Live Activities (lock screen + Dynamic Island)

**Best first use: the shoot timer.** When crew start a shoot, a Live Activity shows the
client, location and a running clock. `Text(timerInterval:)` ticks by itself, so it stays
live with **no server pushes**. That works on the free account.

1. Runner Info.plist: `NSSupportsLiveActivities` = `YES`.
2. In the widget extension (shared file, add it to both targets):

```swift
import ActivityKit

struct ShootAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable {
        var status: String        // "live" | "wrapping"
        var startedAt: Date
    }
    var title: String
    var client: String
    var location: String?
}
```

3. A `MethodChannel('chakra/live')` in `AppDelegate.swift`:

```swift
let attrs = ShootAttributes(title: title, client: client, location: location)
let state = ShootAttributes.ContentState(status: "live", startedAt: .now)
let activity = try Activity.request(attributes: attrs,
                                    content: .init(state: state, staleDate: nil),
                                    pushType: nil)   // nil = no push needed
// keep activity.id; later: await activity.end(nil, dismissalPolicy: .immediate)
```

4. Start it when the person taps "Start shoot" on a `live` shoot (or when `/today` reports
   a shoot of theirs as `live`). End it on wrap, or when `/today` no longer shows it live.
5. The UI is in DESIGN.md §9 (lock-screen banner, compact/minimal/expanded Island).

Server-pushed updates ("Client approved the cut", status changes) need
`pushType: .token` + APNs, which means the paid account. The portal side would then send
ActivityKit pushes; that endpoint isn't built yet.

---

## 8. Run it on your iPhone (free Apple ID)

1. `flutter pub get` → `cd ios && pod install`.
2. Open `ios/Runner.xcworkspace`. For **Runner** and **ChakraWidgets**: Signing & Capabilities →
   Team = your Personal Team, "Automatically manage signing" on, bundle ids as in §3.
   If the bundle id is "not available", add a suffix (`in.chakragroups.chakra.sanjai`) and
   change the App Group to match.
3. Plug in the iPhone, trust the Mac, pick it as the run destination.
4. `flutter run --release` (release builds feel right and launch without the Mac attached).
5. First launch: iPhone → Settings → General → **VPN & Device Management** → your Apple ID →
   Trust.
6. **Every 7 days** the app stops opening. Plug in and `flutter run --release` again.
   Data and sign-in survive.

Android: `flutter run` on any phone with USB debugging, or `flutter build apk --release`
and send the APK. No account or expiry.

---

## 9. App icon and splash

Everything is in [`assets/icons`](assets/icons) (rendered from the vector logo, sources in
`assets/svg`):

| File | Use |
|---|---|
| `app-icon-1024.png` | iOS App Icon (single-size), store icon. Opaque, square. iOS rounds the corners itself |
| `app-icon-1024-dark.png` | iOS 18 dark-mode icon (transparent background) |
| `app-icon-1024-tinted.png` | iOS 18 tinted icon (grayscale) |
| `android-adaptive-foreground.png` + `android-adaptive-background.png` | Android adaptive icon |
| `glyph-white.png` / `notification-icon-96.png` | Android notification small icon, Android 13 themed icon |
| `glyph-navy.png` | Light-background mark (empty states, about screen) |
| `../logo/chakra-wordmark-1200.png`, `productions-wordmark-1200.png` | Splash / login header |

With `flutter_launcher_icons`:

```yaml
flutter_launcher_icons:
  image_path: "assets/icons/app-icon-1024.png"
  ios: true
  remove_alpha_ios: true
  android: "ic_launcher"
  adaptive_icon_foreground: "assets/icons/android-adaptive-foreground.png"
  adaptive_icon_background: "#132A38"
  adaptive_icon_monochrome: "assets/icons/glyph-white.png"
```

For the iOS 18 dark/tinted variants, drop the two PNGs into
`Runner/Assets.xcassets/AppIcon.appiconset` in Xcode (Appearances → Any, Dark, Tinted).

Splash (`flutter_native_splash`): colour `#132A38`, image `chakra-wordmark-1200.png` at ~60%
width.

---

## 10. Try the API from a terminal

Sign in and save the token in a variable:

```bash
TOKEN=$(curl -s https://chakragroups.in/api/mobile/login -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"email":"you@chakragroups.in","password":"…","device_name":"curl","platform":"ios"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
```

Each call below uses that variable:

```bash
curl -s https://chakragroups.in/api/mobile/today -H "Authorization: Bearer $TOKEN"
```

```bash
curl -s https://chakragroups.in/api/mobile/notifications -H "Authorization: Bearer $TOKEN"
```

```bash
curl -s https://chakragroups.in/api/mobile/actions -H "Authorization: Bearer $TOKEN"
```

```bash
curl -s -X POST https://chakragroups.in/api/mobile/actions/list_todos -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"open_only":true}'
```

Sign test tokens out afterwards: `POST /logout`, or an admin signs the device out on chakragroups.in/planner/setup.

---

## 11. Security checklist

- Token only in `flutter_secure_storage` and the App Group (widget). Never in logs,
  analytics or crash reports.
- No passwords stored. Sign-in is a one-time exchange.
- Pin to `https://chakragroups.in`. Don't follow redirects to other hosts with the
  Authorization header.
- Clear the token, widget data and badge on logout or on any 401.
- Don't cache invoices, salaries or client contacts to disk. Keep them in memory. `/today`
  in the App Group is fine; it holds nothing financial except for admins (reel board only).
- Confirm before any `messages_client` action. Never retry writes automatically.

---

## 12. Changing the API

Portal side: `routes/mobile-api.php`, `app/Http/Controllers/Api/Mobile/*`,
tests in `tests/Feature/MobileApiTest.php`. Update [API.md](API.md) in the same commit. New
portal features usually need **no API change**: add a tool in `app/Tools` and it appears in
`GET /actions` for the people allowed to use it.
