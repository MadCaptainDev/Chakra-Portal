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

    /*
     * The caption writer's house rules (App\Services\Ai\CaptionWriter).
     * Applied twice: told to Gemini, then enforced on what comes back, so a
     * caption that breaks one is fixed rather than shown.
     */
    'captions' => [
        // Never more than this many hashtags.
        'max_hashtags' => 5,
        // The line every caption carries, second, right after the hook.
        // {username} is the client's Instagram handle without the @.
        'handle_line' => '@{username} -',
        // The [keyword, keyword] line the studio puts before the hashtags.
        'keywords' => true,
        // Instagram's own limit.
        'max_length' => 2200,
        // How many past captions of the client's best reels to learn from.
        'examples' => 3,
        // Tried in order; the next is used when one is retired or busy.
        'models' => ['gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.8-flash'],
    ],

    /* The quick AI check of a cut uploaded for review (App\Services\Ai\VideoCheck). */
    'review' => [
        'enabled' => true,
        // Longer cuts are not sent: a check is for reels, not a 20-minute film.
        'max_seconds' => 300,
        // Gemini's file store takes up to 2 GB; this keeps the upload sane.
        'max_mb' => 500,
        'models' => ['gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.8-flash'],
    ],

];
