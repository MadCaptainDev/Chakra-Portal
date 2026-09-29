{{--
    The Video Checker. The analysis is resources/js/video-check.js and runs in
    the browser on the editor's own device; the file is never uploaded. What
    comes back to the server is the verdict, when "Save" is pressed.
--}}
<x-app-layout title="Video Checker">
    <x-slot name="header">
        <x-page-header title="Video Checker"
                       subtitle="Check an export before it goes to the client or gets posted. The video stays on this device — nothing is uploaded." />
    </x-slot>

    <div class="space-y-5" x-data="videoChecker({ saveUrl: @js(route('video-check.store')) })">
        <x-card padding="md">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">Where is it going?</p>
            <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
                @foreach ($presets as $key => $name)
                    <button type="button" @click="preset = @js($key); changePreset()"
                            :class="preset === @js($key) ? 'bg-brand-400 text-brand-900 ring-brand-400' : 'bg-white/5 text-brand-100/80 ring-white/10 hover:bg-white/10'"
                            class="min-h-[44px] px-3 rounded-lg ring-1 text-sm font-semibold transition">
                        {{ $name }}
                    </button>
                @endforeach
            </div>

            <label class="mt-4 flex flex-col items-center justify-center gap-2 min-h-[120px] px-4 py-6 rounded-xl border-2 border-dashed border-white/15
                          hover:border-brand-400/60 hover:bg-white/[0.03] cursor-pointer text-center transition">
                <x-icon name="eye" class="w-7 h-7 text-brand-300" />
                <span class="text-sm font-semibold text-white" x-text="file ? 'Check another video' : 'Choose a video to check'"></span>
                <span class="text-xs text-brand-100/60" x-show="! file">MP4 or MOV, straight from the edit export</span>
                <span class="text-xs text-brand-100/60 truncate max-w-full" x-show="file" x-text="file?.name" x-cloak></span>
                <input type="file" accept="video/*" class="sr-only" @change="pick($event)">
            </label>
        </x-card>

        <div x-show="file" x-cloak class="grid gap-5 lg:grid-cols-[minmax(0,340px)_1fr]">
            {{-- Preview, with where Instagram's buttons and caption will sit. --}}
            <x-card padding="sm">
                <div class="relative mx-auto overflow-hidden rounded-lg bg-black" :class="isVertical ? 'max-w-[300px]' : ''">
                    <video x-ref="video" controls playsinline muted class="block w-full h-auto max-h-[70vh]"></video>

                    <template x-if="safeZones && isVertical && status === 'done'">
                        <div class="pointer-events-none absolute inset-0">
                            <div class="absolute inset-x-0 top-0 h-[12%] bg-red-500/25 border-b border-dashed border-red-300/70"></div>
                            <div class="absolute inset-x-0 bottom-0 h-[22%] bg-red-500/25 border-t border-dashed border-red-300/70"></div>
                            <div class="absolute right-0 top-[40%] bottom-[22%] w-[16%] bg-red-500/25 border-l border-dashed border-red-300/70"></div>
                        </div>
                    </template>
                </div>

                <label class="mt-3 flex items-center gap-2 text-xs text-brand-100/70" x-show="isVertical">
                    <input type="checkbox" x-model="safeZones" class="rounded border-white/20 bg-white/5 text-brand-400 focus:ring-brand-400">
                    Show where buttons and captions cover the video — keep text out of the red
                </label>
            </x-card>

            <div class="space-y-4 min-w-0">
                <x-card padding="md" x-show="status === 'working'">
                    <div class="flex items-center gap-3 text-sm text-brand-100/80">
                        <span class="inline-block w-4 h-4 rounded-full border-2 border-brand-300 border-t-transparent animate-spin"></span>
                        <span x-text="step"></span>
                    </div>
                </x-card>

                <template x-if="status === 'done'">
                    <div class="space-y-4">
                        <div class="rounded-2xl p-5 ring-1"
                             :class="{
                                'bg-emerald-400/10 ring-emerald-400/30': verdict === 'pass',
                                'bg-amber-400/10 ring-amber-400/30': verdict === 'warn',
                                'bg-red-400/10 ring-red-400/30': verdict === 'fail',
                             }">
                            <p class="text-lg font-bold"
                               :class="{ 'text-emerald-200': verdict === 'pass', 'text-amber-200': verdict === 'warn', 'text-red-200': verdict === 'fail' }"
                               x-text="{ pass: '✓ Ready to go', warn: 'Good, with things to check', fail: '✕ Fix before sending' }[verdict]"></p>
                            <p class="mt-1 text-sm text-brand-100/70" x-text="'Checked as ' + presets[preset].name"></p>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <template x-for="chip in chips()" :key="chip">
                                    <span class="px-2 py-0.5 rounded-md bg-white/10 text-xs font-medium text-brand-100 tabular-nums" x-text="chip"></span>
                                </template>
                            </div>
                        </div>

                        <x-card padding="none" class="overflow-hidden">
                            <ul class="divide-y divide-white/5">
                                <template x-for="(r, i) in results" :key="i">
                                    <li class="flex items-start gap-3 px-4 py-3">
                                        <span class="shrink-0 mt-0.5 inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
                                              :class="{
                                                'bg-emerald-400/15 text-emerald-300': r.status === 'pass',
                                                'bg-amber-400/15 text-amber-300': r.status === 'warn',
                                                'bg-red-400/15 text-red-300': r.status === 'fail',
                                                'bg-sky-400/15 text-sky-300': r.status === 'info',
                                              }"
                                              x-text="{ pass: '✓', warn: '!', fail: '✕', info: 'i' }[r.status]"></span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-white" x-text="r.title"></p>
                                            <p class="mt-0.5 text-sm text-brand-100/70" x-text="r.detail"></p>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </x-card>

                        <x-card padding="md">
                            <x-input-label for="video_check_label" value="Save to history as" />
                            <div class="mt-1 flex flex-col sm:flex-row gap-2">
                                <x-text-input id="video_check_label" type="text" class="block w-full" maxlength="120"
                                              x-model="label" placeholder="Client and reel name" />
                                <button type="button" @click="save()" :disabled="saving || saved"
                                        class="shrink-0 min-h-[44px] px-5 rounded-md bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 disabled:opacity-60 transition">
                                    <span x-show="! saved && ! saving">Save</span>
                                    <span x-show="saving" x-cloak>Saving…</span>
                                    <span x-show="saved" x-cloak>Saved ✓</span>
                                </button>
                            </div>
                            <p class="mt-2 text-sm text-red-300" x-show="saveError" x-text="saveError" x-cloak></p>
                            <p class="mt-2 text-xs text-brand-100/50">Only the result is saved — never the video.</p>
                        </x-card>
                    </div>
                </template>
            </div>
        </div>

        <x-card padding="none" class="overflow-hidden">
            <div class="px-4 py-3 border-b border-white/10">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">
                    {{ $showOwner ? 'Recent checks — everyone' : 'Your recent checks' }}
                </p>
            </div>

            @forelse ($checks as $check)
                <div class="flex items-center gap-3 px-4 py-3 {{ $loop->first ? '' : 'border-t border-white/5' }}">
                    <span @class([
                        'shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold',
                        'bg-emerald-400/15 text-emerald-300' => $check->verdict === 'pass',
                        'bg-amber-400/15 text-amber-300' => $check->verdict === 'warn',
                        'bg-red-400/15 text-red-300' => $check->verdict === 'fail',
                    ])>{{ ['pass' => '✓', 'warn' => '!', 'fail' => '✕'][$check->verdict] ?? '?' }}</span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-white truncate">{{ $check->label ?: $check->file_name }}</p>
                        <p class="text-xs text-brand-100/60 truncate">
                            {{ $check->presetLabel() }}
                            @foreach ($check->tally() as $status => $count)
                                · <span class="{{ $status === 'fail' ? 'text-red-300' : 'text-amber-300' }}">{{ $count }} {{ $status === 'fail' ? 'to fix' : 'to check' }}</span>
                            @endforeach
                            @if ($showOwner)
                                · {{ $check->user?->name }}
                            @endif
                        </p>
                    </div>

                    <span class="shrink-0 text-xs text-brand-100/50">{{ $check->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-brand-100/60">Nothing checked yet.</p>
            @endforelse
        </x-card>
    </div>
</x-app-layout>
