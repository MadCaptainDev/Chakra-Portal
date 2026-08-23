# Admin UI/UX Redesign — Progressive Shell Refresh

**Date:** 2026-08-24  
**Status:** Approved for implementation (approach #1, phases A→C→B in one sweep)  
**Scope:** Admin pages only (role-branched shell). Employee `/my/*` and client portal keep working; shared layout changes must not break role branches.

## Goal

Make the admin portal faster to navigate, visually coherent, and consistent on high-traffic list/detail screens — without a big-bang rewrite or a new design language.

## Approach

**Progressive shell refresh:** improve the existing Blade + Tailwind + Alpine shell and shared components, then apply those patterns to key admin screens. Phases are conceptual order of work, delivered together:

1. **A — Navigation IA**  
2. **C — Visual system**  
3. **B — Page usability patterns**

## Architecture

| Layer | Responsibility |
|-------|----------------|
| `layouts/app.blade.php` | Shell: dark rail, mobile drawer, content column, flash banners |
| `layouts/sidebar.blade.php` | Role branches (admin / employee / client) |
| `layouts/_nav-modules.blade.php` | Permission-registry module links (single source of truth) |
| `App\Support\Permission` | Module labels, icons, groups — not duplicated in Blade |
| Shared components (`page-header`, `nav-section`, `btn`, `card`, `empty-state`, new helpers) | Patterns reused by admin screens |
| Module views | Consume patterns; no one-off chrome |

Employee and client branches stay in the same sidebar file; admin-only enhancements (collapse, nav filter) apply only inside the admin `@else` branch or via props that default off for non-admin.

## A — Navigation IA

### Current state

Admin sidebar: Overview → Team → every granted Permission group (Production, Clients, Website, Finance, App Studio) → Setup (Settings hub). Admins see every module; the list scrolls.

### Target IA

Keep groups and routes. Change density and findability:

1. **Overview** — Dashboard, Editor Output, Content Dashboard (always expanded).  
2. **Team** — To-dos, Users (always expanded).  
3. **Permission groups** — Production, Clients, Website, Finance, App Studio from `Permission::grouped()`; **collapsible**; section containing the active route starts open; others start collapsed.  
4. **Setup** — Settings (always expanded).  
5. **Nested links** unchanged: Equipment under Shoots, Recurring under Invoices, Developer under App Studio / saas-products.  
6. **Nav filter** — admin-only search field at top of nav; filters visible link labels client-side (Alpine). Empty query shows all sections; non-matching links hide; sections with no visible links hide.

`Permission` remains the only registry for module rows. Do not hardcode a second admin module list.

### Out of scope for nav

- New routes or permission model changes  
- Changing Settings hub tabs  
- Merging employee/client IA into admin

## C — Visual system

### Brand

Keep Chakra tokens: deep navy `brand-900` (`#132A38`), cyan accents `brand-400`/`brand-500`. No purple-on-white, cream/serif “AI default,” or glow-heavy dark mode rewrite.

### Shell rules

| Surface | Treatment |
|---------|-----------|
| Sidebar / mobile chrome | `brand-900`; active row teal spine + soft pill (existing `sidebar-link`) |
| Content plane | Calm light plane: `bg-brand-50` (or `bg-gray-50` with subtle brand tint) instead of flat generic gray-only; dark dashboard pages keep `dark` / `brand-900` canvas |
| Page header slot | White (or near-white) bar, hairline bottom border, consistent vertical padding; titles via `x-page-header` |
| Main column | Keep `max-w-7xl`; consistent `px` / `py` |
| Cards / tables | `x-card` hairline ring; table headers use compact uppercase tracking |
| Primary actions | Prefer `x-btn` (primary cyan) over one-off uppercase anchor classes |
| Motion | Existing `animate-rise-in` / settle only; no decorative noise |

### Dark vs light split

Dashboard and other intentional `dark` pages stay ops-dark. List/CRUD pages stay light. Unification means shared spacing, headers, buttons, and filters — not forcing every admin page onto navy.

## B — Page usability patterns

### Shared patterns

1. **Page header** — `x-page-header` with title, optional eyebrow/subtitle, actions slot using `x-btn`.  
2. **Filter / toolbar row** — shared wrapper (`x-filter-bar` or equivalent) for search + chips/selects on a card or muted strip.  
3. **Empty state** — `x-empty-state` + primary CTA link/button.  
4. **List layout** — mobile cards + desktop table inside `x-card` (existing pattern; align typography/spacing).  
5. **Flash / errors** — keep layout session banners; form errors stay on fields.

### Screens in this sweep (highest traffic)

| Screen | Focus |
|--------|--------|
| Dashboard | Keep structure; align section labels/spacing with shell tokens only if needed |
| Clients index | Header/`x-btn`, empty state, table/card consistency |
| Invoices index | Header/`x-btn`, filter chips in a coherent toolbar, empty state |
| Shoots index | Already strong; align filter card + buttons if gaps |
| Team to-dos | Already patterned; light visual align only |
| Users index | Already patterned; ensure `x-btn`/`x-page-header` consistency |

Detail/create/edit forms: only touch when shared header/button patterns are already wrong; no form-field redesign in this sweep.

## Components (create or extend)

| Component | Change |
|-----------|--------|
| `nav-section` | Optional collapsible + open state (Alpine); preserve non-collapsible default for employee/client |
| `sidebar` (admin) | Nav filter input; wire collapse defaults by active route |
| `_nav-modules` | Pass collapsible into sections for admin (or always collapsible when prop set) |
| `app` layout | Content plane background; optional sticky header polish |
| `filter-bar` (new) | Consistent toolbar wrapper for filters |
| `page-header` / `btn` / `empty-state` / `card` | Prefer reuse; small token tweaks only |

## Data flow

No backend API changes. Controllers and routes unchanged. UI reads the same view data. Nav filter and section collapse are client-side only. Permission gates unchanged.

## Error and empty states

- Session `status` / `error` banners unchanged in layout.  
- Empty lists use `x-empty-state` with one clear next action.  
- Nav filter with zero matches: short “No matches” line in the nav, not a blank drawer.

## Testing

- Extend or add Feature coverage for admin sidebar: Settings still single link; Equipment nested; Developer under App Studio; collapsible/filter must not hide active routes when query empty.  
- Existing `SidebarRestructureTest`, `DashboardTest`, and module index tests must stay green.  
- Run focused PHPUnit via `/opt/alt/php82/usr/bin/php artisan test` (filter sidebar / dashboard / clients / invoices as practical).

## Success criteria

1. Admin can reach any module with less scrolling (collapsed inactive groups + optional filter).  
2. Light admin pages share one header/action/filter/empty vocabulary.  
3. Brand remains navy + cyan; employee and client shells still work.  
4. No Permission registry duplication; no new admin-only route map.

## Non-goals

- Separate admin SPA or Livewire migration  
- Redesigning public site, auth screens, or Settings tab contents  
- Rewriting every module show/edit form  
- Changing authorization rules

## Implementation delivery

One sweep implementing A+C+B per this spec. Implementation plan: `docs/superpowers/plans/2026-08-24-admin-ui-ux.md`.
