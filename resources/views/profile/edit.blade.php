<x-app-layout title="My profile">
    <x-slot name="header">
        <x-page-header :title="__('Profile')" />
    </x-slot>

    <div class="space-y-6">
        <x-card class="p-4 sm:p-8">
            <div class="max-w-xl">
                @include('profile.partials.update-profile-information-form')
            </div>
        </x-card>

        {{-- Staff do not set their own password; an admin resets it from the
             Users screen. The route refuses them too -- hiding the form while
             leaving the endpoint open is not removing it. --}}
        @if ($user->isAdmin())
            <x-card class="p-4 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </x-card>
        @else
            <x-card class="p-4 sm:p-8">
                <div class="max-w-xl">
                    <h2 class="text-lg font-medium text-gray-900">Password</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Ask the studio to reset your password — they can set a new one from the Users screen.
                    </p>
                </div>
            </x-card>
        @endif

        {{-- Everyone gets this, admin or not. Losing a phone is not a
             privilege of rank. --}}
        <x-card class="p-4 sm:p-8">
            <div class="max-w-xl">
                @include('profile.partials.browser-sessions')
            </div>
        </x-card>

        @if ($user->isAdmin())
            <x-card class="p-4 sm:p-8">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </x-card>
        @endif
    </div>
</x-app-layout>
