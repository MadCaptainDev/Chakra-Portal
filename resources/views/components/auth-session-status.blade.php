@props(['status'])

{{-- Sits on the brand-900 ground, so the old text-green-600 was close to
     invisible. Reads as a confirmation panel instead. --}}
@if ($status)
    <div {{ $attributes->merge([
        'class' => 'px-3.5 py-3 rounded-md bg-brand-400/15 border border-brand-400/40 text-sm font-medium text-brand-200',
    ]) }} role="status">
        {{ $status }}
    </div>
@endif
