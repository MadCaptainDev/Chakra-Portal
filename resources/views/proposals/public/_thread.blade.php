{{-- One comment and its replies, as the client sees them. --}}
<div @class(['cp-thread', 'cp-thread--resolved' => $comment->isResolved()]) id="comment-{{ $comment->id }}"
     x-data="proposalComment({{ old('form_id') === 'reply-'.$comment->id ? 'true' : 'false' }})">
    <div class="cp-meta">
        <b>{{ $comment->author_name }}</b>
        @if ($comment->isFromStaff())<span class="cp-badge-staff">Team</span>@endif
        · {{ $comment->created_at->format('j M, g:i A') }}
        @if ($comment->isResolved()) · Resolved @endif
    </div>
    <div style="white-space: pre-line;">{{ $comment->body }}</div>

    @foreach ($comment->replies as $reply)
        <div @class(['cp-reply', 'cp-reply--staff' => $reply->isFromStaff()])>
            <div class="cp-meta">
                <b>{{ $reply->author_name }}</b>
                @if ($reply->isFromStaff())<span class="cp-badge-staff">Team</span>@endif
                · {{ $reply->created_at->format('j M, g:i A') }}
            </div>
            <div style="white-space: pre-line;">{{ $reply->body }}</div>
        </div>
    @endforeach

    <div style="margin-top: 6px;">
        <button type="button" class="cp-link" @click="toggle()" x-show="!open">Reply</button>
    </div>
    @include('proposals.public._form', [
        'formId' => 'reply-'.$comment->id,
        'sectionKey' => $comment->section_key,
        'parentId' => $comment->id,
        'submitLabel' => 'Send reply',
        'placeholder' => 'Your reply…',
    ])
</div>
