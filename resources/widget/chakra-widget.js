// Chakra — today on your home screen.
// Runs in the free Scriptable app (iOS). Works as a small, medium or large widget.
// The key below is read-only: it can only fetch this summary. Revoke it from
// Profile → Phone widget in the portal if this phone is lost.

const API_URL = "__API_URL__";
const TOKEN = "__TOKEN__";

const C = {
  bg: new Color("#132A38"),
  card: new Color("#1D3A4B"),
  text: Color.white(),
  dim: new Color("#E4F2F7", 0.65),
  accent: new Color("#67BCD4"),
  amber: new Color("#FCD34D"),
  green: new Color("#6EE7B7"),
  red: new Color("#FCA5A5"),
  violet: new Color("#C4B5FD"),
};

const family = config.widgetFamily || "large";
const data = await load();
const widget = data.error ? errorWidget(data.error) : build(data);

widget.backgroundColor = C.bg;
widget.refreshAfterDate = new Date(Date.now() + 15 * 60 * 1000);
if (!data.error && data.portal_url) widget.url = data.portal_url;

if (config.runsInWidget) {
  Script.setWidget(widget);
} else {
  await widget.presentLarge();
}
Script.complete();

// ---------------------------------------------------------------- data

async function load() {
  const fm = FileManager.local();
  const cachePath = fm.joinPath(fm.cacheDirectory(), "chakra-widget.json");

  try {
    const req = new Request(API_URL);
    req.headers = { Authorization: "Bearer " + TOKEN, Accept: "application/json" };
    req.timeoutInterval = 30;
    const json = await req.loadJSON();

    if (req.response.statusCode === 401) return { error: "Key revoked. Make a new widget script on your Profile page." };
    if (req.response.statusCode !== 200) throw new Error("HTTP " + req.response.statusCode);

    fm.writeString(cachePath, JSON.stringify(json));
    return json;
  } catch (e) {
    // Offline or the server hiccupped: show the last good copy, marked stale.
    if (fm.fileExists(cachePath)) {
      const cached = JSON.parse(fm.readString(cachePath));
      cached.stale = true;
      return cached;
    }
    return { error: "Can't reach the portal right now." };
  }
}

// ---------------------------------------------------------------- layout

function build(d) {
  const w = new ListWidget();
  w.setPadding(14, 14, 12, 14);

  header(w, d);
  w.addSpacer(family === "small" ? 6 : 8);

  if (family === "small") small(w, d);
  else if (family === "medium") medium(w, d);
  else large(w, d);

  w.addSpacer();
  footer(w, d);
  return w;
}

function header(w, d) {
  const row = w.addStack();
  row.centerAlignContent();
  label(row, "CHAKRA", 10, C.accent, true);
  row.addSpacer();
  label(row, d.date_label, 10, C.dim);
}

function footer(w, d) {
  const t = w.addText((d.stale ? "Offline · " : "Updated ") + d.generated_at);
  t.font = Font.systemFont(8);
  t.textColor = d.stale ? C.amber : C.dim;
}

// Hours shown first: your own if you log work, otherwise the team's.
function hoursBlock(stack, d, big) {
  const own = d.hours;
  const team = d.team_hours;
  const main = own || team;
  if (!main) return;

  label(stack, own ? "HOURS TODAY" : "TEAM TODAY", 9, C.dim, true);
  const v = stack.addText(main.today_label);
  v.font = Font.boldRoundedSystemFont(big ? 30 : 24);
  v.textColor = C.text;
  v.minimumScaleFactor = 0.6;

  if (own) label(stack, own.week_label + " this week", 10, C.dim);
  else label(stack, team.people + (team.people === 1 ? " person" : " people") + " logged", 10, C.dim);
}

function small(w, d) {
  hoursBlock(w, d, true);
  w.addSpacer(6);
  line(w, "🎬", countText(d.shoots.count, "shoot"), d.shoots.count ? C.text : C.dim);
  if (d.reels) line(w, "🎞", d.reels.total_posting + " reels due", reelColor(d.reels));
  else line(w, "✓", countText(d.todos.count, "to-do"), d.todos.overdue ? C.red : C.text);
}

function medium(w, d) {
  const row = w.addStack();
  row.topAlignContent();

  const left = row.addStack();
  left.layoutVertically();
  left.size = new Size(110, 0);
  hoursBlock(left, d, false);
  left.addSpacer(4);
  line(left, "✓", countText(d.todos.count, "to-do"), d.todos.overdue ? C.red : C.text);

  row.addSpacer(10);

  const right = row.addStack();
  right.layoutVertically();
  shootList(right, d, 2);
  if (d.reels) {
    right.addSpacer(4);
    reelSummary(right, d.reels);
  }
}

function large(w, d) {
  const top = w.addStack();
  const hours = top.addStack();
  hours.layoutVertically();
  hoursBlock(hours, d, true);
  top.addSpacer();
  if (d.hours && d.team_hours) {
    const team = top.addStack();
    team.layoutVertically();
    label(team, "TEAM", 9, C.dim, true);
    label(team, d.team_hours.today_label, 16, C.text, true);
    label(team, d.team_hours.people + " logged", 10, C.dim);
  }

  w.addSpacer(10);
  shootList(w, d, 3);

  if (d.reels) {
    w.addSpacer(10);
    reelSummary(w, d.reels);
    d.reels.items.slice(0, 3).forEach((r) => {
      const t = w.addText("• " + r.title + "  ·  " + r.status);
      t.font = Font.systemFont(10);
      t.textColor = C.dim;
      t.lineLimit = 1;
    });
  }

  w.addSpacer(10);
  label(w, "TO-DOS · " + d.todos.count + (d.todos.overdue ? " (" + d.todos.overdue + " overdue)" : ""), 9, d.todos.overdue ? C.red : C.dim, true);
  if (d.todos.items.length === 0) label(w, "Nothing open today", 11, C.dim);
  d.todos.items.forEach((title) => {
    const t = w.addText("• " + title);
    t.font = Font.systemFont(11);
    t.textColor = C.text;
    t.lineLimit = 1;
  });
}

function shootList(stack, d, max) {
  label(stack, "SHOOTS TODAY · " + d.shoots.count, 9, C.dim, true);
  if (d.shoots.count === 0) {
    label(stack, "No shoots", 11, C.dim);
    return;
  }
  d.shoots.items.slice(0, max).forEach((s) => {
    const r = stack.addStack();
    r.centerAlignContent();
    label(r, s.time || "—", 10, s.status === "live" ? C.green : C.accent, true);
    r.addSpacer(5);
    const t = r.addText(s.title + (s.status === "live" ? " · LIVE" : ""));
    t.font = Font.systemFont(11);
    t.textColor = C.text;
    t.lineLimit = 1;
  });
  if (d.shoots.count > max) label(stack, "+" + (d.shoots.count - max) + " more", 9, C.dim);
}

function reelSummary(stack, reels) {
  label(stack, "REEL PLANNER · " + reels.total_posting + " DUE", 9, C.dim, true);
  const r = stack.addStack();
  const c = reels.counts;
  chip(r, c.to_be_edited, "edit", C.amber);
  chip(r, c.edit_in_progress, "wip", C.amber);
  chip(r, c.under_review, "review", C.violet);
  chip(r, c.posted, "posted", C.green);
}

// ---------------------------------------------------------------- bits

function chip(stack, n, text, color) {
  const t = stack.addText(n + " " + text);
  t.font = Font.semiboldSystemFont(10);
  t.textColor = n ? color : C.dim;
  stack.addSpacer(7);
}

function line(stack, icon, text, color) {
  const t = stack.addText(icon + "  " + text);
  t.font = Font.semiboldSystemFont(12);
  t.textColor = color;
  t.lineLimit = 1;
}

function label(stack, text, size, color, bold) {
  const t = stack.addText(String(text));
  t.font = bold ? Font.boldSystemFont(size) : Font.systemFont(size);
  t.textColor = color;
  t.lineLimit = 1;
  return t;
}

function countText(n, word) {
  return n + " " + word + (n === 1 ? "" : "s");
}

function reelColor(reels) {
  const pending = reels.counts.to_be_edited + reels.counts.edit_in_progress;
  return pending ? C.amber : C.green;
}

function errorWidget(message) {
  const w = new ListWidget();
  w.setPadding(14, 14, 14, 14);
  label(w, "CHAKRA", 10, C.accent, true);
  w.addSpacer(6);
  const t = w.addText(message);
  t.font = Font.systemFont(12);
  t.textColor = C.text;
  return w;
}
