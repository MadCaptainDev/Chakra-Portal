@php
    // The fields of one account: bank (by logo), whose account, last 4.
    // $prefix is "new_" when nested inside the EMI form, "" on its own.
    $prefix = $prefix ?? '';
    $account = $account ?? null;
    $uid = $uid ?? ($account?->id ?? 'new');
    $current = old($prefix.'bank', $account?->bank);
@endphp
<div class="space-y-4">
    <div>
        <p class="text-sm font-medium text-brand-100/80">Bank</p>
        <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6">
            @foreach ($banks as $key => $name)
                <label class="cursor-pointer">
                    <input type="radio" name="{{ $prefix }}bank" value="{{ $key }}" class="peer sr-only" @checked($current === $key)>
                    <span class="flex h-14 items-center justify-center rounded-lg bg-white p-1.5 opacity-70 ring-2 ring-transparent transition hover:opacity-100 peer-checked:opacity-100 peer-checked:ring-brand-400 peer-focus-visible:ring-brand-300"
                          title="{{ $name }}">
                        <img src="{{ \App\Support\Banks::logo($key) }}" alt="{{ $name }}" class="max-h-full max-w-full object-contain" loading="lazy">
                    </span>
                </label>
            @endforeach
            <label class="cursor-pointer">
                <input type="radio" name="{{ $prefix }}bank" value="" class="peer sr-only" @checked($current === null || $current === '')>
                <span class="flex h-14 items-center justify-center rounded-lg bg-white/5 px-1 text-center text-[11px] font-semibold leading-tight text-brand-100/70 ring-2 ring-transparent transition peer-checked:ring-brand-400">
                    Person<br>(paid directly)
                </span>
            </label>
        </div>
        <x-input-error :messages="$errors->get($prefix.'bank')" class="mt-2" />
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
            <x-input-label :for="$prefix.'holder_'.$uid" value="Whose account (deposit into)" />
            <x-text-input :id="$prefix.'holder_'.$uid" :name="$prefix.'holder_name'" type="text" class="mt-1 block w-full"
                          value="{{ old($prefix.'holder_name', $account?->holder_name) }}" placeholder="e.g. Annamalai" />
            <x-input-error :messages="$errors->get($prefix.'holder_name')" class="mt-2" />
        </div>
        <div>
            <x-input-label :for="$prefix.'last4_'.$uid" value="Account last 4 digits" />
            <x-text-input :id="$prefix.'last4_'.$uid" :name="$prefix.'last4'" type="text" inputmode="numeric" maxlength="4" pattern="\d{4}"
                          class="mt-1 block w-full" value="{{ old($prefix.'last4', $account?->last4) }}" placeholder="Optional" />
            <x-input-error :messages="$errors->get($prefix.'last4')" class="mt-2" />
        </div>
        <div>
            <x-input-label :for="$prefix.'nick_'.$uid" value="Nickname" />
            <x-text-input :id="$prefix.'nick_'.$uid" :name="$prefix.'nickname'" type="text" class="mt-1 block w-full"
                          value="{{ old($prefix.'nickname', $account?->nickname) }}" placeholder="Optional, e.g. Salary account" />
        </div>
    </div>
</div>
