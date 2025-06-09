<?php

namespace VsE\Codegenerator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use DateTimeInterface;

/**
 * Robust Unique Code Generator with Conservative Sequence Management and Dynamic Patterns.
 *
 * Features:
 * - Thread-safe code generation using database locks and optimistic concurrency.
 * - Conservative sequence reservation (only commits used sequences via confirmUsage()).
 * - Supports dynamic code patterns with various placeholders ({TYPE}, {LOCATION}, {DATE}, {TIME}, {SEQUENCE}, {RANDOM}, {UUID}).
 * - Automatic retry mechanism with exponential backoff for concurrency conflicts.
 * - Configurable global defaults and pattern-specific overrides.
 * - Optional enforcement of total code length.
 */
class CodeGenerator
{
    // Configuration properties, initialized with defaults from config file or explicit setters
    protected string $type;
    protected string $location;
    protected int $sequenceLength;
    protected string $dateFormat;
    protected string $timeFormat;
    protected ?int $codeLength; // Nullable for optional total code length enforcement
    protected int $maxAttempts;
    protected int $retryDelay;
    protected string $pattern;
    protected bool $useSequence;

    /**
     * Initializes the CodeGenerator with default configuration.
     */
    public function __construct()
    {
        $this->loadDefaultConfig();
    }

    /**
     * Loads default configuration from the 'codegenerator' config file.
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
        // useSequence is dynamically determined based on pattern, but defaults to true for safety
        $this->useSequence = true;
    }

    /**
     * Configures the generator based on a predefined pattern key from the config file.
     * This method is called internally by the Facade's generateFor method.
     *
     * @param string $codeTypeKey The key from the 'patterns' config array.
     * @return $this
     */
    public function setPatternConfig(string $codeTypeKey): self
    {
        $patternConfig = config('codegenerator.patterns.' . $codeTypeKey);

        if (!$patternConfig) {
            throw new RuntimeException("Code pattern '{$codeTypeKey}' not found in configuration.");
        }

        // Apply specific pattern configurations, falling back to defaults if not set
        $this->pattern = $patternConfig['pattern'] ?? $this->pattern;
        $this->type = $patternConfig['type'] ?? $this->type;
        $this->location = $patternConfig['location'] ?? $this->location;
        $this->sequenceLength = $patternConfig['sequence_length'] ?? $this->sequenceLength;
        $this->dateFormat = $patternConfig['date_format'] ?? $this->dateFormat;
        $this->timeFormat = $patternConfig['time_format'] ?? $this->timeFormat;
        $this->codeLength = $patternConfig['code_length'] ?? $this->codeLength;
        $this->maxAttempts = $patternConfig['max_attempts'] ?? $this->maxAttempts;
        $this->retryDelay = $patternConfig['retry_delay'] ?? $this->retryDelay;

        // Determine useSequence: If pattern contains {SEQUENCE}, it's true unless explicitly false.
        // Otherwise, it's false.
        $this->useSequence = str_contains($this->pattern, '{SEQUENCE}') ?
            ($patternConfig['use_sequence'] ?? true) : ($patternConfig['use_sequence'] ?? false);

        return $this;
    }

    /**
     * Generates a unique code with automatic retry on conflicts for sequential patterns.
     *
     * @return string The generated code.
     * @throws RuntimeException After maximum retry attempts or if pattern is invalid.
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
                // Exponential backoff with jitter (adding randomness to retry delay)
                usleep(($this->retryDelay * pow(2, $attempt - 1) + random_int(0, $this->retryDelay / 2)) * 1000);
            }
        }

        throw new RuntimeException(
            'Failed to generate code after ' . $this->maxAttempts . ' attempts: ' .
                ($lastException ? $lastException->getMessage() : 'Unknown error.'),
            0,
            $lastException
        );
    }

    /**
     * Performs a single attempt at code generation within a database transaction if sequencing is used.
     *
     * @return string The generated code.
     * @throws RuntimeException On concurrency conflicts or database errors.
     */
    protected function attemptGeneration(): string
    {
        // If sequencing is enabled, wrap in a transaction for atomicity and locking.
        if ($this->useSequence) {
            return DB::transaction(function () {
                $now = now();
                $dateKey = $this->extractDateKeyFromPattern($now); // Use the date part relevant for the pattern
                $record = $this->getOrCreateSequenceRecord($dateKey);

                // Calculate next available sequence number
                $sequence = $this->calculateNextSequence($record);

                // Reserve the sequence by updating pending_sequence (optimistic concurrency)
                $this->reserveSequence($record, $sequence);

                // Generate and format the code using the pattern
                return $this->formatCodeFromPattern($now, $sequence);
            });
        }

        // If not using sequence, generate code directly from pattern without transaction
        return $this->formatCodeFromPattern(now(), 0); // Sequence value is ignored for non-sequential patterns
    }

    /**
     * Extracts the relevant date key from the pattern for sequence tracking.
     * E.g., for {DATE:Ym}, returns 'YYYY-MM'. For {DATE:Y}, returns 'YYYY'.
     *
     * @param DateTimeInterface $dateTime
     * @return string
     */
    protected function extractDateKeyFromPattern(DateTimeInterface $dateTime): string
    {
        preg_match('/\{DATE:?([a-zA-Z0-9]+)?\}/', $this->pattern, $matches);
        $format = $matches[1] ?? $this->dateFormat; // Use pattern-specific format or default

        // Only return part of date relevant for sequence if it exists in pattern
        // Otherwise, use a daily key if date part is not in pattern for sequence or full date for general
        if (str_contains($this->pattern, '{DATE')) {
            return $dateTime->format($format);
        }
        // If no date placeholder, use a fixed daily key for the sequence
        return $dateTime->format('Y-m-d'); // Default to daily sequence if pattern has no date segment.
    }

    /**
     * Gets an existing sequence record or creates a new one with a lock.
     *
     * @param string $dateKey The date component used as part of the unique key for the sequence.
     * @return \stdClass The database record.
     */
    protected function getOrCreateSequenceRecord(string $dateKey): \stdClass
    {
        // Use the pattern's type and location for the unique key, ensuring consistency
        $type = $this->type;
        $location = $this->location;

        $record = DB::table('code_sequences')
            ->where('date', $dateKey)
            ->where('type', $type)
            ->where('location', $location)
            ->lockForUpdate() // Acquire an exclusive lock on this row
            ->first();

        if (!$record) {
            $id = DB::table('code_sequences')->insertGetId([
                'date' => $dateKey,
                'type' => $type,
                'location' => $location,
                'sequence' => 0,          // Initial confirmed sequence
                'pending_sequence' => null, // No pending sequence initially
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Retrieve the newly created record to ensure consistent object structure
            $record = (object) DB::table('code_sequences')->where('id', $id)->first();
        }

        return $record;
    }

    /**
     * Calculates the next available sequence number.
     *
     * @param \stdClass $record The current sequence record.
     * @return int The next sequence number.
     */
    protected function calculateNextSequence(\stdClass $record): int
    {
        // The next sequence is max of last confirmed + 1, or last pending + 1.
        // This handles cases where a pending sequence was reserved but not confirmed,
        // ensuring we always pick up from the highest previously attempted number.
        return max($record->sequence, (int)$record->pending_sequence) + 1;
    }

    /**
     * Reserves a sequence number by updating the pending_sequence field.
     * Includes an optimistic concurrency check.
     *
     * @param \stdClass $record The current sequence record (before update).
     * @param int $sequence The sequence number to reserve.
     * @throws RuntimeException If the record was modified by another process (concurrency conflict).
     */
    protected function reserveSequence(\stdClass $record, int $sequence): void
    {
        // Optimistic concurrency control: ensure the record hasn't changed since we fetched it.
        $updated = DB::table('code_sequences')
            ->where('id', $record->id)
            ->where('sequence', $record->sequence)             // Check last confirmed value
            ->where('pending_sequence', $record->pending_sequence) // Check last pending value
            ->update([
                'pending_sequence' => $sequence,
                'updated_at' => now()
            ]);

        if ($updated === 0) {
            // This indicates another process modified the record between our fetch and update.
            throw new RuntimeException('Sequence record was modified by another process. Retrying...');
        }
    }

    /**
     * Formats the code based on the configured pattern and generated values.
     *
     * @param DateTimeInterface $dateTime The current timestamp.
     * @param int $sequence The generated sequence number (0 if not applicable).
     * @return string The formatted code string.
     */
    protected function formatCodeFromPattern(DateTimeInterface $dateTime, int $sequence): string
    {
        $code = $this->pattern;

        // Replace {TYPE}
        $code = str_replace('{TYPE}', $this->type, $code);

        // Replace {LOCATION}
        $code = str_replace('{LOCATION}', $this->location, $code);

        // Replace {DATE:format}
        $code = preg_replace_callback('/\{DATE:?([a-zA-Z0-9]+)?\}/', function ($matches) use ($dateTime) {
            $format = $matches[1] ?? $this->dateFormat;
            return $dateTime->format($format);
        }, $code);

        // Replace {TIME:format}
        $code = preg_replace_callback('/\{TIME:?([a-zA-Z0-9]+)?\}/', function ($matches) use ($dateTime) {
            $format = $matches[1] ?? $this->timeFormat;
            return $dateTime->format($format);
        }, $code);

        // Replace {SEQUENCE:length}
        // This should only be present if $this->useSequence is true
        if ($this->useSequence) {
            $code = preg_replace_callback('/\{SEQUENCE:?([0-9]+)?\}/', function ($matches) use ($sequence) {
                $length = $matches[1] ?? $this->sequenceLength;
                return str_pad($sequence, (int)$length, '0', STR_PAD_LEFT);
            }, $code);
        } else {
            // Remove {SEQUENCE} placeholder if useSequence is false
            $code = preg_replace('/\{SEQUENCE:?[0-9]+?\}/', '', $code);
        }


        // Replace {RANDOM:length}
        $code = preg_replace_callback('/\{RANDOM:([0-9]+)\}/', function ($matches) {
            return Str::random((int)$matches[1]);
        }, $code);

        // Replace {UUID}
        $code = str_replace('{UUID}', Str::uuid()->toString(), $code);

        return $code;
    }

    /**
     * Applies final length enforcement to the generated code.
     *
     * @param string $code The generated code before finalization.
     * @return string The finalized (padded/truncated) code.
     */
    protected function finalizeCode(string $code): string
    {
        if (is_int($this->codeLength) && $this->codeLength > 0) {
            if (strlen($code) < $this->codeLength) {
                // Pad with zeros to the right if shorter
                return str_pad($code, $this->codeLength, '0', STR_PAD_RIGHT);
            } elseif (strlen($code) > $this->codeLength) {
                // Truncate if longer
                return substr($code, 0, $this->codeLength);
            }
        }
        return $code;
    }


    /**
     * Confirms the usage of a generated sequential code.
     * This moves the 'pending_sequence' to 'sequence' in the database,
     * marking the code as officially used.
     *
     * @param string $code The code to confirm.
     * @return bool True if confirmation succeeded, false otherwise.
     * @throws RuntimeException If the code format is invalid or cannot be parsed.
     */
    public function confirmUsage(string $code): bool
    {
        return DB::transaction(function () use ($code) {
            $components = $this->parseCode($code);
            $parsedSequence = (int) $components['sequence'];

            // Determine the date key to use for confirmation based on the original pattern's date format
            $now = now();
            preg_match('/\{DATE:?([a-zA-Z0-9]+)?\}/', $this->pattern, $matches);
            $confirmationDateKeyFormat = $matches[1] ?? $this->dateFormat;
            $confirmationDateKey = $now->format($confirmationDateKeyFormat);

            // If pattern doesn't contain date, use daily key as default fallback for sequence
            if (!str_contains($this->pattern, '{DATE')) {
                $confirmationDateKey = $now->format('Y-m-d');
            }


            // The `where` clauses must precisely match the unique index on `date`, `type`, `location`.
            // The `pending_sequence` check ensures that we only confirm the specific code that was last reserved.
            $updated = DB::table('code_sequences')
                ->where('date', $confirmationDateKey)
                ->where('type', $components['type'])
                ->where('location', $components['location'])
                ->where('pending_sequence', $parsedSequence) // Only confirm if it's the specific pending sequence
                ->update([
                    'sequence' => $parsedSequence,      // Move pending to confirmed sequence
                    'pending_sequence' => null,         // Clear the pending sequence
                    'updated_at' => now()
                ]);

            return $updated > 0;
        });
    }

    /**
     * Parses a generated code string back into its components based on the active pattern.
     * This method relies on the current `pattern` configuration of the generator instance.
     *
     * @param string $code The code string to parse.
     * @return array Associative array of parsed components (type, date, location, time, sequence, random, uuid).
     * @throws RuntimeException If the code does not match the active pattern's expected format.
     */
    protected function parseCode(string $code): array
    {
        $components = [];
        $pattern = $this->pattern;
        $tempPattern = $pattern; // Use a temporary pattern for matching

        // Escape static parts of the pattern for regex
        $tempPattern = preg_quote($tempPattern, '/');

        // Replace placeholders with named regex capture groups
        $tempPattern = str_replace(preg_quote('{TYPE}', '/'), '(?<type>[A-Z0-9]+)', $tempPattern);
        $tempPattern = str_replace(preg_quote('{LOCATION}', '/'), '(?<location>[A-Z0-9]+)', $tempPattern);
        $tempPattern = preg_replace('/' . preg_quote('{DATE:', '/') . '([a-zA-Z0-9]+)' . preg_quote('}', '/') . '/', '(?<date>[0-9A-Za-z]+)', $tempPattern);
        $tempPattern = str_replace(preg_quote('{DATE}', '/'), '(?<date>[0-9A-Za-z]+)', $tempPattern); // for default date format
        $tempPattern = preg_replace('/' . preg_quote('{TIME:', '/') . '([a-zA-Z0-9]+)' . preg_quote('}', '/') . '/', '(?<time>[0-9]+)', $tempPattern);
        $tempPattern = str_replace(preg_quote('{TIME}', '/'), '(?<time>[0-9]+)', $tempPattern); // for default time format
        $tempPattern = preg_replace('/' . preg_quote('{SEQUENCE:', '/') . '([0-9]+)' . preg_quote('}', '/') . '/', '(?<sequence>[0-9]+)', $tempPattern);
        $tempPattern = str_replace(preg_quote('{SEQUENCE}', '/'), '(?<sequence>[0-9]+)', $tempPattern); // for default seq length
        $tempPattern = preg_replace('/' . preg_quote('{RANDOM:', '/') . '([0-9]+)' . preg_quote('}', '/') . '/', '(?<random>[A-Za-z0-9]+)', $tempPattern);
        $tempPattern = str_replace(preg_quote('{UUID}', '/'), '(?<uuid>[0-9a-fA-F-]+)', $tempPattern);

        // Perform the regex match
        if (!preg_match('/^' . $tempPattern . '$/', $code, $matches)) {
            throw new RuntimeException("Code '{$code}' does not match the expected pattern '{$this->pattern}'.");
        }

        // Extract captured groups
        foreach ($matches as $key => $value) {
            if (is_string($key)) { // Only get named capture groups
                $components[$key] = $value;
            }
        }

        // Ensure sequence is an integer, if present
        if (isset($components['sequence'])) {
            $components['sequence'] = (int)$components['sequence'];
        }

        // Ensure all expected components from pattern are present in parsed array
        // (This can be more robust by matching actual placeholders in the pattern)
        if (!isset($components['type'])) $components['type'] = $this->type;
        if (!isset($components['location'])) $components['location'] = $this->location;


        return $components;
    }


    /*
    |--------------------------------------------------------------------------
    | Fluent Configuration Setters
    |--------------------------------------------------------------------------
    | These methods allow chaining configuration calls for a single generation.
    */

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
        // Re-evaluate useSequence based on new pattern
        $this->useSequence = str_contains($this->pattern, '{SEQUENCE}');
        return $this;
    }

    public function useSequence(bool $use): self
    {
        $this->useSequence = $use;
        return $this;
    }
}
