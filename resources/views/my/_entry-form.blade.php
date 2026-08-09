@php
    // Renders bare -- the caller supplies the card chrome.
    $entry = $entry ?? null;
    $uid = $entry?->id ?? 'new';
    $ventures = $ventures ?? [];

    // Durations people actually log, so the common case is one tap.
    $presets = [30 => '30m', 60 => '1h', 90 => '1½h', 120 => '2h', 240 => '4h', 480 => '8h'];
@endphp

<form method="POST" action="{{ $entry ? route('my.timesheet.update', $entry) : route('my.timesheet.store') }}"
      x-data="timesheetEntry({
          start: @js(old('started_at', $entry?->started_at ? substr($entry->started_at, 0, 5) : '')),
          end: @js(old('ended_at', $entry?->ended_at ? substr($entry->ended_at, 0, 5) : '')),
          minutes: {{ (int) old('minutes', $entry->minutes ?? 0) }},
      })">
    @csrf
    @if ($entry)
        @method('PUT')
    @endif

    {{-- What and when --}}
    <div class="space-y-4">
        <div>
            <x-input-label :for="'ts_task_'.$uid" value="What did you work on?" />
            <x-text-input :id="'ts_task_'.$uid" name="task" type="text" class="mt-1 text-base"
                          value="{{ old('task', $entry->task ?? '') }}" placeholder="e.g. Shoot at Thor Gym" required />
            <x-input-error :messages="$errors->get('task')" class="mt-2" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <x-input-label :for="'ts_date_'.$uid" value="Date" />
                    <div class="flex gap-1">
                        <button type="button" @click="setDate(0)"
                                class="inline-flex items-center min-h-[32px] px-2.5 rounded-full bg-gray-100 text-[11px] font-semibold text-gray-600 hover:bg-brand-50 hover:text-brand-700">Today</button>
                        <button type="button" @click="setDate(-1)"
                                class="inline-flex items-center min-h-[32px] px-2.5 rounded-full bg-gray-100 text-[11px] font-semibold text-gray-600 hover:bg-brand-50 hover:text-brand-700">Yesterday</button>
                    </div>
                </div>
                <x-text-input :id="'ts_date_'.$uid" name="worked_on" type="date" class="mt-1" x-ref="date"
                              value="{{ old('worked_on', $entry?->worked_on?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required />
                <x-input-error :messages="$errors->get('worked_on')" class="mt-2" />
            </div>

            <div>
                <x-input-label :for="'ts_venture_'.$uid" value="Venture / Project" />
                <x-text-input :id="'ts_venture_'.$uid" name="venture" type="text" class="mt-1"
                              value="{{ old('venture', $entry->venture ?? '') }}" placeholder="e.g. SVA Website"
                              list="venture-suggestions" />
                <x-input-error :messages="$errors->get('venture')" class="mt-2" />
            </div>
        </div>
    </div>

    @if (! empty($ventures))
        <datalist id="venture-suggestions">
            @foreach ($ventures as $venture)
                <option value="{{ $venture }}"></option>
            @endforeach
        </datalist>
    @endif

    {{-- How long. The running total is the whole point of this block, so it
         sits above the inputs rather than under them. --}}
    <div class="mt-5 rounded-lg border border-gray-200 bg-gray-50/60 p-4">
        <div class="flex items-baseline justify-between gap-3">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Time on it</p>
            <p class="text-lg font-bold text-brand-600" x-text="label"></p>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3">
            <div>
                <label for="ts_start_{{ $uid }}" class="block text-xs font-medium text-gray-600 mb-1">Start</label>
                <div class="flex gap-1">
                    <input id="ts_start_{{ $uid }}" name="started_at" type="time"
                           x-model="start" @change="recompute()"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                    <button type="button" @click="now('start')" title="Use the time now"
                            class="shrink-0 inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-gray-300 bg-white text-xs font-semibold text-gray-600 hover:bg-gray-50">Now</button>
                </div>
                <x-input-error :messages="$errors->get('started_at')" class="mt-2" />
            </div>

            <div>
                <label for="ts_end_{{ $uid }}" class="block text-xs font-medium text-gray-600 mb-1">Finish</label>
                <div class="flex gap-1">
                    <input id="ts_end_{{ $uid }}" name="ended_at" type="time"
                           x-model="end" @change="recompute()"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                    <button type="button" @click="now('end')" title="Use the time now"
                            class="shrink-0 inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-gray-300 bg-white text-xs font-semibold text-gray-600 hover:bg-gray-50">Now</button>
                </div>
                <x-input-error :messages="$errors->get('ended_at')" class="mt-2" />
            </div>
        </div>

        <p class="mt-2 text-[11px] text-gray-500">24-hour time, so 10:30 PM is 22:30. Past midnight is fine.</p>

        <div class="mt-4">
            <div class="flex items-center justify-between gap-2">
                <label for="ts_minutes_{{ $uid }}" class="block text-xs font-medium text-gray-600">Or just the duration</label>
                <button type="button" @click="clearTimes()" x-show="start || end" x-cloak
                        class="text-[11px] font-semibold text-gray-500 hover:text-gray-800 min-h-[32px] px-1">Clear times</button>
            </div>

            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach ($presets as $value => $chip)
                    <button type="button" @click="setMinutes({{ $value }})"
                            class="inline-flex items-center min-h-[36px] px-3 rounded-full border text-xs font-semibold transition-colors"
                            :class="minutes === {{ $value }}
                                ? 'bg-brand-400 border-brand-400 text-white'
                                : 'bg-white border-gray-300 text-gray-600 hover:border-brand-300 hover:text-brand-600'">
                        {{ $chip }}
                    </button>
                @endforeach
            </div>

            <input id="ts_minutes_{{ $uid }}" name="minutes" type="number" min="0" max="1440"
                   x-model.number="minutes"
                   class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <p class="mt-1 text-[11px] text-gray-500" x-show="start && end" x-cloak>
                Worked out from the start and finish times.
            </p>
            <x-input-error :messages="$errors->get('minutes')" class="mt-2" />
        </div>
    </div>

    {{-- Status --}}
    <div class="mt-5">
        <x-input-label :for="'ts_status_'.$uid" value="Status" />
        {{-- Segmented control. Styled off :checked with peer-*, not Alpine, so
             the selected pill is right in the first painted frame. --}}
        <div class="mt-1.5 grid grid-cols-3 gap-2">
            @foreach (\App\Models\TimesheetEntry::STATUSES as $value => $label)
                <label class="block">
                    <input type="radio" name="status" value="{{ $value }}" class="peer sr-only"
                           @checked(old('status', $entry->status ?? \App\Models\TimesheetEntry::STATUS_COMPLETED) === $value)>
                    <span class="flex items-center justify-center min-h-[44px] px-2 rounded-md border text-sm font-semibold cursor-pointer transition-colors
                                 border-gray-300 bg-white text-gray-600 hover:bg-gray-50
                                 peer-checked:border-brand-400 peer-checked:bg-brand-50 peer-checked:text-brand-700
                                 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-400">
                        {{ $label }}
                    </span>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('status')" class="mt-2" />
    </div>

    <div class="mt-5">
        <x-input-label :for="'ts_notes_'.$uid" value="Notes (optional)" />
        <x-textarea :id="'ts_notes_'.$uid" name="notes" rows="2" class="mt-1">{{ old('notes', $entry->notes ?? '') }}</x-textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
    </div>

    <div class="mt-5">
        <x-primary-button class="w-full sm:w-auto">{{ $entry ? 'Save changes' : 'Add entry' }}</x-primary-button>
    </div>
</form>

@once
    @push('scripts')
        <script>
            function timesheetEntry(initial) {
                return {
                    start: initial.start || '',
                    end: initial.end || '',
                    minutes: initial.minutes || 0,

                    /* Mirrors TimesheetEntry::minutesBetween() so the figure
                       shown matches what the server will store. */
                    recompute() {
                        if (! this.start || ! this.end) return;

                        const [sh, sm] = this.start.split(':').map(Number);
                        const [eh, em] = this.end.split(':').map(Number);
                        let diff = (eh * 60 + em) - (sh * 60 + sm);
                        if (diff < 0) diff += 24 * 60;

                        this.minutes = diff;
                    },

                    now(field) {
                        const d = new Date();
                        const value = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');

                        this[field === 'start' ? 'start' : 'end'] = value;
                        this.recompute();
                    },

                    setDate(offset) {
                        const d = new Date();
                        d.setDate(d.getDate() + offset);
                        // Local date, not toISOString(): that converts to UTC
                        // and rolls the date back for anyone east of Greenwich.
                        this.$refs.date.value = [
                            d.getFullYear(),
                            String(d.getMonth() + 1).padStart(2, '0'),
                            String(d.getDate()).padStart(2, '0'),
                        ].join('-');
                    },

                    // A typed duration wins only while the times are blank, which
                    // is the same rule the controller applies.
                    setMinutes(value) {
                        this.minutes = value;
                        this.start = '';
                        this.end = '';
                    },

                    clearTimes() {
                        this.start = '';
                        this.end = '';
                    },

                    get label() {
                        if (! this.minutes) return '—';

                        const h = Math.floor(this.minutes / 60), m = this.minutes % 60;

                        return [
                            h ? h + (h === 1 ? ' hr' : ' hrs') : '',
                            m ? m + (m === 1 ? ' min' : ' mins') : '',
                        ].filter(Boolean).join(' ');
                    },
                };
            }
        </script>
    @endpush
@endonce
