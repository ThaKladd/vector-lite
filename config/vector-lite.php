<?php

use ThaKladd\VectorLite\Enums\ReduceBy;

// config for ThaKladd/VectorLite
return [
    /*
    |--------------------------------------------------------------------------
    | Column names
    |--------------------------------------------------------------------------
    |
    | To give a little bit of customizability, you can change the column names
    | of a few columns with their base name. The variations of
    | _small, _hash, and _norm are added automatically
    |
    */
    'similarity_alias' => 'similarity', // The calculated match
    'vector_column' => 'vector', // Column storing the vector
    'embed_hash_column' => 'embed_hash', // Hash that check the changes in the vector data

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Where vectors come from. Any server with an OpenAI-compatible
    | POST /v1/embeddings endpoint works: OpenAI itself (the default),
    | Ollama, LM Studio, llama.cpp, vLLM or Text Embeddings Inference.
    |
    | One embedding model per vector column: vectors from different models
    | (or dimensions, or document prefixes) live in different spaces and
    | cannot be compared. Changing any of these marks every row as stale
    | (see embed_hash); re-embed them with `php artisan vector-lite:reembed`.
    |
    | - url: base URL (".../v1/") or the full ".../v1/embeddings" endpoint.
    | - api_key: sent as a Bearer token when set. Local servers don't need one.
    |   For OpenAI it falls back to 'openai.api_key' (OPENAI_API_KEY) below.
    | - send_dimensions: send 'default_dimensions' as "dimensions". Turn off
    |   for models that reject it; the returned length is validated either way.
    | - query_prefix / document_prefix: prepended to search queries and to
    |   stored documents. Asymmetric retrieval models need them, e.g.
    |   Qwen3-Embedding: query_prefix =
    |     "Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:"
    |   EmbeddingGemma 2: query_prefix = "task: search result | query: ",
    |     document_prefix = "title: none | text: "
    |   (In .env, use double quotes so "\n" becomes a newline.)
    | - on_error: what the model saving hook does when the provider fails.
    |   'throw' (default) aborts the save. 'skip' reports the error and saves
    |   the model without a new vector; its embed_hash stays stale, so the
    |   next save or `vector-lite:reembed --only-stale` retries it.
    | - providers: optional named endpoints for Embeddings::...->generate('name').
    |   Each inherits the settings above except api_key. "openai" is built in.
    |
    */
    'embeddings' => [
        'url' => env('VECTOR_LITE_EMBEDDINGS_URL', 'https://api.openai.com/v1/'),
        'api_key' => env('VECTOR_LITE_EMBEDDINGS_API_KEY'),
        'model' => env('VECTOR_LITE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'timeout' => (int) env('VECTOR_LITE_EMBEDDINGS_TIMEOUT', 60),
        'send_dimensions' => env('VECTOR_LITE_EMBEDDINGS_SEND_DIMENSIONS', true),
        'query_prefix' => env('VECTOR_LITE_EMBEDDINGS_QUERY_PREFIX', ''),
        'document_prefix' => env('VECTOR_LITE_EMBEDDINGS_DOCUMENT_PREFIX', ''),
        'on_error' => env('VECTOR_LITE_EMBEDDINGS_ON_ERROR', 'throw'), // 'throw' or 'skip'
        'providers' => [
            // 'ollama' => [
            //     'url' => 'http://localhost:11434/v1/',
            //     'model' => 'qwen3-embedding:0.6b',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAi API Key
    |--------------------------------------------------------------------------
    |
    | Legacy location of the OpenAI key, still used as the fallback key when
    | the embeddings endpoint above is OpenAI and has no api_key of its own.
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
    | the size of the OpenAI text-embedding-3-small model. It must match
    | what the embedding model returns (e.g. at most 1024 for
    | qwen3-embedding:0.6b) - a mismatch throws instead of storing it.
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
    'default_dimensions' => (int) env('VECTOR_LITE_DEFAULT_DIMENSIONS', 1536), // Default dimensions for vectors
    'use_clustering_dimensions' => true, // Use clustering dimensions for clustering
    'reduction_method' => ReduceBy::RPM, // Method to reduce dimensions for clustering
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

    /*
    |--------------------------------------------------------------------------
    | FFI dot product
    |--------------------------------------------------------------------------
    |
    | Cosine similarity (COSIM/COSIM_CACHE) is normally computed by unpacking
    | vectors into PHP arrays and summing products in a PHP loop. Turning
    | this on computes the dot product in C via PHP's FFI extension instead
    | - the packed vector bytes are passed straight through, with no
    | unpacking. A prebuilt library (compiled from resources/c/dot_product.c)
    | ships for Linux (x86_64/aarch64) and Windows (x86_64) under
    | resources/c/lib/ and is picked automatically for the running
    | OS/architecture - nothing needs to be compiled on those platforms.
    |
    | Requires the "ffi" extension (and ffi.enable=true in php.ini, since it
    | defaults to preload-only). Silently falls back to the PHP
    | implementation if disabled, the extension is missing, the ini
    | directive blocks it, or no library is available (e.g. on macOS, which
    | has no shipped binary yet - compile resources/c/dot_product.c
    | yourself and set "library_path" below to use it there).
    */
    'ffi' => [
        'enabled' => env('VECTOR_LITE_FFI_ENABLED', false),
        'library_path' => env('VECTOR_LITE_FFI_LIBRARY_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings caching
    |--------------------------------------------------------------------------
    |
    | Mirrors Laravel AI SDK's config('ai.caching.embeddings') shape, for the
    | Embeddings::for(...)->cache()->generate() / Str::toEmbeddings(cache: ...)
    | helpers. When 'cache' is enabled, every generated embedding is cached
    | (keyed on model, dimensions, and the input text) for 'ttl' seconds,
    | unless a call overrides the duration with ->cache(seconds: ...).
    */
    'caching' => [
        'embeddings' => [
            'cache' => env('VECTOR_LITE_CACHE_EMBEDDINGS', false),
            'store' => env('CACHE_STORE', 'database'),
            'ttl' => 60 * 60 * 24 * 30, // 30 days
        ],
    ],

    /*
     * --------------------------------------------------------------------------
     * Other settings
     * --------------------------------------------------------------------------
     *
     * exclude_self_by_default: If, when doing a vector query on an existing model,
     * wether not exclude or include the current model by default.
     */
    'exclude_self_by_default' => false,
];
