# Admin UI/UX Progressive Shell Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver admin nav collapse + filter, unified Chakra shell surfaces, and standardized list-page patterns on high-traffic admin screens in one sweep.

**Architecture:** Shared Blade layouts and components remain the shell. `Permission` stays the module registry. Admin-only Alpine on the sidebar adds collapse and filter. Light content plane + `x-page-header` / `x-btn` / `x-filter-bar` / `x-empty-state` unify CRUD pages; dark dashboard stays opt-in via `AppLayout` `$dark`.

**Tech Stack:** Laravel Blade, Tailwind (brand palette), Alpine.js, existing Feature tests (PHPUnit via `/opt/alt/php82/usr/bin/php artisan test`).

## Global Constraints

- Scope: admin shell + patterns; employee `/my/*` and client portal must keep working.
- Brand: navy `brand-900` + cyan `brand-400`/`brand-500`; no purple/cream redesign.
- `Permission::MODULES` / `grouped()` is the only module nav source of truth.
- Do not push; do not commit implementation unless explicitly asked (design doc already committed).
- PHP binary for tests: `/opt/alt/php82/usr/bin/php`.

---

### Task 1: Collapsible `nav-section` + admin sidebar filter

**Files:**
- Modify: `resources/views/components/nav-section.blade.php`
- Modify: `resources/views/layouts/sidebar.blade.php`
- Modify: `resources/views/layouts/_nav-modules.blade.php`
- Test: `tests/Feature/SidebarRestructureTest.php` (extend)

**Interfaces:**
- Consumes: existing `x-sidebar-link`, `Permission::grouped()`, `auth()->user()->isAdmin()`
- Produces: `x-nav-section` props `collapsible` (bool, default false), `forceOpen` (bool); admin nav Alpine `navQ` string filter

- [ ] **Step 1: Extend SidebarRestructureTest for empty-query visibility**

Add assertions that with no filter, dashboard still shows Production/Finance group labels and Settings; Equipment and Developer nesting assertions remain.

```php
public function test_admin_sidebar_still_lists_permission_groups_and_settings(): void
{
    $response = $this->actingAs($this->admin())->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Production', false)
        ->assertSee('Finance', false)
        ->assertSee(route('settings.edit'));
}
```

- [ ] **Step 2: Run test to verify baseline still passes (or new test fails only if labels missing)**

Run: `/opt/alt/php82/usr/bin/php artisan test --filter=SidebarRestructureTest`

- [ ] **Step 3: Implement collapsible nav-section**

```blade
@props(['label', 'collapsible' => false, 'forceOpen' => false])

@php
    $sectionId = 'nav-'.\Illuminate\Support\Str::slug($label);
@endphp

@if ($collapsible)
<div class="pt-4 first:pt-0" x-data="{ open: {{ $forceOpen ? 'true' : 'false' }} }"
     x-show="!navQ || $el.querySelectorAll('a:not([style*=display]):not([hidden])').length || true"
     data-nav-section>
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-3 pb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-brand-200/40 hover:text-brand-200/70">
        <span>{{ $label }}</span>
        <svg class="w-3.5 h-3.5 transition" :class="open && 'rotate-180'" ...chevron...</svg>
    </button>
    <div class="space-y-0.5" x-show="open || navQ" x-cloak>
        {{ $slot }}
    </div>
</div>
@else
{{-- existing non-collapsible markup --}}
@endif
```

Wire Alpine on admin `<nav>`: `x-data="{ navQ: '' }"` and filter each `a` by `textContent` match; hide non-matching links with `x-show` or a small `@input` handler. Prefer: wrap each sidebar-link usage is hard — instead filter in Alpine with `$watch` on `navQ` querying `a` inside nav.

Simpler filter approach on admin nav:

```html
<nav x-data="{ navQ: '' }" @input.debounce.100ms="...">
  <input x-model="navQ" placeholder="Filter menu…" class="..." />
  ...
</nav>
```

Use Alpine `x-effect` or `@keyup` to set `hidden` / `style.display` on each `a` whose text does not include `navQ`, and hide section wrappers with no visible links. When `navQ` is non-empty, force all collapsible sections open (`open || navQ`).

Pass `:collapsible="true"` from `_nav-modules` when `$isAdmin` (detect via `auth()->user()?->isAdmin()`). Pass `forceOpen` when any child route is active (`request()->routeIs(...)` per module in loop — compute `$groupActive`).

Overview/Team/Setup: non-collapsible (always visible).

- [ ] **Step 4: Re-run SidebarRestructureTest**

Expected: PASS

---

### Task 2: Shell visual tokens (content plane + header)

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `app/View/Components/AppLayout.php` (comment only if needed)
- Test: `tests/Feature/DashboardTest.php` (existing dark dashboard assertions)

- [ ] **Step 1: Update light content plane**

In `layouts/app.blade.php`, change light mode wrapper from `bg-gray-50` to `bg-brand-50` (or `bg-brand-50/80`). Keep `$dark ? 'bg-brand-900 text-white'`.

Header slot: keep white bar; add `sticky top-0 z-20` on `lg:` if it does not break mobile menu (desktop only sticky is fine: `lg:sticky lg:top-0 lg:z-20`).

- [ ] **Step 2: Run DashboardTest**

Run: `/opt/alt/php82/usr/bin/php artisan test --filter=DashboardTest`  
Expected: PASS (dashboard uses `dark`)

---

### Task 3: Add `x-filter-bar` component

**Files:**
- Create: `resources/views/components/filter-bar.blade.php`

**Interfaces:**
- Produces: `<x-filter-bar>` wrapping filter controls in `x-card padding="sm"` with flex/grid slot

```blade
@props([])
<x-card padding="sm" {{ $attributes->merge(['class' => 'space-y-3']) }}>
    {{ $slot }}
</x-card>
```

No dedicated unit test; covered by view rendering in index Feature tests.

---

### Task 4: Apply patterns to Clients + Users indexes

**Files:**
- Modify: `resources/views/clients/index.blade.php`
- Modify: `resources/views/users/index.blade.php` (light touch if already good)

- [ ] Replace raw primary anchors with `<x-btn :href="..." icon="plus">`.
- [ ] Ensure empty state + table sit in consistent `space-y-4`.
- [ ] Clients page-header: add eyebrow `Clients` or leave title-only; use `x-btn`.

Run any existing clients feature test if present:  
`/opt/alt/php82/usr/bin/php artisan test --filter=Client`

---

### Task 5: Apply patterns to Invoices + Shoots indexes

**Files:**
- Modify: `resources/views/invoices/index.blade.php`
- Modify: `resources/views/shoots/index.blade.php`

- [ ] Invoices: wrap month nav + status/type chips + search in `<x-filter-bar>`; primary action via `x-btn`.
- [ ] Shoots: wrap filter form in `<x-filter-bar>` if not already card-consistent; keep existing `x-btn` actions.

Run: `/opt/alt/php82/usr/bin/php artisan test --filter=Invoice` (or narrower if slow) and shoots-related tests if available.

---

### Task 6: Dashboard + To-dos light align + full focused suite

**Files:**
- Modify: `resources/views/dashboard.blade.php` only if spacing/token gaps are obvious (prefer minimal).
- Modify: `resources/views/todos/index.blade.php` only for sticky bar bg to match `brand-50`.

- [ ] To-dos sticky filter backdrop: `bg-brand-50/80` instead of `bg-gray-50/80`.
- [ ] Run focused suite:

```bash
/opt/alt/php82/usr/bin/php artisan test --filter='SidebarRestructureTest|DashboardTest'
```

Fix breakages. Do not commit implementation unless asked.

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Collapsible Permission groups | Task 1 |
| Admin nav filter | Task 1 |
| Overview/Team/Setup always open | Task 1 |
| Permission as source of truth | Task 1 (`_nav-modules`) |
| Content plane + header polish | Task 2 |
| Brand navy/cyan | Tasks 1–2 |
| filter-bar + page patterns | Tasks 3–5 |
| Clients/Invoices/Shoots/Users/Todos/Dashboard | Tasks 4–6 |
| Employee/client unbroken | Task 1 (collapsible only when admin / prop) |
| Tests | Tasks 1, 2, 6 |

## Execution note

User directed: implement immediately in this session (inline), no further approval gates, no implementation commit unless asked.
