@php $employee = $employee ?? null; @endphp

{{-- x-data must live on a plain element; @if inside <x-card> breaks Blade component compilation. --}}
<div @if ($employee) x-data="{ unlocking: {{ old('unlock_amount') ? 'true' : 'false' }} }" @endif>
    <x-card class="p-4 sm:p-6">
        <form method="POST" action="{{ $employee ? route('salaries.update', $employee) : route('salaries.store') }}" enctype="multipart/form-data">
            @csrf
            @if ($employee)
                @method('PUT')
            @endif

            @if ($employee?->user)
                <div class="mb-6 pb-6 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-900 mb-3">Profile</h4>
                    <div class="flex items-start gap-4 mb-4">
                        <x-avatar :name="$employee->user->name" :src="$employee->user->avatarUrl()" size="lg" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <x-input-label for="emp_avatar" value="Profile Photo" />
                            <input
                                id="emp_avatar"
                                name="avatar"
                                type="file"
                                accept="image/*"
                                class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100"
                            />
                            <p class="text-xs text-gray-500">JPG, PNG or WebP. Max 2 MB.</p>
                            @if ($employee->user->avatar_path)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_avatar" value="1" class="rounded border-gray-300 text-brand-500 shadow-sm focus:ring-brand-400">
                                    Remove current photo
                                </label>
                            @endif
                            <x-input-error :messages="$errors->get('avatar')" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="emp_bio" value="Bio" />
                        <textarea
                            id="emp_bio"
                            name="bio"
                            rows="3"
                            maxlength="1000"
                            class="mt-1 block w-full border-gray-300 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm"
                            placeholder="A short introduction…"
                        >{{ old('bio', $employee->user->bio) }}</textarea>
                        <x-input-error :messages="$errors->get('bio')" class="mt-2" />
                    </div>
                </div>
            @elseif ($employee)
                <p class="mb-4 text-sm text-gray-500">
                    No login linked yet — photo and bio need a staff account.
                    <a href="{{ route('users.create') }}" class="font-semibold text-brand-500 hover:text-brand-600">Create one</a>
                </p>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div>
                    <x-input-label for="emp_name" value="Name" />
                    <x-text-input id="emp_name" name="name" type="text" class="mt-1 block w-full"
                                  value="{{ old('name', $employee->name ?? '') }}" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="emp_role" value="Role" />
                    <x-text-input id="emp_role" name="role" type="text" class="mt-1 block w-full"
                                  value="{{ old('role', $employee->role ?? '') }}" placeholder="e.g. Editor" />
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="emp_amount" value="Monthly Salary" />
                    @if ($employee)
                        <template x-if="! unlocking">
                            <div class="mt-1">
                                <div class="flex items-center gap-3 min-h-[44px] flex-wrap">
                                    <p class="text-lg font-semibold text-gray-900">{{ number_format((float) $employee->amount, 2) }}</p>
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-700 bg-amber-50 px-2 py-0.5 rounded">Locked</span>
                                    <button type="button" @click="unlocking = true" class="text-xs font-semibold text-brand-500 hover:text-brand-600">Change amount</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="unlocking">
                            <div class="mt-1 space-y-2">
                                <input type="hidden" name="unlock_amount" value="1">
                                <x-text-input id="emp_amount" name="amount" type="number" step="0.01" min="0"
                                              class="block w-full" value="{{ old('amount', $employee->amount) }}" required />
                                <label class="inline-flex items-start gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="confirm_amount_change" value="1" required
                                           class="mt-1 rounded border-gray-300 text-brand-500 focus:ring-brand-400"
                                           @checked(old('confirm_amount_change'))>
                                    <span>I confirm changing this locked salary amount.</span>
                                </label>
                                <button type="button" @click="unlocking = false" class="text-xs font-semibold text-gray-500 hover:text-gray-700">Keep locked</button>
                            </div>
                        </template>
                    @else
                        <x-text-input id="emp_amount" name="amount" type="number" step="0.01" min="0"
                                      class="mt-1 block w-full" value="{{ old('amount') }}" required />
                    @endif
                    <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    <x-input-error :messages="$errors->get('confirm_amount_change')" class="mt-2" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-4">
                <div>
                    <x-input-label for="emp_joined" value="Joined On" />
                    <x-text-input id="emp_joined" name="joined_on" type="date" class="mt-1 block w-full"
                                  value="{{ old('joined_on', $employee?->joined_on?->format('Y-m-d') ?? '') }}" />
                    <x-input-error :messages="$errors->get('joined_on')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="emp_phone" value="Phone" />
                    <x-text-input id="emp_phone" name="phone" type="text" class="mt-1 block w-full"
                                  value="{{ old('phone', $employee?->user?->phone ?? $employee->phone ?? '') }}" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                </div>

                <div class="flex items-end">
                    <label class="inline-flex items-center min-h-[44px] gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $employee->is_active ?? true))
                               class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                        <span class="text-sm text-gray-700">Currently working</span>
                    </label>
                </div>

                <div class="flex items-end">
                    <x-primary-button>{{ $employee ? 'Save Changes' : 'Add Employee' }}</x-primary-button>
                </div>
            </div>
        </form>
    </x-card>
</div>
