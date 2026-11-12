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

it('organise un modèle qui emploie ses traits sous un nom qualifié', function (): void {
    $app = TemporaryApplication::create([
        'Models/Redacteur.php' => emptyModel('Redacteur'),
        'Models/Theme.php' => emptyModel('Theme'),
        'Models/Illustration.php' => emptyModel('Illustration'),
        'Models/Billet.php' => <<<'PHP'
            <?php

            namespace App\Models;

            use App\Models\Concerns\Billet as BilletConcerns;
            use Illuminate\Database\Eloquent\Model;

            class Billet extends Model
            {
                use \App\Models\Concerns\Billet\HasRedacteur;
                use Concerns\Billet\HasTheme;
                use BilletConcerns\HasIllustrations;
            }
            PHP,
        'Models/Concerns/Billet/HasRedacteur.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Billet;

            use App\Models\Redacteur;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;

            trait HasRedacteur
            {
                public function redacteur(): BelongsTo
                {
                    return $this->belongsTo(Redacteur::class);
                }
            }
            PHP,
        'Models/Concerns/Billet/HasTheme.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Billet;

            use App\Models\Theme;
            use Illuminate\Database\Eloquent\Relations\BelongsTo;

            trait HasTheme
            {
                public function theme(): BelongsTo
                {
                    return $this->belongsTo(Theme::class);
                }
            }
            PHP,
        'Models/Concerns/Billet/HasIllustrations.php' => <<<'PHP'
            <?php

            namespace App\Models\Concerns\Billet;

            use App\Models\Illustration;
            use Illuminate\Database\Eloquent\Relations\HasMany;

            trait HasIllustrations
            {
                public function illustrations(): HasMany
                {
                    return $this->hasMany(Illustration::class);
                }
            }
            PHP,
    ]);

    $before = $app->methods(['App\Models\Billet']);

    $output = $app->organize();

    expect($output)->not->toContain('introuvable')
        ->and($app->lintErrors())->toBe([])
        ->and($app->methods(['App\Models\Billet']))->toBe($before)
        ->and($app->contents('Models/Concerns/Billet/Relations.php'))
        ->toContain('public function redacteur(): BelongsTo', 'public function theme(): BelongsTo', 'public function illustrations(): HasMany')
        ->and($app->exists('Models/Concerns/Billet/HasRedacteur.php'))->toBeFalse()
        ->and($app->exists('Models/Concerns/Billet/HasTheme.php'))->toBeFalse()
        ->and($app->exists('Models/Concerns/Billet/HasIllustrations.php'))->toBeFalse()
        ->and($app->contents('Models/Billet.php'))
        ->toContain('    use Relations;')
        ->not->toContain('HasRedacteur;', 'HasTheme;', 'HasIllustrations;');
});
