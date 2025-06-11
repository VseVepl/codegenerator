<?php

namespace Vsent\CodeGenerator;

use Illuminate\Support\ServiceProvider;
use Vsent\CodeGenerator\CodeGenerator;

/**
 * Laravel Service Provider for Code Generator Package
 *
 * Registers and bootstraps the code generation services with the Laravel application.
 * Handles package configuration merging, service binding, and asset publishing.
 */
class CodeGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Package name for asset publishing
     */
    protected const PACKAGE_NAME = 'codegenerator';

    /**
     * Register bindings in the service container.
     *
     * This method:
     * - Merges package configuration with application config
     * - Registers the CodeGenerator as a singleton service
     * - Sets up facade aliases
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfiguration();
        $this->registerServices();
    }

    /**
     * Bootstrap package services.
     *
     * Handles:
     * - Publishing configuration files
     * - Publishing database migrations
     * - Registering console commands (if any)
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishAssets();
    }

    /**
     * Merge package configuration with application config
     *
     * @return void
     */
    protected function mergeConfiguration(): void
    {
        $this->mergeConfigFrom(
            $this->packagePath('config/codegenerator.php'),
            self::PACKAGE_NAME
        );
    }

    /**
     * Register package services and facades
     *
     * @return void
     */
    protected function registerServices(): void
    {
        // Register primary generator service
        $this->app->singleton(self::PACKAGE_NAME, function ($app) {
            return new CodeGenerator();
        });

        // Register facade alias
        $this->app->alias(self::PACKAGE_NAME, CodeGenerator::class);
    }

    /**
     * Publish package assets
     *
     * @return void
     */
    protected function publishAssets(): void
    {
        // Publish configuration
        $this->publishes([
            $this->packagePath('config/codegenerator.php') => config_path('codegenerator.php'),
        ], self::PACKAGE_NAME . '-config');

        // Publish migrations
        $this->publishes([
            $this->packagePath('database/migrations/create_code_sequences_table.php.stub') =>
            database_path('migrations/' . date('Y_m_d_His') . '_create_code_sequences_table.php'),
        ], self::PACKAGE_NAME . '-migrations');
    }

    /**
     * Get full package path for a relative file
     *
     * @param string $path Relative path from package root
     * @return string Absolute path
     */
    protected function packagePath(string $path): string
    {
        return __DIR__ . '/../' . $path;
    }
}
