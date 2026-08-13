@props(['granted' => [], 'role' => 'employee'])

@php
    use App\Support\Permission;

    /*
     * What this person can reach.
     *
     * There is no "access level" to choose any more -- everybody is an employee
     * with their own timesheet, and access is the modules ticked below. Admin is
     * the one exception, and it is a switch rather than an option in a list,
     * because it is not another rung on a ladder: it is "this person can reach
     * everything, including the money".
     *
     * $granted arrives as ['scripts' => ['view','edit'], …] so a redisplay after
     * a validation error keeps the boxes the admin ticked.
     */
    $isAdmin = $role === \App\Models\User::ROLE_ADMIN;
@endphp

<div x-data="{ admin: @js($isAdmin) }">

    {{-- The hidden field carries "employee" when the box is unticked. PHP takes
         the last value of a repeated name, so ticking it wins. --}}
    <input type="hidden" name="role" value="{{ \App\Models\User::ROLE_EMPLOYEE }}">

    <label class="flex items-start gap-3 p-4 rounded-xl ring-1 cursor-pointer transition-colors"
           :class="admin ? 'bg-amber-50 ring-amber-300' : 'bg-white ring-gray-900/5 hover:bg-gray-50'">
        <input type="checkbox" name="role" value="{{ \App\Models\User::ROLE_ADMIN }}"
               x-model="admin" @checked($isAdmin)
               class="mt-0.5 w-5 h-5 rounded border-gray-300 text-brand-500 focus:ring-brand-400">
        <span class="min-w-0">
            <span class="block text-sm font-semibold text-gray-900">Studio admin</span>
            <span class="block text-xs text-gray-500 mt-0.5">
                Everything, including invoices, salaries and staff accounts. Leave this off
                and pick the modules below instead.
            </span>
        </span>
    </label>
    <x-input-error :messages="$errors->get('role')" class="mt-2" />

    <div class="mt-4 flex items-baseline justify-between gap-3">
        <x-input-label value="Module access" />
        <p class="text-xs text-gray-500">Beyond what everyone gets.</p>
    </div>

    {{-- Said out loud, because an empty matrix otherwise reads as "this person
         can do nothing" and invites over-granting. --}}
    <div class="mt-2 rounded-xl bg-gray-50 ring-1 ring-gray-900/5 p-4">
        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400">Everyone gets</p>
        <ul class="mt-2 space-y-1">
            @foreach (Permission::DEFAULTS as $label => $detail)
                <li class="flex items-start gap-2 text-sm text-gray-700">
                    <x-icon name="check-circle" class="w-4 h-4 shrink-0 mt-0.5 text-brand-400" />
                    <span><span class="font-medium">{{ $label }}</span> — <span class="text-gray-500">{{ $detail }}</span></span>
                </li>
            @endforeach
            <li class="flex items-start gap-2 text-sm text-gray-700">
                <x-icon name="check-circle" class="w-4 h-4 shrink-0 mt-0.5 text-brand-400" />
                <span><span class="font-medium">Team timesheet</span> —
                    <span class="text-gray-500">automatically, for anyone named as their manager</span></span>
            </li>
        </ul>
    </div>

    <div class="mt-3 rounded-xl ring-1 ring-gray-900/5 overflow-hidden" :class="admin && 'opacity-50'">
        {{-- Greyed out rather than hidden: hiding it would suggest the account
             has no access, when in fact it has all of it. --}}
        <p x-show="admin" x-cloak class="px-4 py-3 bg-amber-50 border-b border-amber-200 text-xs text-amber-800">
            An admin already reaches every module. These are saved as empty and
            take effect only if Studio admin is later switched off.
        </p>

        @foreach (Permission::grouped() as $group => $modules)
            <div class="px-4 py-2 bg-gray-50 border-b border-gray-100">
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $group }}</p>
            </div>

            @foreach ($modules as $module => $config)
                @php $moduleGranted = $granted[$module] ?? []; @endphp

                <div class="p-4 border-b border-gray-100 last:border-0">
                    <p class="text-sm font-semibold text-gray-900">{{ $config['label'] }}</p>

                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                        @foreach ($config['abilities'] as $ability)
                            <label class="inline-flex items-center gap-2 min-h-[36px] text-sm text-gray-700 cursor-pointer"
                                   :class="admin && 'pointer-events-none'">
                                <input type="checkbox"
                                       name="permissions[{{ $module }}][]"
                                       value="{{ $ability }}"
                                       @checked(in_array($ability, $moduleGranted, true))
                                       :disabled="admin"
                                       class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                                {{ Permission::ABILITIES[$ability] }}
                            </label>
                        @endforeach
                    </div>

                    <p class="mt-1.5 text-xs text-gray-400">
                        Manage covers everything above it, for this module only.
                    </p>
                </div>
            @endforeach
        @endforeach
    </div>

    <x-input-error :messages="$errors->get('permissions')" class="mt-2" />
</div>
