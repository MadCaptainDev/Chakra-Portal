<?php

return [

    /*
     * The integration token itself lives in the notion_settings table
     * (App\Models\NotionSetting::current()->api_key, encrypted), not here --
     * see that model. Rotating it is a person on Setup -> Notion, not an
     * env change plus a deploy.
     */

    'cache_ttl' => (int) env('NOTION_CACHE_TTL', 86400),

    /*
     * Chakra Productions' content-planning databases.
     *
     * `ids` is a LIST and is authoritative. Two things forced that, both
     * found on the real workspace rather than anticipated:
     *
     * 1. Title matching is not unique. Twelve databases have titles
     *    containing "content planner" (one real, eleven empty "(1)"
     *    duplicates), two contain "reel planner", two "post planner".
     *    Notion's search does not guarantee an order, so resolving by title
     *    picked a different database on different runs -- which is exactly
     *    what happened: one sync wrote 465 post rows from one database, the
     *    next wrote 60 from another, and the table ended up holding a mix
     *    from two sources with no way to tell them apart.
     *
     * 2. A source can legitimately span more than one database. "Post
     *    Planner - Instagram" and "Post Planner - Instagram (1)" both hold
     *    real, DIFFERENT content -- confirmed by comparing every title in
     *    each: zero overlap, the older holding May-June and the newer
     *    June-August. Reading only one silently loses half the studio's
     *    posts.
     *
     * `name_contains` remains as a self-healing fallback for the case the
     * original comment was written for: an id that no longer resolves
     * because the database was duplicated or recreated. It is only
     * consulted when NONE of the configured ids are reachable, and it now
     * prefers a title without a "(n)" copy suffix.
     */
    'databases' => [
        'youtube' => [
            'label' => 'YouTube',
            'name_contains' => 'content planner',
            // The eleven "Content Planner - YT (1)" duplicates are all
            // empty; only this one has ever held rows.
            'ids' => ['68032af9-aff5-82c1-a830-8139d96cbb6e'],
        ],
        'reel' => [
            'label' => 'Reel',
            'name_contains' => 'reel planner',
            // "Reel Planner - Instagram (1)" is empty.
            'ids' => ['2ba32af9-aff5-8063-af78-e65c7319f8b9'],
        ],
        'post' => [
            'label' => 'Post',
            'name_contains' => 'post planner',
            // BOTH, deliberately -- see note 2 above. Newer first.
            'ids' => [
                '37f32af9-aff5-807a-9a9b-d2630826f66b', // Post Planner - Instagram
                '37d32af9-aff5-807b-be4b-de3d252f53e8', // Post Planner - Instagram (1)
            ],
        ],
        'story' => [
            'label' => 'Story',
            'name_contains' => 'story tracker',
            'ids' => ['37d32af9-aff5-8018-a74b-c397079c1df3'],
        ],
        'shoot' => [
            'label' => 'Shoots',
            'name_contains' => 'shoots',
            'ids' => ['61632af9-aff5-8244-a7a9-0169f924c9d9'],
        ],
    ],

    /*
     * Notion property names to look for, in priority order, for each local
     * column. Matching is exact first, then trimmed/case-insensitive — the
     * planners have properties literally named "Editor " and "" (yes, empty).
     */
    'properties' => [
        'venture' => ['Venture'],
        'status' => ['', 'Status', 'Stage'],
        'published_date' => ['Published Date'],
        'shoot_date' => ['Shoot Date'],
        'editor' => ['Editor', 'Posted by', 'Editor Name'],
        'tier' => ['Tier'],
        'post_type' => ['Post Type'],
        'ssd' => ['SSD'],
        'assigned_to' => ['Assigned To'],
        'effort_hours' => ['Effort Hours'],
        'insta_csv_link' => ['Insta CSV Link', 'Insta/YT CSV Link'],
        'yt_csv_link' => ['YT CSV Link'],
        'script' => ['Script'],
        'show_notes' => ['Show Notes'],
    ],

    /*
     * Notion property names for the Shoots database -- a separate map from
     * `properties` above, which is also read for the 4 content sources
     * (youtube/reel/post/story). A shoot-only name like "Location" has no
     * business being a candidate there.
     *
     * Alternate spellings exist because ContentSyncService::findProperty()'s
     * fuzzy pass is trimmed/case-insensitive but not whitespace-normalised
     * inside the string -- "Host / Model" and "Host/Model" are different
     * keys to it.
     */
    'shoot_properties' => [
        'status' => ['Status'],
        'client' => ['Client'],
        'team' => ['Team'],
        'host_model' => ['Host / Model', 'Host/Model', 'Host'],
        'location' => ['Location'],
        'shoot_date' => ['Date', 'Shoot Date'],
        'duration' => ['Duration'],
        'video_count' => ['No Of Videos', 'No of Videos'],
        'gear_needed' => ['Gear Needed'],
        'weather_forecast' => ['Weather Forecast'],
        'photo_url' => ['Photo'],
    ],

    /*
     * Board columns, in true production-pipeline order, keyed by source --
     * the Reel and Shoot pipelines have genuinely different status
     * vocabularies, so one shared list would not fit either well.
     *
     * `color` mirrors the Notion option colour so the board reads the same.
     * Note the real spelling difference, confirmed against the live
     * databases: the Reel planner's option is "Canceled" (one L); the
     * Shoots database's is "Cancelled" (two) -- both must match Notion's
     * actual option text character-for-character or that column renders
     * empty.
     */
    'boards' => [
        'reel' => [
            ['status' => 'Idea', 'color' => 'brown'],
            ['status' => 'To Be Shooted', 'label' => 'To Be Shot', 'color' => 'yellow'],
            ['status' => 'To Be Edited', 'color' => 'orange'],
            ['status' => 'Edit in Progress', 'color' => 'yellow'],
            ['status' => 'Under Review', 'color' => 'purple'],
            ['status' => 'Video Ready', 'color' => 'pink'],
            ['status' => 'Scheduled', 'color' => 'gray'],
            ['status' => 'Published', 'color' => 'green'],
            ['status' => 'Canceled', 'color' => 'red'],
        ],
        'shoot' => [
            ['status' => 'Planned', 'color' => 'brown'],
            ['status' => 'Shooting', 'color' => 'yellow'],
            ['status' => 'Editing', 'color' => 'orange'],
            ['status' => 'Review', 'color' => 'purple'],
            ['status' => 'Completed', 'color' => 'green'],
            ['status' => 'Cancelled', 'color' => 'red'],
        ],
    ],

];
