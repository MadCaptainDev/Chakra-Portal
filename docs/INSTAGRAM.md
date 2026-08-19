# Instagram integration

Connects a client's Instagram Professional account to the portal so their
analytics can be read without anybody logging in as them.

Uses Meta's **Instagram API with Instagram Login** — not the retired Basic
Display API, and not the Facebook-Login variant that requires a linked Facebook
Page. Source of truth:
<https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login>

**Phases 1–4 are live: connect, status, disconnect, analytics.** Phase 5
(scheduled syncing) is not — see §10.

---

## 1. Create the Meta app

1. <https://developers.facebook.com/apps> → **Create app**.
2. Pick a use case that offers **Instagram**, then add the **Instagram**
   product and choose **API setup with Instagram login**.
3. That panel gives you an **Instagram App ID** and **Instagram App Secret**.
   These are *not* the Meta app's own App ID/Secret shown under App settings →
   Basic. Using the wrong pair fails with a generic "Invalid platform app",
   which points nowhere near the real cause.

This is a **separate app from the WhatsApp one**, by choice — a mistake in one
cannot take down the other.

## 2. Redirect URI

In the same panel, under **Business login settings**, set:

```
https://chakragroups.in/oauth/instagram/callback
```

Meta matches this **character for character**. The portal builds the same
string from `APP_URL`, and Setup → Instagram shows it with a copy button —
copy it from there rather than typing it. A mismatch is the single most common
failure and Meta's error for it is unhelpful.

## 3. Enter the credentials

Portal → **Setup → Instagram** (admin only). Paste the Instagram App ID and
Secret and save.

The secret is stored **encrypted with `APP_KEY`** and is never shown again;
saving with the secret box blank keeps the existing one. It is deliberately in
the database rather than `.env` so it can be rotated without a deploy — the
same decision as the WhatsApp settings.

## 3b. The other three URLs Meta asks for

All five values are on Setup → Instagram, numbered by the box they belong in.
Two of them look alike and go in different places, which is the easiest
mistake to make here:

| Meta field | URL |
|---|---|
| Business login → **Valid OAuth Redirect URI** | `/oauth/instagram/callback` |
| Webhooks → **Callback URL** | `/webhooks/instagram` |
| Webhooks → **Verify token** | shown on the settings screen |
| Business login → **Deauthorize callback URL** | `/webhooks/instagram/deauthorize` |
| Business login → **Data deletion request URL** | `/webhooks/instagram/data-deletion` |

The OAuth callback is behind a login because a person lands on it. The other
three are public because Meta calls them as a stranger — they authenticate
with a signature instead:

- the webhook with `X-Hub-Signature-256` over the raw body;
- deauthorize and data deletion with a `signed_request` form field, which is a
  *different* mechanism on the same app secret (see `SignedRequest`).

**Deauthorize** discards the token and marks the connection revoked, keeping
the record so the client can reconnect.

**Data deletion** removes what we hold about the Instagram account — token,
handle, profile, pushed events — and answers with the JSON Meta requires. The
client's own records here, such as invoices and the brand brief, are the
studio's business records and are untouched. A receipt row outlives the
deletion so the status URL Meta hands the person keeps working afterwards.

## 4. Permissions requested

| Scope | Why |
|---|---|
| `instagram_business_basic` | Mandatory. The account's id, username, type, follower and media counts. |
| `instagram_business_manage_insights` | Not used until Phase 3. Requested now so nobody has to be walked back through a second consent screen later. |

The dashboard also offers `instagram_business_manage_messages`,
`instagram_business_manage_comments` and `instagram_business_content_publish`.
They are deliberately **not** requested: this integration reads analytics, and
asking a client for permission to post as them or read their DMs — in order to
draw a chart — is a request to make when something actually needs it.

The authorize URL also carries `force_reauth=true`, so Instagram asks for
credentials even when the browser already has a session. Without it, a staff
member signed in to the studio's own Instagram would silently connect *that*
account to the client.

## 5. Testing before App Review

Until the app passes App Review, **only people with a role on the app can
authorise**. Add yourself and the client's Instagram account under
**App roles → Roles** (or as Instagram testers), and accept the invitation from
inside the Instagram account.

The account must also be **Professional** — Business or Creator. A personal
account cannot connect, and the portal says so in those words when it happens.

## 6. Connecting a client

Client page → **Social Media** tab → **Connect Instagram**. Needs the
`clients → manage` permission, the same bar as issuing a client login.

What happens:

1. The portal stores a random `state` **and the client id** in the session.
2. The browser goes to `www.instagram.com/oauth/authorize`.
3. The client signs in and approves.
4. Instagram returns to `/oauth/instagram/callback` with `code` and `state`.
5. The portal checks `state` with `hash_equals` and **consumes it**.
6. `code` → short-lived token (`api.instagram.com/oauth/access_token`).
7. Short-lived → long-lived, ~60 days
   (`graph.instagram.com/access_token?grant_type=ig_exchange_token`).
8. `GET graph.instagram.com/v23.0/me` for the username and account type.
9. The row is written to `social_accounts`, token encrypted.

**Why the client id is in the session and not the URL.** Meta permits one exact
redirect URI, so the callback cannot carry a `{client}` segment. Putting the
client id in the query or in `state` would put it in the browser, where it can
be edited — and an edited value would attach one client's Instagram account to
another client's record. The session decides; nothing the browser sends does.
There is a test named after this exact attack.

## 7. Disconnecting and reconnecting

**Disconnect** discards the stored token and marks the row `revoked`. The row
itself is kept, so the account's history stays attached and reconnecting later
is the same record rather than a new one.

**Reconnect** appears beside Disconnect and does not require disconnecting
first — the usual reason to press it is a token that has gone stale, and making
somebody disconnect to fix that is a step that exists only to satisfy the UI.

One Instagram account can belong to **one client**. Authorising the same
account for a second client is refused with a message naming the client that
already holds it, rather than silently splitting that account's analytics.

## 8. Troubleshooting

| What you see | What it means |
|---|---|
| "Invalid redirect_uri" | The URI in Meta does not match `APP_URL` exactly. Copy it from Setup → Instagram. |
| "Invalid platform app" | The Meta app's ID/Secret were used instead of the **Instagram** ones. |
| "The user is not an Instagram Business account" | Personal account. Switch to Business or Creator in the Instagram app under Settings → Account type. |
| "That Instagram link expired or did not match" | The `state` did not match, or the callback was opened without pressing Connect. Start again from the client page. |
| The connection was cancelled | The client pressed Cancel on Instagram. Nothing was changed. |
| Nothing happens / not configured | No app ID or secret saved yet. Setup → Instagram. |

Failures are logged with the status, Meta's error type/code and message.
**Tokens and the app secret are never logged**, never rendered, and never sent
to a browser — `SocialAccount::$hidden` keeps the token out of any accidental
JSON as well.

## 9. Analytics (Phases 3–4)

`php artisan instagram:sync` pulls fresh data for every connected account and
caches it in `social_insights` (one metric, one row, one day) and
`social_media_items` (recent posts/reels). The insights screen at
**Client → Social Media → View Analytics** reads only that cache — it never
calls Instagram on a page load. **Sync now** on that screen, or the command,
are the only things that do.

**The metric list is not from Meta's docs.** Meta's own pages disagree with
each other and with themselves across pages, and the reference sub-pages
don't render for a scraper. It was built by calling the live endpoint against
a real connected account and reading Meta's own rejection error, which names
every metric the endpoint currently accepts — see `InstagramInsights` for the
full account and the citation. `impressions` was probed deliberately and
confirmed retired (2 Jul 2024) in favour of `views`.

**Why every metric is synced one day at a time**, including the ones that
look like they should cover a whole range: a `total_value` metric (views,
engagement, profile views, and most others) answers with exactly one number
for whatever `[since, until]` was asked, confirmed by asking a real account
for both a 30-day and a 1-day range on the same metric. Caching that as one
row for "the last 30 days" breaks the moment anybody's window doesn't align
to the sync's window byte-for-byte — which it usually won't, since the
dashboard computes its own range independently of whenever sync last ran. The
first real sync against a live account hit exactly this: Reach showed real
numbers, Views and Engagement both showed 0, despite Instagram reporting real
non-zero numbers for every individual day underneath. One row per metric per
day, summed however a viewer's range requires, is what actually composes
correctly — the same shape `reach` and `follower_count` already use as
genuine time-series metrics.

An unsupported metric for a given account or media type is skipped, not
fatal: Meta rejects a whole batched request when one metric in it is invalid,
so a failed batch is retried one metric at a time and only the bad ones are
logged and dropped. `php artisan instagram:sync` prints which were skipped,
if any.

Every sync also refreshes the account's follower demographics (age, gender,
city) — see `docs/MONTHLY_REPORT.md` for the API shape and the monthly
report screen this feeds.

**Sync throttle.** There was no throttle at all: the Sync now button posted
straight to the sync service on every click, so a double-click or a refresh-
and-press-again fired the same batch of Instagram calls twice in a row for no
benefit — the data cannot have changed in the seconds between clicks, and a
studio running this against several clients' accounts could burn through
Meta's rate limit on repeat clicks alone. Each account now tracks
`last_synced_at`, and `SocialAccount::canSyncNow()` refuses a sync — both from
the client page and from `php artisan instagram:sync` — until
`sync_throttle_minutes` has passed since the last one. The interval is one
number on **Setup → Instagram** (default 15 minutes, admin-editable, applies
per account), not a constant buried in a controller. The command accepts
`--force` to sync a throttled account anyway; the summary line reports how
many accounts were skipped as throttled versus synced or failed.

**Content performance respects the selected date range.** The table used to
ignore `$since`/`$until` entirely and always show the most recently *synced*
items overall, so picking "Last 7 days" could still surface a post from weeks
ago sitting inside that most-recent-N window. It now filters by each item's
own `posted_at` falling inside the selected range. This is a different fix
from the one-value-per-range limitation above: Meta's *media* insights answer
with a single current total for a piece of content, not a per-day series the
way account metrics do, so there is no equivalent "reach as of the 12th" for
one post — filtering which posts appear is the correct and only fix for that
report.

## 10. Mapping a post into the Portfolio

A Portfolio piece (`portfolio_items`) can point at one cached post/reel
(`social_media_item_id`) instead of everything being hand-typed — see
`PortfolioItem::mapToInstagram()`/`refreshFromInstagram()`.

- **Where staff do it**: the client picker on the Portfolio create/edit form
  shows recent posts for the picked client, when that client has a connected,
  synced Instagram account (`GET portfolio-items/instagram-media`). Or, from
  a client's Instagram Insights → Content performance table, "Add to
  portfolio" opens the create form pre-selected to that exact post.
- **What's copied once, at map time**: `video_url` (the permalink, not the
  short-lived CDN URL), the thumbnail (downloaded and stored locally under
  `public/uploads/portfolio/thumbnails`, never hot-linked to Instagram's
  signed CDN URL — see `PublicUpload::storeFromUrl()`), and `published_on` if
  it was blank. Re-mapping to a different post repeats this; re-saving the
  same mapping does not.
- **What refreshes automatically, and when**: the performance fields
  (`views`, `reach`, `likes`, `comments`, `shares`, `saves`, and
  `avg_watch_seconds` for reels) are refreshed from cached `social_insights`
  every time that client's Instagram account is synced — the Sync now button
  or `instagram:sync`, not a separate schedule (`SocialAccount::refreshLinkedPortfolioItems()`).
  Business-impact figures, title, description, and every other case-study
  field are never touched — Instagram has no equivalent for them, and they
  stay exactly what staff typed.
- **Playback**: the public case-study page embeds the real Instagram post
  inline via Meta's public `embed.js` widget when pressed play — no token, no
  API call per page view. A small "View on Instagram" badge on the cover
  links to the real post independent of the play button.
- **What breaks the link**: disconnecting the account, or the underlying
  cached post being purged (re-sync churn, a data-deletion request),
  unlinks the mapping (`nullOnDelete`) but leaves the portfolio piece intact
  with whatever thumbnail and numbers were last written — a published case
  study is never deleted by something happening on the Instagram side.

## 11. Production notes

- Tokens last ~60 days and are refreshed by being used. There is no automatic
  refresh yet; a connection left completely idle for two months will need
  reconnecting.
- **No queue worker on this server**, but there is now a real crontab entry
  (`* * * * * php artisan schedule:run`) driving Laravel's scheduler --
  `instagram:sync --force` runs daily at 2am IST (see routes/console.php),
  on top of the page-view-triggered sync in `InstagramSyncRunner`. Both
  matter: page views keep whatever a staff member is actively looking at
  fresh immediately; the cron job is what protects every OTHER connected
  client from silently losing account-level history to Instagram's 90-day
  retention just because nobody happened to open their page in time. (Found
  live on Digital Harvest/Janet Hospitals, before this cron entry existed:
  April's daily reach and follower trend were already gone by the time
  anyone asked for them, because the account had never been synced before
  and nothing was driving a sync in the background.)
- App Review is required before clients outside your app roles can connect.
  `instagram_business_manage_insights` is the scope that needs it.
