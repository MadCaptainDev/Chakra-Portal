# Instagram integration

Connects a client's Instagram Professional account to the portal so their
analytics can be read without anybody logging in as them.

Uses Meta's **Instagram API with Instagram Login** — not the retired Basic
Display API, and not the Facebook-Login variant that requires a linked Facebook
Page. Source of truth:
<https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login>

**Phase 1 is what exists today: connect, status, disconnect.** No analytics are
fetched yet. Phases 3–5 add insights, a dashboard and scheduled syncing.

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

## 9. Production notes

- Tokens last ~60 days and are refreshed by being used. Phase 5 adds the
  refresh; until then a connection left completely idle for two months will
  need reconnecting.
- **There is no cron and no queue worker on this server.** `schedule:run` never
  fires, so the scheduled sync in Phase 5 will need either a real cron entry or
  the catch-up-middleware approach this codebase already uses for recurring
  invoices (`EnsureRecurringInvoicesGenerated`). Worth settling before Phase 5,
  not during it.
- App Review is required before clients outside your app roles can connect.
  `instagram_business_manage_insights` is the scope that needs it.
