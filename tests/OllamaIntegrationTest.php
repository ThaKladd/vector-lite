<?php

use Illuminate\Support\Facades\Http;
use ThaKladd\VectorLite\Embeddings;
use ThaKladd\VectorLite\Reranking;
use ThaKladd\VectorLite\Tests\Models\Vector;

/**
 * Runs against a real Ollama server, and only when one is configured:
 *
 *   VECTOR_LITE_TEST_OLLAMA_URL=http://localhost:11434/v1/ vendor/bin/pest tests/OllamaIntegrationTest.php
 *
 * Optional: VECTOR_LITE_TEST_OLLAMA_MODEL (default qwen3-embedding:0.6b) and
 * VECTOR_LITE_TEST_OLLAMA_DIMENSIONS (default 256 - Qwen3-Embedding can
 * shorten its 1024-dimensional output down to 32).
 */
function ollamaTestSetting(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    return $value !== false && $value !== '' ? $value : ($_ENV[$name] ?? $default);
}

beforeEach(function () {
    $url = ollamaTestSetting('VECTOR_LITE_TEST_OLLAMA_URL');
    if (! $url) {
        $this->markTestSkipped('Set VECTOR_LITE_TEST_OLLAMA_URL (e.g. http://localhost:11434/v1/) to run against a real Ollama server.');
    }

    Http::preventStrayRequests(false);

    config()->set('vector-lite.default_dimensions', (int) ollamaTestSetting('VECTOR_LITE_TEST_OLLAMA_DIMENSIONS', '256'));
    config()->set('vector-lite.embeddings.url', $url);
    config()->set('vector-lite.embeddings.api_key', null);
    config()->set('vector-lite.embeddings.model', ollamaTestSetting('VECTOR_LITE_TEST_OLLAMA_MODEL', 'qwen3-embedding:0.6b'));
    config()->set('vector-lite.embeddings.timeout', 120);
    config()->set(
        'vector-lite.embeddings.query_prefix',
        "Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:"
    );
});

it('embeds at the configured dimensions through a real Ollama server', function () {
    $dimensions = config('vector-lite.default_dimensions');

    $response = Embeddings::for(['Paris is the capital of France.', 'Oslo er hovedstaden i Norge.'])->generate();

    expect($response->embeddings)->toHaveCount(2);
    expect($response->embeddings[0])->toHaveCount($dimensions);
    expect($response->embeddings[1])->toHaveCount($dimensions);
});

it('ranks the matching passage first with query/document prompts against a real Ollama server', function () {
    $results = Reranking::of([
        'The cat slept on the warm windowsill all afternoon.',
        'Paris is the capital and largest city of France.',
        'Photosynthesis turns light into chemical energy.',
    ])->rerank('What is the capital of France?');

    expect($results->first()->document)->toBe('Paris is the capital and largest city of France.');
});

it('stores and clusters a model embedded by a real Ollama server', function () {
    $vector = Vector::create(['title' => 'Paris is the capital of France.']);
    $vector->refresh();

    expect(strlen($vector->vector))->toBe(config('vector-lite.default_dimensions') * 4);
    expect($vector->hasStaleEmbedding())->toBeTrue(); // the id was unknown while creating; see VectorModel::getEmbeddingText()
    expect($vector->vector_cluster_id)->not->toBeNull();
});
