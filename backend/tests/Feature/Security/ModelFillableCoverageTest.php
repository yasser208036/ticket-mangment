<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class ModelFillableCoverageTest extends TestCase
{
    public function test_every_eloquent_model_declares_fillable_fields(): void
    {
        foreach (glob(app_path('Models/*.php')) as $path) {
            $class = 'App\\Models\\'.basename($path, '.php');
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $hasFillableAttribute = $reflection->getAttributes(Fillable::class) !== [];
            $model = $reflection->newInstanceWithoutConstructor();
            $this->assertTrue(
                $hasFillableAttribute || $model->getGuarded() === ['*'],
                "{$class} defines neither #[Fillable] nor \$guarded = ['*'] — every field is mass-assignable.",
            );
        }
    }
}
