<?php

use ThaKladd\VectorLite\Support\SqlValidator;

it('accepts every allowed comparison operator', function () {
    foreach (['=', '>', '<', '>=', '<=', '<>', '!='] as $operator) {
        SqlValidator::validateOperator($operator);
    }

    expect(true)->toBeTrue();
});

it('rejects an operator that is not on the allow-list', function () {
    expect(fn () => SqlValidator::validateOperator('1=1; DROP TABLE users; --'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty operator', function () {
    expect(fn () => SqlValidator::validateOperator(''))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts asc/desc directions regardless of case', function () {
    foreach (['asc', 'ASC', 'desc', 'DESC', 'Asc', 'Desc'] as $direction) {
        SqlValidator::validateDirection($direction);
    }

    expect(true)->toBeTrue();
});

it('rejects a direction that is not asc or desc', function () {
    expect(fn () => SqlValidator::validateDirection('RANDOM()'))
        ->toThrow(InvalidArgumentException::class);
});
