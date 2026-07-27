<?php

use ThaKladd\VectorLite\Support\VectorLiteFfi;
use ThaKladd\VectorLite\VectorLite;

/**
 * Enables the shipped FFI library (auto-detected for the running OS/arch -
 * see VectorLiteFfi::defaultLibraryPath()) and skips (rather than fails)
 * when this environment genuinely can't exercise it: no "ffi" extension,
 * an unsupported platform (e.g. macOS has no shipped binary yet), or
 * ffi.enable not set to "true" in php.ini.
 */
function enableVectorLiteFfiOrSkip(): void
{
    if (! extension_loaded('ffi')) {
        test()->markTestSkipped('The "ffi" extension is not loaded.');
    }

    config()->set('vector-lite.ffi.enabled', true);
    config()->set('vector-lite.ffi.library_path', null);
    VectorLiteFfi::reset();

    if (! VectorLiteFfi::isAvailable()) {
        test()->markTestSkipped(
            'FFI is not usable here - no shipped library for '.PHP_OS_FAMILY.'/'.php_uname('m').
            ', or ffi.enable is not "true" in php.ini.'
        );
    }
}

beforeEach(fn () => VectorLiteFfi::reset());

afterEach(fn () => VectorLiteFfi::reset());

it('reports unavailable when the ffi config is disabled', function () {
    config()->set('vector-lite.ffi.enabled', false);

    expect(VectorLiteFfi::isAvailable())->toBeFalse();
});

it('reports unavailable when an explicit library path does not exist', function () {
    config()->set('vector-lite.ffi.enabled', true);
    config()->set('vector-lite.ffi.library_path', '/nonexistent/vector-lite-dot-product.so');

    expect(VectorLiteFfi::isAvailable())->toBeFalse();
});

it('resolves the shipped library for the running platform when one exists', function () {
    $path = VectorLiteFfi::defaultLibraryPath();

    if ($path === null) {
        // No binary shipped for this OS/architecture (e.g. macOS) - nothing to assert.
        expect($path)->toBeNull();

        return;
    }

    expect(is_file($path))->toBeTrue();
    expect($path)->toEndWith(PHP_OS_FAMILY === 'Windows' ? 'dot_product.dll' : 'dot_product.so');
});

it('computes the same dot product as the pure PHP implementation', function () {
    enableVectorLiteFfiOrSkip();

    $vectorA = [0.1, 0.2, 0.3, 0.4, -0.5, 0.6];
    $vectorB = [0.6, -0.1, 0.25, 0.4, 0.15, -0.2];

    $binaryA = pack('f*', ...$vectorA);
    $binaryB = pack('f*', ...$vectorB);

    $expected = VectorLite::dotProduct($vectorA, $vectorB);
    $actual = VectorLiteFfi::dotProduct($binaryA, $binaryB);

    expect(round($actual, 4))->toBe(round($expected, 4));
});

it('routes VectorLite::cosineSimilarityBinary through FFI once enabled, with the same result', function () {
    $vectorA = [0.1, 0.2, 0.3, 0.4, -0.5, 0.6];
    $vectorB = [0.6, -0.1, 0.25, 0.4, 0.15, -0.2];
    $binaryA = pack('f*', ...$vectorA);
    $binaryB = pack('f*', ...$vectorB);

    config()->set('vector-lite.ffi.enabled', false);
    $withoutFfi = VectorLite::cosineSimilarityBinary($binaryA, $binaryB);

    enableVectorLiteFfiOrSkip();
    $withFfi = VectorLite::cosineSimilarityBinary($binaryA, $binaryB);

    expect(round($withFfi, 4))->toBe(round($withoutFfi, 4));
});
