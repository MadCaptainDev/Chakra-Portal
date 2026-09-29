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
//   today   Everything, one summary
// Left empty: reels for admins, today for everyone else.

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
  // A tap on a widget arrow opens this script with ?view=…&date=…
  const query = typeof args !== "undefined" && args.queryParameters ? args.queryParameters : {};
  if (!opts.runsInWidget && query.date) opts.date = query.date;
  const data = await load(opts);

  let mode = String(opts.parameter || "").trim().toLowerCase();
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
  const cachePath = fm.joinPath(fm.cacheDirectory(), "chakra-widget.json");

  try {
    const params = [opts.fresh ? "fresh=1" : null, opts.date ? "date=" + encodeURIComponent(opts.date) : null].filter(Boolean);
    const req = new Request(opts.apiUrl + (params.length ? "?" + params.join("&") : ""));
    req.headers = { Authorization: "Bearer " + opts.token, Accept: "application/json" };
    req.timeoutInterval = opts.fresh ? 60 : 25;
    const json = await req.loadJSON();

    if (req.response.statusCode === 401) return { error: "Key revoked. Make a new widget script on your Profile page." };
    if (req.response.statusCode !== 200) throw new Error("HTTP " + req.response.statusCode);

    // Only today is the offline copy; another day must never stand in for it.
    if (!opts.date) fm.writeString(cachePath, JSON.stringify(json));
    return json;
  } catch (e) {
    if (!opts.date && fm.fileExists(cachePath)) {
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
  const w = frame(d, "REEL PLANNER", tapUrl("reels") || r.url, family === "small" ? null : "reels");
  const total = r.total_posting;
  const posted = r.counts.posted || 0;
  const items = sortReels(r.items);

  if (family === "small") {
    bigNumber(w, total, 40);
    text(w, total === 1 ? "reel due today" : "reels due today", 11, C.dim);
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
    text(left, "due today", 11, C.dim);
    left.addSpacer(8);
    progress(left, total ? posted / total : 0, 96, 5);
    left.addSpacer(4);
    text(left, posted + "/" + total + " posted", 10, C.dim, true);

    row.addSpacer(14);

    const right = row.addStack();
    right.layoutVertically();
    if (items.length === 0) emptyLine(right, "Nothing due today");
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
  text(heroLabel, "today", 13, C.dim);
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
  if (items.length === 0) emptyLine(w, "Nothing due today 🎉");
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
  const w = frame(d, "SHOOTS TODAY", tapUrl("shoots") || s.url, family === "small" ? null : "shoots");

  if (family === "small") {
    bigNumber(w, s.count, 40);
    text(w, s.count === 1 ? "shoot today" : "shoots today", 11, C.dim);
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
  if (s.count === 0) emptyLine(w, "No shoots today");
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
    empty.addText("Nothing due today 🎉");
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

  listHead(table, "Shoots — " + dayOf(d).label, s.count + (s.count === 1 ? " shoot" : " shoots") + (dayOf(d).is_today ? " today" : ""));
  afterHead();

  if (s.count === 0) {
    const empty = new UITableRow();
    empty.addText("No shoots today");
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
 * `nav` (medium and large only -- iOS gives a small widget one tap target)
 * puts ‹ date › in the header. Each arrow is its own tap target opening the
 * full list for that day; the widget itself always shows today.
 */
function frame(d, title, url, nav) {
  const w = new ListWidget();
  w.setPadding(16, 16, 12, 16);
  if (url) w.url = url;

  const head = w.addStack();
  head.centerAlignContent();
  text(head, title, 10, C.accent, true);
  head.addSpacer();

  const day = dayOf(d);
  if (nav && day.prev && tapUrl(nav)) {
    arrow(head, "‹", tapUrl(nav, day.prev));
    head.addSpacer(6);
    text(head, day.label, 10, C.dim, true);
    head.addSpacer(6);
    arrow(head, "›", tapUrl(nav, day.next));
  } else {
    text(head, d.date_label, 10, C.faint);
  }

  w.addSpacer(8);
  return w;
}

function arrow(stack, glyph, url) {
  const a = stack.addStack();
  a.url = url;
  a.backgroundColor = C.card;
  a.cornerRadius = 9;
  a.setPadding(1, 8, 2, 8);
  text(a, glyph, 13, C.accent, true);
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
