<?php

namespace VsE\Codegenerator;

use Illuminate\Support\ServiceProvider;
use VsE\Codegenerator\CodeGenerator;

class CodeGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge package's default configuration with user's published configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/codegenerator.php',
            'codegenerator'
        );

        // Bind the CodeGenerator class to the service container as a singleton.
        // This ensures that the same instance is used throughout the application,
        // which can be important for maintaining internal state if needed (though
        // current design is mostly stateless per generation).
        $this->app->singleton('code-generator', function ($app) {
            return new CodeGenerator();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish the configuration file to the application's config directory.
        // This allows users to customize the generator's behavior.
        $this->publishes([
            __DIR__ . '/../../config/codegenerator.php' => config_path('codegenerator.php'),
        ], 'codegenerator-config');

        // Publish the migration file for the code_sequences table.
        // This ensures the necessary database table can be created by the user.
        $this->publishes([
            __DIR__ . '/../../database/migrations/2025_06_09_000000_create_code_sequences_table.php' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_code_sequences_table.php'),
        ], 'codegenerator-migrations');
    }
}
