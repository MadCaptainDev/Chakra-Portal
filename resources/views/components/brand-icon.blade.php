@props(['name', 'class' => 'w-5 h-5'])

@php
    /*
     * Real platform marks, not the app's own outline set (icon.blade.php).
     * Those are deliberately generic (single currentColor path, so they pick
     * up whatever colour the surrounding text has); a platform logo is the
     * opposite -- Instagram's gradient and YouTube's red ARE the point,
     * because they are what makes a Reel column readable as "Instagram" at
     * a glance instead of one more line of a legend somebody has to learn.
     *
     * Inlined rather than hotlinked from a CDN: this is a business tool
     * staff read every day, and a dashboard that goes half-broken because an
     * external icon host is down or rate-limits is worse than a dashboard
     * with no icons. Paths are the standard simplified glyphs used across
     * the web for each brand (the same shapes as Simple Icons), redrawn as
     * plain SVG so nothing here depends on a package or a network request.
     */
@endphp

@switch($name)
    @case('instagram')
        @php
            // A fresh id per render -- this icon appears once per Reel/Post
            // row, often several times on one page, and a repeated <linearGradient
            // id> is invalid markup the moment there is more than one on screen.
            $gradId = 'ig-grad-'.\Illuminate\Support\Str::random(8);
        @endphp
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" aria-hidden="true">
            <defs>
                <linearGradient id="{{ $gradId }}" x1="0%" y1="100%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#FFDD55" />
                    <stop offset="35%" stop-color="#FF543E" />
                    <stop offset="70%" stop-color="#C837AB" />
                    <stop offset="100%" stop-color="#5B51D8" />
                </linearGradient>
            </defs>
            <rect x="2" y="2" width="20" height="20" rx="5.5" fill="url(#{{ $gradId }})" />
            <circle cx="12" cy="12" r="4.6" fill="none" stroke="#fff" stroke-width="1.6" />
            <circle cx="17.3" cy="6.7" r="1.15" fill="#fff" />
        </svg>
        @break

    @case('youtube')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#FF0000" d="M23.5 6.2a3.02 3.02 0 00-2.12-2.14C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.38.56A3.02 3.02 0 00.5 6.2 31.6 31.6 0 000 12a31.6 31.6 0 00.5 5.8 3.02 3.02 0 002.12 2.14c1.88.56 9.38.56 9.38.56s7.5 0 9.38-.56a3.02 3.02 0 002.12-2.14A31.6 31.6 0 0024 12a31.6 31.6 0 00-.5-5.8z" />
            <path fill="#fff" d="M9.6 15.6V8.4L15.8 12z" />
        </svg>
        @break

    @case('whatsapp')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="10.5" fill="#25D366" />
            <path fill="#fff" d="M16.98 13.83c-.27-.14-1.6-.79-1.85-.88-.25-.09-.43-.14-.61.14-.18.27-.7.88-.86 1.06-.16.18-.32.2-.59.07-.27-.14-1.14-.42-2.17-1.34-.8-.72-1.34-1.6-1.5-1.87-.16-.27-.02-.42.12-.55.12-.12.27-.32.41-.48.14-.16.18-.27.27-.45.09-.18.05-.34-.02-.48-.07-.14-.61-1.47-.84-2.02-.22-.53-.44-.46-.61-.47h-.52c-.18 0-.48.07-.73.34-.25.27-.96.94-.96 2.29 0 1.35.98 2.65 1.12 2.83.14.18 1.93 2.95 4.68 4.14.65.28 1.16.45 1.56.58.66.21 1.25.18 1.72.11.53-.08 1.6-.65 1.82-1.28.23-.63.23-1.17.16-1.28-.07-.11-.25-.18-.52-.32z" />
        </svg>
        @break

    @default
        {{-- Falls back to the generic outline set so a typo'd name never
             renders nothing at all. --}}
        <x-icon :name="$name" :class="$class" {{ $attributes }} />
@endswitch
