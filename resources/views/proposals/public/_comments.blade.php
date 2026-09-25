{{-- Under each section of the public page: its threads and a Comment box. --}}
<div class="cp-ui" x-data="proposalComment({{ old('form_id') === 'section-'.$sectionKey ? 'true' : 'false' }})">
    @if ($thread->isNotEmpty())
        <div class="cp-threads">
            @foreach ($thread as $comment)
                @include('proposals.public._thread', ['comment' => $comment])
            @endforeach
        </div>
    @endif

    <div style="margin-top: 12px;" x-show="!open">
        <button type="button" class="cp-comment-btn" @click="toggle()" aria-label="Comment on {{ $label }}">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m-9 6 3.6-3H18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v14Z" />
            </svg>
            Comment on this section
        </button>
    </div>

    @include('proposals.public._form', [
        'formId' => 'section-'.$sectionKey,
        'sectionKey' => $sectionKey,
        'parentId' => null,
    ])
</div>
