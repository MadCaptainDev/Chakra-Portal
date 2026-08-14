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
            <x-input-label :for="'todo_start_'.$uid" value="First day" />
            <x-text-input :id="'todo_start_'.$uid" name="starts_on" type="date" class="mt-1 block w-full"
                          x-model="startsOn" @change="syncDue()" required />
            <x-input-error :messages="$errorsFor('starts_on')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'todo_due_'.$uid" value="Last day" />
            <x-text-input :id="'todo_due_'.$uid" name="due_on" type="date" class="mt-1 block w-full"
                          x-model="dueOn" />
            <p class="text-[11px] text-gray-500 mt-1">
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
