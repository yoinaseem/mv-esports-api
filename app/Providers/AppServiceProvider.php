<?php

namespace App\Providers;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Required by `$middleware->throttleApi()` in bootstrap/app.php — Laravel 12
        // doesn't auto-define the 'api' limiter, so it must be registered here.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Scramble's auto-generated /docs/api needs to know that protected
        // routes use bearer-token auth (Sanctum personal access tokens).
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        // Polymorphic relation alias map. participant_type / winner_type /
        // similar morph columns store the short alias rather than the FQCN
        // so DB rows stay decoupled from PHP namespaces. Renaming a model
        // class then becomes a code refactor, not a data migration.
        //
        // 'user' is included because Spatie's HasRoles / HasPermissions
        // traits use morph relationships against `model_has_roles` and
        // `model_has_permissions` — under enforceMorphMap (strict), every
        // morphable model needs an entry or the lookup throws.
        Relation::enforceMorphMap([
            'user'   => User::class,
            'team'   => Team::class,
            'player' => Player::class,
        ]);
    }
}
