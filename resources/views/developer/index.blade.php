<x-app-layout title="Developer">
    <x-slot name="header">
        <x-page-header title="Developer" eyebrow="App Studio"
                       subtitle="The full backup + AMC-license API, live -- authenticate with a real product token and try it from here." />
    </x-slot>

    <x-card class="overflow-hidden" padding="none">
        <div id="swagger-ui"></div>
    </x-card>

    {{-- Self-hosted (public/swagger-ui) rather than a CDN, matching how
         everything else this app depends on ships with it. Deliberately
         NOT under public/vendor/ -- the repo-root .htaccess blocks every
         URL starting with vendor/ outright (it exists to keep Composer's
         top-level vendor/ off the web), and that rule cannot tell that
         path apart from this one. --}}
    <link rel="stylesheet" href="{{ asset('swagger-ui/swagger-ui.css') }}">
    <script src="{{ asset('swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script src="{{ asset('swagger-ui/swagger-ui-standalone-preset.js') }}"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            window.ui = SwaggerUIBundle({
                url: @js(route('developer.openapi')),
                dom_id: '#swagger-ui',
                presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                plugins: [SwaggerUIBundle.plugins.DownloadUrl],
                layout: 'StandaloneLayout',
                // A real product token pasted here is sent on every "Try it
                // out" call below -- this is a live client against
                // production, not a mock. Persisted so it survives a reload
                // while working through the endpoints.
                persistAuthorization: true,
                docExpansion: 'list',
            });
        });
    </script>

    <style>
        /* Swagger UI's own CSS assumes it owns the page; this keeps it
           inside the card instead of fighting the app's own topbar/sidebar
           chrome for the full viewport. */
        #swagger-ui .swagger-ui .topbar { display: none; }
        #swagger-ui .swagger-ui { padding: 0.5rem 0.5rem 1.5rem; }
    </style>
</x-app-layout>
