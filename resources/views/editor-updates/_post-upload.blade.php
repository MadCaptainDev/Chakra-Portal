{{--
    Upload an Instagram picture post (one picture or a carousel) for review,
    straight from Editor Updates. EditorUpdatesController::post → the same
    review as a video: comments per slide, approve or send back, then Ready
    to publish.
--}}
<section x-data="{
            open: {{ $errors->has('pictures') || $errors->has('pictures.*') || $errors->has('title') ? 'true' : 'false' }},
            mode: @js($cards->isNotEmpty() ? 'card' : 'new'),
            previews: [], sending: false,
            pick(e) {
                this.previews.forEach((p) => URL.revokeObjectURL(p.url));
                this.previews = [...e.target.files].slice(0, 10).map((f) => ({ url: URL.createObjectURL(f), name: f.name }));
            },
         }"
         class="rounded-[20px] border border-pink-400/30 bg-[#15303f]">
    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6">
        <span>
            <span class="text-[17px] font-bold text-white">🖼 Upload a picture post</span>
            <span class="mt-0.5 block text-xs text-brand-100/60">One picture or a carousel (up to 10). It goes to review like a video — comments on each slide, then approve and post.</span>
        </span>
        <span class="text-brand-100/50" x-text="open ? '▴' : '▾'"></span>
    </button>

    <form x-show="open" x-cloak method="POST" action="{{ route('editor-updates.post') }}" enctype="multipart/form-data" @submit="sending = true"
          class="space-y-3 border-t border-white/[0.06] px-5 py-4 sm:px-6">
        @csrf
        @if ($errors->has('pictures') || $errors->has('pictures.*') || $errors->has('title') || $errors->has('todo_id'))
            <p class="rounded-lg bg-red-500/15 px-3 py-2 text-sm text-red-200">{{ $errors->first('pictures') ?: ($errors->first('pictures.*') ?: ($errors->first('title') ?: $errors->first('todo_id'))) }}</p>
        @endif

        <div class="flex flex-wrap gap-2 text-sm">
            @if ($cards->isNotEmpty())
                <button type="button" @click="mode = 'card'" class="h-9 rounded-full px-3.5 ring-1" :class="mode === 'card' ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15'">For a card on the board</button>
            @endif
            <button type="button" @click="mode = 'new'" class="h-9 rounded-full px-3.5 ring-1" :class="mode === 'new' ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15'">New post</button>
        </div>

        <template x-if="mode === 'card'">
            <select name="todo_id" required class="h-11 w-full rounded-xl border-0 bg-[#0f2230] px-3 text-sm text-white focus:ring-2 focus:ring-pink-400">
                <option value="">Pick the card…</option>
                @foreach ($cards->groupBy(fn ($c) => $c->user_id === auth()->id() ? 'Mine' : ($c->user?->name ?? 'Someone')) as $who => $group)
                    <optgroup label="{{ $who }}">
                        @foreach ($group as $c)
                            <option value="{{ $c->id }}" @selected(old('todo_id') == $c->id)>{{ $c->title }}{{ $c->venture ? ' · '.$c->venture : '' }}{{ $c->kind === 'post' ? ' · 🖼' : '' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </template>
        <template x-if="mode === 'new'">
            <input name="title" value="{{ old('title') }}" required maxlength="255" placeholder="What is the post? e.g. Zira — Diwali offer carousel"
                   class="h-11 w-full rounded-xl border-0 bg-[#0f2230] px-3 text-sm text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-pink-400">
        </template>

        <label class="flex min-h-[88px] cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-white/20 bg-white/[0.03] px-4 py-4 text-center hover:border-pink-300/60">
            <span class="text-sm font-semibold text-white">Choose pictures</span>
            <span class="text-xs text-brand-100/55">JPG, PNG or WebP · in the order they should appear</span>
            <input type="file" name="pictures[]" accept="image/jpeg,image/png,image/webp" multiple required class="sr-only" @change="pick($event)">
        </label>
        <div x-show="previews.length" class="flex gap-2 overflow-x-auto pb-1">
            <template x-for="(p, i) in previews" :key="p.url">
                <div class="relative h-24 w-20 shrink-0 overflow-hidden rounded-lg ring-1 ring-white/15">
                    <img :src="p.url" alt="" class="h-full w-full object-cover">
                    <span class="absolute left-1 top-1 rounded-full bg-black/60 px-1.5 text-[10px] font-bold text-white" x-text="i + 1"></span>
                </div>
            </template>
        </div>

        <textarea name="note" rows="2" maxlength="1000" placeholder="Caption or a note for the reviewer (optional)"
                  class="w-full rounded-xl border-0 bg-[#0f2230] px-3 py-2.5 text-sm text-white placeholder:text-brand-100/40 focus:ring-2 focus:ring-pink-400">{{ old('note') }}</textarea>

        <button type="submit" :disabled="sending || !previews.length" class="inline-flex h-11 items-center rounded-xl bg-pink-500 px-5 text-sm font-bold text-white hover:bg-pink-400 disabled:opacity-40">
            <span x-show="!sending">Send for review</span><span x-show="sending" x-cloak>Uploading…</span>
        </button>
    </form>
</section>
