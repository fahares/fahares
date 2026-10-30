<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default search connection that gets used while
    | using Laravel Scout. This connection is used when syncing all models
    | to the search service. You should adjust this based on your needs.
    |
    | Supported: "algolia", "meilisearch", "typesense", "turbopuffer",
    |            "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'collection'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search index
    | names used by Scout. This prefix may be useful if you have multiple
    | "tenants" or applications sharing the same search infrastructure.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When this is set to "true" then
    | all automatic data syncing will get queued for better performance.
    |
    */

    'queue' => env('SCOUT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | This configuration option determines if your data will only be synced
    | with your search indexes after every open database transaction has
    | been committed, thus preventing any discarded data from syncing.
    |
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | These options allow you to control the maximum chunk size when you are
    | mass importing data into the search engine. This allows you to fine
    | tune each of these chunk sizes based on the power of the servers.
    |
    */

    // 250, not 1000: the production host has 5.4 GB for every project on it, and a bulk import of
    // manuscripts with their relations in 1000-row chunks has been killed for memory (step 13.73)
    'chunk' => [
        'searchable' => 250,
        'unsearchable' => 250,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option allows to control whether to keep soft deleted records in
    | the search indexes. Maintaining soft deleted records can be useful
    | if your application still needs to search for the records later.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | This option allows you to control whether to notify the search engine
    | of the user performing the search. This is sometimes useful if the
    | engine supports any analytics based on this application's users.
    |
    | Supported engines: "algolia"
    |
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Algolia settings. Algolia is a cloud hosted
    | search engine which works great with Scout out of the box. Just plug
    | in your application ID and admin API key to get started searching.
    |
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
        'index-settings' => [
            // 'users' => [
            //     'searchableAttributes' => ['id', 'name', 'email'],
            //     'attributesForFaceting'=> ['filterOnly(email)'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Meilisearch settings. Meilisearch is an open
    | source search engine with minimal configuration. Below, you can state
    | the host and key information for your own Meilisearch installation.
    |
    | See: https://www.meilisearch.com/docs/learn/configuration/instance_options#all-instance-options
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            \App\Models\Work::class => [
                'filterableAttributes' => [
                    'id',
                    'author_id',
                    'volume_number',
                    'composition_year_hijri',
                    'manuscripts_count',
                    'work_form',
                    'subjects',
                    'languages',
                    'era_year',
                    'has_autograph',
                    'bibliographies',
                    'author_ids',
                    'related_person_ids',
                ],
                'sortableAttributes' => [
                    'composition_year_hijri',
                    'manuscripts_count',
                    'volume_number',
                    'author_death_year',
                    'era_year',
                ],
                // Among equal text matches, a match in a title or the author (titles_names) before one in the
                // incipits or the description (backlog item 93: «نهج البلاغه» is not the «جنگ» quoting it), a match
                // in the work's own title before one in the title of the work it is made on (base_titles, backlog
                // item 95: «تجرید» is the Tajrid, then its commentaries), then the work found in more manuscripts:
                // «کافی» is al-Kafi of Kulayni. A chosen sort comes before the field.
                // Checked with fahares:search-benchmark (backlog item 83)
                'rankingRules' => ['words', 'typo', 'proximity', 'sort', 'attributeRank', 'manuscripts_count:desc', 'wordPosition', 'exactness'],
                'searchableAttributes' => [
                    'titles_names',
                    'base_titles',
                    'primary_title',
                    'clean_title',
                    'alternative_titles',
                    'author_name',
                    'author_aliases',
                    // Translators, dedicatees, commissioners…: after the work's own titles and authors (backlog item 35)
                    'related_people',
                    'transliteration',
                    'subject_summary',
                    'language_summary',
                    'incipit_text',
                    'explicit_text',
                    'description',
                    'manuscript_texts',
                ],
                'pagination' => [
                    'maxTotalHits' => 100000,
                ],
                // A number is matched as written: «1189» is not «1188» or «11890» (Meilisearch ≥ 1.15, backlog item 83)
                'typoTolerance' => [
                    'disableOnNumbers' => true,
                ],
                'faceting' => [
                    'maxValuesPerFacet' => 500,
                ],
                'synonyms' => [
                    'بهجه' => ['بهجت'],
                    'بهجت' => ['بهجه'],
                    'حجه' => ['حجت'],
                    'حجت' => ['حجه'],
                    'کفایه' => ['کفایت'],
                    'کفایت' => ['کفایه'],
                    'نزهه' => ['نزهت'],
                    'نزهت' => ['نزهه'],
                    'حکمه' => ['حکمت'],
                    'حکمت' => ['حکمه'],
                    'رحمه' => ['رحمت'],
                    'رحمت' => ['رحمه'],
                    'تحفه' => ['تحفت'],
                    'تحفت' => ['تحفه'],
                    'روضه' => ['روضت'],
                    'روضت' => ['روضه'],
                    'هدایه' => ['هدایت'],
                    'هدایت' => ['هدایه'],
                    'بدایه' => ['بدایت'],
                    'بدایت' => ['بدایه'],
                ],
            ],
            \App\Models\Manuscript::class => [
                'filterableAttributes' => [
                    'id',
                    'work_id',
                    'library_id',
                    'scribe_id',
                    'city',
                    'copy_place',
                    'copy_date_hijri_year',
                    'volume_number',
                    'is_autograph',
                    'is_illuminated',
                    'is_illustrated',
                    'is_corrected',
                    'has_marginal_notes',
                    'is_ruled',
                    'has_catchwords',
                    'is_facsimile',
                    'is_collated',
                    'is_bika',
                    'is_bita',
                    'scripts',
                    'subjects',
                    'languages',
                    'folios',
                    'has_author_marginalia',
                    'scribe_ids',
                ],
                'sortableAttributes' => [
                    'copy_date_hijri_year',
                    'sequence_number',
                    'volume_number',
                    'folios',
                ],
                // Meilisearch's own order, with the manuscript of the work found in more manuscripts first among
                // equal matches in the same field (backlog item 93)
                'rankingRules' => ['words', 'typo', 'proximity', 'attributeRank', 'sort', 'work_manuscripts_count:desc', 'wordPosition', 'exactness'],
                'searchableAttributes' => [
                    'work_title',
                    'author_name',
                    'shelfmark',
                    'shelfmark_key',
                    'scribe_name',
                    'commissioned_by',
                    'incipit_text',
                    'explicit_text',
                    'library',
                    'library_full_name',
                    'city',
                    'script_names',
                    'copy_place',
                    'copy_date_raw',
                ],
                'pagination' => [
                    'maxTotalHits' => 100000,
                ],
                // A number is matched as written: «1189» is not «1188» or «11890» (Meilisearch ≥ 1.15, backlog item 83)
                'typoTolerance' => [
                    'disableOnNumbers' => true,
                ],
                // Libraries and cities: every value, not Meilisearch's first 100
                'faceting' => [
                    'maxValuesPerFacet' => 500,
                ],
                'synonyms' => [
                    'بهجه' => ['بهجت'],
                    'بهجت' => ['بهجه'],
                    'حجه' => ['حجت'],
                    'حجت' => ['حجه'],
                    'کفایه' => ['کفایت'],
                    'کفایت' => ['کفایه'],
                    'نزهه' => ['نزهت'],
                    'نزهت' => ['نزهه'],
                    'حکمه' => ['حکمت'],
                    'حکمت' => ['حکمه'],
                    'رحمه' => ['رحمت'],
                    'رحمت' => ['رحمه'],
                    'تحفه' => ['تحفت'],
                    'تحفت' => ['تحفه'],
                    'روضه' => ['روضت'],
                    'روضت' => ['روضه'],
                    'هدایه' => ['هدایت'],
                    'هدایت' => ['هدایه'],
                    'بدایه' => ['بدایت'],
                    'بدایت' => ['بدایه'],
                ],
            ],
            // The printed entries of the sources, al-Dhari'a first (backlog item 83, phase 7)
            \App\Models\SourceItem::class => [
                'filterableAttributes' => ['source_id', 'volume_number', 'kind'],
                'sortableAttributes' => ['volume_number', 'position'],
                'searchableAttributes' => ['title', 'text'],
                'pagination' => [
                    'maxTotalHits' => 100000,
                ],
                // A number is matched as written: «1189» is not «1188» or «11890» (Meilisearch ≥ 1.15, backlog item 83)
                'typoTolerance' => [
                    'disableOnNumbers' => true,
                ],
                'synonyms' => [
                    'بهجه' => ['بهجت'],
                    'بهجت' => ['بهجه'],
                    'حجه' => ['حجت'],
                    'حجت' => ['حجه'],
                    'کفایه' => ['کفایت'],
                    'کفایت' => ['کفایه'],
                    'نزهه' => ['نزهت'],
                    'نزهت' => ['نزهه'],
                    'حکمه' => ['حکمت'],
                    'حکمت' => ['حکمه'],
                    'رحمه' => ['رحمت'],
                    'رحمت' => ['رحمه'],
                    'تحفه' => ['تحفت'],
                    'تحفت' => ['تحفه'],
                    'روضه' => ['روضت'],
                    'روضت' => ['روضه'],
                    'هدایه' => ['هدایت'],
                    'هدایت' => ['هدایه'],
                    'بدایه' => ['بدایت'],
                    'بدایت' => ['بدایه'],
                ],
            ],
            \App\Models\Person::class => [
                'filterableAttributes' => [
                    'id',
                    'is_author',
                    'is_scribe',
                    'is_translator',
                    'is_donor',
                    'century_hijri',
                    'death_year_hijri',
                    'works_count',
                    'manuscripts_count',
                ],
                'sortableAttributes' => [
                    'death_year_hijri',
                    'works_count',
                    'manuscripts_count',
                ],
                // Among equal text matches, the better-known bearer of a name first (backlog item 83)
                'rankingRules' => ['words', 'typo', 'proximity', 'sort', 'works_count:desc', 'manuscripts_count:desc', 'attribute', 'exactness'],
                'searchableAttributes' => [
                    'name',
                    'normalized_name',
                    'aliases',
                    'transliteration',
                ],
                'pagination' => [
                    'maxTotalHits' => 100000,
                ],
                // A number is matched as written: «1189» is not «1188» or «11890» (Meilisearch ≥ 1.15, backlog item 83)
                'typoTolerance' => [
                    'disableOnNumbers' => true,
                ],
            ],
        ],
        'model-settings' => [
            // User::class => [
            //     'embedding' => [
            //         'embedder' => 'default',
            //         'dimensions' => 1536,
            //     ],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Typesense settings. Typesense is an open
    | source search engine using minimal configuration. Below, you will
    | state the host, key, and schema configuration for the instance.
    |
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => [
                'host' => env('TYPESENSE_HOST', 'localhost'),
                'port' => env('TYPESENSE_PORT', '8108'),
                'path' => env('TYPESENSE_PATH', ''),
                'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
            ],
            'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
        // 'max_total_results' => env('TYPESENSE_MAX_TOTAL_RESULTS', 1000),
        'model-settings' => [
            // User::class => [
            //     'collection-schema' => [
            //         'fields' => [
            //             [
            //                 'name' => 'id',
            //                 'type' => 'string',
            //             ],
            //             [
            //                 'name' => 'name',
            //                 'type' => 'string',
            //             ],
            //             [
            //                 'name' => 'created_at',
            //                 'type' => 'int64',
            //             ],
            //         ],
            //         'default_sorting_field' => 'created_at',
            //     ],
            //     'search-parameters' => [
            //         'query_by' => 'name'
            //     ],
            //     'embedding' => [
            //         'attribute' => 'embedding',
            //         'dimensions' => 1536,
            //     ],
            // ],
        ],
        'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Turbopuffer Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Turbopuffer connection and the schema and
    | searchable attributes defined by each of your application's models.
    | Turbopuffer is a scalable engine with full-text + vector search.
    |
    */

    'turbopuffer' => [
        'api_key' => env('TURBOPUFFER_API_KEY'),
        'region' => env('TURBOPUFFER_REGION', 'gcp-us-central1'),
        'base_url' => env('TURBOPUFFER_BASE_URL'),
        'timeout' => env('TURBOPUFFER_TIMEOUT', 60),
        'connect_timeout' => env('TURBOPUFFER_CONNECT_TIMEOUT', 5),
        'retries' => env('TURBOPUFFER_RETRIES', 3),
        'model-settings' => [
            // User::class => [
            //     'searchable-attributes' => [
            //         'name' => 2,
            //         'email' => 1,
            //     ],
            //     'embedding' => [
            //         'attribute' => 'embedding',
            //         'dimensions' => 1536,
            //     ],
            //     'schema' => [
            //         'name' => ['type' => 'string', 'full_text_search' => true],
            //         'email' => ['type' => 'string', 'full_text_search' => true],
            //         'embedding' => ['type' => '[1536]f32', 'ann' => true],
            //     ],
            // ],
        ],
    ],

];
