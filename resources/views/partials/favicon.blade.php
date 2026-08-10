{{--
    Site icons, shared by the public site, the admin app and the login screens.

    The mark is the camera from the logo rather than the full wordmark, which is
    illegible below about 100px. Tiles are opaque white because the camera art
    is black: on a transparent icon it vanishes into a dark tab bar or a dark
    iOS home screen.
--}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="#132A38">

{{-- iOS reads none of the manifest's display or colour fields, so the
     standalone behaviour has to be declared again in its own tags. Without
     these an installed icon opens in a Safari tab with browser chrome. --}}
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Chakra">
