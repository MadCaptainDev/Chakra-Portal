// Chakra — home-screen widgets. Runs in the free Scriptable app (iOS).
//
// Add as many Scriptable widgets as you like, all using this one script, and
// set each widget's Parameter to choose what it shows:
//   reels   Reel Planner — today
//   shoots  Today's shoots
//   hours   Hours logged today
//   todos   Open to-dos
//   today   Everything, in one summary
//
// The key below is read-only: it can only fetch this summary. Revoke it from
// Profile → iPhone widget in the portal if this phone is lost.
// The design is downloaded from the portal, so it stays up to date by itself.

const API_URL = "__API_URL__";
const TOKEN = "__TOKEN__";
const CODE_URL = "__CODE_URL__";

const fm = FileManager.local();
const codePath = fm.joinPath(fm.documentsDirectory(), "chakra-widget-app.js");

try {
  const req = new Request(CODE_URL + "?t=" + Date.now());
  req.timeoutInterval = 15;
  const code = await req.loadString();
  if (req.response.statusCode === 200 && code.includes("module.exports")) {
    fm.writeString(codePath, code);
  }
} catch (e) {
  // Offline: the last downloaded design is used below.
}

if (fm.fileExists(codePath)) {
  const app = importModule(codePath);
  await app.run({
    apiUrl: API_URL,
    token: TOKEN,
    parameter: args.widgetParameter,
    family: config.widgetFamily || "large",
    runsInWidget: config.runsInWidget,
  });
} else {
  const w = new ListWidget();
  w.backgroundColor = new Color("#0F2230");
  const t = w.addText("Chakra: open this script once with internet to finish setup.");
  t.textColor = Color.white();
  t.font = Font.systemFont(12);
  Script.setWidget(w);
}

Script.complete();
