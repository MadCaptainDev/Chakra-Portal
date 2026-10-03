<?php

/*
|--------------------------------------------------------------------------
| The studio's public identity
|--------------------------------------------------------------------------
|
| One place for the name, contact details and profiles the public site
| prints and hands to search engines (App\Support\Seo). Contact details
| come from .env so changing a phone number is not a deploy; anything
| left empty simply does not render, on the page or in the structured data.
|
| Local search ranks on these matching everywhere -- the site, the Google
| Business Profile, Instagram -- so fill them in exactly as they appear on
| the Business Profile.
|
*/

return [

    'name' => 'Chakra Productions',

    'email' => env('STUDIO_EMAIL'),

    // As printed, e.g. '+91 98765 43210'.
    'phone' => env('STUDIO_PHONE'),

    // Digits only, country code first, e.g. '919876543210'.
    'whatsapp' => env('STUDIO_WHATSAPP'),

    'address' => [
        'street' => env('STUDIO_STREET'),
        'locality' => env('STUDIO_LOCALITY'),   // e.g. 'Manapparai'
        'region' => env('STUDIO_REGION', 'Tamil Nadu'),
        'postal_code' => env('STUDIO_POSTAL_CODE'),
        'country' => 'IN',
    ],

    // The Google Business Profile / Maps link, once there is one.
    'maps_url' => env('STUDIO_MAPS_URL'),

    // Where the studio is found and works -- areaServed in the structured data.
    'areas' => [
        ['@type' => 'City', 'name' => 'Tiruchirappalli', 'alternateName' => 'Trichy'],
        ['@type' => 'City', 'name' => 'Manapparai'],
        ['@type' => 'State', 'name' => 'Tamil Nadu'],
    ],

    'social' => [
        'Instagram' => env('STUDIO_INSTAGRAM', 'https://www.instagram.com/thechakra_productions/'),
        'YouTube' => env('STUDIO_YOUTUBE'),
        'LinkedIn' => env('STUDIO_LINKEDIN'),
    ],

];
