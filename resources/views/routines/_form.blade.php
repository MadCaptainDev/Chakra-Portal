@php
    use App\Models\Routine;
    use App\Models\RoutineField;

    $routine?->loadMissing(['checkpoints', 'subjects', 'fields', 'users']);
    $selectedUsers = old('user_ids', $routine?->users->pluck('id')->all() ?? []);
    $selectedSocial = old(
        'social_account_ids',
        $routine?->subjects->where('subject_type', Routine::SUBJECT_SOCIAL)->pluck('subject_id')->all() ?? []
    );
    $selectedContent = old(
        'content_account_ids',
        $routine?->subjects->where('subject_type', Routine::SUBJECT_CONTENT)->pluck('subject_id')->all() ?? []
    );
    $checkpointNames = old('checkpoint_names', $routine?->checkpoints->pluck('name')->all() ?? []);
    if ($checkpointNames === []) {
        $checkpointNames = [''];
    }
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
            <x-input-label for="title" value="Title" />
            <x-text-input id="title" name="title" class="mt-1 block w-full" :value="old('title', $routine?->title)" required />
            <x-input-error :messages="$errors->get('title')" class="mt-1" />
        </div>
        <div class="sm:col-span-2">
            <x-input-label for="description" value="Description" />
            <textarea id="description" name="description" rows="2"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 text-sm">{{ old('description', $routine?->description) }}</textarea>
        </div>
        <div>
            <x-input-label for="schedule_type" value="Schedule" />
            <select id="schedule_type" name="schedule_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @foreach (Routine::SCHEDULES as $value => $label)
                    <option value="{{ $value }}" @selected(old('schedule_type', $routine?->schedule_type ?? Routine::SCHEDULE_DAILY) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="schedule_interval" value="Every N days" />
            <x-text-input id="schedule_interval" name="schedule_interval" type="number" min="1" max="365" class="mt-1 block w-full"
                          :value="old('schedule_interval', $routine?->schedule_interval)" />
        </div>
        <div>
            <x-input-label for="day_of_month" value="Day of month" />
            <x-text-input id="day_of_month" name="day_of_month" type="number" min="1" max="31" class="mt-1 block w-full"
                          :value="old('day_of_month', $routine?->day_of_month)" />
        </div>
        <div>
            <x-input-label for="starts_on" value="Starts on" />
            <x-text-input id="starts_on" name="starts_on" type="date" class="mt-1 block w-full"
                          :value="old('starts_on', optional($routine?->starts_on)->format('Y-m-d') ?? now()->format('Y-m-d'))" required />
        </div>
        <div>
            <x-input-label for="completion_mode" value="Completion" />
            <select id="completion_mode" name="completion_mode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @foreach (Routine::MODES as $value => $label)
                    <option value="{{ $value }}" @selected(old('completion_mode', $routine?->completion_mode ?? Routine::MODE_SHARED) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="catch_up_days" value="Catch-up days" />
            <x-text-input id="catch_up_days" name="catch_up_days" type="number" min="0" max="366" class="mt-1 block w-full"
                          :value="old('catch_up_days', $routine?->catch_up_days ?? 31)" />
        </div>
        <div class="flex items-center gap-2 pt-6">
            <input type="hidden" name="is_active" value="0">
            <input id="is_active" type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-brand-500 focus:ring-brand-400"
                   @checked(old('is_active', $routine?->is_active ?? true))>
            <x-input-label for="is_active" value="Active" />
        </div>
    </div>

    <div>
        <p class="text-sm font-semibold text-gray-900 mb-1">Permitted people</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-40 overflow-y-auto">
            @foreach ($staff as $person)
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 min-h-[36px]">
                    <input type="checkbox" name="user_ids[]" value="{{ $person->id }}" class="rounded border-gray-300 text-brand-500"
                           @checked(in_array($person->id, $selectedUsers, true))>
                    {{ $person->name }}
                </label>
            @endforeach
        </div>
    </div>

    <div>
        <p class="text-sm font-semibold text-gray-900 mb-1">Client Instagram</p>
        <p class="text-xs text-gray-500 mb-2">Toggle connected client accounts already on Clients → Social Media.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-40 overflow-y-auto">
            @forelse ($socialAccounts as $account)
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 min-h-[36px]">
                    <input type="checkbox" name="social_account_ids[]" value="{{ $account->id }}" class="rounded border-gray-300 text-brand-500"
                           @checked(in_array($account->id, $selectedSocial, true))>
                    <span>
                        {{ $account->handle() }}
                        @if ($account->client)
                            <span class="text-xs text-gray-400">({{ $account->client->name }})</span>
                        @endif
                        @if ($account->status === \App\Models\SocialAccount::STATUS_REVOKED)
                            <span class="text-xs text-red-500">(revoked)</span>
                        @endif
                    </span>
                </label>
            @empty
                <p class="text-sm text-gray-500">No client Instagram connections yet.</p>
            @endforelse
        </div>
    </div>

    <div>
        <p class="text-sm font-semibold text-gray-900 mb-1">Venture accounts</p>
        <p class="text-xs text-gray-500 mb-2">Toggle Content Accounts from Setup → Content Accounts.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-40 overflow-y-auto">
            @forelse ($contentAccounts as $account)
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 min-h-[36px]">
                    <input type="checkbox" name="content_account_ids[]" value="{{ $account->id }}" class="rounded border-gray-300 text-brand-500"
                           @checked(in_array($account->id, $selectedContent, true))>
                    <span>
                        {{ $account->name }}
                        @if ($account->client)
                            <span class="text-xs text-gray-400">({{ $account->client->name }})</span>
                        @endif
                    </span>
                </label>
            @empty
                <p class="text-sm text-gray-500">No venture accounts yet.</p>
            @endforelse
        </div>
    </div>

    <div>
        <p class="text-sm font-semibold text-gray-900 mb-1">Checkpoints</p>
        <p class="text-xs text-gray-500 mb-2">Leave blank for a single implicit duty. Example: Messages, Comments.</p>
        <div class="space-y-2">
            @foreach ($checkpointNames as $i => $name)
                <x-text-input name="checkpoint_names[]" class="block w-full" :value="$name" placeholder="Checkpoint name" />
            @endforeach
            <x-text-input name="checkpoint_names[]" class="block w-full" placeholder="Add another…" />
        </div>
    </div>

    <div>
        <p class="text-sm font-semibold text-gray-900 mb-1">Capture fields</p>
        <p class="text-xs text-gray-500 mb-2">Optional values collected on complete. Number fields default to 0.</p>
        @php
            $fields = old('fields', $routine?->fields->map(fn ($f) => [
                'label' => $f->label,
                'key' => $f->key,
                'type' => $f->type,
                'default_value' => $f->default_value,
                'checkpoint_name' => $f->checkpoint?->name,
            ])->all() ?? []);
            if ($fields === []) {
                $fields = [['label' => '', 'key' => '', 'type' => 'number', 'default_value' => '0', 'checkpoint_name' => '']];
            }
        @endphp
        <div class="space-y-3">
            @foreach ($fields as $i => $field)
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                    <x-text-input name="fields[{{ $i }}][label]" class="block w-full" :value="$field['label'] ?? ''" placeholder="Label" />
                    <x-text-input name="fields[{{ $i }}][key]" class="block w-full" :value="$field['key'] ?? ''" placeholder="key" />
                    <select name="fields[{{ $i }}][type]" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        @foreach (RoutineField::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(($field['type'] ?? 'number') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-text-input name="fields[{{ $i }}][default_value]" class="block w-full" :value="$field['default_value'] ?? ''" placeholder="Default" />
                    <x-text-input name="fields[{{ $i }}][checkpoint_name]" class="block w-full" :value="$field['checkpoint_name'] ?? ''" placeholder="Checkpoint (optional)" />
                </div>
            @endforeach
        </div>
    </div>

    <div class="flex justify-end">
        <x-btn type="submit">{{ $routine ? 'Save' : 'Create routine' }}</x-btn>
    </div>
</div>
