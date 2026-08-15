@php
    /*
    |---------------------------------------------------------------------------
    | Privacy policy.
    |---------------------------------------------------------------------------
    | Public, unauthenticated and linked from the footer, because Meta (and
    | Google, and the app stores) will fetch this URL as a stranger and reject
    | anything that needs a login to read.
    |
    | Contact details come from Settings -> Company where they have been filled
    | in, and fall back to the constants below otherwise. A privacy policy with
    | no reachable contact is a privacy policy Meta will fail, so the fallbacks
    | are deliberately real addresses rather than null.
    */
    $company = App\Models\CompanySetting::current();

    $legalName = $company->company_name ?: 'Chakra Productions';
    $email = $company->email ?: $company->notification_email ?: 'hello@chakragroups.in';
    $phone = $company->phone;
    $address = $company->address;

    // Shown as "Last updated". Bump it by hand when the text changes -- a date
    // that moves on every deploy tells a reader nothing.
    $updated = 'August 15, 2026';

    $section = 'text-lg font-semibold text-white mt-10 mb-3';
    $para = 'text-sm leading-relaxed text-brand-100/70 mb-4';
    $list = 'list-disc pl-5 space-y-2 text-sm leading-relaxed text-brand-100/70 mb-4 marker:text-brand-400';
    $link = 'text-brand-300 underline underline-offset-2 hover:text-white transition-colors';
@endphp

<x-public-layout
    title="Privacy Policy — {{ $legalName }}"
    description="How {{ $legalName }} collects, uses, stores and deletes personal data, including data accessed through Meta's platforms on behalf of our clients.">

    <div class="max-w-3xl mx-auto px-5 sm:px-8 py-16 sm:py-24">

        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-4">Legal</p>
        <h1 class="text-3xl sm:text-4xl font-semibold text-white mb-3">Privacy Policy</h1>
        <p class="text-sm text-brand-100/50 mb-10">Last updated {{ $updated }}</p>

        <p class="{{ $para }}">
            {{ $legalName }} (&ldquo;we&rdquo;, &ldquo;us&rdquo;, &ldquo;our&rdquo;) is a video content studio. We
            produce, edit, schedule and publish video and social media content for the businesses that hire us, and we
            run this website and an internal staff and client portal at
            <a href="{{ url('/') }}" class="{{ $link }}">{{ parse_url(url('/'), PHP_URL_HOST) }}</a>.
            This policy explains what personal data we collect, why we collect it, who we share it with, how long we
            keep it, and how you can have it deleted.
        </p>
        <p class="{{ $para }}">
            It applies to this website, to our client and staff portal, and to any content or advertising accounts we
            operate on a client&rsquo;s behalf &mdash; including accounts on Meta&rsquo;s platforms (Facebook and
            Instagram).
        </p>

        <h2 class="{{ $section }}">1. Who is responsible for your data</h2>
        <p class="{{ $para }}">
            {{ $legalName }} is the data controller for the data described in this policy, except where we handle a
            client&rsquo;s own audience or customer data on their instructions &mdash; there, the client is the
            controller and we act as their processor.
        </p>
        <ul class="{{ $list }}">
            <li>Email: <a href="mailto:{{ $email }}" class="{{ $link }}">{{ $email }}</a></li>
            @if ($phone)
                <li>Phone: {{ $phone }}</li>
            @endif
            @if ($address)
                <li>Address: {{ $address }}</li>
            @endif
        </ul>

        <h2 class="{{ $section }}">2. What we collect</h2>

        <h3 class="text-sm font-semibold text-brand-100 mt-6 mb-2">Enquiries from this website</h3>
        <p class="{{ $para }}">
            When you send us an enquiry we collect the name, email address, phone number, project description and
            message you type into the form, together with your IP address and which page of the site you came from. We
            need this to reply to you and to understand which of our pages actually bring in work.
        </p>

        <h3 class="text-sm font-semibold text-brand-100 mt-6 mb-2">Portal accounts</h3>
        <p class="{{ $para }}">
            Our staff and clients sign in to a private portal. For an account holder we store a name, email address,
            phone number, a hashed password, an optional profile photo and short bio, and the role that decides what
            they are allowed to see. Depending on the role we also store work records such as timesheets, shoot
            schedules, tasks, invoices and expenses.
        </p>

        <h3 class="text-sm font-semibold text-brand-100 mt-6 mb-2">Client account credentials</h3>
        <p class="{{ $para }}">
            Where a client asks us to publish on their behalf, the portal can hold the credentials or access tokens for
            the accounts we have been asked to post to &mdash; for example a Facebook Page, an Instagram Business
            account, a YouTube channel or a website login. These are supplied by the client, held only for as long as we
            work on that account, and are visible only to the specific staff whose job requires them. Every time such a
            credential is revealed in the portal, we record who revealed it and when.
        </p>

        <h3 class="text-sm font-semibold text-brand-100 mt-6 mb-2">Technical data</h3>
        <p class="{{ $para }}">
            Our servers keep standard logs (IP address, browser type, pages requested, timestamps) for security and
            troubleshooting. We use strictly necessary cookies to keep you signed in and to protect forms against
            cross-site request forgery. We do not use advertising or cross-site tracking cookies on this website.
        </p>

        <h2 class="{{ $section }}">3. Data we access through Meta&rsquo;s platforms</h2>
        <p class="{{ $para }}">
            When a client connects their Facebook Page or Instagram Business account to us, or grants us access through
            Meta&rsquo;s tools, we may access and process the following on their instructions:
        </p>
        <ul class="{{ $list }}">
            <li>The public profile and basic account details of the connected Page or Instagram account, and of the
                person who authorises the connection.</li>
            <li>Access tokens that let us publish and manage content on the connected account.</li>
            <li>Content on the account &mdash; posts, reels, stories, captions, comments and messages &mdash; where
                managing them is part of the work.</li>
            <li>Page and content insights, such as reach, views, engagement and follower counts, used to report on how
                the content we produced performed.</li>
        </ul>
        <p class="{{ $para }}">
            We use this data only to deliver the service the client has asked for: creating, scheduling, publishing and
            reporting on their content. We do <span class="text-white font-medium">not</span> sell it, do not use it to
            build advertising profiles, do not use it to train machine learning models, and do not transfer it to data
            brokers or any party other than those listed in section 5. We handle it in line with Meta&rsquo;s Platform
            Terms and Developer Policies, and a client can disconnect our access at any time from their Facebook or
            Instagram settings, or by asking us to.
        </p>

        <h2 class="{{ $section }}">4. Why we are allowed to use it</h2>
        <ul class="{{ $list }}">
            <li><span class="text-brand-100">To perform a contract</span> &mdash; delivering the production, publishing
                and reporting work our clients engage us for.</li>
            <li><span class="text-brand-100">Consent</span> &mdash; when you send us an enquiry, or when a client
                authorises us to connect to their social accounts. Consent can be withdrawn at any time.</li>
            <li><span class="text-brand-100">Legitimate interests</span> &mdash; running and securing our site and
                portal, and keeping records of the work we have done.</li>
            <li><span class="text-brand-100">Legal obligation</span> &mdash; keeping invoices, tax and employment
                records for the periods the law requires.</li>
        </ul>

        <h2 class="{{ $section }}">5. Who we share it with</h2>
        <p class="{{ $para }}">
            We do not sell personal data. We share it only with:
        </p>
        <ul class="{{ $list }}">
            <li>Our own staff and contractors, limited to what their role in the portal permits.</li>
            <li>Service providers who run our infrastructure on our behalf &mdash; web hosting, email delivery and
                backups &mdash; under confidentiality obligations.</li>
            <li>The platforms a client has asked us to publish to, such as Meta, YouTube or a client&rsquo;s own
                website, which then handle that content under their own privacy policies.</li>
            <li>Authorities, where we are required to by law.</li>
        </ul>

        <h2 class="{{ $section }}">6. How long we keep it</h2>
        <ul class="{{ $list }}">
            <li>Website enquiries: up to 24 months from your last contact with us, then deleted.</li>
            <li>Portal accounts and work records: for the life of the engagement, and afterwards only as long as our
                accounting, tax and legal obligations require.</li>
            <li>Client account credentials and platform access tokens: deleted when the engagement ends, or immediately
                on request. Revoking our access on the platform also makes any token we hold useless.</li>
            <li>Server logs: a rolling short-term window for security and troubleshooting.</li>
        </ul>

        <h2 class="{{ $section }}">7. How we protect it</h2>
        <p class="{{ $para }}">
            The site and portal are served over HTTPS. Passwords are stored hashed and are never recoverable in plain
            text. Access inside the portal is controlled by role, so staff see only the records their job needs, and
            sensitive items such as client credentials are restricted further and their disclosure is logged. No system
            is perfectly secure, but if a breach affects your data we will notify you and any relevant regulator as the
            law requires.
        </p>

        <h2 class="{{ $section }}">8. Your rights</h2>
        <p class="{{ $para }}">
            You can ask us to give you a copy of the personal data we hold about you, correct it if it is wrong, delete
            it, restrict or object to how we use it, or withdraw a consent you previously gave. Write to
            <a href="mailto:{{ $email }}" class="{{ $link }}">{{ $email }}</a> and we will respond within 30 days. If
            you believe we have handled your data badly, you may also complain to your local data protection authority.
        </p>

        <h2 id="data-deletion" class="{{ $section }}">9. Deleting your data</h2>
        <p class="{{ $para }}">
            To have your data deleted, email <a href="mailto:{{ $email }}" class="{{ $link }}">{{ $email }}</a> from
            the address you contacted us with, or from the address on the account, with the subject line
            <span class="text-brand-100">&ldquo;Delete my data&rdquo;</span>. Tell us which of the following applies so
            we can find your records:
        </p>
        <ul class="{{ $list }}">
            <li>An enquiry you sent through this website &mdash; give us the email address or phone number you used.</li>
            <li>A portal account &mdash; give us the email address you sign in with.</li>
            <li>A Facebook Page or Instagram account connected to us &mdash; give us the account name or handle.</li>
        </ul>
        <p class="{{ $para }}">
            We confirm receipt within 7 days and complete the deletion within 30 days, then confirm to you in writing.
            We will keep only what we are legally required to keep, such as invoices and tax records, and nothing more.
        </p>
        <p class="{{ $para }}">
            If you connected a Facebook or Instagram account to us, you can also cut off our access yourself at any
            time: in Facebook, under <span class="text-brand-100">Settings &amp; Privacy &rarr; Settings &rarr; Business
            Integrations</span>; in Instagram, under <span class="text-brand-100">Settings &rarr; Website Permissions
            &rarr; Apps and Websites</span>. Removing us there revokes our access token immediately. Deleting the data
            we already hold still needs the email above.
        </p>

        <h2 class="{{ $section }}">10. Children</h2>
        <p class="{{ $para }}">
            Our website and portal are not directed at children under 13, and we do not knowingly collect their personal
            data. Where a child appears in content we produce, we do so only with the consent of a parent or guardian
            obtained by our client. If you believe we hold a child&rsquo;s data without that consent, contact us and we
            will delete it.
        </p>

        <h2 class="{{ $section }}">11. Where your data is held</h2>
        <p class="{{ $para }}">
            Our website and portal are hosted on servers operated by our hosting provider, and some of the platforms we
            publish to store data outside your country. Where data is transferred internationally, we rely on our
            providers&rsquo; contractual safeguards for that transfer.
        </p>

        <h2 class="{{ $section }}">12. Changes to this policy</h2>
        <p class="{{ $para }}">
            We may update this policy as our services change. The date at the top always shows when it was last
            revised, and material changes will be notified to account holders by email.
        </p>

        <h2 class="{{ $section }}">13. Contact us</h2>
        <p class="{{ $para }}">
            Questions about this policy, or about anything we hold on you, go to
            <a href="mailto:{{ $email }}" class="{{ $link }}">{{ $email }}</a>@if ($phone), or {{ $phone }}@endif.
        </p>

    </div>
</x-public-layout>
