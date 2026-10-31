@props(['account' => null, 'size' => 'md'])
{{-- A bank's own logo on a white tile (logos are made for white), or the
     account holder's initials when the EMI is paid to a person directly. --}}
@php
    $box = [
        'sm' => 'h-7 w-14 rounded-md p-0.5',
        'md' => 'h-10 w-20 rounded-lg p-1',
        'lg' => 'h-14 w-28 rounded-xl p-1.5',
    ][$size] ?? 'h-10 w-20 rounded-lg p-1';
    $logo = $account?->logoUrl();
@endphp
@if ($logo)
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center bg-white shadow-sm ring-1 ring-black/5 $box"]) }}
          title="{{ $account->bankName() }}">
        <img src="{{ $logo }}" alt="{{ $account->bankName() }}" class="max-h-full max-w-full object-contain" loading="lazy">
    </span>
@else
    <span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center bg-brand-400/15 font-bold text-brand-200 ring-1 ring-white/10 $box ".($size === 'sm' ? 'text-[10px]' : 'text-sm')]) }}
          title="{{ $account ? 'Paid directly' : 'No account set' }}">
        {{ $account ? $account->initials() : '?' }}
    </span>
@endif
