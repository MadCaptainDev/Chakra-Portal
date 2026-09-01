@php
    $result = session('importResult');
@endphp

<x-app-layout title="Import from Google Keep">
    <x-slot name="header">
        <x-page-header title="Import from Google Keep" eyebrow="Scripts"
                       subtitle="One script per Keep note, matched to a content item by title.">
            <x-slot name="actions">
                <a href="{{ route('scripts.index') }}" class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    ← Back to Scripts
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-2xl mx-auto space-y-6">
        <x-card class="p-4 sm:p-6">
            <h3 class="text-sm font-semibold text-white mb-3">Before you upload</h3>
            <ol class="space-y-2 text-sm text-brand-100/70 list-decimal list-inside">
                <li>
                    Google Keep has no direct connection this app can use — Google only
                    offers that to paid Workspace accounts, for legal-hold purposes, not
                    for reading your own notes. This is the sanctioned way around that.
                </li>
                <li>
                    Go to <a href="https://takeout.google.com" target="_blank" rel="noopener" class="text-brand-300 hover:text-brand-200 font-medium">takeout.google.com</a>,
                    click <strong class="text-brand-200">Deselect all</strong>, then tick only
                    <strong class="text-brand-200">Keep</strong>.
                </li>
                <li>Create the export and download the <code class="text-brand-300">.zip</code> Google sends you.</li>
                <li>Upload that zip below, unchanged.</li>
            </ol>
            <p class="mt-3 text-xs text-brand-100/50">
                Each Keep note's <strong class="text-brand-200">title</strong> must match a content item's title exactly
                (case and spacing are ignored) — a note with no matching title is listed after import so you can fix
                either side and re-run.
            </p>
        </x-card>

        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('scripts.import-keep.store') }}" enctype="multipart/form-data">
                @csrf
                <x-input-label for="keep_export" value="Takeout export (.zip)" />
                <input id="keep_export" name="keep_export" type="file" accept=".zip,application/zip" required
                       class="mt-1 block w-full text-sm text-brand-100/70 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:uppercase file:tracking-widest file:bg-white/10 file:text-white hover:file:bg-white/[0.16]">
                <x-input-error :messages="$errors->get('keep_export')" class="mt-2" />

                <div class="mt-4">
                    <x-primary-button type="submit">Import</x-primary-button>
                </div>
            </form>
        </x-card>

        @if ($result)
            <x-card class="p-4 sm:p-6 space-y-5">
                <h3 class="text-sm font-semibold text-white">Last import</h3>

                @if (count($result['imported']) > 0)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-green-300 mb-2">
                            Imported ({{ count($result['imported']) }})
                        </p>
                        <ul class="space-y-1">
                            @foreach ($result['imported'] as $row)
                                <li class="text-sm">
                                    <a href="{{ route('scripts.edit', $row['script_id']) }}" class="text-white hover:text-brand-300">
                                        {{ $row['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (count($result['skipped_existing']) > 0)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">
                            Already had a script, skipped ({{ count($result['skipped_existing']) }})
                        </p>
                        <p class="text-sm text-brand-100/60">{{ implode(', ', $result['skipped_existing']) }}</p>
                    </div>
                @endif

                @if (count($result['ambiguous']) > 0)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-amber-300 mb-2">
                            Matched more than one content item, used the most recent ({{ count($result['ambiguous']) }})
                        </p>
                        <p class="text-sm text-brand-100/60">{{ implode(', ', $result['ambiguous']) }}</p>
                    </div>
                @endif

                @if (count($result['unmatched']) > 0)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-red-300 mb-2">
                            No matching content item, not imported ({{ count($result['unmatched']) }})
                        </p>
                        <p class="text-sm text-brand-100/60">{{ implode(', ', $result['unmatched']) }}</p>
                    </div>
                @endif
            </x-card>
        @endif
    </div>
</x-app-layout>
