@php
    /*
    |---------------------------------------------------------------------------
    | Terms of service.
    |---------------------------------------------------------------------------
    | The companion to the privacy policy, and public for the same reason: Meta
    | asks for a Terms of Service URL alongside the privacy policy URL, and
    | fetches it as an anonymous stranger.
    |
    | These are a plain-English baseline describing how the studio actually
    | works -- they are NOT legal advice and have not been reviewed by a
    | lawyer. The commercial clauses (payment terms, cancellation, ownership on
    | non-payment) are the ones most worth having somebody check against how you
    | really trade, because they are the ones that get argued about.
    */
    $company = App\Models\CompanySetting::current();

    $legalName = $company->company_name ?: 'Chakra Productions';
    $email = $company->notification_email ?: 'hello@chakragroups.in';
    $address = $company->address;

    // Where a dispute would be heard. India by default, because that is where
    // the studio trades; name the city here once it is settled.
    $jurisdiction = 'India';

    // Bump by hand when the text changes -- a date that moves on every deploy
    // tells a reader nothing.
    $updated = 'August 16, 2026';

    $section = 'text-lg font-semibold text-white mt-10 mb-3';
    $para = 'text-sm leading-relaxed text-brand-100/70 mb-4';
    $list = 'list-disc pl-5 space-y-2 text-sm leading-relaxed text-brand-100/70 mb-4 marker:text-brand-400';
    $link = 'text-brand-300 underline underline-offset-2 hover:text-white transition-colors';
@endphp

<x-public-layout
    title="Terms of Service — {{ $legalName }}"
    description="The terms on which {{ $legalName }} provides video production, publishing and client portal services.">

    <div class="max-w-3xl mx-auto px-5 sm:px-8 py-16 sm:py-24">

        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-4">Legal</p>
        <h1 class="text-3xl sm:text-4xl font-semibold text-white mb-3">Terms of Service</h1>
        <p class="text-sm text-brand-100/50 mb-10">Last updated {{ $updated }}</p>

        <p class="{{ $para }}">
            These terms govern your use of the website and client portal at
            <a href="{{ url('/') }}" class="{{ $link }}">{{ parse_url(url('/'), PHP_URL_HOST) }}</a>, and the
            production, publishing and reporting services provided by {{ $legalName }} (&ldquo;we&rdquo;,
            &ldquo;us&rdquo;, &ldquo;our&rdquo;). By using the site, signing in to the portal, or engaging us for work,
            you agree to them.
        </p>
        <p class="{{ $para }}">
            Where we have signed a separate written agreement, quotation or statement of work with you, that document
            governs the specifics of the engagement &mdash; scope, fees, deadlines and deliverables &mdash; and these
            terms cover everything it does not say. If the two genuinely conflict, the signed document wins.
        </p>

        <h2 class="{{ $section }}">1. What we do</h2>
        <p class="{{ $para }}">
            We are a video content studio. Depending on what you engage us for, that includes scripting, shooting,
            editing, post-production, stills and design, and publishing or scheduling content to channels you own
            &mdash; including, where you ask us to, accounts on Meta&rsquo;s platforms, YouTube and your own website.
            We also operate a private portal where clients and staff can follow work in progress.
        </p>

        <h2 class="{{ $section }}">2. Accounts</h2>
        <ul class="{{ $list }}">
            <li>Portal accounts are issued by us, not self-registered. You are responsible for keeping your password
                private and for what is done under your account.</li>
            <li>Tell us promptly if you believe someone else has your login, so we can reset it.</li>
            <li>Each account is for one person. Sharing a login defeats the record of who did what, which is the main
                reason the portal exists.</li>
            <li>We may suspend an account that is being used to break these terms, to reach data it should not, or in a
                way that puts the platform at risk.</li>
        </ul>

        <h2 class="{{ $section }}">3. Your material and your accounts</h2>
        <p class="{{ $para }}">
            To do the work we usually need material from you &mdash; briefs, brand assets, product information, people
            available to film &mdash; and sometimes access to accounts we publish on your behalf. You confirm that:
        </p>
        <ul class="{{ $list }}">
            <li>You own or have permission to use everything you give us, including logos, music, footage and anybody
                appearing on camera.</li>
            <li>You have the authority to grant us access to any account you connect or hand credentials for.</li>
            <li>What you ask us to publish is lawful, is not misleading, and does not infringe anyone&rsquo;s rights.</li>
        </ul>
        <p class="{{ $para }}">
            Credentials you give us are held encrypted, are visible only to the staff whose work requires them, and
            every time one is revealed we record who revealed it and when. You can withdraw that access at any time.
            See our <a href="{{ route('privacy') }}" class="{{ $link }}">Privacy Policy</a> for how we handle it.
        </p>
        <p class="{{ $para }}">
            Delays in supplying material, approvals or access move the delivery dates by at least the length of the
            delay. We will say so at the time rather than quietly missing a date.
        </p>

        <h2 class="{{ $section }}">4. Messaging you and your customers</h2>
        <p class="{{ $para }}">
            Where we send messages on your behalf &mdash; including over WhatsApp &mdash; you are responsible for
            having the recipient&rsquo;s consent to be contacted, and for the accuracy of the lists you give us. We
            will not send messages to people who have not opted in, and we will act on an opt-out or a request to stop
            as soon as we receive it.
        </p>
        <p class="{{ $para }}">
            Messaging over Meta&rsquo;s platforms is additionally subject to Meta&rsquo;s own policies. Where those
            policies and your instructions conflict, Meta&rsquo;s win &mdash; we cannot send something that would put
            your account or ours at risk, and we will tell you why rather than simply not doing it.
        </p>

        <h2 class="{{ $section }}">5. Approvals and revisions</h2>
        <p class="{{ $para }}">
            Work moves through the stages set out in your engagement: script, shoot, edit, review, publish. Revisions
            are included as agreed for that engagement. Changes that arrive after a stage has been signed off &mdash;
            a new script direction after the shoot, for instance &mdash; are new work and are quoted separately before
            we start them, never billed as a surprise.
        </p>

        <h2 class="{{ $section }}">6. Fees and payment</h2>
        <ul class="{{ $list }}">
            <li>Fees, schedule and any advance are those set out in your quotation or invoice.</li>
            <li>Invoices are payable by the due date stated on them. Taxes are charged where applicable.</li>
            <li>Third-party costs incurred for your project &mdash; talent, locations, licensed music, paid media
                &mdash; are yours, and are agreed with you before we commit to them.</li>
            <li>We may pause work on an account with materially overdue invoices. We will tell you before we do,
                not after.</li>
        </ul>

        <h2 class="{{ $section }}">7. Who owns the work</h2>
        <p class="{{ $para }}">
            On full payment for a piece of work, the final delivered content is yours to use as you see fit. Until then
            it remains ours, and permission to publish it is not granted.
        </p>
        <p class="{{ $para }}">
            We keep ownership of our working materials &mdash; project files, raw and unused footage, templates and
            internal tools &mdash; unless your agreement says otherwise. If you need the raw footage or project files,
            ask and we will quote for handing them over.
        </p>
        <p class="{{ $para }}">
            Unless you tell us not to, we may show finished work in our portfolio and on our own channels. Tell us at
            any time and we will take it down.
        </p>

        <h2 class="{{ $section }}">8. Confidentiality</h2>
        <p class="{{ $para }}">
            Each of us will keep the other&rsquo;s non-public information confidential and use it only for the work.
            That covers unreleased campaigns, commercial terms and anything else obviously private, and it continues
            after the engagement ends.
        </p>

        <h2 class="{{ $section }}">9. Cancellation</h2>
        <p class="{{ $para }}">
            Either of us can end an engagement in writing. If you cancel, you pay for work completed and for
            commitments we have already made on your behalf that cannot be recovered &mdash; a booked crew, a held
            location, licensed material. Cancelling a confirmed shoot at short notice may carry a charge, which will
            have been set out in your quotation.
        </p>

        <h2 class="{{ $section }}">10. What we can and cannot promise</h2>
        <p class="{{ $para }}">
            We will carry out the work with reasonable skill and care, to a professional standard. What we cannot
            promise is a result on someone else&rsquo;s platform: views, reach, engagement, follower growth, leads or
            sales all depend on algorithms, audiences and timing outside anyone&rsquo;s control. Any figure discussed
            beforehand is an expectation, not a guarantee.
        </p>
        <p class="{{ $para }}">
            We also cannot promise that a third-party platform will stay available, keep its features, or continue to
            approve your account or content. Where a platform changes something that affects the work, we will tell you
            and propose the way round it.
        </p>
        <p class="{{ $para }}">
            The site and portal are provided as they are. We aim to keep them available and secure, but we do not
            guarantee uninterrupted access.
        </p>

        <h2 class="{{ $section }}">11. Liability</h2>
        <p class="{{ $para }}">
            Neither of us is liable for indirect or consequential losses, or for lost profits, revenue or goodwill. Our
            total liability for any engagement is limited to the fees you paid us for that engagement. Nothing here
            limits liability that cannot lawfully be limited &mdash; including for death or personal injury caused by
            negligence, or for fraud.
        </p>

        <h2 class="{{ $section }}">12. Ending access</h2>
        <p class="{{ $para }}">
            We may suspend or close portal access when an engagement ends, or where an account is being misused. You can
            ask us to close your account at any time. Closing an account does not by itself delete the records we are
            required to keep &mdash; see the
            <a href="{{ route('privacy') }}#data-deletion" class="{{ $link }}">deletion section</a> of our privacy
            policy for what is removed and what is retained.
        </p>

        <h2 class="{{ $section }}">13. Changes to these terms</h2>
        <p class="{{ $para }}">
            We may update these terms as the services change. The date at the top shows when they were last revised,
            and material changes will be notified to account holders by email. Continuing to use the portal after that
            means you accept the revised terms.
        </p>

        <h2 class="{{ $section }}">14. Governing law</h2>
        <p class="{{ $para }}">
            These terms are governed by the laws of {{ $jurisdiction }}, and disputes are subject to the exclusive
            jurisdiction of its courts. Before starting proceedings, both of us agree to raise the issue directly and
            try to settle it.
        </p>

        <h2 class="{{ $section }}">15. Contact</h2>
        <p class="{{ $para }}">
            Questions about these terms go to
            <a href="mailto:{{ $email }}" class="{{ $link }}">{{ $email }}</a>@if ($address), or {{ $address }}@endif.
        </p>

    </div>
</x-public-layout>
