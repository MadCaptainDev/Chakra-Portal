@php
    // Renders bare -- the caller supplies the card chrome.
    $entry = $entry ?? null;
    $uid = $entry?->id ?? 'new';
    $knownValues = collect($ventureOptions)->pluck('value')->all();
    // ?venture= arrives with ?task= when a finished to-do hands its work over.
    $currentVenture = old('venture', $entry->venture ?? ($entry ? '' : (string) request('venture')));
    $currentType = old('task_type', $entry->task_type ?? '');
    // ?task= lets a finished to-do hand its title straight to the timesheet, so
    // the same work is not typed twice. Only ever seeds a new entry.
    $initialTask = old('task', $entry->task ?? ($entry ? '' : (string) request('task')));
    // Prefer stored/posted type; otherwise suggest from task text for new rows.
    if ($currentType === '' || ! array_key_exists($currentType, \App\Models\TimesheetEntry::taskTypes())) {
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
                <p class="mt-1 text-xs text-amber-200">Current value “{{ $currentVenture }}” is not a client — pick one below to fix it.</p>
            @endif
            <x-input-error :messages="$errors->get('venture')" class="mt-2" />

            {{-- More ventures on the same entry: a shared shoot, a day split
                 between two SVA brands. The first select above stays the
                 primary and is what every hours report counts against; these
                 record who else the work was for.

                 Collapsed behind a link because most entries name one venture
                 and a permanently open multi-select would be five rows of
                 nothing on the common case. --}}
            @php
                $extraVentures = collect(old('ventures', $entry?->ventures ?? []))
                    ->reject(fn ($v) => $v === $currentVenture)
                    ->values();
            @endphp
            <div x-data="{ open: {{ $extraVentures->isNotEmpty() || old('new_venture') ? 'true' : 'false' }}, other: {{ old('new_venture') ? 'true' : 'false' }} }"
                 class="mt-2">
                <button type="button" @click="open = ! open"
                        class="text-xs font-semibold text-brand-300 hover:text-brand-200">
                    <span x-text="open ? 'Fewer options' : '+ Also for other ventures'">+ Also for other ventures</span>
                </button>

                <div x-show="open" x-cloak class="mt-2 space-y-2">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($ventureOptions as $option)
                            @continue ($option['value'] === $currentVenture)
                            @php $checked = $extraVentures->contains($option['value']); @endphp
                            <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs font-medium cursor-pointer ring-1 ring-inset
                                          {{ $checked ? 'bg-white/5 text-brand-200 ring-brand-300' : 'bg-white/5 text-brand-100/70 ring-white/10 hover:ring-white/10' }}">
                                <input type="checkbox" name="ventures[]" value="{{ $option['value'] }}" @checked($checked)
                                       class="rounded border-white/15 text-brand-300 focus:ring-brand-500 w-3.5 h-3.5">
                                {{ $option['label'] }}
                            </label>
                        @endforeach
                    </div>

                    <div>
                        <button type="button" @click="other = ! other"
                                class="text-xs font-semibold text-brand-300 hover:text-brand-200">
                            <span x-text="other ? 'Cancel new venture' : '+ Other venture'">+ Other venture</span>
                        </button>

                        {{-- Typing a name here creates it as master data, so
                             the next person picks it off the list rather than
                             inventing a second spelling of the same thing. --}}
                        <div x-show="other" x-cloak class="mt-1.5">
                            <x-text-input name="new_venture" type="text" class="w-full sm:max-w-xs text-sm"
                                          value="{{ old('new_venture') }}"
                                          placeholder="Name the venture, e.g. Studio showreel" />
                            <p class="mt-1 text-xs text-brand-100/60">Added to the venture list for everyone.</p>
                            <x-input-error :messages="$errors->get('new_venture')" class="mt-1" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
            <x-input-label :for="'ts_type_'.$uid" value="Type" />
            <x-select :id="'ts_type_'.$uid" name="task_type" class="mt-1" required
                      x-model="taskType" @change="typeTouched = true">
                @foreach (\App\Models\TimesheetEntry::taskTypes() as $value => $label)
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
                   class="mt-1 block w-full rounded-md border-white/15 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <x-input-error :messages="$errors->get('started_at')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'ts_end_'.$uid" value="Finish" />
            <input id="ts_end_{{ $uid }}" name="ended_at" type="time"
                   x-model="end" @change="recompute()"
                   class="mt-1 block w-full rounded-md border-white/15 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <x-input-error :messages="$errors->get('ended_at')" class="mt-2" />
            <p class="text-[11px] text-brand-100/60 mt-1">24-hour time (e.g. 22:30).</p>
        </div>

        <div>
            <x-input-label :for="'ts_minutes_'.$uid" value="Duration (mins)" />
            <input id="ts_minutes_{{ $uid }}" name="minutes" type="number" min="0" max="1440"
                   x-model.number="minutes"
                   class="mt-1 block w-full rounded-md border-white/15 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <p class="text-[11px] text-brand-100/60 mt-1" x-text="label"></p>
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
