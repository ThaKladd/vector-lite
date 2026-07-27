<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Schema::dropIfExists('macro_test');
    Schema::dropIfExists('macro_cluster_test');
    Schema::dropIfExists('macro_fixed_test');
});

it('creates vectorLite() columns (including the _small trio) and drops them via dropVectorLite()', function () {
    Schema::create('macro_test', function (Blueprint $table) {
        $table->id();
        $table->vectorLite('embedding', dimensions: 4);
    });

    expect(Schema::hasColumns('macro_test', [
        'embed_hash', 'embedding', 'embedding_norm', 'embedding_hash',
        'embedding_small', 'embedding_small_norm', 'embedding_small_hash',
    ]))->toBeTrue();

    Schema::table('macro_test', function (Blueprint $table) {
        $table->dropVectorLite('embedding');
    });

    expect(Schema::hasColumn('macro_test', 'embedding'))->toBeFalse();
    expect(Schema::hasColumn('macro_test', 'embed_hash'))->toBeFalse();
    expect(Schema::hasColumn('macro_test', 'embedding_small'))->toBeFalse();
});

it('creates vectorLiteCluster() columns without an embed_hash column', function () {
    Schema::create('macro_cluster_test', function (Blueprint $table) {
        $table->id();
        $table->vectorLiteCluster('vector', dimensions: 4);
    });

    expect(Schema::hasColumn('macro_cluster_test', 'embed_hash'))->toBeFalse();
    expect(Schema::hasColumns('macro_cluster_test', [
        'vector', 'vector_norm', 'vector_hash',
        'vector_small', 'vector_small_norm', 'vector_small_hash',
    ]))->toBeTrue();
});

it('adds fixed-length binary vector columns when the fixed flag is used', function () {
    Schema::create('macro_fixed_test', function (Blueprint $table) {
        $table->id();
        $table->vectorLiteColumns('vector', dimensions: 4, fixed: true);
    });

    expect(Schema::hasColumns('macro_fixed_test', ['vector', 'vector_norm', 'vector_hash']))->toBeTrue();
});
