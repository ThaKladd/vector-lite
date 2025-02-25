# Adding vector capabilities to your existing SQLite database.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/thakladd/vector-lite.svg?style=flat-square)](https://packagist.org/packages/thakladd/vector-lite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/thakladd/vector-lite/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/thakladd/vector-lite/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/thakladd/vector-lite/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/thakladd/vector-lite/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/thakladd/vector-lite.svg?style=flat-square)](https://packagist.org/packages/thakladd/vector-lite)

Sometimes you don't need a specialized vector database, but just want something fast and simple even though it may be a bit slower that works together with your existing SQLite database.

This package adds the possibility to store vectors into your existing SQLite database together with the SQLite method to figure out the most similar vector by cosine similarity dot product calculations.

It has also added support for clustering the vectors, so that you can group similar vectors together to make it faster to find the most similar vectors.

The idea was heavily inspired by an article by [Andreas Gohr](https://www.splitbrain.org/blog/2023-08/15-using_sqlite_as_vector_store_in_php)

The package has tried to improve the speeds drastically from what the article suggested by a few methods of normalizing, binary packing, caching, and optional clustering. 

### What can it do?
In case you are not that familiar with vectors, cosine similarity, and clustering, I will give you a simple use case. 

With vectors, you can build a RAG (Retrieval Augmented Generation) system for your chatbot, where you can feed the vectorized embedding to your vector database and get back the most similar chunks of data to then give as context in the LLM prompt.

What the vector search does is a cosine similarity matching, matching one vector with the ones in your database, giving you the ability to finding similar data to the one you are searching for. So: Simple search, similar products etc.

What you need to do, is to create a vector from your data, and then store it in the database. Then you can search for similar vectors in the database with any other vectorized string you have.

Normally you would need to connect to a vector database in order to store the vectors, but with this package you can store the vectors in your existing SQLite database.

To be fair, there is libSQL that can do this, but it is a bit more complex to use as you need to replace your current database with it. For postgres you need to install pgvector, and for MySQL you need wait for the support to come. 

Using a dedicated vector database like Pinecone and Milvus adds the extra latency of the network, and you need to connect to them via their api's giving distance between your database and your vectors.

With VectorLite you can get the power of vectors within your existing SQLite database and use it within your SQL queries.

## Support us

The package was made by using the package-skeleton-laravel by [Spatie](https://spatie.be/github-ad-click/vector-lite) - So go and support them.

## Installation

You can install the package via composer:

```bash
composer require thakladd/vector-lite
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="vector-lite-config"
```

This is the contents of the published config file:

```php
return [
];
```

## Usage

```php
$vectorLite = new ThaKladd\VectorLite();
echo $vectorLite->echoPhrase('Hello, ThaKladd!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [John Larsen](https://github.com/ThaKladd)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
