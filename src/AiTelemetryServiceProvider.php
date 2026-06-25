<?php

namespace Vinit\LaravelAiTelemetry;

use Illuminate\Support\ServiceProvider;

class AiTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-telemetry.php', 'ai-telemetry');

        $this->app->singleton(TelemetryManager::class, fn ($app) => new TelemetryManager(
            $app['config']->get('ai-telemetry', [])
        ));

        $this->app->singleton(SpanCollector::class, fn ($app) => new SpanCollector(
            $app->make(TelemetryManager::class)->driver()
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-telemetry.php' => config_path('ai-telemetry.php'),
            ], ['ai-telemetry', 'ai-telemetry-config']);
        }

        if (config('ai-telemetry.enabled')) {
            $this->registerListeners();
        }
    }

    private function registerListeners(): void
    {
        $listener = $this->app->make(TelemetryListener::class);

        $dispatcher = $this->app['events'];

        foreach (TelemetryListener::eventMappings() as $event => $method) {
            $dispatcher->listen($event, [$listener, $method]);
        }

        $this->app->terminating(fn () => $this->app->make(SpanCollector::class)->shutdown());
    }
}
