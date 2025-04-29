<?php

// config for ThaKladd/VectorLite
return [
    /*
    |--------------------------------------------------------------------------
    | OpenAi API Key
    |--------------------------------------------------------------------------
    |
    | If you want to use the built-in support for OpenAi embeddings in order
    | to create the vectors stored in the database, then you can provide
    | your own API key, from your .env file in your project root.
    |
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cluster Size
    |--------------------------------------------------------------------------
    |
    | Cluster size for optimal speed is determined by the amount of vectors
    | in your db. Ideal size for either amount of vectors or clusters
    | is between 100 and 1000 and decides the speed.
    |
    | Example: 1000 clusters of size 1000 will cover 1 mill vectors. Finding similar
    |          cluster(ca 0.05s) and then vector(ca 0.05s) will total ca 0.1 sec.
    |          Looking up 1 mill vectors directly, would take around a minute.
    |
    | Takeaways: VectorLite is ok up to a few million vectors, but not for billions.
    |            You could reduce the amount of cluster to look up, by filtering
    |            on other columns, giving room for even more vectors.
    */
    'clusters_size' => 500, // Default to manage around 250000 vectors

    /*
     * --------------------------------------------------------------------------
     * Cosine Similarity Function Cache
     * --------------------------------------------------------------------------
     *
     * The default cosine similarity function is used to calculate the similarity.
     * The cached cosine similarity function uses more memory, but is faster.
     * Cache is turned on by default, even if it uses more memory. For more
     * granular control, you can turn this off, or write your own function.
     *
     * If you add a cache driver, then the cache becomes more global
     * and persist between sessions.
     */
    'use_cached_cosim' => true, // Cached cosim function on by default
    'cache_driver' => env('CACHE_DRIVER', false),
    'cache_time' => 60 * 60 * 24, // Cache time in seconds
];
