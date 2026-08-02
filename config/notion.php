<?php

return [

    'api_key' => env('NOTION_API_KEY'),

    'cache_ttl' => (int) env('NOTION_CACHE_TTL', 86400),

    /*
     * Chakra Productions' content-planning databases.
     *
     * `id` is only a fallback. The sync service resolves the real id by
     * searching the workspace for a database whose title contains
     * `name_contains`, because Notion ids change when a database is
     * duplicated or recreated — which already silently broke the Post
     * planner once. Property names/types are detected from the API payload
     * rather than configured here, for the same reason.
     */
    'databases' => [
        'youtube' => [
            'label' => 'YouTube',
            'name_contains' => 'content planner',
            'id' => '68032af9-aff5-82c1-a830-8139d96cbb6e',
        ],
        'reel' => [
            'label' => 'Reel',
            'name_contains' => 'reel planner',
            'id' => '2ba32af9-aff5-8063-af78-e65c7319f8b9',
        ],
        'post' => [
            'label' => 'Post',
            'name_contains' => 'post planner',
            'id' => '37d32af9-aff5-807b-be4b-de3d252f53e8',
        ],
        'story' => [
            'label' => 'Story',
            'name_contains' => 'story tracker',
            'id' => '37d32af9-aff5-8018-a74b-c397079c1df3',
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
     * Board columns, in true production-pipeline order. Notion's own option
     * order differs between the Reel and Post planners (they disagree on
     * whether shooting or editing comes first) and buries Scheduled after
     * Canceled, so the portal imposes one correct order for both.
     *
     * `color` mirrors the Notion option colour so the board reads the same.
     */
    'board_columns' => [
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

];
