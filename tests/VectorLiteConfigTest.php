<?php

use ThaKladd\VectorLite\Support\VectorLiteConfig;

it('resolves the test environment config values as singleton readonly properties', function () {
    VectorLiteConfig::reset();
    $config = VectorLiteConfig::getInstance();

    expect($config->isSqlLite)->toBeTrue();
    expect($config->defaultDimensions)->toBe(36);
    expect($config->clusteringDimensions)->toBe(6);
    expect($config->maxClusterSize)->toBe(10);
    expect($config->vectorColumn)->toBe('vector');
    expect($config->similarityAlias)->toBe('similarity');
    expect($config->embedHashColumn)->toBe('embed_hash');

    expect(VectorLiteConfig::getInstance())->toBe($config);
});

it('creates a new instance after reset()', function () {
    VectorLiteConfig::reset();
    $first = VectorLiteConfig::getInstance();

    VectorLiteConfig::reset();
    $second = VectorLiteConfig::getInstance();

    expect($second)->not->toBe($first);
});
