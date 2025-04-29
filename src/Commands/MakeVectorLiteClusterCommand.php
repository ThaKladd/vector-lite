<?php

namespace ThaKladd\VectorLite\Commands;

use Illuminate\Console\GeneratorCommand;

class MakeVectorLiteClusterCommand extends GeneratorCommand
{
    protected $name = 'vector-lite:make:cluster';

    protected $description = 'Create a new cluster model class';

    protected $type = 'Model';

    protected function getStub()
    {
        return __DIR__.'/../Stubs/cluster.stub';
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Models';
    }
}
