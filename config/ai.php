<?php

return [

    /*
     * Google Gemini prices, US dollars per million tokens, used to work out
     * what each call cost against the monthly limit (AI settings → Gemini
     * spend limit). Google bills the real amount; these are its published
     * paid-tier prices, rounded UP, so the portal's figure errs high and the
     * limit trips early rather than late. Matched by the first pattern the
     * model name contains; check them against ai.google.dev/pricing when
     * Google changes prices.
     *
     * input  = text, image and video in
     * audio  = audio in (a reel's sound track counts as audio)
     * output = everything written back, thinking included
     */
    'gemini_prices' => [
        'flash-lite' => ['input' => 0.10, 'audio' => 0.30, 'output' => 0.40],
        'pro' => ['input' => 2.50, 'audio' => 2.50, 'output' => 15.00],
        'flash' => ['input' => 0.50, 'audio' => 1.00, 'output' => 3.00],
        // Anything else is charged as the dearest, so a new model can never slip under the limit.
        '*' => ['input' => 2.50, 'audio' => 2.50, 'output' => 15.00],
    ],

    /*
     * Kept back from the limit before a call starts: a call is refused once
     * this month's spend plus this would pass the limit. One reel transcript
     * costs well under a cent; this covers a long video.
     */
    'gemini_reserve_usd' => 0.05,

];
