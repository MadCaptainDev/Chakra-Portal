{{--
    One comment form on the public page. $formId tells a failed submit which
    box to reopen with the typed text and its errors -- there are dozens of
    these on one page and old() alone cannot say which one was used.
--}}
@php
    $failed = old('form_id') === $formId;
@endphp

<form method="POST" action="{{ route('proposals.public.comment', $token) }}" class="cp-form"
      x-show="open" x-cloak @submit="submit()">
    <input type="hidden" name="form_id" value="{{ $formId }}">
    <input type="hidden" name="section_key" value="{{ $sectionKey }}">
    @if ($parentId)
        <input type="hidden" name="parent_id" value="{{ $parentId }}">
    @endif

    {{-- Honeypot: invisible to people, irresistible to form-filling bots. --}}
    <div class="cp-hp" aria-hidden="true">
        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="cp-form__row">
        <input type="text" name="author_name" x-ref="name" x-model="name" maxlength="120" required
               placeholder="Your name" aria-label="Your name" autocomplete="name"
               @if ($failed) value="{{ old('author_name') }}" x-init="name = @js(old('author_name', ''))" @endif>
        <input type="email" name="author_email" x-model="email" maxlength="190"
               placeholder="Email (optional)" aria-label="Email (optional)" autocomplete="email"
               @if ($failed) x-init="email = @js(old('author_email', ''))" @endif>
    </div>
    <textarea name="body" x-ref="body" maxlength="5000" required
              placeholder="{{ $placeholder ?? 'Your comment, question or idea…' }}" aria-label="Comment">{{ $failed ? old('body') : '' }}</textarea>

    @if ($failed && $errors->any())
        <div style="color: #B91C1C; font-size: 12px;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="cp-form__actions">
        @unless ($alwaysOpen ?? false)
            <button type="button" class="cp-btn cp-btn--ghost" @click="open = false">Cancel</button>
        @endunless
        <button type="submit" class="cp-btn cp-btn--primary" :disabled="sending">
            <span x-show="!sending">{{ $submitLabel ?? 'Send comment' }}</span>
            <span x-show="sending" x-cloak>Sending…</span>
        </button>
    </div>
</form>
