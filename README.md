# Adding vector capabilities to your existing SQLite database.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/thakladd/vector-lite.svg?style=flat-square)](https://packagist.org/packages/thakladd/vector-lite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/thakladd/vector-lite/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/thakladd/vector-lite/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/thakladd/vector-lite/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/thakladd/vector-lite/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/thakladd/vector-lite.svg?style=flat-square)](https://packagist.org/packages/thakladd/vector-lite)

**OBS! DO NOT USE IN PRODUCTION... YET! Wait for version 1.0**

Sometimes you don't need a specialized vector database, but just want something fast and simple even though it may be a bit slower that works together with your existing SQLite database.

This package adds the possibility to store vectors into your existing SQLite database together with the SQLite method to figure out the most similar vector by cosine similarity dot product calculations.

It has also added support for clustering the vectors, so that you can group similar vectors together to make it faster. All automagically.

The idea was heavily inspired by an article by [Andreas Gohr](https://www.splitbrain.org/blog/2023-08/15-using_sqlite_as_vector_store_in_php)

The package has tried to improve the speeds drastically from what the article suggested by a few methods of normalizing, binary packing, caching, optional clustering, and smaller cluster vectors.

### What can it do?

In case you are not that familiar with vectors, cosine similarity, and clustering, I will give you a simple use case.

What the vector search does is cosine similarity matching, matching one vector with the ones in your database, giving you the ability to finding similar data to the one you are searching for. So, usable for: RAG (Retrieval Augmented Generation) system for chatbots, simple search, similar products etc.

What you need to do, is to create a vector from your data, and then store it in the database. Then you can search for similar vectors in the database with any other vectorized string you have.

Normally you would need to connect to a vector database in order to store the vectors, but with this package you can store the vectors in your existing SQLite database and combine with other queries.

To be fair, there is libSQL that can do this, but it is a bit more complex to use as you need to replace your current database with it. For postgres you need to install pgvector, and for MySQL you need wait for the support to come.

Using a dedicated vector database like Pinecone and Milvus adds the extra latency of the network, and you need to connect to them via their api's giving distance between your database and your vectors.

With VectorLite you can get the power of vectors within your existing SQLite database and use it within your SQL queries.

## Requirements

-   PHP 8.4+ (possible to add PHP 8.3 support if requested)
-   Laravel 10.0+

## Usage

When everything is set up as shown below, then this is how it can be used:

```php
$movie = Movie::find(123);
$similarMovies = $movie->getBestVectorMatches(3);
```

## How to start

### 1. Install the package via composer

Run the following command in your terminal:

```bash
composer require thakladd/vector-lite
```

### 2. Add the service provider to your config/app.php file

```php
'providers' => [
    // ...
    ThaKladd\VectorLite\VectorLiteServiceProvider::class,
],
```

### 3. Publish the config file

```bash
php artisan vendor:publish --tag="vector-lite-config"
```

### 4. Optional: Add the OpenAI API key to your .env file

```env
OPENAI_API_KEY=your-openai-api-key
```

## Config file

This is the contents of the published config file:

```php
return [
    'similarity_alias' => 'similarity',
    'vector_column' => 'vector',
    'embed_hash_column' => 'embed_hash',
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],
    'default_dimensions' => 1536,
    'use_clustering_dimensions' => true,
    'reduction_method' => \ThaKladd\VectorLite\Enums\ReduceBy::RPM,
    'clustering_dimensions' => 64,
    'clusters_size' => 500,
    'use_cached_cosim' => true,
    'cache_driver' => env('CACHE_DRIVER', false),
    'cache_time' => 60 * 60 * 24,
    'ffi' => [
        'enabled' => env('VECTOR_LITE_FFI_ENABLED', false),
        'library_path' => env('VECTOR_LITE_FFI_LIBRARY_PATH'),
    ],
    'exclude_self_by_default' => false,
];
```

Take a look at the config file for further comments.

## Usage

There are two things you need to do in order to set up the use of Vectors in your project.

### 1. Extend your model with the VectorModel instead of Model. Everything from model is still there.

```php
class MyModel extends VectorModel {}
```

### 2. Run the artisan command

In order to add the vector column to your model, you need to run the following command:

```bash
php artisan vector-lite:make
```

This will prompt you for the model, create the migration, and run the migration if you choose to.

It will also ask you if you want to use clustering, and it will run the clustering command for you as well as prompt for running migration again.

In practice, it will add `'vector'`, `'vector_hash'`, `'vector_norm'` columns to your model, together with the `'_small'` variants. With added clustering it will make a new table `'model_clusters'` as well as append your model table with `'model_cluster_id'` and `'model_cluster_match'` columns. For embeddings there will be a `'embed_hash'` as well to keep track of changes in the data.

### And, then, how to use?

Nothing will happen until you actually add a vector to the model. You can do this on creation, or later. The choice of how is up to you, but adding a OpenAi key (as the only supported embedding model so far is their text-embedding-3-small), and use the provided `EmbeddingService` class. If you set the `$embedFields` (see below) you can use the `$vectorModel->getEmbeddingText()` method that builds a pseudo-xml of the text you want to make a vector for. Then, you use it with the EmbeddingService to get the vector to set: `$vectorModel->vector = EmbeddingService::createEmbedding($vectorModel->getEmbeddingText())` and then save the model to cluster it if you use clustering. There is a shortcut for this with `$vectorModel->vector = $vectorModel->createEmbedding()` or even simpler `$vectorModel->createAndFillEmbedding()->save()`.

## Methods

There is a new attribute you can add to your model `protected $embedFields = ['title', 'other.description', 'methodName'];` that is used to define what fields the data of the vector consists of.

### Provided by trait

The HasVector trait adds both methods and some other functionality to your model regarding the vector and clustering.

Note: The model may be slower to save, as it needs to get the embedding and calculate the cluster for the vector. But don't worry, it will only recalculate cluster when the vector is changed.

The most important is the setting of the vector attribute. Whatever your columns are named, user the `$model->vector` to set a vector created by an embedding ai model. If you have set up `$embedFields` and a openai key, this should work automatically and there is no need to set the vector. If a similarity is calculated, you can access it with `$model->similarity`.

There is also a method called `$model->createEmbedding($text, 1536)` for creating the embedding.

In addition, if you have made a cluster then you get it with the `$model->cluster` relationship, unless you make your own. Also, in order to find the best clusters `$model->getBestClusters(3)`.

On the model you also have two methods to get matching vectors `$model->findBestByVector()` and `$model->searchBestByVector(3)`.

#### Scopes

The scopes, and the custom querybuilder, is only with SqlLite.

```php
$modelQuery = YourModel::query();
$modelQuery->filterByClosestClusters(3);
$modelQuery->getBestClustersByVector($vector, 3);
$modelQuery->findBestClustersByVector($vector);
$modelQuery->selectSimilarity($vector);
$modelQuery->findBestByVector($vector);
$modelQuery->searchBestByVector($vector, 3);
$modelQuery->whereVector($vector, '>=', 0.8);
$modelQuery->whereVectorBetween($vector, 0.5, 0.9);
$modelQuery->havingSimilarity($vector, '>=', 0.8);
$modelQuery->orderBySimilarity($vector, 'desc');
$modelQuery->withoutModels([$vector, $vector2]);
$modelQuery->includeModels([$vector, $vector2]);
$modelQuery->orIncludeModels([$vector, $vector2]);
$modelQuery->withoutSelf();
$modelQuery->includeSelf();
```

There are a few methods directly on your model as well, and these work with any database of your choice - like mysql.

```php
$model = YourModel::find(1);
$model->getBestVectorMatches(3);
$model->findBestVectorMatch();
$model->getBestClusters(3);
$model->findBestCluster();
```

Similarly, the Vector models when fetched, go into a collection, and to it there are added a few methods as well. These also work regardless of database choice.

```php
$all = YourModel::all();
$all->searchBestByVector($vector, 3);
$all->sortBySimilarityToVector($vector, 'asc');
$all->filterAboveSimilarityThreshold($vector, 0.5);
$all->pluckSimilarities($vector);
$all->withSimilarities($vector);
$all->findBestByVector($vector);
```

The `VectorModel` comes built in with relationship to the cluster, and vice versa.

```php
$model = YourModel::find(1);
$cluster = $model->cluster;
$models = $cluster->models;
```

### Provided by class

The VectorLite class provides useful methods for calculating dot products and normalizing vectors etc.
All of them are used within VectorLite but is also available to use.

```php
use ThaKladd\VectorLite\VectorLite;
```

## Laravel 13 / AI SDK compatibility

Laravel 13 introduced native vector support via a separate opt-in package, [`laravel/ai`](https://laravel.com/docs/13.x/ai-sdk) - a `$table->vector()` migration column, an `'embedding' => 'array'` cast, query methods like `whereVectorSimilarTo()`, and `Embeddings`/`Reranking`/`SimilaritySearch` helpers. It only works on **PostgreSQL with the `pgvector` extension**.

vector-lite is a standalone package (no dependency on `laravel/ai`) that mirrors that same API surface on SQLite, under its own namespace, so code written the "official" way shifts over seamlessly whether or not `laravel/ai` is installed and whether the connection is SQLite or Postgres.

### Migration column

Core's `$table->vector('embedding', dimensions: 1536)` throws on SQLite (`Grammar::typeVector()` has no SQLite implementation), and can't be overridden by a macro since `vector()` is a real method on `Blueprint`. Use vector-lite's own macro instead, reshaped to accept the same `dimensions` argument:

```php
$table->vectorLite('embedding', dimensions: 1536);
```

This creates the same `embedding` / `embedding_norm` / `embedding_hash` (and, when clustering-dimension reduction is enabled, `embedding_small*`) columns vector-lite has always used - `dimensions` just sizes the binary column. `->index()` isn't chainable off of it (SQLite has no vector-index concept; the `_hash` column is already indexed for lookups).

### Casting

```php
use ThaKladd\VectorLite\Casts\AsVector;

protected $casts = [
    'embedding' => AsVector::class, // Laravel 11+: casts(): array { return ['embedding' => AsVector::class]; }
];
```

The SQLite equivalent of the AI SDK's `'embedding' => 'array'` cast: get unpacks the stored binary vector back to a float array, set normalizes and packs it (plus writes the `_norm`/`_hash` companions, and the clustering `_small` trio when applicable).

### One nameable vector column per model

```php
protected static ?string $vectorColumnName = 'embedding';
```

Overrides the app-wide `vector-lite.vector_column` config for that model only. Pair a non-default name with the `AsVector` cast above rather than the legacy `$model->vector = [...]` mutator, which is always keyed to the literal attribute name `vector`.

### Query methods

On SQLite, `VectorLiteQueryBuilder` adds the same method names as the AI SDK:

```php
Document::query()->whereVectorSimilarTo('embedding', $queryEmbedding, minSimilarity: 0.4);
Document::query()->whereVectorSimilarTo('embedding', 'best wineries in Napa Valley'); // auto-embeds the string

Document::query()
    ->select('*') // selectVectorDistance() is additive, like the AI SDK's - select('*') first to keep other columns
    ->selectVectorDistance('embedding', $queryEmbedding, as: 'distance')
    ->whereVectorDistanceLessThan('embedding', $queryEmbedding, maxDistance: 0.3)
    ->orderByVectorDistance('embedding', $queryEmbedding);
```

Two differences from the official semantics to be aware of:

-   vector-lite only computes **cosine distance** (`1 - cosine similarity`). pgvector can also do L2/Euclidean or inner-product distance depending on the index type - there's no equivalent here.
-   A **string** argument to these four methods is treated as natural-language text to embed (matching the AI SDK). This differs from the rest of vector-lite's own API (`whereVector()`, `bestByVector()`, etc.), where a string means an already-packed binary vector.

These four methods exist on every `VectorModel` query regardless of database driver, but only actually **work** on SQLite - `COSIM`/`COSIM_CACHE` are only registered as SQLite functions, so calling `->get()` (or any other execution) on MySQL or Postgres-without-`laravel/ai` raises a query error rather than a "method not found" error. On those drivers, use the [collection methods](#provided-by-trait) instead.

### Embeddings & caching

```php
use ThaKladd\VectorLite\Embeddings;
use Illuminate\Support\Str;

$response = Embeddings::for(['Napa Valley has great wine.'])->cache(seconds: 3600)->generate();
$response->embeddings; // [[0.123, 0.456, ...]]

$embedding = Str::of('Napa Valley has great wine.')->toEmbeddings(cache: true);
```

Backed by vector-lite's own `EmbeddingService` (OpenAI), not `laravel/ai`'s multi-provider client. Caching is configured under `vector-lite.caching.embeddings` in the config file (`cache`, `store`, `ttl`), mirroring the shape of `config('ai.caching.embeddings')`.

### Reranking

```php
use ThaKladd\VectorLite\Reranking;

$response = Reranking::of([$textA, $textB])->rerank('PHP frameworks');
$response->first()->document;
$response->first()->score;
$response->first()->index;

// Or on any collection:
$posts->rerank('body', 'Laravel tutorials');
```

vector-lite has no dedicated reranker model (the AI SDK uses Cohere/Jina/VoyageAI). This scores documents by cosine similarity between embeddings of the query and each document - a reasonable standalone approximation, but not a cross-encoder reranker, so scores won't match a real reranker numerically.

### SimilaritySearch

```php
use ThaKladd\VectorLite\Tools\SimilaritySearch;

$tool = SimilaritySearch::usingModel(Document::class, 'embedding', minSimilarity: 0.7, limit: 10);
$tool('best wineries in Napa Valley');
```

A plain, directly-callable class under vector-lite's own namespace - not a drop-in for `laravel/ai`'s agent `tools()` contract, since that's only meaningful if that package is installed.

## Testing & Fixing

For running the PHPUnit tests:

```bash
ddev composer test
```

For fixing the code style with Pint:

```bash
ddev exec vendor/bin/pint
```

For using PHPStan:

```bash
ddev exec vendor/bin/phpstan
```

## Comparison with other vector databases

**OBS!** The numbers here are from an early proof of concept version, and are inaccurate. I include them here just in order to set an expectation of what it can do. The real numbers should be better than this.

Using a solution like this is in theory much slower, especially on big sets of vectors, but if done correctly, it can be fast enough for your needs. I benchmarked and tested with Pinecone, and the results are interesting.

-   Pinecone has a near O(1) search time, so it is much faster when vectors grow over about 800, but you need to connect to their api and pay for the service.
-   VectorLite becomes slower as the amount of vectors grown, but faster if you stay below 800 vectors - and it should work well with most projects where you limit the amount within a query anyway.
-   Adding clustering can speed up the search time drastically and keep up to par with Pinecone up to about 20000 vectors.
-   There is a network overhead for Pinecone that does not exist for VectorLite.
-   With a few tricks, VectorLite improved speeds to be 1/4 of the speeds from the original article before applying clustering.

Numbers in seconds for search time, with vector size of 1536 and when clustered, then cluster size on 500:

| Vectors | Pinecone | VectorLite | VectorLite w/cache | VectorLite w/cluster | VectorLite w/cluster&cache |
| ------- | -------- | ---------- | ------------------ | -------------------- | -------------------------- |
| 100     | 0.0689   | 0.0246     | 0.0087             | **0.0074**           | 0.0075                     |
| 1000    | 0.0676   | 0.0833     | 0.0929             | 0.015                | **0.0022**                 |
| 10000   | 0.0686   | 0.9353     | 0.7834             | 0.0332               | **0.0251**                 |
| 100000  | 0.0751   | 8.3062     | 9.8218             | N/A                  | N/A                        |

N/A means that I did not test it because the insertion of the vectors took too long. Because of clustering every single model with a vector needs to be saved alone, and not in a batch.

Note on cache: If I ran the same queries twice, where I do 1000 queries - the second round will take 0.004 seconds instead of 0.0929. So the idea with cache is if you do many of the same query on the same session.

### Insert speed

When using clusters, inserting 100 is quite quick (0.0719 seconds). Inserting 1000 is still ok (0.6752 seconds), but when inserting 10000 time begins to slow down drastically (39.5224 seconds initially - but latest version managed to do this in around 10 seconds).

This is because in order to trigger the cluster algorithm, the object needs to be created with Laravel amd cannot be done in batches.

## FFI dot product (experimental)

On SQLite, `COSIM`/`COSIM_CACHE` normally compute the dot product by unpacking each vector into a PHP array and summing products in a PHP loop. As an alternative, vector-lite ships a small C dot product function (`resources/c/dot_product.c`), prebuilt for **Linux (x86_64/aarch64)** and **Windows (x86_64)** under `resources/c/lib/`, that it can call into via PHP's [FFI extension](https://www.php.net/manual/en/book.ffi.php) instead - the packed vector bytes are passed straight through, with no unpacking into PHP arrays. On those platforms there is nothing to compile; just enable it.

```
VECTOR_LITE_FFI_ENABLED=true
```

Requirements/notes:

-   The `ffi` PHP extension must be loaded, with `ffi.enable=true` in `php.ini` (it defaults to preload-only, which blocks runtime `FFI::cdef()` calls).
-   If the extension is missing, `ffi.enable` blocks it, or no library is available for the platform, vector-lite silently falls back to the PHP implementation - nothing needs to change in your code either way.
-   **macOS has no prebuilt binary yet.** Compile `resources/c/dot_product.c` yourself (`gcc -O3 -shared -fPIC -o dot_product.dylib resources/c/dot_product.c`) and point `VECTOR_LITE_FFI_LIBRARY_PATH` at the result to use it there.
-   `VECTOR_LITE_FFI_LIBRARY_PATH` also lets you override the shipped binary on any platform with your own build.
-   This only changes how the dot product itself is computed; clustering, caching, and every other behavior stays the same.
-   Whether this is actually faster depends on your PHP build and vector sizes - it trades away the PHP-array cache (`use_cached_cosim`) memoization for a tighter C loop, so measure before turning it on for a big dataset.

## Support us

The package was made by using the package-skeleton-laravel by [Spatie](https://spatie.be/github-ad-click/vector-lite) - So go and support them.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [John Larsen](https://github.com/ThaKladd)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
