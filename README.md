# Adding vector capabilities to your existing SQLite database.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/thakladd/vector-lite.svg?style=flat-square)](https://packagist.org/packages/thakladd/vector-lite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/thakladd/vector-lite/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/thakladd/vector-lite/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/thakladd/vector-lite/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/thakladd/vector-lite/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/thakladd/vector-lite.svg?style=flat-square)](https://packagist.org/packages/thakladd/vector-lite)

This package adds the possibility to store vectors into your existing SQLite database together with the SQLite method to figure out the most similar vector by cosine similarity dot product calculations.

The idea was heavily inspired by an article by [Andreas Gohr](https://www.splitbrain.org/blog/2023-08/15-using_sqlite_as_vector_store_in_php)

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
