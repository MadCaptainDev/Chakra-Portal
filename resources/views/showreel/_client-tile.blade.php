{{-- One client: their logo on a white tile (logos are drawn for white), or
     their name until a logo is uploaded on the client record. --}}
@if ($client['logo'])
    <div class="{{ $size }} flex items-center justify-center rounded-2xl bg-white p-3 sm:p-4 shadow-2xl shadow-black/40">
        <img src="{{ $client['logo'] }}" alt="{{ $client['name'] }}" loading="lazy" draggable="false"
             class="max-w-full max-h-full object-contain">
    </div>
@else
    <div class="{{ $size }} flex items-center justify-center rounded-2xl bg-white/[0.07] ring-1 ring-white/15 px-4 text-center">
        <span class="text-sm sm:text-base font-semibold leading-tight text-white">{{ $client['name'] }}</span>
    </div>
@endif
