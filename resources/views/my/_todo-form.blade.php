@php
    // Renders bare -- the caller supplies the card chrome.
    $todo = $todo ?? null;
    $uid = $todo?->id ?? 'new';

    /*
     * A board carries one add form and an edit form per to-do, all posting to
     * the same two routes. Unscoped, old() would replay a rejected edit into
     * every one of them at once, so each form only reads back the input it
     * actually sent -- identified by the id it posts alongside.
     */
    $isTarget = $todo
        ? (int) old('edit_todo_id') === $todo->id
        : ! old('edit_todo_id');

    $was = fn (string $key, $fallback) => $isTarget ? old($key, $fallback) : $fallback;
    $errorsFor = fn (string $key) => $isTarget ? $errors->get($key) : [];

    $defaultStart = $todo?->starts_on?->toDateString() ?? ($day ?? today())->toDateString();
    $defaultDue = $todo?->due_on?->toDateString() ?? $defaultStart;

    $allVentures = \App\Support\TimesheetVenture::ALL_CLIENTS;
    $currentVenture = $was('venture', $todo->venture ?? '');
    $knownVentures = collect($ventureOptions)->pluck('value')->all();
@endphp

<form method="POST" action="{{ $todo ? route('my.todos.update', $todo) : route('my.todos.store') }}"
      x-data="{
          startsOn: @js($was('starts_on', $defaultStart)),
          dueOn: @js($was('due_on', $defaultDue)),
          /* A last day before the first is the one combination the server
             rejects, so pull it along rather than let somebody submit into an
             error they did not cause. */
          syncDue() {
              if (! this.dueOn || this.dueOn < this.startsOn) {
                  this.dueOn = this.startsOn;
              }
          },
          get spanLabel() {
              if (! this.startsOn || ! this.dueOn) return '';
              const days = Math.round((new Date(this.dueOn) - new Date(this.startsOn)) / 86400000) + 1;
              return days > 1 ? days + ' days' : 'One day';
          }
      }">
    @csrf
    @if ($todo)
        @method('PUT')
        <input type="hidden" name="edit_todo_id" value="{{ $todo->id }}">
    @endif

    <div class="mb-4">
        <x-input-label :for="'todo_title_'.$uid" value="What needs doing" />
        <x-text-input :id="'todo_title_'.$uid" name="title" type="text" class="mt-1 block w-full"
                      value="{{ $was('title', $todo->title ?? '') }}"
                      placeholder="e.g. Edit the Vellore wedding teaser" required />
        <x-input-error :messages="$errorsFor('title')" class="mt-2" />
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
            <x-input-label :for="'todo_for_'.$uid" value="Who is doing it" />
            @if ($todo)
                {{-- Settled when it was written. Moving work to somebody else is
                     a new to-do, so this one's history keeps describing the
                     person it was actually handed to. --}}
                <div class="mt-1 flex items-center gap-2 min-h-[44px]">
                    <x-avatar :name="$todo->user->name" :src="$todo->user->avatarUrl()" size="sm" />
                    <span class="text-sm text-brand-100/80">{{ $todo->user->name }}</span>
                </div>
            @else
                <x-select :id="'todo_for_'.$uid" name="user_id" class="mt-1" required>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}"
                            @selected((int) $was('user_id', auth()->id()) === $person->id)>
                            {{ $person->id === auth()->id() ? 'Me — '.$person->name : $person->name }}
                        </option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errorsFor('user_id')" class="mt-2" />
            @endif
        </div>

        <div>
            <x-input-label :for="'todo_venture_'.$uid" value="Client / Venture" />
            @php $clientOptions = collect($ventureOptions)->reject(fn ($o) => $o['value'] === $allVentures); @endphp
            <x-select :id="'todo_venture_'.$uid" name="venture" class="mt-1" required>
                <option value="">Select client</option>
                @foreach ($clientOptions as $option)
                    <option value="{{ $option['value'] }}" @selected($currentVenture === $option['value'])>{{ $option['label'] }}</option>
                @endforeach
                {{-- Last, and always offered: plenty of work is not any one
                     client's, and without this the field cannot be answered
                     honestly. --}}
                <option value="{{ $allVentures }}" @selected($currentVenture === $allVentures)>{{ $allVentures }}</option>
            </x-select>
            @if ($currentVenture !== '' && ! in_array($currentVenture, $knownVentures, true))
                <p class="mt-1 text-xs text-amber-200">“{{ $currentVenture }}” is not a client any more — pick one to fix it.</p>
            @endif
            <x-input-error :messages="$errorsFor('venture')" class="mt-2" />
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <div>
            <x-input-label :for="'todo_start_'.$uid" value="First day" />
            <x-text-input :id="'todo_start_'.$uid" name="starts_on" type="date" class="mt-1 block w-full"
                          x-model="startsOn" @change="syncDue()" required />
            <x-input-error :messages="$errorsFor('starts_on')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'todo_due_'.$uid" value="Last day" />
            <x-text-input :id="'todo_due_'.$uid" name="due_on" type="date" class="mt-1 block w-full"
                          x-model="dueOn" />
            <p class="text-[11px] text-brand-100/60 mt-1">
                Same day for a one-day job. <span x-text="spanLabel" class="font-semibold"></span>
            </p>
            <x-input-error :messages="$errorsFor('due_on')" class="mt-2" />
        </div>
    </div>

    <div class="mb-4">
        <x-input-label :for="'todo_notes_'.$uid" value="Notes (optional)" />
        <x-textarea :id="'todo_notes_'.$uid" name="notes" rows="2" class="mt-1">{{ $was('notes', $todo->notes ?? '') }}</x-textarea>
        <x-input-error :messages="$errorsFor('notes')" class="mt-2" />
    </div>

    <x-primary-button>{{ $todo ? 'Save Changes' : 'Add To-do' }}</x-primary-button>
</form>
