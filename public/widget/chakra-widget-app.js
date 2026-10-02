// Chakra home-screen widgets -- the drawing code.
//
// Not pasted into Scriptable: the small script people paste (built from
// resources/widget/chakra-widget.js) downloads this file on every refresh and
// keeps the last good copy, so a design change here reaches every phone
// without anybody pasting again. It holds no key and no data.
//
// One script, several widgets. The widget's Parameter picks which:
//   reels   Reel Planner -- today (admins)
//   shoots  Today's shoots
//   hours   Hours logged today
//   todos   Open to-dos
//   inbox   Instagram DM & comment checks -- today
//   today   Everything, one summary
// Left empty: reels for admins, today for everyone else.
//
// reels and shoots take a day after a space -- "reels tomorrow",
// "reels yesterday", "shoots +2". Widgets cannot hold buttons (any tap opens
// Scriptable), so moving between days is an iOS widget stack: yesterday,
// today and tomorrow piled up and swiped through on the home screen.

const C = {
  bg: new Color("#0F2230"),
  card: new Color("#FFFFFF", 0.06),
  track: new Color("#FFFFFF", 0.1),
  text: Color.white(),
  dim: new Color("#E4F2F7", 0.6),
  faint: new Color("#E4F2F7", 0.38),
  accent: new Color("#67BCD4"),
  amber: new Color("#FBBF24"),
  orange: new Color("#FB923C"),
  violet: new Color("#A78BFA"),
  sky: new Color("#38BDF8"),
  teal: new Color("#2DD4BF"),
  green: new Color("#34D399"),
  red: new Color("#F87171"),
};

// Pipeline order: what needs work first, what is done last.
const REEL_STATUS = [
  { key: "To Be Shooted", short: "To shoot", color: C.violet },
  { key: "To Be Edited", short: "To edit", color: C.amber },
  { key: "Edit in Progress", short: "Editing", color: C.orange },
  { key: "Under Review", short: "Review", color: C.violet },
  { key: "Video Ready", short: "Ready", color: C.teal },
  { key: "Scheduled", short: "Scheduled", color: C.sky },
  { key: "Published", short: "Posted", color: C.green },
];

async function run(opts) {
  const family = opts.family || "large";
  // A tap on a widget opens this script with ?view=…&date=… for its list.
  const query = typeof args !== "undefined" && args.queryParameters ? args.queryParameters : {};
  if (!opts.runsInWidget && query.date) opts.date = query.date;

  // "reels +1", "shoots tomorrow": a widget pinned to another day. Stacked
  // on top of each other, they are swiped through without opening anything.
  const [modeWord, offsetWord] = String(opts.parameter || "").trim().toLowerCase().split(/\s+/);
  const offset = parseOffset(offsetWord);
  if (opts.runsInWidget && offset) opts.date = isoDay(offset);

  const data = await load(opts);

  let mode = modeWord || "";
  if (!mode) mode = data.reels ? "reels" : "today";

  /*
   * Widgets cannot scroll -- iOS does not allow it. Tapping the Reel Planner
   * or Shoots widget runs this script in the app instead (see tapUrl), and
   * that run shows the whole day as a native, scrollable list.
   */
  const view = typeof args !== "undefined" && args.queryParameters ? args.queryParameters.view : null;
  if (!opts.runsInWidget && !data.error && (view === "reels" || view === "shoots")) {
    await presentList(view === "reels" && data.reels ? "reels" : "shoots", data, opts);
    return;
  }

  let widget;
  if (data.error) widget = message("Chakra", data.error);
  else if (mode === "reels") widget = data.reels ? reelsWidget(data, family) : message("Reel Planner", "Only admins can see the Reel Planner.");
  else if (mode === "shoots") widget = shootsWidget(data, family);
  else if (mode === "hours") widget = hoursWidget(data, family);
  else if (mode === "todos") widget = todosWidget(data, family);
  else if (mode === "inbox") widget = inboxWidget(data, family);
  else widget = todayWidget(data, family);

  widget.backgroundColor = C.bg;
  widget.refreshAfterDate = new Date(Date.now() + 15 * 60 * 1000);

  if (opts.runsInWidget) {
    Script.setWidget(widget);
  } else if (family === "small") {
    await widget.presentSmall();
  } else if (family === "medium") {
    await widget.presentMedium();
  } else {
    await widget.presentLarge();
  }
}

// ================================================================ data

async function load(opts) {
  const fm = FileManager.local();
  // One offline copy per day asked for, so a stale copy is always the right day.
  const cachePath = fm.joinPath(fm.cacheDirectory(), "chakra-widget" + (opts.date ? "-" + opts.date : "") + ".json");

  try {
    const params = [opts.fresh ? "fresh=1" : null, opts.date ? "date=" + encodeURIComponent(opts.date) : null].filter(Boolean);
    const req = new Request(opts.apiUrl + (params.length ? "?" + params.join("&") : ""));
    req.headers = { Authorization: "Bearer " + opts.token, Accept: "application/json" };
    req.timeoutInterval = opts.fresh ? 60 : 25;
    const json = await req.loadJSON();

    if (req.response.statusCode === 401) return { error: "Key revoked. Make a new widget script on your Profile page." };
    if (req.response.statusCode !== 200) throw new Error("HTTP " + req.response.statusCode);

    fm.writeString(cachePath, JSON.stringify(json));
    return json;
  } catch (e) {
    if (fm.fileExists(cachePath)) {
      const cached = JSON.parse(fm.readString(cachePath));
      cached.stale = true;
      return cached;
    }
    return { error: "Can't reach the portal right now." };
  }
}

// ================================================================ reel planner

function reelsWidget(d, family) {
  const r = d.reels;
  const w = frame(d, "REEL PLANNER", tapUrl("reels", listDate(d)) || r.url);
  const total = r.total_posting;
  const posted = r.counts.posted || 0;
  const items = sortReels(r.items);

  if (family === "small") {
    bigNumber(w, total, 40);
    text(w, (total === 1 ? "reel due " : "reels due ") + when(d), 11, C.dim);
    w.addSpacer(8);
    progress(w, total ? posted / total : 0, 124, 6);
    w.addSpacer(5);
    text(w, posted + " of " + total + " posted", 10, total && posted === total ? C.green : C.dim, true);
    w.addSpacer();
    footer(w, d);
    return w;
  }

  if (family === "medium") {
    const row = w.addStack();
    row.topAlignContent();

    const left = row.addStack();
    left.layoutVertically();
    left.size = new Size(104, 0);
    bigNumber(left, total, 38);
    text(left, "due " + when(d), 11, C.dim);
    left.addSpacer(8);
    progress(left, total ? posted / total : 0, 96, 5);
    left.addSpacer(4);
    text(left, posted + "/" + total + " posted", 10, C.dim, true);

    row.addSpacer(14);

    const right = row.addStack();
    right.layoutVertically();
    if (items.length === 0) emptyLine(right, "Nothing due " + when(d));
    items.slice(0, 4).forEach((item, i) => {
      if (i) right.addSpacer(6);
      reelRow(right, item, false);
    });
    if (items.length > 4) {
      right.addSpacer(4);
      text(right, "+" + (items.length - 4) + " more", 10, C.faint);
    }

    w.addSpacer();
    footer(w, d);
    return w;
  }

  // Large: the headline, the pipeline, then the list.
  const hero = w.addStack();
  hero.bottomAlignContent();
  bigNumber(hero, total, 42);
  hero.addSpacer(8);
  const heroLabel = hero.addStack();
  heroLabel.layoutVertically();
  text(heroLabel, total === 1 ? "reel due" : "reels due", 13, C.text, true);
  text(heroLabel, when(d), 13, C.dim);
  heroLabel.addSpacer(6);
  hero.addSpacer();
  const done = hero.addStack();
  done.layoutVertically();
  text(done, posted + "/" + total, 20, total && posted === total ? C.green : C.text, true).rightAlignText();
  text(done, "posted", 11, C.dim).rightAlignText();
  done.addSpacer(6);

  w.addSpacer(10);
  progress(w, total ? posted / total : 0, 286, 6);
  w.addSpacer(12);

  const chips = w.addStack();
  statChip(chips, r.counts.to_be_edited, "To edit", C.amber);
  chips.addSpacer(6);
  statChip(chips, r.counts.edit_in_progress, "Editing", C.orange);
  chips.addSpacer(6);
  statChip(chips, r.counts.under_review, "Review", C.violet);
  chips.addSpacer(6);
  statChip(chips, posted, "Posted", C.green);

  w.addSpacer(14);

  const max = 4;
  if (items.length === 0) emptyLine(w, "Nothing due " + when(d) + " 🎉");
  items.slice(0, max).forEach((item, i) => {
    if (i) w.addSpacer(8);
    reelRow(w, item, true);
  });
  if (items.length > max) {
    w.addSpacer(6);
    text(w, "+" + (items.length - max) + " more · tap to see all", 10, C.accent, true);
  }

  w.addSpacer();
  footer(w, d);
  return w;
}

function reelRow(stack, item, withStatus) {
  const s = reelStatus(item.status);
  const row = stack.addStack();
  row.centerAlignContent();

  dot(row, s.color, 7);
  row.addSpacer(8);

  const body = row.addStack();
  body.layoutVertically();
  text(body, item.title, withStatus ? 13 : 12, C.text, true);
  if (withStatus && item.editor && item.editor !== "—") text(body, item.editor, 10, C.dim);

  row.addSpacer();
  if (withStatus) {
    row.addSpacer(8);
    pill(row, s.short, s.color);
  }
}

function reelStatus(status) {
  return REEL_STATUS.find((s) => s.key === status) || { key: status, short: status || "—", color: C.dim };
}

function sortReels(items) {
  const rank = (status) => {
    const i = REEL_STATUS.findIndex((s) => s.key === status);
    return i === -1 ? 50 : i;
  };
  return items.slice().sort((a, b) => rank(a.status) - rank(b.status));
}

// ================================================================ shoots

function shootsWidget(d, family) {
  const s = d.shoots;
  const w = frame(d, "SHOOTS", tapUrl("shoots", listDate(d)) || s.url);

  if (family === "small") {
    bigNumber(w, s.count, 40);
    text(w, (s.count === 1 ? "shoot " : "shoots ") + when(d), 11, C.dim);
    w.addSpacer(8);
    if (s.items[0]) {
      text(w, s.items[0].title, 12, C.text, true);
      text(w, s.items[0].time || s.items[0].location || "Today", 10, C.accent);
    }
    w.addSpacer();
    footer(w, d);
    return w;
  }

  const max = family === "medium" ? 3 : 6;
  if (s.count === 0) emptyLine(w, "No shoots " + when(d));
  s.items.slice(0, max).forEach((shoot, i) => {
    if (i) w.addSpacer(family === "medium" ? 6 : 10);
    const row = w.addStack();
    row.centerAlignContent();

    const when = row.addStack();
    when.size = new Size(58, 0);
    text(when, shoot.status === "live" ? "LIVE" : (shoot.time || "Today"), 11, shoot.status === "live" ? C.green : C.accent, true);

    const body = row.addStack();
    body.layoutVertically();
    text(body, shoot.title, 13, C.text, true);
    const sub = [shoot.client, shoot.location].filter(Boolean).join(" · ");
    if (sub && family !== "medium") text(body, sub, 10, C.dim);
    row.addSpacer();
  });
  if (s.count > max) {
    w.addSpacer(6);
    text(w, "+" + (s.count - max) + " more", 10, C.faint);
  }

  w.addSpacer();
  footer(w, d);
  return w;
}

// ================================================================ hours

function hoursWidget(d, family) {
  const own = d.hours;
  const team = d.team_hours;
  const w = frame(d, own ? "HOURS TODAY" : "TEAM HOURS", own ? own.url : d.portal_url);

  if (!own && !team) {
    emptyLine(w, "No hours to show");
    return w;
  }

  const main = own || team;
  bigNumber(w, main.today_label, family === "small" ? 34 : 44);
  text(w, own ? "logged today" : team.people + (team.people === 1 ? " person" : " people") + " logged today", 11, C.dim);

  if (family !== "small") {
    w.addSpacer(12);
    const row = w.addStack();
    if (own) statChip(row, own.week_label, "This week", C.accent);
    if (own) row.addSpacer(6);
    if (own) statChip(row, own.entries, own.entries === 1 ? "Entry" : "Entries", C.teal);
    if (own && team) row.addSpacer(6);
    if (own && team) statChip(row, team.today_label, "Team", C.violet);
  } else if (own) {
    w.addSpacer(6);
    text(w, own.week_label + " this week", 10, C.accent, true);
  }

  w.addSpacer();
  footer(w, d);
  return w;
}

// ================================================================ to-dos

function todosWidget(d, family) {
  const t = d.todos;
  const w = frame(d, "TO-DOS", t.url || d.portal_url);

  const top = w.addStack();
  top.bottomAlignContent();
  bigNumber(top, t.count, family === "small" ? 38 : 42);
  top.addSpacer(8);
  const lbl = top.addStack();
  lbl.layoutVertically();
  text(lbl, "open", 12, C.dim);
  if (t.overdue) text(lbl, t.overdue + " overdue", 11, C.red, true);
  lbl.addSpacer(6);

  if (family !== "small") {
    w.addSpacer(10);
    if (t.items.length === 0) emptyLine(w, "All clear");
    t.items.forEach((title, i) => {
      if (i) w.addSpacer(6);
      const row = w.addStack();
      row.centerAlignContent();
      dot(row, C.accent, 6);
      row.addSpacer(8);
      text(row, title, 12, C.text);
      row.addSpacer();
    });
  }

  w.addSpacer();
  footer(w, d);
  return w;
}

// ================================================================ inbox check

/*
 * Instagram DMs and comments, account by account -- the Inbox Check screen
 * on the home screen. A tap opens that screen in the portal, where the
 * ticking happens; the widget is the tally.
 */
function inboxWidget(d, family) {
  const ib = d.inbox;
  if (!ib) return message("Inbox Check", "No Instagram accounts are assigned to you.");

  const w = frame(d, "INBOX CHECK", ib.url);
  const allDone = ib.total > 0 && ib.left === 0;
  const fraction = ib.total ? ib.done / ib.total : 0;
  const headline = allDone ? "All clear" : ib.total === 0 ? "Nothing due" : String(ib.left);
  const sub = allDone ? "every inbox checked" : ib.total === 0 ? "no checks today" : (ib.left === 1 ? "check left" : "checks left");

  if (family === "small") {
    bigNumber(w, headline, allDone ? 26 : 40).textColor = allDone ? C.green : C.text;
    text(w, sub, 11, C.dim);
    w.addSpacer(8);
    progress(w, fraction, 124, 6);
    w.addSpacer(5);
    text(w, ib.done + " of " + ib.total + " done", 10, allDone ? C.green : C.dim, true);
    if (ib.late) text(w, ib.late + " late", 10, C.amber, true);
    w.addSpacer();
    footer(w, d);
    return w;
  }

  if (family === "medium") {
    const row = w.addStack();
    row.topAlignContent();

    const left = row.addStack();
    left.layoutVertically();
    left.size = new Size(104, 0);
    bigNumber(left, headline, allDone ? 24 : 38).textColor = allDone ? C.green : C.text;
    text(left, sub, 11, C.dim);
    left.addSpacer(8);
    progress(left, fraction, 96, 5);
    left.addSpacer(4);
    text(left, ib.done + "/" + ib.total + " done", 10, C.dim, true);
    if (ib.late) text(left, ib.late + " late", 10, C.amber, true);

    row.addSpacer(12);

    const right = row.addStack();
    right.layoutVertically();
    inboxRows(right, ib, 4, false);

    w.addSpacer();
    footer(w, d);
    return w;
  }

  // Large: the tally, the chips, every account.
  const hero = w.addStack();
  hero.bottomAlignContent();
  bigNumber(hero, headline, allDone ? 32 : 42).textColor = allDone ? C.green : C.text;
  hero.addSpacer(8);
  const heroLabel = hero.addStack();
  heroLabel.layoutVertically();
  text(heroLabel, sub, 13, C.text, true);
  text(heroLabel, allDone ? "for today" : "on " + ib.accounts_left + (ib.accounts_left === 1 ? " account" : " accounts"), 13, C.dim);
  heroLabel.addSpacer(allDone ? 2 : 6);
  hero.addSpacer();
  const tally = hero.addStack();
  tally.layoutVertically();
  text(tally, ib.done + "/" + ib.total, 20, allDone ? C.green : C.text, true).rightAlignText();
  text(tally, "done", 11, C.dim).rightAlignText();
  tally.addSpacer(6);

  w.addSpacer(10);
  progress(w, fraction, 286, 6);
  w.addSpacer(12);

  inboxRows(w, ib, 6, true);

  w.addSpacer();
  if (ib.last) {
    text(w, "Last: " + ib.last.text + " · " + ib.last.at, 9, C.dim);
    w.addSpacer(2);
  }
  footer(w, d);
  return w;
}

function inboxRows(stack, ib, max, roomy) {
  if (ib.accounts.length === 0) {
    emptyLine(stack, "No accounts today");
    return;
  }
  ib.accounts.slice(0, max).forEach((a, i) => {
    if (i) stack.addSpacer(roomy ? 7 : 5);
    const row = stack.addStack();
    row.centerAlignContent();
    dot(row, a.left === 0 ? C.green : a.late ? C.amber : C.accent, 6);
    row.addSpacer(7);
    text(row, a.handle, roomy ? 13 : 12, a.left === 0 ? C.dim : C.text, a.left > 0);
    row.addSpacer();
    a.checks.forEach((c, j) => {
      if (j) row.addSpacer(4);
      checkPill(row, c, roomy);
    });
  });
  if (ib.accounts.length > max) {
    stack.addSpacer(4);
    text(stack, "+" + (ib.accounts.length - max) + " more", 10, C.faint);
  }
}

// "✓ DMs" when done, "DMs 4" with new chats waiting, plain when open.
function checkPill(row, c, roomy) {
  const label = roomy ? c.short : c.short.slice(0, 1);
  if (c.state === "done") return pill(row, "✓ " + label, C.green);
  if (c.state === "none") return pill(row, label, C.faint);
  if (c.state === "skipped") return pill(row, "– " + label, C.dim);
  const color = c.late ? C.amber : C.accent;
  return pill(row, c.new ? label + " " + c.new : label, color);
}

// ================================================================ everything

function todayWidget(d, family) {
  const w = frame(d, "TODAY", d.portal_url);
  const main = d.hours || d.team_hours;

  if (main) {
    text(w, d.hours ? "Hours" : "Team hours", 10, C.dim);
    bigNumber(w, main.today_label, family === "small" ? 28 : 32);
    w.addSpacer(8);
  }

  const lines = [
    [C.accent, d.shoots.count + (d.shoots.count === 1 ? " shoot" : " shoots")],
    d.reels ? [C.amber, d.reels.total_posting + " reels due · " + d.reels.counts.posted + " posted"] : null,
    [d.todos.overdue ? C.red : C.teal, d.todos.count + " to-dos" + (d.todos.overdue ? " · " + d.todos.overdue + " overdue" : "")],
    d.inbox ? [d.inbox.total && d.inbox.left === 0 ? C.green : d.inbox.late ? C.amber : C.violet, d.inbox.total && d.inbox.left === 0 ? "Inbox check done" : d.inbox.left + " inbox checks left"] : null,
  ].filter(Boolean);

  lines.forEach(([color, label], i) => {
    if (i) w.addSpacer(5);
    const row = w.addStack();
    row.centerAlignContent();
    dot(row, color, 6);
    row.addSpacer(7);
    text(row, label, family === "small" ? 11 : 13, C.text, true);
  });

  w.addSpacer();
  footer(w, d);
  return w;
}

// ================================================================ full lists (tap a widget)

// Runs this same script inside Scriptable, which then shows the full list.
function tapUrl(view, date) {
  try {
    return "scriptable:///run/" + encodeURIComponent(Script.name()) + "?view=" + view + (date ? "&date=" + date : "");
  } catch (e) {
    return null;
  }
}

/*
 * One table for the whole visit. Refresh re-fetches with ?fresh=1 (which
 * makes the portal pull from Notion first), then empties and refills this
 * same table, so the list redraws in place instead of closing.
 */
async function presentList(view, data, opts) {
  const table = new UITable();
  table.showSeparators = true;
  let busy = false;
  let date = opts.date || null;

  // Refresh (fresh: true) and the day buttons (a new date) both land here.
  const reload = async (current, extra) => {
    if (busy) return;
    busy = extra.fresh ? "Getting the latest from Notion" : "Loading " + (extra.date === dayOf(current).today ? "today" : "that day");
    fill(current);

    const next = await load(Object.assign({}, opts, { date: date, fresh: false }, extra));
    busy = false;
    if (next.error) {
      fill(Object.assign({}, current, { stale: true }));
      return;
    }
    if (extra.date !== undefined) date = extra.date;
    fill(next);
  };

  const fill = (d) => {
    table.removeAllRows();
    const afterHead = () => {
      dayNavRow(table, d, busy, (to) => reload(d, { date: to }));
      refreshRow(table, d, busy, () => reload(d, { fresh: true }));
    };
    if (view === "reels" && d.reels) reelsRows(table, d, afterHead);
    else shootsRows(table, d, afterHead);
    table.reload();
  };

  fill(data);
  await table.present(true);
}

function refreshRow(table, d, busy, onTap) {
  const row = new UITableRow();
  row.height = 50;
  row.dismissOnSelect = false;
  if (!busy) row.onSelect = onTap;

  const cell = row.addText(
    busy ? "Loading…" : "↻  Refresh",
    busy ? busy : (d.stale ? "Offline · last update " : "Updated ") + d.generated_at,
  );
  cell.titleColor = busy ? C.dim : C.accent;
  cell.titleFont = Font.semiboldSystemFont(15);
  cell.subtitleFont = Font.systemFont(12);
  table.addRow(row);
}

// ‹ Previous day · Today · Next day › -- three buttons on one row.
function dayNavRow(table, d, busy, go) {
  const day = dayOf(d);
  if (!day.prev) return;

  const row = new UITableRow();
  row.height = 50;
  row.dismissOnSelect = false;

  const prev = row.addButton("‹  " + shortDay(day.prev));
  prev.leftAligned();
  prev.widthWeight = 36;
  prev.onTap = () => { if (!busy) go(day.prev); };

  const now = row.addButton(day.is_today ? "Today" : "Back to today");
  now.centerAligned();
  now.widthWeight = 28;
  now.onTap = () => { if (!busy && !day.is_today) go(day.today); };

  const next = row.addButton(shortDay(day.next) + "  ›");
  next.rightAligned();
  next.widthWeight = 36;
  next.onTap = () => { if (!busy) go(day.next); };

  table.addRow(row);
}

// Older cached copies predate `day`; they are always today, with no arrows.
function dayOf(d) {
  const day = d.day || { date: d.date, label: d.date_label, is_today: true };
  return Object.assign({ today: d.date }, day);
}

function shortDay(iso) {
  const [y, m, dd] = iso.split("-").map(Number);
  const dt = new Date(y, m - 1, dd);
  return dt.toLocaleDateString("en-IN", { weekday: "short", day: "numeric", month: "short" });
}

function listHead(table, title, subtitle) {
  const head = new UITableRow();
  head.height = 76;
  const cell = head.addText(title, subtitle);
  cell.titleFont = Font.boldSystemFont(20);
  cell.subtitleFont = Font.systemFont(14);
  table.addRow(head);
}

function reelsRows(table, d, afterHead) {
  const r = d.reels;
  const items = sortReels(r.items);

  listHead(table, "Reel Planner — " + dayOf(d).label, r.total_posting + " due · " + r.counts.posted + " posted");
  afterHead();

  if (items.length === 0) {
    const empty = new UITableRow();
    empty.addText("Nothing due " + when(d) + " 🎉");
    table.addRow(empty);
  }

  // Grouped in pipeline order, so the list reads as what is left to do.
  REEL_STATUS.concat([{ key: null }]).forEach((status) => {
    const group = items.filter((item) =>
      status.key === null ? !REEL_STATUS.some((s) => s.key === item.status) : item.status === status.key);
    if (group.length === 0) return;

    const s = status.key === null ? { short: "Other", color: C.dim } : status;
    const section = new UITableRow();
    section.isHeader = true;
    section.height = 40;
    const label = section.addText(s.short.toUpperCase() + "  ·  " + group.length);
    label.titleColor = s.color;
    label.titleFont = Font.boldSystemFont(13);
    table.addRow(section);

    group.forEach((item) => {
      const row = new UITableRow();
      row.height = 58;
      row.dismissOnSelect = false;
      row.onSelect = () => Safari.open(r.url);

      const main = row.addText(item.title, item.editor && item.editor !== "—" ? item.editor : " ");
      main.widthWeight = 72;
      main.titleFont = Font.semiboldSystemFont(15);
      main.subtitleFont = Font.systemFont(12);

      const st = row.addText(reelStatus(item.status).short);
      st.widthWeight = 28;
      st.rightAligned();
      st.titleColor = reelStatus(item.status).color;
      st.titleFont = Font.semiboldSystemFont(13);
      table.addRow(row);
    });
  });

  openRow(table, "Open the Reel Planner in the portal", r.url);
}

function shootsRows(table, d, afterHead) {
  const s = d.shoots;

  listHead(table, "Shoots — " + dayOf(d).label, s.count + (s.count === 1 ? " shoot " : " shoots ") + when(d));
  afterHead();

  if (s.count === 0) {
    const empty = new UITableRow();
    empty.addText("No shoots " + when(d));
    table.addRow(empty);
  }

  s.items.forEach((shoot) => {
    const row = new UITableRow();
    row.height = 62;
    row.dismissOnSelect = false;
    row.onSelect = () => Safari.open(s.url);

    const when = row.addText(shoot.status === "live" ? "LIVE" : (shoot.time || "Today"));
    when.widthWeight = 22;
    when.titleColor = shoot.status === "live" ? C.green : C.accent;
    when.titleFont = Font.semiboldSystemFont(13);

    const main = row.addText(shoot.title, [shoot.client, shoot.location].filter(Boolean).join(" · ") || " ");
    main.widthWeight = 78;
    main.titleFont = Font.semiboldSystemFont(15);
    main.subtitleFont = Font.systemFont(12);
    table.addRow(row);
  });

  openRow(table, "Open Shoots in the portal", s.url);
}

function openRow(table, label, url) {
  const row = new UITableRow();
  row.height = 54;
  row.onSelect = () => Safari.open(url);
  const cell = row.addText(label + "  ›");
  cell.titleColor = C.accent;
  cell.titleFont = Font.semiboldSystemFont(15);
  table.addRow(row);
}

// ================================================================ pieces

/*
 * No buttons: any tap on a widget opens Scriptable, so the header only
 * says which day this is. Moving between days is done by stacking a
 * yesterday / today / tomorrow widget and swiping (see the Parameter note
 * at the top of this file).
 */
function frame(d, title, url) {
  const w = new ListWidget();
  w.setPadding(16, 16, 12, 16);
  if (url) w.url = url;

  const head = w.addStack();
  head.centerAlignContent();
  text(head, title, 10, C.accent, true);
  head.addSpacer();

  const day = dayOf(d);
  if (day.is_today) {
    text(head, day.label, 10, C.faint);
  } else {
    text(head, relWord(d) + " · ", 10, C.amber, true);
    text(head, day.label, 10, C.faint);
  }

  w.addSpacer(8);
  return w;
}

// "+1", "-2", "tomorrow", "yesterday" → a day offset; anything else is today.
function parseOffset(word) {
  if (!word) return 0;
  if (word === "tomorrow") return 1;
  if (word === "yesterday") return -1;
  const n = parseInt(word, 10);
  return Number.isFinite(n) && Math.abs(n) <= 90 ? n : 0;
}

// The phone's own calendar day, offset -- not UTC, which is a day behind
// before 5:30 AM in India.
function isoDay(offset) {
  const dt = new Date();
  dt.setDate(dt.getDate() + offset);
  const pad = (n) => String(n).padStart(2, "0");
  return dt.getFullYear() + "-" + pad(dt.getMonth() + 1) + "-" + pad(dt.getDate());
}

function dayDiff(d) {
  const day = dayOf(d);
  const a = Date.parse(day.date + "T00:00:00Z");
  const b = Date.parse(d.date + "T00:00:00Z");
  return Math.round((a - b) / 86400000);
}

function relWord(d) {
  const n = dayDiff(d);
  if (n === 0) return "Today";
  if (n === 1) return "Tomorrow";
  if (n === -1) return "Yesterday";
  return (n > 0 ? "In " + n + " days" : -n + " days ago");
}

// "today", "tomorrow", "on Fri, 2 Oct" -- for sentences like "reels due …".
function when(d) {
  const n = dayDiff(d);
  if (n === 0) return "today";
  if (n === 1) return "tomorrow";
  if (n === -1) return "yesterday";
  return "on " + dayOf(d).label;
}

function listDate(d) {
  const day = dayOf(d);
  return day.is_today ? null : day.date;
}

function footer(w, d) {
  text(w, (d.stale ? "Offline · last update " : "Updated ") + d.generated_at, 8, d.stale ? C.amber : C.faint);
}

function bigNumber(stack, value, size) {
  const t = stack.addText(String(value));
  t.font = Font.boldRoundedSystemFont(size);
  t.textColor = C.text;
  t.minimumScaleFactor = 0.5;
  t.lineLimit = 1;
  return t;
}

function statChip(stack, value, label, color) {
  const chip = stack.addStack();
  chip.layoutVertically();
  chip.backgroundColor = C.card;
  chip.cornerRadius = 10;
  chip.setPadding(6, 9, 6, 9);
  chip.size = new Size(0, 42);
  text(chip, String(value ?? 0), 17, value ? color : C.faint, true);
  text(chip, label, 9, C.dim);
  chip.addSpacer();
}

function pill(stack, label, color) {
  const p = stack.addStack();
  p.backgroundColor = new Color(color.hex, 0.16);
  p.cornerRadius = 7;
  p.setPadding(3, 7, 3, 7);
  text(p, label, 9, color, true);
}

function dot(stack, color, size) {
  const d = stack.addStack();
  d.size = new Size(size, size);
  d.cornerRadius = size / 2;
  d.backgroundColor = color;
}

function progress(stack, fraction, width, height) {
  const ctx = new DrawContext();
  ctx.size = new Size(width, height);
  ctx.opaque = false;
  ctx.respectScreenScale = true;

  const track = new Path();
  track.addRoundedRect(new Rect(0, 0, width, height), height / 2, height / 2);
  ctx.addPath(track);
  ctx.setFillColor(C.track);
  ctx.fillPath();

  if (fraction > 0) {
    const fill = new Path();
    fill.addRoundedRect(new Rect(0, 0, Math.max(height, width * Math.min(fraction, 1)), height), height / 2, height / 2);
    ctx.addPath(fill);
    ctx.setFillColor(C.green);
    ctx.fillPath();
  }

  const img = stack.addImage(ctx.getImage());
  img.imageSize = new Size(width, height);
  return img;
}

function emptyLine(stack, label) {
  text(stack, label, 12, C.dim);
}

function text(stack, value, size, color, bold) {
  const t = stack.addText(String(value));
  t.font = bold ? Font.semiboldSystemFont(size) : Font.systemFont(size);
  t.textColor = color;
  t.lineLimit = 1;
  return t;
}

function message(title, body) {
  const w = new ListWidget();
  w.setPadding(16, 16, 16, 16);
  text(w, title.toUpperCase(), 10, C.accent, true);
  w.addSpacer(8);
  const t = w.addText(body);
  t.font = Font.systemFont(12);
  t.textColor = C.text;
  return w;
}

module.exports = { run };
