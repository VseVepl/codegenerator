<?php

namespace Vsent\CodeGenerator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use DateTimeInterface;
use Carbon\Carbon;

/**
 * Robust Unique Code Generator with Conservative Sequence Management
 *
 * Provides thread-safe, pattern-based code generation with support for various placeholders,
 * sequence management, and configurable patterns. Key features include:
 *
 * - Atomic sequence reservation with optimistic concurrency control
 * - Automatic retry with exponential backoff
 * - Support for {TYPE}, {LOCATION}, {DATE}, {TIME}, {SEQUENCE}, {RANDOM}, {UUID} placeholders
 * - Configurable patterns with runtime overrides
 * - Strict length enforcement
 * - Database-backed sequence tracking
 *
 * @package Vsent\CodeGenerator
 */
class CodeGenerator
{
    /**
     * @var string Default code type prefix
     */
    protected string $type;

    /**
     * @var string Default location code
     */
    protected string $location;

    /**
     * @var int Default sequence padding length
     */
    protected int $sequenceLength;

    /**
     * @var string Default date format
     */
    protected string $dateFormat;

    /**
     * @var string Default time format
     */
    protected string $timeFormat;

    /**
     * @var int|null Enforced total code length
     */
    protected ?int $codeLength;

    /**
     * @var int Max generation attempts
     */
    protected int $maxAttempts;

    /**
     * @var int Initial retry delay (ms)
     */
    protected int $retryDelay;

    /**
     * @var string Active pattern template
     */
    protected string $pattern;

    /**
     * @var bool Whether to use sequence tracking
     */
    protected bool $useSequence;

    /**
     * Initialize with default configuration
     */
    public function __construct()
    {
        $this->loadDefaultConfig();
    }

    /**
     * Load configuration defaults
     */
    protected function loadDefaultConfig(): void
    {
        $this->type = config('codegenerator.default_type', 'GEN');
        $this->location = config('codegenerator.default_location', 'XX');
        $this->sequenceLength = config('codegenerator.default_sequence_length', 4);
        $this->dateFormat = config('codegenerator.date_format', 'ymd');
        $this->timeFormat = config('codegenerator.time_format', 'Hi');
        $this->codeLength = config('codegenerator.default_code_length', null);
        $this->maxAttempts = config('codegenerator.max_attempts', 5);
        $this->retryDelay = config('codegenerator.retry_delay', 150);
        $this->pattern = config('codegenerator.default_pattern', '{TYPE}-{DATE:ymd}-{SEQUENCE:4}');
        $this->useSequence = true;
    }

    /**
     * Configure generator from predefined pattern
     *
     * @param string $codeTypeKey Configuration key from codegenerator.patterns
     * @return self
     * @throws RuntimeException If pattern not found
     */
    public function setPatternConfig(string $codeTypeKey): self
    {
        $patternConfig = config("codegenerator.patterns.{$codeTypeKey}");

        if (!$patternConfig) {
            throw new RuntimeException("Code pattern '{$codeTypeKey}' not found in configuration.");
        }

        $this->pattern = $patternConfig['pattern'] ?? $this->pattern;
        $this->type = $patternConfig['type'] ?? $this->type;
        $this->location = $patternConfig['location'] ?? $this->location;
        $this->sequenceLength = $patternConfig['sequence_length'] ?? $this->sequenceLength;
        $this->dateFormat = $patternConfig['date_format'] ?? $this->dateFormat;
        $this->timeFormat = $patternConfig['time_format'] ?? $this->timeFormat;
        $this->codeLength = $patternConfig['code_length'] ?? $this->codeLength;
        $this->maxAttempts = $patternConfig['max_attempts'] ?? $this->maxAttempts;
        $this->retryDelay = $patternConfig['retry_delay'] ?? $this->retryDelay;

        // Determine sequence usage
        $this->useSequence = str_contains($this->pattern, '{SEQUENCE}')
            ? ($patternConfig['use_sequence'] ?? true)
            : ($patternConfig['use_sequence'] ?? false);

        return $this;
    }

    /**
     * Generate unique code with automatic retry
     *
     * @return string Generated code
     * @throws RuntimeException After max attempts
     */
    public function generate(): string
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            try {
                $code = $this->attemptGeneration();
                return $this->finalizeCode($code);
            } catch (RuntimeException $e) {
                $lastException = $e;
                $attempt++;
                $this->applyRetryDelay($attempt);
            }
        }

        throw new RuntimeException(sprintf(
            'Failed to generate code after %d attempts: %s',
            $this->maxAttempts,
            $lastException?->getMessage() ?? 'Unknown error'
        ), 0, $lastException);
    }

    /**
     * Apply exponential backoff with jitter
     *
     * @param int $attempt Current attempt number
     */
    protected function applyRetryDelay(int $attempt): void
    {
        $delay = $this->retryDelay * pow(2, $attempt - 1) + random_int(0, $this->retryDelay / 2);
        usleep($delay * 1000);
    }

    /**
     * Single generation attempt
     *
     * @return string Generated code
     * @throws RuntimeException On concurrency conflict
     */
    protected function attemptGeneration(): string
    {
        if (!$this->useSequence) {
            return $this->formatCodeFromPattern(now(), 0);
        }

        return DB::transaction(function () {
            $now = now();
            $dateKey = $this->extractDateKeyFromPattern($now);
            $record = $this->getOrCreateSequenceRecord($dateKey);
            $sequence = $this->calculateNextSequence($record);

            $this->reserveSequence($record, $sequence);
            return $this->formatCodeFromPattern($now, $sequence);
        });
    }

    /**
     * Extract date key for sequence tracking
     *
     * @param DateTimeInterface $dateTime
     * @return string Date component key
     */
    protected function extractDateKeyFromPattern(DateTimeInterface $dateTime): string
    {
        preg_match('/\{DATE:?([a-zA-Z0-9]+)?\}/', $this->pattern, $matches);
        $format = $matches[1] ?? $this->dateFormat;

        return str_contains($this->pattern, '{DATE')
            ? $dateTime->format($format)
            : $dateTime->format('Y-m-d');
    }

    /**
     * Get or create sequence record with lock
     *
     * @param string $dateKey Date component
     * @return \stdClass Database record
     */
    protected function getOrCreateSequenceRecord(string $dateKey): \stdClass
    {
        $record = DB::table('code_sequences')
            ->where('date', $dateKey)
            ->where('type', $this->type)
            ->where('location', $this->location)
            ->lockForUpdate()
            ->first();

        if ($record) {
            return $record;
        }

        $id = DB::table('code_sequences')->insertGetId([
            'date' => $dateKey,
            'type' => $this->type,
            'location' => $this->location,
            'sequence' => 0,
            'pending_sequence' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) DB::table('code_sequences')->find($id);
    }

    /**
     * Calculate next sequence number
     *
     * @param \stdClass $record Sequence record
     * @return int Next sequence
     */
    protected function calculateNextSequence(\stdClass $record): int
    {
        return max($record->sequence, (int)$record->pending_sequence) + 1;
    }

    /**
     * Reserve sequence with optimistic lock
     *
     * @param \stdClass $record Current record
     * @param int $sequence Sequence to reserve
     * @throws RuntimeException On concurrency conflict
     */
    protected function reserveSequence(\stdClass $record, int $sequence): void
    {
        $updated = DB::table('code_sequences')
            ->where('id', $record->id)
            ->where('sequence', $record->sequence)
            ->where('pending_sequence', $record->pending_sequence)
            ->update([
                'pending_sequence' => $sequence,
                'updated_at' => now()
            ]);

        if ($updated === 0) {
            throw new RuntimeException('Sequence record modified by another process');
        }
    }

    /**
     * Format code from pattern
     *
     * @param DateTimeInterface $dateTime Current time
     * @param int $sequence Sequence number
     * @return string Formatted code
     */
    protected function formatCodeFromPattern(DateTimeInterface $dateTime, int $sequence): string
    {
        $replacements = [
            '{TYPE}' => $this->type,
            '{LOCATION}' => $this->location,
            '{UUID}' => Str::uuid()->toString(),
        ];

        $code = str_replace(array_keys($replacements), array_values($replacements), $this->pattern);

        $code = preg_replace_callback('/\{DATE:?([a-zA-Z0-9]+)?\}/', function ($m) use ($dateTime) {
            return $dateTime->format($m[1] ?? $this->dateFormat);
        }, $code);

        $code = preg_replace_callback('/\{TIME:?([a-zA-Z0-9]+)?\}/', function ($m) use ($dateTime) {
            return $dateTime->format($m[1] ?? $this->timeFormat);
        }, $code);

        if ($this->useSequence) {
            $code = preg_replace_callback('/\{SEQUENCE:?(\d+)?\}/', function ($m) use ($sequence) {
                $length = $m[1] ?? $this->sequenceLength;
                return str_pad($sequence, (int)$length, '0', STR_PAD_LEFT);
            }, $code);
        } else {
            $code = preg_replace('/\{SEQUENCE:?\d+?\}/', '', $code);
        }

        $code = preg_replace_callback('/\{RANDOM:(\d+)\}/', fn($m) => Str::random((int)$m[1]), $code);

        return $code;
    }

    /**
     * Apply final length constraints
     *
     * @param string $code Generated code
     * @return string Finalized code
     */
    protected function finalizeCode(string $code): string
    {
        if (!is_int($this->codeLength)) {
            return $code;
        }

        $length = $this->codeLength;

        return match (true) {
            strlen($code) < $length => str_pad($code, $length, '0', STR_PAD_RIGHT),
            strlen($code) > $length => substr($code, 0, $length),
            default => $code
        };
    }

    /**
     * Confirm usage of sequential code
     *
     * @param string $code Generated code
     * @return bool Confirmation success
     */
    public function confirmUsage(string $code): bool
    {
        if (!$this->useSequence) {
            return true; // No confirmation needed for non-sequential codes
        }

        return DB::transaction(function () use ($code) {
            $components = $this->parseCode($code);
            $parsedSequence = (int) $components['sequence'];
            $confirmationDateKey = $this->getConfirmationDateKey();

            return $this->updateSequenceRecord(
                $confirmationDateKey,
                $components['type'],
                $components['location'],
                $parsedSequence
            ) > 0;
        });
    }

    /**
     * Get date key for confirmation
     *
     * @return string Date key
     */
    protected function getConfirmationDateKey(): string
    {
        preg_match('/\{DATE:?([a-zA-Z0-9]+)?\}/', $this->pattern, $matches);
        $format = $matches[1] ?? $this->dateFormat;

        return str_contains($this->pattern, '{DATE')
            ? now()->format($format)
            : now()->format('Y-m-d');
    }

    /**
     * Update sequence record on confirmation
     *
     * @param string $dateKey Date component
     * @param string $type Code type
     * @param string $location Location code
     * @param int $sequence Sequence number
     * @return int Number of updated rows
     */
    protected function updateSequenceRecord(
        string $dateKey,
        string $type,
        string $location,
        int $sequence
    ): int {
        return DB::table('code_sequences')
            ->where('date', $dateKey)
            ->where('type', $type)
            ->where('location', $location)
            ->where('pending_sequence', $sequence)
            ->update([
                'sequence' => $sequence,
                'pending_sequence' => null,
                'updated_at' => now()
            ]);
    }

    /**
     * Parse code into components
     *
     * @param string $code Generated code
     * @return array Parsed components
     * @throws RuntimeException On pattern mismatch
     */
    protected function parseCode(string $code): array
    {
        $pattern = preg_quote($this->pattern, '/');
        $pattern = $this->convertPlaceholdersToRegex($pattern);

        if (!preg_match("/^{$pattern}$/", $code, $matches)) {
            throw new RuntimeException("Code does not match pattern '{$this->pattern}'");
        }

        return $this->normalizeParsedComponents($matches);
    }

    /**
     * Convert placeholders to regex patterns
     *
     * @param string $pattern Escaped pattern
     * @return string Regex pattern
     */
    protected function convertPlaceholdersToRegex(string $pattern): string
    {
        $replacements = [
            '\{TYPE\}' => '(?<type>[A-Z0-9]+)',
            '\{LOCATION\}' => '(?<location>[A-Z0-9]+)',
            '\{DATE:([a-zA-Z0-9]+)\}' => '(?<date>[0-9A-Za-z]+)',
            '\{DATE\}' => '(?<date>[0-9A-Za-z]+)',
            '\{TIME:([a-zA-Z0-9]+)\}' => '(?<time>[0-9]+)',
            '\{TIME\}' => '(?<time>[0-9]+)',
            '\{SEQUENCE:(\d+)\}' => '(?<sequence>[0-9]+)',
            '\{SEQUENCE\}' => '(?<sequence>[0-9]+)',
            '\{RANDOM:(\d+)\}' => '(?<random>[A-Za-z0-9]+)',
            '\{UUID\}' => '(?<uuid>[0-9a-fA-F-]{36})',
        ];

        return strtr($pattern, $replacements);
    }

    /**
     * Normalize parsed components
     *
     * @param array $matches Regex matches
     * @return array Normalized components
     */
    protected function normalizeParsedComponents(array $matches): array
    {
        $components = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

        if (isset($components['sequence'])) {
            $components['sequence'] = (int)$components['sequence'];
        }

        $components['type'] ??= $this->type;
        $components['location'] ??= $this->location;

        return $components;
    }

    // Fluent Configuration Setters
    // ----------------------------

    public function setType(string $type): self
    {
        $this->type = strtoupper($type);
        return $this;
    }

    public function setLocation(string $location): self
    {
        $this->location = strtoupper($location);
        return $this;
    }

    public function setSequenceLength(int $length): self
    {
        $this->sequenceLength = $length;
        return $this;
    }

    public function setDateFormat(string $format): self
    {
        $this->dateFormat = $format;
        return $this;
    }

    public function setTimeFormat(string $format): self
    {
        $this->timeFormat = $format;
        return $this;
    }

    public function setCodeLength(?int $length): self
    {
        $this->codeLength = $length;
        return $this;
    }

    public function setMaxAttempts(int $attempts): self
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    public function setRetryDelay(int $milliseconds): self
    {
        $this->retryDelay = $milliseconds;
        return $this;
    }

    public function pattern(string $pattern): self
    {
        $this->pattern = $pattern;
        $this->useSequence = str_contains($pattern, '{SEQUENCE}');
        return $this;
    }

    public function useSequence(bool $use): self
    {
        $this->useSequence = $use;
        return $this;
    }
}
