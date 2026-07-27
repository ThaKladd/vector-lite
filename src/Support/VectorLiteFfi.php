<?php

namespace ThaKladd\VectorLite\Support;

use RuntimeException;
use Throwable;

/**
 * Optional FFI-backed dot product, used by VectorLite::cosineSimilarityBinary()
 * and VectorLite::cosineSimilarityBinaryCache() instead of unpacking vectors
 * into PHP arrays and looping in userland. Off by default; turn it on with
 * "vector-lite.ffi.enabled" (see the config file / README).
 *
 * vector-lite ships prebuilt libraries (built from resources/c/dot_product.c)
 * for Linux x86_64/aarch64 and Windows x86_64 under resources/c/lib/ - these
 * are picked automatically for the running OS/architecture, so nothing needs
 * to be compiled to use this on those platforms. "library_path" only needs
 * to be set to override that (e.g. a custom build, or an unsupported
 * platform such as macOS).
 *
 * Availability is re-resolved lazily (and cached) rather than piggybacking on
 * VectorLite's own config caching, so tests can toggle it via reset().
 */
class VectorLiteFfi
{
    private const CDEF = 'float dot_product(const char *vector_a, const char *vector_b, int dimension);';

    private static ?\FFI $ffi = null;

    private static ?bool $available = null;

    /**
     * Whether the ffi extension is loaded, the config is enabled, and a
     * library (configured or shipped for this platform) could be loaded.
     * Result is memoized until reset().
     */
    public static function isAvailable(): bool
    {
        return self::$available ??= self::resolveAvailability();
    }

    /**
     * The prebuilt library shipped for the running OS/architecture, if any.
     * Covers Linux (x86_64/aarch64 - the compiled function calls no libc
     * functions at all, so it runs the same under glibc or musl) and
     * Windows x86_64. macOS has no shipped binary yet: compile
     * resources/c/dot_product.c yourself and set
     * "vector-lite.ffi.library_path" to use it there.
     */
    public static function defaultLibraryPath(): ?string
    {
        $platform = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Linux' => 'linux',
            default => null,
        };

        $arch = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => null,
        };

        if ($platform === null || $arch === null) {
            return null;
        }

        $extension = $platform === 'windows' ? 'dll' : 'so';
        $path = dirname(__DIR__, 2)."/resources/c/lib/{$platform}-{$arch}/dot_product.{$extension}";

        return is_file($path) ? $path : null;
    }

    /**
     * Dot product of two equal-length, packed ('f*') float vectors, computed
     * in C via the loaded library. Only call this when isAvailable() is true.
     */
    public static function dotProduct(string $binaryVectorA, string $binaryVectorB): float
    {
        if (! self::isAvailable() || ! self::$ffi) {
            throw new RuntimeException('VectorLite: FFI dot product is not available. Check the "vector-lite.ffi" config and that the "ffi" extension plus the compiled library are present.');
        }

        $dimension = intdiv(strlen($binaryVectorA), 4);

        // dot_product() is defined by the CDEF string at runtime, not on the FFI class itself.
        // @phpstan-ignore-next-line method.notFound
        return (float) self::$ffi->dot_product($binaryVectorA, $binaryVectorB, $dimension);
    }

    /**
     * Forget the memoized availability/instance, e.g. between tests that
     * toggle "vector-lite.ffi.*" config values.
     */
    public static function reset(): void
    {
        self::$ffi = null;
        self::$available = null;
    }

    private static function resolveAvailability(): bool
    {
        if (! config('vector-lite.ffi.enabled', false)) {
            return false;
        }

        if (! extension_loaded('ffi')) {
            return false;
        }

        $libraryPath = config('vector-lite.ffi.library_path') ?: self::defaultLibraryPath();

        if (empty($libraryPath) || ! is_file($libraryPath)) {
            return false;
        }

        try {
            self::$ffi = \FFI::cdef(self::CDEF, $libraryPath);
        } catch (Throwable) {
            self::$ffi = null;

            return false;
        }

        return true;
    }
}
