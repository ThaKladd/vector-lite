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
    | Default Dimensions
    |--------------------------------------------------------------------------
    |
    | The default dimensions for the vectors. This is used when creating
    | vectors, if not specified otherwise. The default is 1536, which is
    | the size of the OpenAI text-embedding-3-small model.
    |
    | For clustering, there is no need to have such big vectors, so a
    | smaller dimension vector is created for clustering use. This will make for
    | faster clustering, but slower insertion as two vectors are created.

    | The speed increase in retrieval should be worth it. But in case you
    | want to use the same dimensions for both, this can be disabled.
    |
    | Example: 1536 for OpenAI, 512 for clustering.
    |
    | Since reducing dimensions is an extra step at insert time, there are
    | these following options available:
    | - 'rpm' (default): Random Projection Method, which reduces by random projection.
    | - 'new': Creates a new embedding with the specified dimensions. This requires an OpenAI API key. TODO: WORK OUT WHEN LONG VECTOR IS PRESENT BUT NO SHORT
    | - 'chunked_aggregation': Aggregating mean chunks of the vector into a new one.
    | - 'pca': Principal Component Analysis, RPM, but PCA when total vectors are more than 10,000. TODO: NOT YET IMPLEMENTED. NEED PYTHON.
    */
    'default_dimensions' => 1536, // Default dimensions for vectors
    'use_clustering_dimensions' => true, // Use clustering dimensions for clustering
    'reduction_method' => \ThaKladd\VectorLite\Enums\ReduceBy::RPM, // Method to reduce dimensions for clustering
    'clustering_dimensions' => 64, // Default dimensions for clustering vectors

    /*
    |--------------------------------------------------------------------------
    | Cluster Size
    |--------------------------------------------------------------------------
    |
    | Cluster size for optimal speed is determined by the number of vectors
    | in your db. Ideal size for either number of vectors or clusters
    | is between 100 and 1000 and decides the speed.
    |
    | Example: 1000 clusters of size 1000 will cover 1 mill vectors. Finding similar
    | cluster(ca 0.05s) and then vector(ca 0.05s) will total ca. 0.1 sec.
    | Looking up 1 mill vectors directly would take around a minute.
    |
    | Takeaways: VectorLite is ok up to a few million vectors, but not for billions.
    | You could reduce the amount of cluster to look up by filtering
    | on other columns, giving room for even more vectors.
    */
    'clusters_size' => 500, // Default to manage around 250000 vectors

    /*
     * --------------------------------------------------------------------------
     * Cosine Similarity Function Cache
     * --------------------------------------------------------------------------
     *
     * The default cosine similarity function is used to calculate the similarity.
     * The cached cosine similarity function uses more memory but is faster.
     * Cache is turned on by default, even if it uses more memory. For more
     * granular control, you can turn this off or write your own function.
     *
     * If you add a cache driver, then the cache becomes more global
     * and persists between sessions.
     */
    'use_cached_cosim' => true, // Cached cosim function on by default
    'cache_driver' => env('CACHE_DRIVER', false),
    'cache_time' => 60 * 60 * 24, // Cache time in seconds
];
