<?php

namespace ThaKladd\VectorLite\Tests\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ThaKladd\VectorLite\Models\VectorModel;

class Vector extends VectorModel
{
    protected $guarded = [];

    protected $embedFields = ['title', 'other.description', 'customMethod'];

    public function other(): BelongsTo
    {
        return $this->BelongsTo(Other::class);
    }

    public function customMethod(): array
    {
        return ['title' => 'Text', 'body' => 'Description to the text.'];
    }
}
