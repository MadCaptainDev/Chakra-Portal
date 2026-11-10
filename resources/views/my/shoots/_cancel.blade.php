{{-- Calling the shoot off, from any step before the wrap. The reason is kept. --}}
<details class="mx-auto mt-10 w-full max-w-sm text-left">
    <summary class="cursor-pointer list-none text-center text-xs text-white/35 underline underline-offset-4 hover:text-white/60">
        Shoot not happening? Cancel it
    </summary>
    <form method="POST" action="{{ route('my.shoots.cancel', $shoot) }}" class="mt-3 rounded-xl bg-red-500/10 p-3"
          onsubmit="return confirm('Cancel this shoot? The studio will see it as cancelled.')">
        @csrf
        <label class="text-xs text-red-200/80" for="cancel-reason">Why is it cancelled?</label>
        <input id="cancel-reason" type="text" name="reason" required maxlength="500" placeholder="Client postponed, rain, model not available…"
               class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:ring-2 focus:ring-red-400">
        <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
        <button type="submit" class="mt-2 w-full rounded-lg bg-red-500 px-4 py-2.5 text-sm font-semibold text-white">Cancel shoot</button>
    </form>
</details>
