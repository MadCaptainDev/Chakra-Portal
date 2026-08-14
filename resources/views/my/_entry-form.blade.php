@php
    // Renders bare -- the caller supplies the card chrome.
    $entry = $entry ?? null;
    $uid = $entry?->id ?? 'new';
    $knownValues = collect($ventureOptions)->pluck('value')->all();
    $currentVenture = old('venture', $entry->venture ?? '');
    $currentType = old('task_type', $entry->task_type ?? '');
    // ?task= lets a finished to-do hand its title straight to the timesheet, so
    // the same work is not typed twice. Only ever seeds a new entry.
    $initialTask = old('task', $entry->task ?? ($entry ? '' : (string) request('task')));
    // Prefer stored/posted type; otherwise suggest from task text for new rows.
    if ($currentType === '' || ! array_key_exists($currentType, \App\Models\TimesheetEntry::TASK_TYPES)) {
        $currentType = $entry
            ? ($entry->task_type ?: \App\Models\TimesheetEntry::TASK_OTHER)
            : \App\Models\TimesheetEntry::inferTaskType($initialTask);
    }
@endphp

<form method="POST" action="{{ $entry ? route('my.timesheet.update', $entry) : route('my.timesheet.store') }}"
      x-data="{
          task: @js($initialTask),
          taskType: @js($currentType),
          typeTouched: {{ $entry || old('task_type') ? 'true' : 'false' }},
          suggestType() {
              if (this.typeTouched) return;
              const t = (this.task || '').toLowerCase();
              if (/\b(shoot|shooting|photo\s*shoot)\b/.test(t)) {
                  this.taskType = 'shooting';
              } else if (/\b(edit|editing|edits)\b/.test(t)) {
                  this.taskType = 'editing';
              } else if (/\b(post|posting|upload|uploading|schedule|scheduling|publish|publishing)\b/.test(t)) {
                  this.taskType = 'posting';
              } else if (t.trim() !== '') {
                  this.taskType = 'other';
              }
          }
      }">
    @csrf
    @if ($entry)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
            <x-input-label :for="'ts_date_'.$uid" value="Date" />
            <x-text-input :id="'ts_date_'.$uid" name="worked_on" type="date" class="mt-1 block w-full"
                          value="{{ old('worked_on', $entry?->worked_on?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required />
            <x-input-error :messages="$errors->get('worked_on')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_venture_'.$uid" value="Client / Venture" />
            @php
                $allClientsValue = \App\Support\TimesheetVenture::ALL_CLIENTS;
                $clientOptions = collect($ventureOptions)->reject(fn ($o) => $o['value'] === $allClientsValue);
            @endphp
            <x-select :id="'ts_venture_'.$uid" name="venture" class="mt-1" required>
                <option value="">Select client</option>
                @foreach ($clientOptions as $option)
                    <option value="{{ $option['value'] }}" @selected($currentVenture === $option['value'])>{{ $option['label'] }}</option>
                @endforeach
                <option value="{{ $allClientsValue }}" @selected($currentVenture === $allClientsValue)>{{ $allClientsValue }}</option>
            </x-select>
            @if ($currentVenture !== '' && ! in_array($currentVenture, $knownValues, true))
                <p class="mt-1 text-xs text-amber-700">Current value “{{ $currentVenture }}” is not a client — pick one below to fix it.</p>
            @endif
            <x-input-error :messages="$errors->get('venture')" class="mt-2" />
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
            <x-input-label :for="'ts_type_'.$uid" value="Type" />
            <x-select :id="'ts_type_'.$uid" name="task_type" class="mt-1" required
                      x-model="taskType" @change="typeTouched = true">
                @foreach (\App\Models\TimesheetEntry::TASK_TYPES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
            <x-input-error :messages="$errors->get('task_type')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_task_'.$uid" value="Task name" />
            <x-text-input :id="'ts_task_'.$uid" name="task" type="text" class="mt-1 block w-full"
                          x-model="task" @input="suggestType()"
                          value="{{ $initialTask }}" placeholder="e.g. Shoot, Editing, Upload" required />
            <x-input-error :messages="$errors->get('task')" class="mt-2" />
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-4"
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
            <p class="text-[11px] text-gray-500 mt-1">24-hour time (e.g. 22:30).</p>
        </div>

        <div>
            <x-input-label :for="'ts_minutes_'.$uid" value="Duration (mins)" />
            <input id="ts_minutes_{{ $uid }}" name="minutes" type="number" min="0" max="1440"
                   x-model.number="minutes"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <p class="text-[11px] text-gray-500 mt-1" x-text="label"></p>
            <x-input-error :messages="$errors->get('minutes')" class="mt-2" />
        </div>

    </div>

    <div class="mb-4">
        <x-input-label :for="'ts_notes_'.$uid" value="Notes (optional)" />
        <x-textarea :id="'ts_notes_'.$uid" name="notes" rows="2" class="mt-1">{{ old('notes', $entry->notes ?? '') }}</x-textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
    </div>

    <x-primary-button>{{ $entry ? 'Save Changes' : 'Add Entry' }}</x-primary-button>
</form>
