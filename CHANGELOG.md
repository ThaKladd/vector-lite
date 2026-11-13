# Changelog

All notable changes to `vector-lite` will be documented in this file.

## v0.5.0 - Configurable embedding providers (Ollama and other local models), Laravel 13.42 fixes - 2026-11-13

### Breaking changes

- `VectorModel::createEmbedding()` and `Contracts\HasVectorType::createEmbedding()` now return `?array`. They return `null` when there is no embedding text or no provider is configured. Before, that path threw a `TypeError` on every save.
- `EmbeddingService::createEmbedding()`/`createEmbeddings()` now throw `Exceptions\EmbeddingException` on any failure: no provider configured, server unreachable, HTTP error, a malformed response, or vectors of the wrong length. Before, they returned `[]` or let Guzzle exceptions through. They take an optional `EmbeddingInputType` as a third argument, and they now use Laravel's HTTP client, so fake them with `Http::fake()`.
- `Embeddings::for()->generate($provider, $model)` now uses both arguments. An unknown provider name, or a list of providers, throws instead of being ignored.
- `embed_hash` now covers the embedding model, dimensions and document prefix as well as the text. On the old default (OpenAI `text-embedding-3-small`, 1536 dimensions, no prefix) the hash is unchanged, so nothing re-embeds after upgrading. Any other setup, e.g. OpenAI with a custom `default_dimensions`, re-embeds each row once: on its next save, or all at once with `vector-lite:reembed`.
- Cosine similarity (`COSIM`, `COSIM_CACHE` and every PHP `cosineSimilarity*` variant) returns `-1.0` for two vectors of different lengths or a missing vector. Before, it returned a meaningless number or threw. With FFI on, it read past the end of the shorter vector.
- `Support\VectorLiteConfig::$cacheDriver` is now `?string`. An empty `cache_driver` is `null`.

### Upgrading

- If you published `config/vector-lite.php` before 0.5.0, its `'default_dimensions' => 1536` is hard-coded and wins over the new `VECTOR_LITE_DEFAULT_DIMENSIONS`. The `embeddings` block comes from the package defaults unless you publish again.
- `guzzlehttp/guzzle` (`^7.5||^8.0`, whatever your Laravel version allows) is now a declared dependency. It was always needed, but was never listed.

### Added

- A `vector-lite.embeddings` config block (`url`, `api_key`, `model`, `timeout`, `send_dimensions`, `query_prefix`, `document_prefix`, `on_error`, `providers`) with `VECTOR_LITE_EMBEDDINGS_*` env variables, plus `VECTOR_LITE_DEFAULT_DIMENSIONS`. Works with any OpenAI-compatible `/v1/embeddings` endpoint: Ollama, LM Studio, llama.cpp, vLLM, TEI. Local endpoints need no API key. `OPENAI_API_KEY` / `vector-lite.openai.api_key` still work as the fallback key, for OpenAI only.
- Separate query and document embeddings (`Enums\EmbeddingInputType`) with configurable prefixes, for asymmetric retrieval models such as Qwen3-Embedding and EmbeddingGemma 2. Model embeddings and the `new_embedding` reduction are documents, query-builder text searches are queries, and reranking sends both in one request. `Embeddings::for()->asQuery()` marks search text.
- The returned vector length is validated against the expected dimensions, with a clear exception on mismatch.
- `vector-lite.embeddings.on_error`: `throw` (default, as before) or `skip`, which saves the model without a new vector and leaves its `embed_hash` stale so it is retried.
- `php artisan vector-lite:reembed {model} --chunk= --only-stale --keep-clusters`: re-embeds rows in batched requests with a progress bar, then rebuilds the model's clusters.
- `VectorLite::rebuildClusters()`, `VectorModel::hasStaleEmbedding()`, `embeddingHash()`, `fillEmbedding()` and `getEmbedFields()`.
- Docs: "Embedding providers" in the README, covering local models with Ollama, the one-model-per-column rule, switching models and outages.

### Fixed

- Fatal error on Laravel 13.42+: `VectorModelCollection::wrap()` now matches the new `Collection::wrap($value, ...$args)` signature.
- `Cache store [] is not defined` on Laravel 13.42+, from every cached cosine similarity call when `cache_driver` is unset.
- `embed_hash` was written before the embedding was made, so a failed or empty embedding marked the row as up to date and it was never retried. The hash and the vector are now written together, only after a valid embedding.
- A row whose vector changed on update lost its cluster: the old cluster was cleared in the database but not on the model, so re-clustering was skipped.
- A cached RPM projection matrix whose shape doesn't match its dimensions is regenerated instead of used. Matrices are now also kept in memory instead of being re-read from disk for every vector.
- `VectorLiteConfig::reset()` didn't re-read the config.
- PHPStan errors with the latest Larastan.
- CI also runs on pull requests, weekly and on demand, so new upstream releases get tested even without a push. It no longer cancels the other jobs when one fails, adds PHP 8.5 against the newest Laravel 13.x, and can install the end-of-life Laravel 10 releases that Composer now blocks for security advisories.

## Laravel 13 support, FFI option, tests - 2026-07-27

With the help of Claude:

- Added features from the Laravel 13 Postgres Vector approach, including reranking and similarity search.
- Added an option to use FFI calculation of the dot product
- Added more tests
