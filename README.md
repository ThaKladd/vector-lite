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

-   PHP 8.4+
-   Laravel 12.0+

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

## Methods

There is a new attribute you can add to your model `protected $embedFields = ['title', 'other.description'];` that is used to define what fields the data of the vector consists of.

### Provided by trait

The HasVector trait adds both methods and some other functionality to your model regarding the vector and clustering.

Note: The model may be slower to save, as it needs to get the embedding and calculate the cluster for the vector. But don't worry, it will only recalculate cluster when the vector is changed.

The most important is the setting of the vector attribute. Whatever your columns are named, user the `$model->vector` to set a vector created by an embedding ai model. If you have set up `$embedFields` and a openai key, this should work automatically and there is no need to set the vector. If a similarity is calculated, you can access it with `$model->similarity`.

There is also a method called `$model->createEmbedding($text, 1536)` for creating the embedding.

In addition, if you have made a cluster then you get it with the `$model->cluster` relationship, unless you make your own. Also, in order to find the best clusters `$model->getBestClusters(3)`.

On the model you also have two methods to get matching vectors `$model->findBestByVector()` and `$model->searchBestByVector(3)`.

#### Scopes

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

Similarly, the Vector models when fetched, go into a collection, and to it there are added a few methods as well.

```php
$all = YourModel::all();
$all->searchBestByVector($vector, 3);
$all->sortBySimilarityToVector($vector, 'asc');
$all->filterAboveSimilarityThreshold($vector, 0.5);
$all->pluckSimilarities($vector);
$all->withSimilarities($vector);
$all->findBestByVector($vector);
```

### Provided by class

The VectorLite class provides useful methods for calculating dot products and normalizing vectors etc.
All of them are used within VectorLite but is also available to use.

```php
use ThaKladd\VectorLite\VectorLite;
```

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
