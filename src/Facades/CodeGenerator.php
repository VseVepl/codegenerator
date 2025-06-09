<?php

namespace VsE\Codegenerator\Facades;

use Illuminate\Support\Facades\Facade;
use VsE\Codegenerator\CodeGenerator as BaseCodeGenerator; // Alias the base class

/**
 * @method static string generateFor(string $codeTypeKey, array $overrides = [])
 * @method static \VsE\Codegenerator\CodeGenerator setType(string $type)
 * @method static \VsE\Codegenerator\CodeGenerator setLocation(string $location)
 * @method static \VsE\Codegenerator\CodeGenerator setSequenceLength(int $length)
 * @method static \VsE\Codegenerator\CodeGenerator setDateFormat(string $format)
 * @method static \VsE\Codegenerator\CodeGenerator setTimeFormat(string $format)
 * @method static \VsE\Codegenerator\CodeGenerator setCodeLength(?int $length)
 * @method static \VsE\Codegenerator\CodeGenerator setMaxAttempts(int $attempts)
 * @method static \VsE\Codegenerator\CodeGenerator setRetryDelay(int $milliseconds)
 * @method static \VsE\Codegenerator\CodeGenerator pattern(string $pattern)
 * @method static \VsE\Codegenerator\CodeGenerator useSequence(bool $use)
 * @method static bool confirmUsage(string $code)
 *
 * @see \VsE\Codegenerator\CodeGenerator
 */
class CodeGenerator extends Facade
{
    /**
     * Generate a code based on a predefined pattern key or a custom inline pattern.
     *
     * If $codeTypeKey matches a key in `config/codegenerator.php -> patterns`,
     * it will load that pattern's configuration. Otherwise, $codeTypeKey is
     * treated as a direct pattern string.
     *
     * @param string $codeTypeKey The key from the 'patterns' config array (e.g., 'order', 'invoice'),
     * or a direct pattern string (e.g., 'CUST-{DATE:ymd}-{RANDOM:5}').
     * @param array $overrides Optional array to temporarily override pattern-specific configurations
     * for this specific generation (e.g., ['location' => 'SFO', 'code_length' => 10]).
     * @return string The generated code.
     * @throws \RuntimeException If the pattern key is not found or generation fails.
     */
    public static function generateFor(string $codeTypeKey, array $overrides = []): string
    {
        /** @var BaseCodeGenerator $instance */
        $instance = static::getFacadeRoot();

        // Reload default configuration to ensure a clean state for this generation.
        // This is important when reusing the facade instance in a single request.
        $instance = new BaseCodeGenerator(); // Create a fresh instance for each `generateFor` call

        // Check if $codeTypeKey is a predefined pattern key from the config
        if (config()->has('codegenerator.patterns.' . $codeTypeKey)) {
            $instance->setPatternConfig($codeTypeKey); // Load pattern config first
        } else {
            // Assume $codeTypeKey is a direct pattern string
            $instance->pattern($codeTypeKey);
        }

        // Apply any runtime overrides. Fluent setters provide type safety.
        foreach ($overrides as $key => $value) {
            $method = 'set' . \Illuminate\Support\Str::studly($key);
            if (method_exists($instance, $method)) {
                $instance->$method($value);
            } else {
                // Log a warning or throw an exception if an invalid override key is passed
                // For now, we'll just ignore it to be more lenient.
                // You might want to add: throw new \InvalidArgumentException("Invalid override key: {$key}");
            }
        }

        return $instance->generate();
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'code-generator';
    }
}
