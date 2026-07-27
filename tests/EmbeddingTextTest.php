<?php

use ThaKladd\VectorLite\Tests\Models\Other;
use ThaKladd\VectorLite\Tests\Models\Vector;

it('builds a pseudo-XML embedding text from embedFields, including nested relations and array-returning methods', function () {
    $other = Other::create(['description' => 'A short description.']);
    $vector = new Vector(['title' => 'My Title', 'other_id' => $other->id]);
    $vector->id = 42;

    $text = $vector->getEmbeddingText();

    expect($text)->toStartWith('<vector id="42">');
    expect($text)->toEndWith("</vector>\n");
    expect($text)->toContain('<title>My Title</title>');
    expect($text)->toContain('<other>');
    expect($text)->toContain('<description>A short description.</description>');
    expect($text)->toContain('<customMethod>');
    expect($text)->toContain('<title>Text</title>');
    expect($text)->toContain('<body>Description to the text.</body>');
});

it('omits empty or null attribute values entirely from the embedding text', function () {
    $other = Other::create(['description' => null]);
    $vector = new Vector(['title' => '', 'other_id' => $other->id]);
    $vector->id = 43;

    $text = $vector->getEmbeddingText();

    // customMethod always emits one <title> tag ("Text") - the top-level
    // "title" field is empty and must not add a second one.
    expect(substr_count($text, '<title>'))->toBe(1);
    expect($text)->not->toContain('<description>');
});

it('returns an empty string for a model with no embedFields configured', function () {
    $other = new Other(['description' => 'irrelevant']);
    $other->id = 44;

    expect($other->getEmbeddingText())->toBe('');
});

it('escapes HTML-sensitive characters in embedded attribute values', function () {
    $vector = new Vector(['title' => '<script>alert(1)</script> & "quotes"']);
    $vector->id = 45;

    $text = $vector->getEmbeddingText();

    expect($text)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot;');
    expect($text)->not->toContain('<script>');
});
