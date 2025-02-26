<?php

// config for ThaKladd/VectorLite
return [
    'pinecone' => [
        'base_url' => env('PINECONE_BASE_URL'),
        'api_key' => env('PINECONE_API_KEY'),
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],
];
