# Admin action catalogue

Every action an admin can run through `POST /api/mobile/actions/{name}`. This file is generated from the live portal on 2026-11-04.
The live list is always `GET /api/mobile/actions`: it returns each action's full JSON Schema with descriptions, and only the actions the signed-in person may use.

- `*` marks a required argument. `a|b|c` is an enum.
- **Result** is the shape of `result` in `{ "ok": true, "result": … }`. Types are shown in place of values, and a list shows its first item.
- ✉️ = `messages_client: true`. It WhatsApps a real client, so show a confirmation sheet first. 🗑 = `destructive: true`.
- **text** means the result is a plain string meant for a model to read. Show it as preformatted text, or use the structured action named next to it.

## Flags

| Flag | Actions |
|---|---|
| ✉️ messages_client | `send_quotation_whatsapp`, `send_invoice_whatsapp`, `send_brief_reminder`, `send_ad_report` |
| 🗑 destructive | `delete_proposal` |

## Use these structured ones in screens

| Instead of (text) | Use |
|---|---|
| `invoice_lookup`, `overdue_invoices` | `list_invoices` (`status: overdue`), `money_due` |
| `money_summary` | `money_due` + `needs_attention` + `hours_vs_revenue` |
| `find_client` | `list_clients` (`search`) |
| `todays_shoots`, `shoots_between` | `list_shoots`, `get_shoot`, `/today` shoots |
| `timesheet_gaps` | text only for now, so show it as text |

## Invoices

### `approve_invoice`: Approve an invoice
write · permission: `invoices.approve`

**Args:** `invoice*`: string

**Result:** `{"approved":"bool","invoice":"<get_invoice shape>"}`

### `create_invoice`: Create an invoice
write · permission: `invoices.create`

**Args:** `client*`: string<br>`items*`: array<br>`invoice_date`: string<br>`due_date`: string<br>`intro_text`: string<br>`discount_label`: string<br>`discount_amount`: number<br>`allow_second_this_month`: boolean

**Result:** `{"created":"bool","sent_to_client":"false","...":"<get_invoice shape>"}`

### `create_recurring_invoice`: Set up a recurring invoice
write · permission: `invoices.manage`

**Args:** `client`: string<br>`from_invoice`: string<br>`items`: array<br>`frequency`: weekly|monthly|quarterly|yearly<br>`day_of_month`: integer<br>`next_run_on`: string<br>`due_days`: integer<br>`label`: string<br>`intro_text`: string<br>`discount_label`: string<br>`discount_amount`: number<br>`allow_another`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `duplicate_invoice`: Duplicate an invoice
write · permission: `invoices.create`

**Args:** `invoice*`: string<br>`invoice_date`: string<br>`allow_second_this_month`: boolean

**Result:** `{"created":"bool","copied_from":"string","sent_to_client":"false","...":"<get_invoice shape>"}`

### `get_invoice`: Read one invoice in full
read · permission: `invoices.view`

**Args:** `invoice*`: string

**Result:** `{"invoice_id":"int","number":"string","client":"string","status":"string","invoice_date":"string","due_date":"null","intro_text":"string","lines":[{"description":"string","quantity":"number","unit_price":"number","line_total":"number"}],"subtotal":"number","discount":"null","total":"number","paid":"number","balance_due":"number","payments":[],"from_recurring_schedule":"null","open_in_portal":"string"}`

### `list_invoices`: List invoices
read · permission: `invoices.view`

**Args:** `status`: open|overdue|partial|unpaid|pending_approval|paid|all<br>`client`: string<br>`from`: date<br>`to`: date<br>`search`: string<br>`limit`: integer

**Result:** `{"status":"string","count":"int","shown":"int","balance_due_shown":"number","invoices":[{"invoice_id":"int","number":"string","client":"string","client_id":"int","client_logo_url":"string","status":"string","invoice_date":"string","due_date":"null","days_overdue":"int","total":"number","paid":"number","balance_due":"number"}]}`

### `list_recurring_invoices`: List recurring invoices
read · permission: `invoices.manage`

**Args:** `client`: string

**Result:** `{"schedules":[{"schedule_id":"int","client":"string","label":"string","active":"bool","frequency":"string","day_of_month":"int","next_invoice_on":"string","last_generated_on":"string","due_days":"int","lines":[{"description":"string","quantity":"string","unit_price":"number"}],"discount":{"label":"string","amount":"number"},"note":"string"}]}`

### `record_invoice_payment`: Record a payment received on an invoice
write · permission: `invoices.edit`

**Args:** `invoice*`: string<br>`amount`: number<br>`paid_on`: date<br>`method`: string<br>`note`: string

**Result:** `{"recorded":"number","paid_on":"YYYY-MM-DD","invoice":"<get_invoice shape>"}`

### `send_invoice_whatsapp`: Send an invoice to the client on WhatsApp
✉️ write · permission: `invoices.approve`

**Args:** `invoice*`: string<br>`phone`: string

**Result:** `{"sent":"bool","to":"string","client":"string","invoice":"string","total":"number","note":"string"}`

### `update_invoice`: Edit an invoice
write · permission: `invoices.edit`

**Args:** `invoice*`: string<br>`items`: array<br>`invoice_date`: string<br>`due_date`: string<br>`intro_text`: string<br>`discount_label`: string<br>`discount_amount`: number<br>`client`: string

**Result:** `{"updated":"bool","sent_to_client":"false","before":"object","after":"<get_invoice shape>"}`

### `update_recurring_invoice`: Edit or pause a recurring invoice
write · permission: `invoices.manage`

**Args:** `schedule_id*`: integer<br>`active`: boolean<br>`items`: array<br>`frequency`: weekly|monthly|quarterly|yearly<br>`day_of_month`: integer<br>`next_run_on`: string<br>`due_days`: integer<br>`label`: string<br>`intro_text`: string<br>`discount_label`: string<br>`discount_amount`: number

**Result:** confirmation object (see `GET /actions` description)

## Finance

### `client_advances_owed`: Who owes the studio for costs it paid
read · permission: `client-advances.view`

**Args:** `client`: string

**Result:** `{"owed_total":"number","clients":[{"client":"string","client_id":"int","items":"int","owed":"number","oldest":"string"}]}`

### `log_client_advance`: Log money paid on a client's behalf
write · permission: `client-advances.create`

**Args:** `client*`: string<br>`name*`: string<br>`amount*`: number<br>`billable_amount`: number<br>`category`: string<br>`payee`: string<br>`spent_on`: string<br>`notes`: string

**Result:** `{"logged":"bool","advance_id":"int","client":"string","name":"string","...":"amounts"}`

### `money_due`: Money due: unpaid invoices and upcoming renewals
read · permission: `invoices.view`

**Args:** `days_ahead`: integer

**Result:** `{"unpaid_invoices":{"count":"int","total":"number","overdue_total":"number","items":[{"invoice":"string","client":"string","balance_due":"number","due_date":"string","days_overdue":"int"}]},"recurring_invoices_due":[{"label":"string","client":"string","frequency":"string","generates_on":"string"}],"amc_renewals":[{"product":"string","client":"string","paid_until":"string","status":"string"}]}`

## Expenses

### `create_emi`: Add an EMI
write · permission: `expenses.create`

**Args:** `name*`: string<br>`bank_account*`: string<br>`account_holder`: string<br>`amount*`: number<br>`tenure_months*`: integer<br>`start_month`: string<br>`already_paid`: integer<br>`notes`: string

**Result:** confirmation object (see `GET /actions` description)

### `expenses_due`: The studio's own outgoings for a month
read · permission: `expenses.view`

**Args:** `month`: string<br>`type`: emi|salary|bill|other<br>`unpaid_only`: boolean

**Result:** `{"month":"string","salaries_included":"bool","total_due":"number","total_paid":"number","still_to_pay":"number","items":[{"expense_id":"int","name":"string","type":"string","payee":"string","category":"null","installment":"string","due":"number","paid":"number","paid_on":"null","status":"string"}]}`

### `log_expense`: Log a one-time studio expense
write · permission: `expenses.create`

**Args:** `name*`: string<br>`amount*`: number<br>`category*`: Travel|Fuel|Equipment|Software|Marketing|Office|Food & Entertainment|Professional Services|Maintenance|Miscellaneous<br>`spent_on`: string<br>`payee`: string<br>`notes`: string

**Result:** `{"logged":"bool","expense_id":"int","name":"string","amount":"number","category":"string","...":"…"}`

### `record_expense_payment`: Mark an EMI, salary or bill paid
write · permission: `expenses.edit`

**Args:** `expense*`: string<br>`month`: string<br>`amount`: number<br>`paid_on`: string<br>`note`: string<br>`replace_existing`: boolean

**Result:** `{"recorded":"bool","expense_id":"int","name":"string","type":"string","month":"YYYY-MM","amount_paid":"number","standard_amount":"number","status":"paid | part paid","paid_on":"string","replaced_previous_amount":"number|null"}`

## Insights

### `hours_vs_revenue`: Hours spent vs money billed, per client
read · permission: `insights.view`

**Args:** `month`: string<br>`whole_year`: boolean

**Result:** `{"period":{"from":"string","to":"string"},"clients":[{"client":"string","hours":"number","share_of_hours_percent":"number","invoiced":"number","collected":"number","per_hour":"int"}],"hours_on_no_client":{"hours":"number","share":"number","ventures":["string"]},"totals":{"hours":"number","invoiced":"number","collected":"number","per_hour":"int"}}`

### `needs_attention`: What needs attention right now
read · permission: `insights.view`

**Args:** —

**Result:** `{"count":"int","items":[{"severity":"string","title":"string","detail":"string","url":"string"}]}`

### `payment_behaviour`: Who pays late
read · permission: `insights.view`

**Args:** —

**Result:** `{"clients":[{"client":"string","payments":"int","paid_total":"number","average_days_late":"int","worst_days_late":"int","last_paid_on":"string","confident":"bool"}]}`

## Shoots

### `assign_shoot_crew`: Put someone on a shoot's crew
write · permission: `shoots.edit`

**Args:** `shoot_id*`: integer<br>`person*`: string<br>`role`: string<br>`call_time`: string

**Result:** `{"person":"string","shoot":"string","shoot_starts_at":"string","role":"string|null","call_time":"HH:MM|null","result":"added | already on the crew; details updated","push_notification_sent":"bool","warnings":["string"]}`

### `book_shoots`: Clients who need a shoot booked
read · permission: `forecast.view`

**Args:** —

**Result:** `{"book_now":[{"client":"string","reels_left":"int","per_week":"number","runs_out":"string","book_by":"string","next_shoot":"null"}],"book_soon":[{"client":"string","reels_left":"int","per_week":"number","runs_out":"string","book_by":"string","next_shoot":"null"}],"covered":["string"],"cannot_be_counted":["string"]}`

### `create_shoot`: Plan a shoot
write · permission: `shoots.create`

**Args:** `title*`: string<br>`starts_at*`: string<br>`ends_at`: string<br>`client`: string<br>`location`: string<br>`status`: planned|confirmed<br>`notes`: string

**Result:** `{"created":"bool","shoot_id":"int","title":"string","client":"string|null","starts_at":"YYYY-MM-DD HH:MM","ends_at":"string|null","status":"string (label)","next":"string","open_in_portal":"url"}`

### `get_shoot`: Read one shoot
read · permission: `any staff`

**Args:** `shoot_id*`: integer

**Result:** `{"shoot_id":"int","title":"string","client":"string","client_id":"int","starts_at":"string","ends_at":"null","location":"string","status":"string","live":"bool","departed_at":"string","reached_at":"string","started_at":"string","finished_at":"string","notes":"null","crew":[{"user_id":"int","name":"string","role":"null","call_time":"null"}],"open_in_portal":"string","client_logo_url":"null"}`

### `my_shoots`: My shoots
read · permission: `any staff`

**Args:** —

**Result:** `{"shoots":[],"how":"string"}`

### `run_shoot`: Shoot day: on the way, reached, start, wrap
write · permission: `any staff`

**Args:** `shoot_id*`: integer<br>`action*`: on_the_way|reached|start|wrap

**Result:** `{"done":"on_the_way|reached|start|wrap","shoot":"<get_shoot shape>"}`

### `shoots_rolling_now`: Shoots happening right now
read · permission: `shoots.view`

**Args:** —

**Result:** `{"rolling":"int","shoots":[]}`

### `update_shoot`: Edit a shoot
write · permission: `shoots.edit`

**Args:** `shoot_id*`: integer<br>`title`: string<br>`starts_at`: string<br>`ends_at`: string<br>`client`: string<br>`location`: string<br>`status`: planned|confirmed|completed|cancelled<br>`notes`: string<br>`remove_crew`: string

**Result:** `{"updated":["field names"],"removed_from_crew":"string|null","shoot":"<get_shoot shape>"}`

## Editing

### `editor_updates`: Editor updates
read · permission: `any staff`

**Args:** —

**Result:** `{"as_of":"string","scope":"string","needs_you":[{"kind":"string","count":"int","text":"string","person":"string","detail":"string"}],"editors":[{"name":"string","state":"string","editing":"string","for_minutes":"int","idle_minutes":"null","on_hold":[],"today":{"done":"int","out":"int","fixes":"int","added":"int"},"up_next":["string"],"last_move":{"label":"string","title":"string","time":"string"}}],"flow_today":{"editing":"int","added":"int","done":"int","out":"int","fixes":"int","review":"int","ready":"int"},"waiting_for_review":[{"title":"string","editor":"string","client":"string"}],"ready_to_publish":[{"title":"string","editor":"string","client":"string","publish_at":"string","late":"bool"}],"latest_moves":[{"time":"string","person":"string","type":"string","label":"string","detail":"null","title":"string"}]}`

### `my_editing`: My editing
read · permission: `any staff`

**Args:** —

**Result:** `{"today":[],"corrections_to_fix":[],"waiting_for_review":[],"ready_to_publish":[],"out_today":"int"}`

## Clients

### `get_client_brief`: Read a client's brand brief
read · permission: `clients.view`

**Args:** `client*`: string

**Result:** `{"client":"string","status":"string","submitted_at":"null","required_answered":"int","required_total":"int","brief":"string"}`

### `instagram_performance`: Instagram performance for a client
read · permission: `clients.view`

**Args:** `client*`: string<br>`month`: string<br>`top_posts`: integer

**Result:** `{"client":"string","account":"string","period":"string","last_synced_at":"string","overview":{"reach":"int","views":"int","engagement":"int","followers":"int","follower_growth":"null"},"engagement_breakdown":[{"label":"string","value":"int"}],"posts_published":"int","posts_by_format":[],"top_posts_by_reach":[]}`

### `list_clients`: List clients
read · permission: `clients.view`

**Args:** `search`: string<br>`include_inactive`: boolean

**Result:** `{"clients":[{"id":"int","name":"string","logo_url":"null","phone":"null","email":"null","type":"string","active":"bool"}]}`

### `send_brief_reminder`: Remind a client to fill in their brand brief
✉️ write · permission: `clients.edit`

**Args:** `client*`: string

**Result:** confirmation object (see `GET /actions` description)

## Quotations

### `create_quotation`: Create a draft quotation
write · permission: `quotations.create`

**Args:** `client*`: string<br>`items*`: array<br>`quotation_date`: string<br>`valid_until`: string<br>`intro_text`: string<br>`notes`: string<br>`discount_label`: string<br>`discount_amount`: number<br>`app_studio`: boolean

**Result:** `{"created":"bool","status":"draft (not sent)","quotation_id":"int","number":"string","...":"totals"}`

### `list_quotations`: List quotations
read · permission: `quotations.view`

**Args:** `client`: string

**Result:** `{"quotations":[{"quotation_id":"int","number":"string","client":"string","date":"string","valid_until":"null","total":"number","status":"string","whatsapp_sent_at":"string"}]}`

### `send_quotation_whatsapp`: Send a quotation to the client on WhatsApp
✉️ write · permission: `quotations.edit`

**Args:** `quotation_id*`: integer<br>`phone`: string

**Result:** confirmation object (see `GET /actions` description)

## General

### `arrange_proposal_sections`: Arrange proposal sections
write · permission: `proposals.edit`

**Args:** `proposal*`: string<br>`remove`: array<br>`order`: array

**Result:** confirmation object (see `GET /actions` description)

### `create_proposal`: Create proposal
write · permission: `proposals.create`

**Args:** `title*`: string<br>`client`: string<br>`valid_until`: date<br>`sections`: array<br>`duplicate_of`: string

**Result:** confirmation object (see `GET /actions` description)

### `create_todo`: Create todo
write · permission: `any staff`

**Args:** `title*`: string<br>`person`: string<br>`client`: string<br>`starts_on`: date<br>`due_on`: date<br>`notes`: string

**Result:** confirmation object (see `GET /actions` description)

### `delete_proposal`: Delete proposal
🗑 write · permission: `proposals.delete`

**Args:** `proposal*`: string<br>`confirm_title*`: string

**Result:** confirmation object (see `GET /actions` description)

### `describe_data`: Describe data
read · permission: `admin only`

**Args:** `like`: string

**Result:** confirmation object (see `GET /actions` description)

### `find_client`: Find client
read · permission: `clients.view`

**Args:** `name*`: string

**Result:** **text**

### `get_proposal`: Get proposal
read · permission: `proposals.view`

**Args:** `proposal*`: string<br>`sections`: array

**Result:** confirmation object (see `GET /actions` description)

### `invoice_lookup`: Invoice lookup
read · permission: `invoices.view`

**Args:** `invoice_number`: string<br>`client_id`: integer<br>`unpaid_only`: boolean

**Result:** **text**

### `list_proposal_comments`: List proposal comments
read · permission: `proposals.view`

**Args:** `proposal*`: string<br>`include_resolved`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `list_proposals`: List proposals
read · permission: `proposals.view`

**Args:** `search`: string<br>`status`: draft|sent|viewed|accepted|declined<br>`limit`: integer

**Result:** `{"count":"int","proposals":[{"id":"int","title":"string","status":"string","client":{"id":"int","name":"string"},"valid_until":"null","updated_at":"string","first_viewed_by_client_at":"string","open_client_comments":"int","view_url":"string","edit_url":"string","pdf_url":"string","client_link":"string"}]}`

### `list_scripts`: List scripts
read · permission: `scripts.view`

**Args:** `mine`: boolean<br>`include_closed`: boolean<br>`status`: draft|writing|internal_review|changes_required|ready|client_review|completed|archived<br>`limit`: integer

**Result:** confirmation object (see `GET /actions` description)

### `list_shoots`: List shoots
read · permission: `shoots.view`

**Args:** `include_past`: boolean<br>`limit`: integer

**Result:** `{"count":"int","shoots":[{"id":"int","title":"string","client":"string","starts_at":"string","location":"string","status":"string","crew":[{"name":"string"}],"kit":"string"}]}`

### `list_timesheet`: List timesheet
read · permission: `any staff`

**Args:** `from`: date<br>`to`: date<br>`person`: string

**Result:** `{"person":"string","from":"string","to":"string","total":"string","days_worked":"int","days":[]}`

### `list_todos`: List todos
read · permission: `any staff`

**Args:** `date`: date<br>`person`: string<br>`scope`: own|assigned<br>`open_only`: boolean

**Result:** `{"person":"string","date":"string","scope":"string","count":"int","todos":[]}`

### `log_timesheet_entry`: Log timesheet entry
write · permission: `any staff`

**Args:** `task*`: string<br>`date`: date<br>`type`: shooting|editing|posting|other<br>`client`: string<br>`start`: string<br>`end`: string<br>`minutes`: integer<br>`notes`: string

**Result:** confirmation object (see `GET /actions` description)

### `money_summary`: Money summary
read · permission: `admin only`

**Args:** —

**Result:** **text**

### `overdue_invoices`: Overdue invoices
read · permission: `admin only`

**Args:** —

**Result:** **text**

### `proposal_guide`: Proposal guide
read · permission: `proposals.view`

**Args:** —

**Result:** confirmation object (see `GET /actions` description)

### `reel_planner_today`: Reel planner today
read · permission: `content-dashboard.view`

**Args:** —

**Result:** confirmation object (see `GET /actions` description)

### `reply_to_proposal_comment`: Reply to proposal comment
write · permission: `proposals.comment`

**Args:** `proposal*`: string<br>`body*`: string<br>`comment_id`: integer<br>`section_key`: string<br>`resolve`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `run_query`: Run query
read · permission: `admin only`

**Args:** `sql*`: string

**Result:** confirmation object (see `GET /actions` description)

### `send_proposal_whatsapp`: Send proposal whatsapp
write · permission: `proposals.edit`

**Args:** `proposal*`: string<br>`phone*`: string

**Result:** confirmation object (see `GET /actions` description)

### `set_proposal_comment_status`: Set proposal comment status
write · permission: `proposals.comment`

**Args:** `proposal*`: string<br>`comment_id*`: integer<br>`resolved*`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `set_todo_status`: Set todo status
write · permission: `any staff`

**Args:** `id*`: integer<br>`status*`: waiting|started|blocked|completed|cancelled<br>`note`: string

**Result:** confirmation object (see `GET /actions` description)

### `share_proposal`: Share proposal
write · permission: `proposals.edit`

**Args:** `proposal*`: string<br>`action`: link|new_link|close

**Result:** confirmation object (see `GET /actions` description)

### `shoots_between`: Shoots between
read · permission: `shoots.view`

**Args:** `from*`: string<br>`to*`: string

**Result:** **text**

### `timesheet_gaps`: Timesheet gaps
read · permission: `admin only`

**Args:** —

**Result:** **text**

### `todays_shoots`: Todays shoots
read · permission: `admin only`

**Args:** —

**Result:** **text**

### `update_proposal`: Update proposal
write · permission: `proposals.edit`

**Args:** `proposal*`: string<br>`title`: string<br>`client`: string<br>`valid_until`: date<br>`status`: draft|sent|viewed|accepted|declined

**Result:** confirmation object (see `GET /actions` description)

### `whoami`: Whoami
read · permission: `any staff`

**Args:** —

**Result:** `{"name":"string","email":"string","is_admin":"bool","manages_people":"bool","today":"string","modules":["string"],"note":"string"}`

### `write_proposal_sections`: Write proposal sections
write · permission: `proposals.edit`

**Args:** `proposal*`: string<br>`sections*`: array<br>`after`: string<br>`replace_all`: boolean

**Result:** confirmation object (see `GET /actions` description)

## Team

### `list_team`: The studio team
read · permission: `any staff`

**Args:** —

**Result:** `{"team":[{"id":"int","name":"string","email":"string","role":"string","avatar_url":"null","is_you":"bool"}]}`

### `post_announcement`: Post an announcement
write · permission: `announcements.create`

**Args:** `title*`: string<br>`body*`: string<br>`visible_to_clients`: boolean

**Result:** `{"id":"int","posted":"bool","to_clients":"bool"}`

## Leads

### `list_enquiries`: Website enquiries
read · permission: `enquiries.view`

**Args:** `only_open`: boolean<br>`days`: integer

**Result:** `{"count":"int","enquiries":[{"id":"int","name":"string","phone":"string","email":"string","project":"string","message":"string","source":"string","received":"string","read":"bool","handled":"bool"}]}`

## WhatsApp

### `whatsapp_inbox`: WhatsApp inbox
read · permission: `whatsapp-crm.view`

**Args:** `only_unread`: boolean

**Result:** `{"count":"int","conversations":[]}`

## Scripts

### `create_script`: Add a script
write · permission: `scripts.create`

**Args:** `client*`: string<br>`title*`: string<br>`text`: string<br>`sections`: array<br>`writer`: string<br>`priority`: low|normal|high<br>`due_on`: date<br>`target_seconds`: integer

**Result:** confirmation object (see `GET /actions` description)

### `get_script`: Read a script
read · permission: `scripts.view`

**Args:** `script*`: string

**Result:** confirmation object (see `GET /actions` description)

### `transcribe_reel`: Transcribe a reel
write · permission: `scripts.view`

**Args:** `url*`: string<br>`username`: string<br>`refresh`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `update_script`: Edit a script
write · permission: `scripts.edit`

**Args:** `script*`: string<br>`title`: string<br>`text`: string<br>`sections`: array<br>`status`: draft|writing|internal_review|changes_required|ready|client_review|completed|archived

**Result:** confirmation object (see `GET /actions` description)

## Portfolio

### `add_portfolio_top_reels`: Add a client's top reels to the portfolio
write · permission: `portfolio.create`

**Args:** `client*`: string<br>`count`: integer

**Result:** confirmation object (see `GET /actions` description)

### `add_portfolio_video`: Add one video to the portfolio
write · permission: `portfolio.create`

**Args:** `instagram_url`: string<br>`planner_item_id`: integer<br>`video_url`: string<br>`client`: string<br>`title`: string<br>`shown`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `add_recent_portfolio_reels`: Add recent good reels to the portfolio
write · permission: `portfolio.create`

**Args:** `days`: integer<br>`min_views`: integer<br>`per_client`: integer<br>`limit`: integer<br>`preview`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `list_portfolio`: List portfolio
read · permission: `portfolio.view`

**Args:** `client`: string<br>`missing_case_study`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `update_portfolio_item`: Edit a portfolio piece / write its case study
write · permission: `portfolio.edit`

**Args:** `id*`: integer<br>`title`: string<br>`summary`: string<br>`creative_hook`: string<br>`creative_concept`: string<br>`creative_storytelling`: string<br>`creative_cta`: string<br>`creative_offer`: string<br>`creative_audience`: string<br>`client`: string<br>`category`: string<br>`video_url`: string<br>`published_on`: date<br>`description`: string<br>`shown`: boolean<br>`featured`: boolean

**Result:** confirmation object (see `GET /actions` description)

## Ad reports

### `import_ad_report`: Import a monthly ads report
write · permission: `admin only`

**Args:** `client*`: string<br>`report*`: object<br>`check_only`: boolean

**Result:** confirmation object (see `GET /actions` description)

### `list_ad_reports`: List monthly ads reports
read · permission: `admin only`

**Args:** `client`: string<br>`month`: string

**Result:** confirmation object (see `GET /actions` description)

### `send_ad_report`: Send an ads report to the client on WhatsApp
✉️ write · permission: `admin only`

**Args:** `report_id*`: integer<br>`phone`: string

**Result:** confirmation object (see `GET /actions` description)

## Timesheet

### `timesheet_hint`: Timesheet hints
read · permission: `any staff`

**Args:** `date`: date

**Result:** confirmation object (see `GET /actions` description)
