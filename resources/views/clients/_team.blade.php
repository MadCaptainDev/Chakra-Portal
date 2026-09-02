<x-card class="p-4 sm:p-6 border border-white/10">
    <h3 class="font-semibold text-white">Their team</h3>
    <p class="mt-1 text-sm text-brand-100/70">
        Who {{ $client->name }} sees on their own dashboard as "Your team" -- purely a name and a label, not a
        permission. Adding someone here does not change what they can do in the portal.
    </p>

    @if ($teamMembers->isEmpty())
        <p class="mt-4 text-sm text-brand-100/60">Nobody added yet.</p>
    @else
        <ul class="mt-4 divide-y divide-white/10">
            @foreach ($teamMembers as $member)
                <li class="flex items-center justify-between gap-3 py-2.5">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-white truncate">{{ $member->name }}</p>
                        @if ($member->pivot->role)
                            <p class="text-xs text-brand-100/60">{{ $member->pivot->role }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('clients.team.destroy', [$client, $member]) }}"
                          onsubmit="return confirm('Remove {{ $member->name }} from {{ $client->name }}\'s team?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs font-semibold text-red-300 hover:text-red-200">Remove</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($assignableStaff->isNotEmpty())
        <form method="POST" action="{{ route('clients.team.store', $client) }}"
              class="mt-4 pt-4 border-t border-white/10 flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[160px]">
                <x-input-label for="team_user_id" value="Add someone" />
                <x-select id="team_user_id" name="user_id" class="mt-1" required>
                    <option value="" selected disabled>Choose someone&hellip;</option>
                    @foreach ($assignableStaff as $staff)
                        <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex-1 min-w-[160px]">
                <x-input-label for="team_role" value="As" />
                <x-text-input id="team_role" name="role" type="text" class="mt-1 w-full" placeholder="e.g. Editor, Account Manager" />
            </div>
            <x-primary-button>Add</x-primary-button>
        </form>
    @endif
</x-card>
