<?php

namespace ThaKladd\VectorLite\Tests\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use ThaKladd\VectorLite\Models\VectorModel;

class Other extends VectorModel
{
    protected $guarded = [];

    public function vectors(): BelongsToMany
    {
        return $this->belongsToMany(Vector::class);
    }
}
