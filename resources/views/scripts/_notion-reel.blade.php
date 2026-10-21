{{--
    Which Notion Reel Planner row this script is. The sync links most by
    title (VideoLinker); this settles the rest, and once the reel is past
    the shoot in Notion the script is marked Completed.
--}}
@php
    $linked = $script->contentItem;
    $suggestions = auth()->user()->can('scripts.edit') && $script->client_id
        ? app(\App\Services\Videos\VideoLinker::class)->suggestionsFor($script)
        : collect();
@endphp
@if ($linked || $suggestions->isNotEmpty())
    <x-card class="p-4 sm:p-5 print:hidden">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-100/50">Notion reel</p>
                @if ($linked)
                    <p class="mt-1 font-semibold text-white">{{ $linked->title }}</p>
                    <p class="text-xs text-brand-100/60">{{ $linked->status }}{{ $linked->published_date ? ' · publishes '.$linked->published_date->format('j M') : '' }}{{ $script->shot_at ? ' · shot '.$script->shot_at->format('j M') : '' }}</p>
                @else
                    <p class="mt-1 text-sm text-amber-200">Not linked yet — pick its reel below so the planner shows this script and Notion can mark it shot.</p>
                @endif
            </div>
            @if ($linked?->notion_url)
                <a href="{{ $linked->notion_url }}" target="_blank" rel="noopener" class="shrink-0 text-sm text-brand-300 hover:text-white">Open in Notion ↗</a>
            @endif
        </div>

        @if ($suggestions->isNotEmpty())
            <form method="POST" action="{{ route('scripts.notion-reel', $script) }}" class="mt-3 flex flex-wrap gap-2">
                @csrf
                <select name="content_item_id" class="min-w-0 flex-1 rounded-lg border-0 bg-[#0f2230] text-sm text-white ring-1 ring-white/15">
                    <option value="">{{ $linked ? '— Remove the link —' : '— Choose the Notion reel —' }}</option>
                    @foreach ($suggestions as $s)
                        <option value="{{ $s['item']->id }}" @selected($linked?->id === $s['item']->id) @disabled($s['taken_by'])>
                            {{ Str::limit($s['item']->title, 70) }} · {{ $s['item']->status }}{{ $s['taken_by'] ? ' (script #'.$s['taken_by'].')' : '' }}
                        </option>
                    @endforeach
                </select>
                <x-btn type="submit" size="sm">{{ $linked ? 'Change' : 'Link' }}</x-btn>
            </form>
        @endif
    </x-card>
@endif
