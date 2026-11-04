<?php

declare(strict_types=1);

use Axn\ModelsOrganizer\Tests\Support\TemporaryApplication;

it('range relations, scopes et accessors dans des traits dédiés', function (): void {
    $app = TemporaryApplication::create([
        'Models/Rubrique.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Rubrique extends Model
            {
            }
            PHP,
        'Models/Article.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Builder;
            use Illuminate\Database\Eloquent\Model;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;

            class Article extends Model
            {
                public function rubrique(): BelongsTo
                {
                    return $this->belongsTo(Rubrique::class);
                }

                public function scopePublie(Builder $query): void
                {
                    $query->whereNotNull('publie_le');
                }

                public function getTitreMajusculeAttribute(): string
                {
                    return mb_strtoupper($this->titre);
                }

                public function resume(): string
                {
                    return mb_substr($this->contenu, 0, 100);
                }
            }
            PHP,
    ]);

    $before = $app->methods(['App\Models\Article']);

    $app->organize();

    expect($app->lintErrors())->toBe([])
        ->and($app->methods(['App\Models\Article']))->toBe($before)
        ->and($app->contents('Models/Concerns/Article/Relations.php'))->toContain('public function rubrique(): BelongsTo')
        ->and($app->contents('Models/Concerns/Article/Scopes.php'))->toContain('public function scopePublie(Builder $query): void')
        ->and($app->contents('Models/Concerns/Article/Attributes.php'))->toContain('public function getTitreMajusculeAttribute(): string')
        ->and($app->contents('Models/Article.php'))
        ->toContain('use App\Models\Concerns\Article\Relations;', '    use Relations;', 'public function resume(): string')
        ->not->toContain('function rubrique', 'function scopePublie', 'function getTitreMajusculeAttribute');
});
