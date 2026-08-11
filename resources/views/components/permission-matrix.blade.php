@props(['granted' => [], 'role' => 'employee'])

@php
    use App\Support\Permission;

    /*
     * The module × ability grid.
     *
     * $granted arrives as ['scripts' => ['view','edit'], …] so a redisplay
     * after a validation error keeps the boxes the admin ticked.
     *
     * Admins are shown the grid greyed out rather than hidden: hiding it would
     * suggest the account has no access, when in fact it has all of it.
     */
    $isAdmin = $role === \App\Models\User::ROLE_ADMIN;
@endphp

<div x-data="{ admin: @js($isAdmin) }"
     x-init="$watch('role', value => admin = value === 'admin')">

    <div class="flex items-baseline justify-between gap-3">
        <x-input-label value="Module access" />
        <p class="text-xs text-gray-500">What this person can reach beyond their own timesheet.</p>
    </div>

    <div class="mt-2 rounded-xl ring-1 ring-gray-900/5 overflow-hidden" :class="admin && 'opacity-50'">
        <p x-show="admin" x-cloak class="px-4 py-3 bg-amber-50 border-b border-amber-200 text-xs text-amber-800">
            An admin already reaches every module. These are saved as empty and
            take effect only if the account is later changed to Employee.
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
