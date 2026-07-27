/*
 * Optional FFI dot product for vector-lite.
 *
 * VectorLite::cosineSimilarityBinary()/cosineSimilarityBinaryCache() call
 * into this instead of unpacking vectors into PHP arrays, when
 * "vector-lite.ffi.enabled" is true and "vector-lite.ffi.library_path"
 * points at the compiled result of this file.
 *
 * Both vectors are the raw bytes of a PHP pack('f*', ...) call (little-endian
 * 32-bit floats), so they're simply reinterpreted as float arrays here - no
 * copying or parsing needed.
 *
 * Prebuilt copies of this file live under resources/c/lib/{platform}-{arch}/
 * for Linux (x86_64/aarch64) and Windows (x86_64) and are picked up
 * automatically - see VectorLiteFfi::defaultLibraryPath(). It calls no libc
 * functions, so a build is portable across glibc/musl without any
 * relinking. Only rebuild this if you're targeting a platform with no
 * prebuilt copy (e.g. macOS) or want your own optimization flags.
 *
 * Build (Linux/macOS):
 *   gcc -O3 -shared -fPIC -o dot_product.so dot_product.c   # .dylib on macOS
 *
 * Build (Windows, MinGW-w64):
 *   gcc -O3 -shared -o dot_product.dll dot_product.c
 */

#if defined(_WIN32) || defined(__CYGWIN__)
#define VECTOR_LITE_EXPORT __declspec(dllexport)
#else
#define VECTOR_LITE_EXPORT
#endif

VECTOR_LITE_EXPORT float dot_product(const char *vector_a, const char *vector_b, int dimension)
{
    const float *a = (const float *) vector_a;
    const float *b = (const float *) vector_b;
    float dot = 0.0f;
    int i;

    for (i = 0; i < dimension; i++) {
        dot += a[i] * b[i];
    }

    return dot;
}
