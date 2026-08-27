@php
    // Bytes the browser will refuse before it even starts uploading, matching
    // the server-side rule so the failure is instant rather than at 400 MB.
    $maxVideoMb = (int) round(\App\Models\PortfolioItem::VIDEO_MAX_KB / 1024);

    // Seeds the Alpine picker's "already selected" state: an item already
    // mapped when editing, or a pre-selection handed down by the "Add to
    // portfolio" link on a client's Instagram Insights page (see
    // PortfolioItemController::create()). Never both at once in practice --
    // $preselectMedia only exists on the create screen, socialMediaItem only
    // exists once a piece has been saved -- but either can be null.
    $initialMedia = ($preselectMedia ?? $item->socialMediaItem ?? null);
    $initialMedia = $initialMedia ? [
        'id' => $initialMedia->id,
        // thumbnail_url is frequently absent -- see
        // PortfolioItemController::mediaThumbnailSource() for why this falls
        // back to media_url the same way.
        'thumbnail_url' => $initialMedia->thumbnail_url ?: $initialMedia->media_url,
        'permalink' => $initialMedia->permalink,
        'caption' => $initialMedia->shortCaption(80),
        'posted_at' => $initialMedia->posted_at?->format('j M Y'),
        'posted_at_iso' => $initialMedia->posted_at?->toDateString(),
        'type' => $initialMedia->typeLabel(),
        'reach' => $initialMedia->metricValue('reach'),
        'views' => $initialMedia->metricValue('views'),
    ] : null;
@endphp

<form method="POST" enctype="multipart/form-data"
      action="{{ $item->exists ? route('portfolio.update', $item) : route('portfolio.store') }}"
      x-data="portfolioForm({{ $maxVideoMb }}, {{ old('client_id', $item->client_id) ?: 'null' }}, @js($initialMedia), {{ $item->exists ? $item->id : 'null' }})"
      @submit="uploading = true">
    @csrf
    @if ($item->exists)
        @method('PUT')
    @endif

    <div class="space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="title" value="Title" />
                <x-text-input id="title" name="title" type="text" class="mt-1"
                              value="{{ old('title', $item->title) }}"
                              placeholder="e.g. Brand film for Thor Gym" required />
                <x-input-error :messages="$errors->get('title')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="client_id" value="Client" />
                <x-select id="client_id" name="client_id" class="mt-1" @change="onClientChange($event.target.value)">
                    <option value="">Not a client project</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) old('client_id', $item->client_id) === $client->id)>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </x-select>
                <p class="mt-1 text-xs text-brand-100/60">
                    Links the piece to the client record.
                    <a href="{{ route('clients.create') }}" target="_blank" rel="noopener"
                       class="text-brand-500 hover:text-brand-300 font-semibold">Add a client</a>
                </p>
                <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
            </div>
        </div>

        {{-- Instagram mapping. Only appears once the picked client has a
             connected account with something cached -- see
             PortfolioItemController::instagramMedia(). --}}
        <input type="hidden" name="social_media_item_id" :value="selectedMedia?.id ?? ''">

        <div x-show="selectedMedia" x-cloak
             class="rounded-lg border border-brand-200 bg-white/5 p-3 flex items-center gap-3">
            <img :src="selectedMedia?.thumbnail_url" alt="" onerror="this.remove()"
                 class="w-12 h-12 rounded-md object-cover ring-1 ring-white/15 shrink-0">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold text-brand-200">Linked to this Instagram post</p>
                <p class="text-xs text-brand-200 truncate" x-text="selectedMedia?.caption"></p>
            </div>
            <button type="button" @click="unlinkMedia()"
                    class="shrink-0 inline-flex items-center min-h-[36px] px-2.5 text-xs font-semibold text-brand-200 hover:text-white underline">
                Unlink
            </button>
        </div>

        <div x-show="! selectedMedia && (instagramItems.length > 0 || instagramLoading)" x-cloak>
            <x-input-label value="Or map an Instagram post" />
            <p class="mt-1 text-xs text-brand-100/60">
                Fills in the link, published date and performance numbers for you, and keeps them
                current every time this client's Instagram is synced.
            </p>

            <x-loader label="Loading posts" class="mt-2" x-show="instagramLoading" x-cloak />

            <div class="mt-2 flex gap-3 overflow-x-auto pb-1">
                <template x-for="post in instagramItems" :key="post.id">
                    <button type="button" @click="useMedia(post)" :disabled="post.already_added"
                            :class="post.already_added ? 'opacity-50 cursor-not-allowed border-white/10' : 'border-white/10 hover:border-brand-300 hover:bg-white/10'"
                            class="relative shrink-0 w-36 text-left rounded-lg border overflow-hidden transition-colors">
                        <div class="aspect-video bg-white/10">
                            <img :src="post.thumbnail_url" alt="" onerror="this.remove()"
                                 class="w-full h-full object-cover">
                        </div>
                        <div class="p-2">
                            <p class="text-[11px] font-semibold text-white truncate" x-text="post.caption"></p>
                            <p class="mt-0.5 text-[10px] text-brand-100/60" x-text="post.type + ' · ' + post.posted_at"></p>
                        </div>
                        <span x-show="post.already_added" x-cloak
                              class="absolute top-1 right-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide bg-gray-900/80 text-white">
                            Already added
                        </span>
                    </button>
                </template>
            </div>
        </div>

        <div>
            <x-input-label for="client_name" value="Or a name to show instead (optional)" />
            <x-text-input id="client_name" name="client_name" type="text" class="mt-1"
                          value="{{ old('client_name', $item->client_name) }}"
                          placeholder="e.g. Private — for work with no client record" />
            <p class="mt-1 text-xs text-brand-100/60">
                Used when no client is linked. A linked client's own name always wins.
            </p>
            <x-input-error :messages="$errors->get('client_name')" class="mt-2" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="portfolio_category_id" value="Category" />
                <x-select id="portfolio_category_id" name="portfolio_category_id" class="mt-1">
                    <option value="">Uncategorised</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}"
                            @selected((int) old('portfolio_category_id', $item->portfolio_category_id) === $category->id)>
                            {{ $category->name }}@unless ($category->is_visible) (hidden){{ '' }}@endunless
                        </option>
                    @endforeach
                </x-select>
                <p class="mt-1 text-xs text-brand-100/60">
                    Categories are the tabs on the public page.
                    <a href="{{ route('portfolio-categories.index') }}" class="text-brand-500 hover:text-brand-300 font-semibold">Manage tabs</a>
                </p>
                <x-input-error :messages="$errors->get('portfolio_category_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="sort_order" value="Sort order" />
                <x-text-input id="sort_order" name="sort_order" type="number" min="0" max="9999" class="mt-1"
                              value="{{ old('sort_order', $item->sort_order ?? 0) }}" />
                <p class="mt-1 text-xs text-brand-100/60">Lower numbers show first.</p>
                <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="service_type_id" value="Service type (internal)" />
                <x-select id="service_type_id" name="service_type_id" class="mt-1">
                    <option value="">Not set</option>
                    @foreach ($lists['service_type'] as $term)
                        <option value="{{ $term->id }}" @selected((int) old('service_type_id', $item->service_type_id) === $term->id)>
                            {{ $term->name }}@unless ($term->is_active) (retired)@endunless
                        </option>
                    @endforeach
                </x-select>
                <p class="mt-1 text-xs text-brand-100/60">For our own reporting. Never shown on the website.</p>
                <x-input-error :messages="$errors->get('service_type_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label value="Tags" />
                @php $chosen = collect(old('tags', $item->tags->pluck('id')->all()))->map(fn ($id) => (int) $id); @endphp
                @if ($lists['tag']->isEmpty())
                    <p class="mt-2 text-xs text-brand-100/60">
                        No tags yet —
                        <a href="{{ route('taxonomy.index', ['type' => 'tag']) }}" target="_blank" rel="noopener"
                           class="text-brand-500 hover:text-brand-300 font-semibold">add some</a>.
                    </p>
                @else
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($lists['tag'] as $term)
                            <label class="inline-flex items-center gap-2 min-h-[44px] px-3 rounded-full border cursor-pointer transition
                                          {{ $chosen->contains($term->id)
                                                ? 'bg-white/5 border-brand-300 text-brand-200'
                                                : 'bg-white/5 border-white/15 text-brand-100/70 hover:bg-white/[0.09]' }}">
                                <input type="checkbox" name="tags[]" value="{{ $term->id }}"
                                       @checked($chosen->contains($term->id))
                                       class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                                <span class="text-sm font-medium">{{ $term->name }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
                <x-input-error :messages="$errors->get('tags')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="description" value="Description (optional)" />
            <x-textarea id="description" name="description" rows="3" class="mt-1"
                        placeholder="One or two lines about the piece.">{{ old('description', $item->description) }}</x-textarea>
            <p class="mt-1 text-xs text-brand-100/60">Left blank and mapped to an Instagram post above? The caption fills this in for you.</p>
            <x-input-error :messages="$errors->get('description')" class="mt-2" />
        </div>

        {{-- Video --}}
        <div class="rounded-lg border border-white/10 p-4">
            <h4 class="font-semibold text-white text-sm">Video</h4>
            <p class="text-xs text-brand-100/60 mt-0.5">
                Upload the file itself, or paste a link if it already lives on YouTube or Vimeo.
            </p>

            @if ($item->video_path)
                <div class="mt-3 flex items-center gap-3 rounded-md bg-green-400/10 border border-green-400/30 p-3">
                    <svg class="w-5 h-5 text-green-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold text-green-200">A video is uploaded</p>
                        <p class="text-[11px] text-green-200 truncate">{{ basename($item->video_path) }}</p>
                    </div>
                    <a href="{{ asset($item->video_path) }}" target="_blank" rel="noopener"
                       class="shrink-0 inline-flex items-center min-h-[44px] px-2 text-xs font-semibold text-green-200 hover:text-green-200 underline">Open</a>
                </div>
            @endif

            <div class="mt-3">
                <x-input-label for="video" :value="$item->video_path ? 'Replace video file' : 'Video file'" />
                <input id="video" name="video" type="file" accept="video/*" x-ref="video" @change="checkVideo()"
                       class="mt-1 block w-full text-sm text-brand-100/80 file:mr-3 file:min-h-[44px] file:rounded-md file:border-0 file:bg-white/5 file:px-4 file:text-sm file:font-semibold file:text-brand-200 hover:file:bg-brand-400/20">
                <p class="mt-1 text-xs text-brand-100/60">MP4, WebM, MOV or M4V. Up to {{ $maxVideoMb }} MB.</p>
                <p x-show="videoError" x-cloak x-text="videoError" class="mt-1 text-sm text-red-300"></p>
                <x-input-error :messages="$errors->get('video')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="video_url" value="Or a link" />
                <x-text-input id="video_url" name="video_url" type="url" class="mt-1"
                              value="{{ old('video_url', $item->video_url) }}"
                              placeholder="https://youtube.com/watch?v=..." />
                <p class="mt-1 text-xs text-brand-100/60">Used only when no file is uploaded.</p>
                <x-input-error :messages="$errors->get('video_url')" class="mt-2" />
            </div>
        </div>

        {{-- Thumbnail --}}
        <div class="rounded-lg border border-white/10 p-4">
            <h4 class="font-semibold text-white text-sm">Thumbnail</h4>
            <p class="text-xs text-brand-100/60 mt-0.5">
                The still shown in the grid.
                {{ $item->isVertical() ? 'This piece is 9:16 -- upload a vertical still to match.' : 'Landscape 16:9 looks best.' }}
            </p>

            <div class="mt-3 flex flex-col sm:flex-row sm:items-start gap-4">
                <div class="w-full sm:w-56 shrink-0">
                    <div class="{{ $item->isVertical() ? 'aspect-[9/16]' : 'aspect-video' }} rounded-md bg-white/10 border border-white/10 overflow-hidden flex items-center justify-center">
                        <template x-if="thumbPreview">
                            <img :src="thumbPreview" alt="" class="w-full h-full object-cover">
                        </template>
                        <template x-if="! thumbPreview">
                            @if ($item->thumbnail_path)
                                <img src="{{ asset($item->thumbnail_path) }}" alt="Current thumbnail" class="w-full h-full object-cover">
                            @else
                                <span class="text-xs text-brand-100/50">No thumbnail</span>
                            @endif
                        </template>
                    </div>
                </div>

                <div class="flex-1 min-w-0">
                    <x-input-label for="thumbnail" :value="$item->thumbnail_path ? 'Replace thumbnail' : 'Thumbnail image'" />
                    <input id="thumbnail" name="thumbnail" type="file" accept="image/*" @change="previewThumb($event)"
                           class="mt-1 block w-full text-sm text-brand-100/80 file:mr-3 file:min-h-[44px] file:rounded-md file:border-0 file:bg-white/5 file:px-4 file:text-sm file:font-semibold file:text-brand-200 hover:file:bg-brand-400/20">
                    <p class="mt-1 text-xs text-brand-100/60">JPG, PNG or WebP. Up to 4 MB.</p>
                    <x-input-error :messages="$errors->get('thumbnail')" class="mt-2" />
                </div>
            </div>
        </div>

        @include('portfolio._case-study-fields', ['item' => $item])

        {{-- Visibility --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="flex items-start gap-3 rounded-lg border border-white/10 p-3 min-h-[44px] cursor-pointer hover:bg-white/[0.09]">
                <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $item->exists ? $item->is_visible : true))
                       class="mt-0.5 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                <span>
                    <span class="block text-sm font-semibold text-white">Show on the website</span>
                    <span class="block text-xs text-brand-100/60">Untick to keep it as a draft.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-lg border border-white/10 p-3 min-h-[44px] cursor-pointer hover:bg-white/[0.09]">
                <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $item->is_featured))
                       class="mt-0.5 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                <span>
                    <span class="block text-sm font-semibold text-white">Feature on the home page</span>
                    <span class="block text-xs text-brand-100/60">Featured work leads the landing page grid.</span>
                </span>
            </label>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-3 pt-1">
            <x-primary-button ::disabled="uploading" class="justify-center">
                <span x-show="! uploading">{{ $item->exists ? 'Save changes' : 'Add to portfolio' }}</span>
                <span x-show="uploading" x-cloak>Uploading&hellip;</span>
            </x-primary-button>
            <a href="{{ route('portfolio.index') }}"
               class="inline-flex items-center justify-center min-h-[44px] px-4 text-sm font-semibold text-brand-100/70 hover:text-white">Cancel</a>
            <p x-show="uploading" x-cloak class="text-xs text-brand-100/60">Large videos can take a few minutes. Keep this tab open.</p>
        </div>
    </div>

    {{-- Genuinely can run minutes on a large video, so this is the one spot
         in the form worth a full attention-holding state rather than just
         the disabled button above. --}}
    <x-loader overlay label="Uploading" x-show="uploading" x-cloak />
</form>

@push('scripts')
    <script>
        function portfolioForm(maxMb, initialClientId, initialMedia, currentItemId) {
            return {
                uploading: false,
                videoError: '',
                thumbPreview: '',

                // Instagram mapping.
                instagramItems: [],
                instagramLoading: false,
                selectedMedia: initialMedia,

                init() {
                    if (initialClientId) this.loadInstagramMedia(initialClientId);
                },

                onClientChange(clientId) {
                    this.instagramItems = [];
                    if (clientId) this.loadInstagramMedia(clientId);
                },

                async loadInstagramMedia(clientId) {
                    this.instagramLoading = true;
                    try {
                        const excludeParam = currentItemId ? `&exclude_item_id=${currentItemId}` : '';
                        const res = await fetch(`{{ route('portfolio.instagram-media') }}?client_id=${clientId}${excludeParam}`);
                        const data = await res.json();
                        this.instagramItems = data.items ?? [];
                    } finally {
                        this.instagramLoading = false;
                    }
                },

                // Fills in what staff would otherwise type by hand. A
                // staff-typed title or publish date is never silently
                // overwritten -- only blank fields get filled. Refuses an
                // already_added post client-side too -- validated()'s
                // unique rule is the real guard, this just saves the round
                // trip of finding that out after submitting.
                useMedia(media) {
                    if (media.already_added) return;

                    this.selectedMedia = media;

                    const titleField = document.getElementById('title');
                    const urlField = document.getElementById('video_url');
                    const publishedField = document.getElementById('published_on');

                    if (titleField && ! titleField.value.trim()) titleField.value = media.caption;
                    if (urlField) urlField.value = media.permalink;
                    if (publishedField && ! publishedField.value) publishedField.value = media.posted_at_iso || '';
                },

                // Clears the link itself, but deliberately leaves whatever
                // video_url/title/published_on it already filled in -- those
                // are now just normal editable fields.
                unlinkMedia() {
                    this.selectedMedia = null;
                },

                // Catches an oversized file before the browser spends ten
                // minutes uploading something the server will reject.
                checkVideo() {
                    const file = this.$refs.video?.files?.[0];
                    this.videoError = '';

                    if (file && file.size > maxMb * 1024 * 1024) {
                        this.videoError = `That file is ${Math.round(file.size / 1048576)} MB. The limit is ${maxMb} MB.`;
                        this.$refs.video.value = '';
                    }
                },

                previewThumb(event) {
                    const file = event.target.files?.[0];
                    this.thumbPreview = file ? URL.createObjectURL(file) : '';
                },
            };
        }
    </script>
@endpush
