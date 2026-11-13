<?php

namespace App\Providers;

use App\Agent\AgentClient;
use App\Agent\FakeAgentClient;
use App\Agent\SocketAgentClient;
use App\Models\LoginKey;
use App\Services\LoginKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AgentClient::class, fn (): AgentClient => match (config('agent.driver')) {
            'fake' => new FakeAgentClient,
            'socket' => new SocketAgentClient(config('agent.socket'), config('agent.timeout')),
            default => throw new InvalidArgumentException('Unsupported agent driver ['.config('agent.driver').'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApi();
    }

    /**
     * The API: requests authenticate with a login key as a bearer token
     * (AuthenticateWithLoginKey, which sets the user of the "api" guard),
     * 120 requests a minute per key.
     */
    protected function configureApi(): void
    {
        Auth::viaRequest('login-key', LoginKeyService::authenticate(...));

        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->attributes->get('login_key');

            return Limit::perMinute(120)->by($key instanceof LoginKey ? 'login-key:'.$key->id : 'ip:'.$request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Built front-end files live in public/assets and are served at
        // /assets/<file> (buildDirectory in vite.config.ts).
        Vite::useBuildDirectory('assets');

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
