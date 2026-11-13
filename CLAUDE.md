# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel package (`thakladd/vector-lite`) that adds vector storage and cosine-similarity search to an existing SQLite database, with optional clustering to keep large vector sets fast. Non-SQLite databases (MySQL, etc.) are also supported but fall back to a slower, pure-PHP path instead of SQL. Requires PHP 8.3/8.4 + Laravel 10-13. The idea originates from a blog post by Andreas Gohr (linked in README.md); do not use in production until v1.0 per README warning.

It also ships a standalone mirror of Laravel 13's AI SDK vector API (`laravel/ai`, Postgres+pgvector only, a separate opt-in package) so the same method names/migration syntax work on SQLite with or without that package installed — see "AI SDK compatibility layer" below.

## Commands

```bash
composer test              # vendor/bin/pest - run the full test suite
composer test-coverage     # pest --coverage
composer analyse           # vendor/bin/phpstan analyse (level 5, see phpstan.neon.dist)
composer format             # vendor/bin/pint - fix code style
```

Under DDEV (used by the maintainer, see CONTRIBUTING.md), prefix with `ddev`:

```bash
ddev composer test
ddev exec vendor/bin/phpstan
ddev exec vendor/bin/pint
```

Run a single test with Pest by filtering on filename or test description:

```bash
vendor/bin/pest tests/VectorLiteTest.php
vendor/bin/pest --filter="some test name"
```

CI (`.github/workflows/run-tests.yml`) runs `vendor/bin/pest --ci` across the Laravel 10/11/12/13 matrix. A PR must pass tests, PHPStan, and Pint (see CONTRIBUTING.md).

## Architecture

### Two execution paths: SQLite vs. other drivers

Almost everything branches on `VectorLiteConfig::getInstance()->isSqlLite` (`src/Support/VectorLiteConfig.php`, a request-scoped singleton wrapping the `vector-lite` config plus the live DB driver check):

- **SQLite path** — cosine similarity is computed *in SQL* via two custom SQLite functions registered in `VectorLiteServiceProvider::boot()`: `COSIM` (plain) and `COSIM_CACHE` (memoized on `VectorLite::$dot`/`$cache`), both backed by static methods on `VectorLite`. `VectorLiteQueryBuilder` builds raw SQL calling these functions (`whereVector`, `orderBySimilarity`, `bestByVector`, `havingSimilarity`, `filterByClosestClusters`, etc.). Clustering lookups also happen in SQL.
- **Other drivers path** — no raw SQL / custom functions available, so cluster bookkeeping is kept in memory via `Support/ClusterCache` (one instance per cluster model class, loaded via `fetchAllFromDb()`, mutated with `increment`/`decrement`, and flushed with `persist()`) instead of raw `UPDATE`/`CASE` SQL, and model-level convenience methods (`getBestVectorMatches`, etc.) route similarity search through `VectorModelCollection` methods (`searchBestByVector`, `sortBySimilarityToVector`, `filterAboveSimilarityThreshold`, ...), which compute cosine similarity per-model in PHP via `VectorLite::cosineSimilarityModelAndVector(Cache)`.

Note: `HasVector::newEloquentBuilder()` itself branches on `isSqlLite` (plain `Builder` on non-SQLite) — but `VectorModel` declares its own `newEloquentBuilder()` that *always* returns `VectorLiteQueryBuilder`, which as a class method takes precedence over the trait's version. So for actual `VectorModel` subclasses (the only supported way to use this package), `VectorLiteQueryBuilder` is used on every driver; its raw-SQL methods just fail at *execution* time on non-SQLite connections (unknown function), not at call time. `$model->vectorQuery()` is a separate, explicit convenience that adds its own upfront `RuntimeException` guard for fail-fast callers — plain `$model::query()` doesn't have that guard.

When changing clustering or query behavior, check both paths — logic is frequently duplicated with an `if (self::$isSqlLite) { ... } else { ... }` split (see `VectorLite::processCreated`, `findBestCluster`, `HasVector::getBestVectorMatches`).

### Model layer

- Consumers extend `ThaKladd\VectorLite\Models\VectorModel` (abstract, implements `Contracts\HasVectorType`) instead of Eloquent's `Model`. It pulls in the `Traits\HasVector` trait, which is where almost all behavior lives (model boot hooks, vector accessor/mutator, cluster relationships, search methods).
- `VectorModel::mergeFillableFromVectorColumn()` (hooked on `retrieved`/`creating`) auto-appends the vector/`_norm`/`_hash` (and `_small*` variants) column names to `$fillable` when the model uses `$fillable` at all, so consumers don't need to list vector columns themselves. Models using `$guarded = []` (as in tests) are unaffected.
- `HasVector` wires model events: `creating`/`saving` strip the computed `similarity` pseudo-attribute and, if `$embedFields` is set, call `createAndFillEmbedding()`; `created` triggers cluster assignment (`VectorLite::processCreated`) if a `*_clusters` table exists and a vector is set; `saved` re-clusters if the vector column was dirty (`processUpdated`); `deleted` decrements the old cluster (`processDelete`).
- Setting `$model->vector = [0.1, 0.2, ...]` (`HasVector::setVectorAttribute`) normalizes the array, packs it to a binary float blob, hashes it, and stores `vector`/`vector_norm`/`vector_hash`. If clustering-dimension reduction is enabled it also derives and stores the smaller `vector_small`/`_small_norm`/`_small_hash` trio via the configured `ReduceBy` method — this is the vector actually used when matching against a cluster. This mutator is always keyed to the literal attribute name `vector`, regardless of `vectorColumn()`; a model that overrides its vector column name (see below) should use the `AsVector` cast instead, which performs the same normalize/pack/hash work but is keyed to whatever column name it's declared against.
- `HasVector::vectorColumn()` (and `embedHashColumn()`/`similarityAlias()`) default to the app-wide `config('vector-lite.vector_column')`, but a model can override just the column name via `protected static ?string $vectorColumnName = 'embedding';` (declared on the `HasVector` trait) — this is a per-model override, not full multi-column support: clustering, `embed_hash` tracking, and the cluster table name are still one-per-model.
- Clusters are a separate, parallel `VectorModel` per model (naming convention: `{Model}Cluster`, e.g. `Vector` -> `VectorCluster`; see `HasVector::getClusterClass()`/`isCluster()`, based on the `Cluster` class-name suffix, not a column/flag). Cluster tables follow `{model_table_singular}_clusters` (e.g. `vectors` -> `vector_clusters`), and the base model table gets `{cluster_table_singular}_id` and `{cluster_table_singular}_match` columns pointing at the assigned cluster and the similarity score at assignment time.

### Clustering algorithm (`src/VectorLite.php`)

`VectorLite` is constructed per-model (`new VectorLite($model)`) to resolve all the naming (`clusterTable`, `clusterForeignKey`, `matchColumn`, `countColumn`, etc.) and holds the actual assignment algorithm:

- `processCreated`: finds the best-matching existing cluster by cosine similarity (`findBestCluster`); if found, assigns the model to it and, if the cluster is now at/over `clusters_size`, calls `handleFullCluster`; if no cluster exists yet, creates one.
- `handleFullCluster`: when a cluster overflows, evicts its currently least-similar member into a brand-new cluster, then re-checks every remaining member against all clusters, reassigning any that now fit better elsewhere — this is how clusters stay locally optimal without a full re-cluster.
- `processUpdated`/`processDelete`: decrement the old cluster's count (and, when re-assigning, `processUpdated` delegates back into `processCreated` — after clearing the FK/match on the in-memory model too, since `processCreated` returns early for a model that still has a cluster).
- `rebuildClusters()` drops and rebuilds all of a model's clusters (used after a model switch); `flushMemoryCache()` clears the static `$cache`/`$dot` memoization between chunks of bulk jobs; `reset()` also forgets the lazy `init()` config snapshot (tests call it in `setUp()`).
- **Dimension guard.** `COSIM`, `COSIM_CACHE` and every PHP `cosineSimilarity*` variant return `VectorLite::NON_MATCHING_SIMILARITY` (`-1.0`, same as `VectorModelCollection`'s score for a model without a vector) for vectors of different lengths or a missing one, on both the SQLite and the PHP path — the FFI path would otherwise read past the shorter buffer. `processCreated()` starts a new cluster instead of joining a best cluster that only scored `-1`.
- Cluster member-count updates are batched: within a `DB::transaction`, count deltas are collected in `VectorLite::$clusterCache` and flushed once (a single `CASE`-based `UPDATE` on SQLite, or `ClusterCache::increment`/`persist()` otherwise) rather than issued per-model.
- `VectorLite` also owns the low-level vector math used everywhere else: `normalize`/`denormalize`, binary pack/unpack (`normalToBinary`/`binaryVectorToArray`), hashing (`hashVectorBlob`/`hashText`), and the several `cosineSimilarity*` variants (binary vs. array input, cached vs. uncached, model-vs-model vs. model-vs-raw-vector). New similarity call sites should reuse one of these rather than re-deriving dot products.

### Dimension reduction for clustering (`Enums/ReduceBy`)

When `use_clustering_dimensions` is enabled, full vectors (e.g. 1536-dim OpenAI embeddings) are reduced to a smaller `clustering_dimensions` size for cluster matching only, per `reduction_method`:

- `RPM` (default): random projection matrix, cached per `(inputDim, outputDim)` pair as JSON under `storage/app/random_project_{in}_{out}.json` so the same projection is reused across inserts.
- `CHUNKED_AGGREGATION`: mean of contiguous chunks.
- `NEW_EMBEDDING`: calls back into `EmbeddingService` for a second, smaller *document* embedding through the configured endpoint (noted in code as not fully worked out when a long vector exists but no short one yet; `setVectorAttribute()` passes it the vector array, not the text, so it can't run from the mutator today).

The full vector is always stored; the reduced vector only affects which cluster a model is matched into, not the base model's own vector data. RPM matrices are keyed by (input, output) size — a new embedding length gets its own matrix, and a cached file of the wrong shape is regenerated — and memoized in-process (a `static` inside `generateRandomMatrix()`, since enums can't hold properties). Note that reduced vectors from *different* models have the same length, so the cosine dimension guard (below) can't tell them apart: after a model switch the clusters must be rebuilt (`vector-lite:reembed` does this).

### Embeddings (`Services/EmbeddingService`, `Support/EmbeddingConfig`, `VectorModel::getEmbeddingText`)

- `$embedFields` on a model (dot-notation, can traverse relations and zero-arg methods, e.g. `['title', 'other.description', 'customMethod']`) drives `getEmbeddingText()`, which serializes the referenced values into a pseudo-XML string wrapped in a tag named after the model (see `tests/Models/Vector.php` for a live example including a relation and a method source). The root tag carries `id="{key}"`, which is empty while a model is being created — so a freshly created row is stale (`hasStaleEmbedding()`) and is embedded once more on its next save. Pre-existing; changing the text would change every stored hash.
- **One code path.** `EmbeddingService` (bound fresh per `app()` resolve in `VectorLiteServiceProvider::register()`) is the only thing that talks to an embedding server: one OpenAI-compatible `POST {url}/embeddings` via Laravel's `Http` client (so tests use `Http::fake()`). Its five callers: `VectorModel::createEmbedding()`/`createAndFillEmbedding()` and `ReduceBy::createNewEmbedding()` (documents), `VectorLiteQueryBuilder::resolveEmbeddingInput()` (queries), `Reranking::rerank()` (first input query, the rest documents, one batch), `Embeddings::generate()` (documents unless `->asQuery()`). Don't add another HTTP path.
- `Support/EmbeddingConfig` is the resolved `vector-lite.embeddings` block (url, api_key, model, dimensions = `default_dimensions`, timeout, send_dimensions, query/document prefixes). `resolve($provider)` also handles named `providers` entries (built-in `openai`), which inherit everything except the API key unless they share the main endpoint's host. The legacy `vector-lite.openai.api_key` is a fallback **only** for `api.openai.com`. `isConfigured()` is false only for OpenAI without a key — local endpoints need none.
- `createEmbeddings($texts, $dimensions, EmbeddingInputType|array $types)` prefixes each text by its type, validates one vector per input, each exactly `$dimensions` long (throws `EmbeddingException::dimensionMismatch`), reorders by `index`, casts to floats, and wraps every HTTP/connection/response failure in `Exceptions\EmbeddingException`.
- **embed_hash identity.** `EmbeddingConfig::embedHash($text)` = `xxh3($text)` for the legacy identity (OpenAI `text-embedding-3-small`, 1536, no document prefix — byte-identical to pre-0.5 hashes) and `xxh3(json_encode(['model','dimensions','document_prefix']) . "\n" . $text)` otherwise. URL and query prefix are deliberately excluded. Changing model/dimensions/document prefix therefore stales every row. `Embeddings`' cache key uses the same identity plus input type and that type's prefix (`EmbeddingConfig::cacheKey()`).
- `createAndFillEmbedding()` compares that hash to the stored `embed_hash` and only embeds on a mismatch. It writes the vector and hash together (`fillEmbedding()`) **only after a valid embedding**; no provider/no text (`createEmbedding()` returns `null`) or an exception leaves both untouched, so the row stays stale and is retried. The `saving` hook in `HasVector` applies `vector-lite.embeddings.on_error`: `throw` (default) rethrows, `skip` `report()`s and saves without the new vector.
- `vector-lite:reembed {model} --chunk --only-stale --keep-clusters` (`Commands/VectorLiteReembedCommand`) batches stale/all rows per chunk into one request, writes them with `saveQuietly()` and timestamps off, then calls `VectorLite::rebuildClusters()` (null all FKs, delete clusters, re-run `processCreated()` per row) — or, with `--keep-clusters`, `processUpdated()` per re-embedded row instead. It aborts on the first `EmbeddingException` (finished chunks stay saved; `--only-stale` resumes).

### Schema helpers

`VectorLiteServiceProvider::boot()` registers `Blueprint` macros used by migrations instead of hand-rolling vector columns: `vectorLite($column, ?dimensions)` (adds `embed_hash` + vector columns), `vectorLiteCluster($column, ?dimensions)` (vector columns only, no `embed_hash`), `vectorLiteColumns($column, ?dimensions)` (the shared binary/`_norm`/`_hash` triple, plus `_small*` variants when clustering-dimension reduction is on), and their `drop*` counterparts. `dimensions` (float count) is converted to a byte length (`dimensions * 4`, since vectors are packed as 4-byte floats) for the underlying `binary()` column — this mirrors core's own `$table->vector($column, dimensions: $n)` signature, which throws on SQLite and can't be macro-overridden since it's a real `Blueprint` method. `phpstan/Blueprint.php` is a stub so PHPStan recognizes these macros (referenced from `phpstan.neon.dist`).

`vector-lite:make` and `vector-lite:cluster` (in `src/Commands/`) are interactive Artisan commands that scaffold the migrations/models above for a chosen model; they generate migration stubs at runtime rather than shipping static migration files. `vector-lite:reembed` is the maintenance command described under Embeddings.

### Query builder vs. collection API surface

Two parallel APIs exist for the same operations because SQLite queries can push similarity into SQL while everything else can't:

- `VectorLiteQueryBuilder` (always the builder class for `VectorModel` subclasses, but its raw-SQL methods only work on SQLite — see the execution-paths note above; `$model->vectorQuery()` adds an explicit upfront throw on other drivers): `selectSimilarity`, `whereVector`, `whereVectorBetween`, `havingSimilarity`, `orderBySimilarity`, `bestByVector`/`findBestByVector`/`searchBestByVector`, `filterByClosestClusters`, `withoutModels`/`includeModels`/`orIncludeModels`/`withoutSelf`/`includeSelf`, plus the AI-SDK-compatible `whereVectorSimilarTo`/`selectVectorDistance`/`whereVectorDistanceLessThan`/`orderByVectorDistance` (see below).
- `VectorModelCollection` (any driver, operates on already-loaded models): `searchBestByVector`, `sortBySimilarityToVector`, `filterAboveSimilarityThreshold`, `pluckSimilarities`, `withSimilarities`, `findBestByVector`, `withoutModels`.

Model-level convenience methods (`getBestVectorMatches`, `findBestVectorMatch`, `getBestClusters`, `findBestCluster` in `HasVector`) pick whichever of the two is appropriate based on `isSqlLite`, so they work identically regardless of database.

`Support/SqlValidator` whitelists comparison operators/sort directions before they're interpolated into raw SQL (`whereVector`, `orderBySimilarity`, etc.) — extend this list rather than skipping validation if a new operator is needed.

### Config (`config/vector-lite.php`)

Column base names (`vector_column`, `embed_hash_column`, `similarity_alias`) are configurable and read through static accessors on `HasVector` (`vectorColumn()`, `embedHashColumn()`, `similarityAlias()`) rather than hardcoded — use those accessors, not literal `'vector'`, when touching shared code. Other notable keys: `use_clustering_dimensions`/`clustering_dimensions`/`clusters_size` (clustering behavior), `use_cached_cosim`/`cache_driver`/`cache_time` (the `COSIM_CACHE`/`cosineSimilarity*Cache` memoization), `exclude_self_by_default`.

### AI SDK compatibility layer

A standalone mirror of Laravel 13's `laravel/ai` vector API, added so code written the "official" way works unchanged on SQLite whether or not `laravel/ai` is installed. Everything lives under vector-lite's own namespace on purpose (no `Laravel\Ai\*` FQCN collisions):

- **Query methods** (`src/QueryBuilders/VectorLiteQueryBuilder.php`): `whereVectorSimilarTo`, `selectVectorDistance`, `whereVectorDistanceLessThan`, `orderByVectorDistance` all take an explicit `$column` argument (unlike the rest of this builder's methods, which use the model's single configured `vectorColumn()`) — the column-resolution helpers (`qualifiedVectorColumnName`, `vectorColumnNameWithSmall`, `getVectorHashColumn`, `getCosimMethod`) all accept an optional `?string $column` that bypasses the model's configured column (and its clustering `_small` suffix) when given. Distance is always `1 - cosine similarity` (vector-lite has no L2/inner-product). A **string** argument to these four methods is text to embed (via `resolveEmbeddingInput()` → `EmbeddingService`) — a deliberate difference from the rest of this builder's API, where a string means an already-packed binary vector.
- **Casting** (`src/Casts/AsVector.php`): a `CastsAttributes` implementation usable in `$casts`/`casts()` for any column name, doing the same normalize/pack/hash work as `HasVector::setVectorAttribute()` but keyed to the cast's column instead of the literal `vector` attribute name. Also writes the `_small` trio when applied to a `VectorModel`'s own configured vector column with clustering-dimension reduction on.
- **Embeddings** (`src/Embeddings.php` + `Facades/Embeddings.php`): `Embeddings::for($inputs)->dimensions()->asQuery()->cache()->generate($provider, $model)` and the `Str::toEmbeddings()`/`Stringable::toEmbeddings()` macros (registered in `VectorLiteServiceProvider::registerStrToEmbeddingsMacro()`), backed by the existing `EmbeddingService`. `$provider` resolves a `vector-lite.embeddings.providers` entry (unknown names and failover arrays throw — mixing models is never silent); `$model` overrides the model. Per-input caching (`EmbeddingConfig::cacheKey()`: model+dimensions+input type+prefix+text) is configured under `vector-lite.caching.embeddings`. `Embeddings::for()` is a private-constructor static factory (like `EmbeddingService`'s sibling classes below), so `register()` binds a throwaway instance for the Facade root to call through.
- **Reranking** (`src/Reranking.php` + the `Collection::rerank()` macro in `registerCollectionRerankMacro()`): `Reranking::of($documents)->rerank($query)` scores by cosine similarity between query/document embeddings (there's no dedicated reranker model here; its `$provider` stays ignored, since AI-SDK callers pass reranker services like Cohere there) and returns a `Collection<RerankedDocument>`. The collection macro works on any `Collection` (registering on `Illuminate\Support\Collection` surfaces on `Eloquent\Collection`/`VectorModelCollection` too, since they don't redeclare the `Macroable` trait's static `$macros` property) and returns the original items reordered, not `RerankedDocument` wrappers.
- **SimilaritySearch** (`src/Tools/SimilaritySearch.php`): `SimilaritySearch::usingModel($model, $column, ...)` or `new SimilaritySearch(using: Closure)`, a plain callable class running `whereVectorSimilarTo()` under the hood — not wired into any agent-tool contract, since vector-lite doesn't depend on `laravel/ai`.

None of this touches the clustering algorithm or the core similarity math in `VectorLite`/`VectorLiteQueryBuilder`'s pre-existing methods. One incidental fix made while extending this file: `whereVector()`/`whereVectorBetween()` previously passed their raw `array|string|VectorModel` input straight into `getCosimMethod()`/`preparedBinaryVector()` (both typed `array`) without resolving it first — a `string`/`VectorModel` argument would throw a `TypeError`. They now call `resolveVector()` first, matching the pattern `havingSimilarity()`/`orderBySimilarity()` already used.

## Testing notes

- **No real HTTP.** `TestCase::setUp()` calls `Http::preventStrayRequests()`; fake the embedding endpoint with `VectorTestHelpers::fakeEmbeddingApi()` (random vectors of the requested size) or `fakeEmbeddingVectors([input => vector])` (fixed vectors, keyed by the input *as sent*, prefix included), and inspect requests with `sentEmbeddingInputs()`. `Http::fake()` stubs stack and the first match wins, so a test that needs the endpoint to change behaviour uses one closure with a flag rather than a second `Http::fake()`. The service validates vector length, so fixed fake vectors must match `default_dimensions` (or `->dimensions()`). `tests/OllamaIntegrationTest.php` only runs with `VECTOR_LITE_TEST_OLLAMA_URL` set.
- `TestCase::setUp()` also resets `VectorLiteConfig`, `ClusterCache` and `VectorLite` static state, since tests run in random order. `useMySqlConnection()` lives in `tests/Pest.php`.

- Pest-based (`tests/Pest.php` binds `TestCase` globally). `tests/TestCase.php` rebuilds a fresh in-memory SQLite schema (`others`, `documents`, `vectors`, `vector_clusters`) in `setUpDatabase()` before every test — there are no migration files used in tests, so schema changes for tests are made directly in that method. Orchestra Testbench boots a fresh app (and thus a fresh `:memory:` connection) per test method, so tests don't need to clean up after themselves.
- `tests/Models/Vector.php` / `VectorCluster.php` are the canonical example `VectorModel` subclasses (using `$guarded = []`), including a live `$embedFields` example that mixes a plain attribute, a dot-path through a `BelongsTo` relation, and a zero-arg method. `tests/Models/Document.php` is the AI-SDK-compatibility fixture: `$vectorColumnName = 'embedding'` + `$casts = ['embedding' => AsVector::class]`, with no cluster table sibling (`documents` has no `document_clusters` table), so it's deliberately isolated from the clustering machinery.
- `tests/AiSdkCompatibilityTest.php` covers the AI-SDK-compatible surface (macro reshape, per-model column name, the 4 query methods, `AsVector`, `Embeddings`/`Str::toEmbeddings` caching, `Reranking`, the `rerank()` macro, `SimilaritySearch`), plus a `- mysql` case asserting the 4 query methods raise a `QueryException` at execution time on mysql (the methods exist regardless of driver; `COSIM`/`COSIM_CACHE` don't).
- A real bug was caught by these tests and fixed in `VectorLiteQueryBuilder`: SQLite/PDO binds plain PHP floats such that comparing `COSIM(...)` (numeric storage class) against a bound float parameter (bound as TEXT) always evaluates false, per SQLite's storage-class comparison ordering (numeric always < text) — silently making `whereVector()`, `whereVectorBetween()`, `havingSimilarity()`'s alias branch, and the new `whereVectorSimilarTo`/`whereVectorDistanceLessThan` return empty regardless of threshold. Fixed by wrapping every bound threshold in `CAST(? AS REAL)` (and switching `havingSimilarity`'s alias branch from the fluent `having()` to `havingRaw()`, since `having()` can't express the cast). Integers happened to work, which is why this was never caught before — no prior test exercised these methods against real data.
- Test config shrinks dimensions for speed: `default_dimensions = 36`, `clustering_dimensions = 6`, `clusters_size = 10`.
