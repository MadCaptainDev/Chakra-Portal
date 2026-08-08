@php
    // Renders bare -- the caller supplies the card chrome.
    $entry = $entry ?? null;
    $uid = $entry?->id ?? 'new';
    $ventures = $ventures ?? [];
@endphp

<form method="POST" action="{{ $entry ? route('my.timesheet.update', $entry) : route('my.timesheet.store') }}">
    @csrf
    @if ($entry)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        <div>
            <x-input-label :for="'ts_date_'.$uid" value="Date" />
            <x-text-input :id="'ts_date_'.$uid" name="worked_on" type="date" class="mt-1 block w-full"
                          value="{{ old('worked_on', $entry?->worked_on?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required />
            <x-input-error :messages="$errors->get('worked_on')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_task_'.$uid" value="Task" />
            <x-text-input :id="'ts_task_'.$uid" name="task" type="text" class="mt-1 block w-full"
                          value="{{ old('task', $entry->task ?? '') }}" placeholder="e.g. Shoot" required />
            <x-input-error :messages="$errors->get('task')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_venture_'.$uid" value="Venture / Project" />
            <x-text-input :id="'ts_venture_'.$uid" name="venture" type="text" class="mt-1 block w-full"
                          value="{{ old('venture', $entry->venture ?? '') }}" placeholder="e.g. SVA Website"
                          list="venture-suggestions" />
            <x-input-error :messages="$errors->get('venture')" class="mt-2" />
        </div>
    </div>

    @if (! empty($ventures))
        <datalist id="venture-suggestions">
            @foreach ($ventures as $venture)
                <option value="{{ $venture }}"></option>
            @endforeach
        </datalist>
    @endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4"
         x-data="{
             start: '{{ old('started_at', $entry?->started_at ? substr($entry->started_at, 0, 5) : '') }}',
             end: '{{ old('ended_at', $entry?->ended_at ? substr($entry->ended_at, 0, 5) : '') }}',
             minutes: {{ (int) old('minutes', $entry->minutes ?? 0) }},
             /* Mirrors TimesheetEntry::minutesBetween() so the figure shown
                matches what the server will store. */
             recompute() {
                 if (! this.start || ! this.end) return;
                 const [sh, sm] = this.start.split(':').map(Number);
                 const [eh, em] = this.end.split(':').map(Number);
                 let diff = (eh * 60 + em) - (sh * 60 + sm);
                 if (diff < 0) diff += 24 * 60;
                 this.minutes = diff;
             },
             get label() {
                 if (! this.minutes) return '—';
                 const h = Math.floor(this.minutes / 60), m = this.minutes % 60;
                 return [h ? h + (h === 1 ? ' hr' : ' hrs') : '', m ? m + (m === 1 ? ' min' : ' mins') : ''].filter(Boolean).join(' ');
             }
         }">
        <div>
            <x-input-label :for="'ts_start_'.$uid" value="Start" />
            <input :id="'ts_start_'.$uid" id="ts_start_{{ $uid }}" name="started_at" type="time"
                   x-model="start" @change="recompute()"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <x-input-error :messages="$errors->get('started_at')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_end_'.$uid" value="Finish" />
            <input id="ts_end_{{ $uid }}" name="ended_at" type="time"
                   x-model="end" @change="recompute()"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <x-input-error :messages="$errors->get('ended_at')" class="mt-2" />
            <p class="text-[11px] text-gray-500 mt-1">Use 24-hour time, so 10:30 PM is 22:30.</p>
        </div>

        <div>
            <x-input-label :for="'ts_minutes_'.$uid" value="Duration (mins)" />
            <input id="ts_minutes_{{ $uid }}" name="minutes" type="number" min="0" max="1440"
                   x-model.number="minutes"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <p class="text-[11px] text-gray-500 mt-1" x-text="label"></p>
            <x-input-error :messages="$errors->get('minutes')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_status_'.$uid" value="Status" />
            <x-select :id="'ts_status_'.$uid" name="status" class="mt-1" required>
                @foreach (\App\Models\TimesheetEntry::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $entry->status ?? 'completed') === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <x-input-error :messages="$errors->get('status')" class="mt-2" />
        </div>
    </div>

    <div class="mb-4">
        <x-input-label :for="'ts_notes_'.$uid" value="Notes (optional)" />
        <x-textarea :id="'ts_notes_'.$uid" name="notes" rows="2" class="mt-1">{{ old('notes', $entry->notes ?? '') }}</x-textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
    </div>

    <x-primary-button>{{ $entry ? 'Save Changes' : 'Add Entry' }}</x-primary-button>
</form>
