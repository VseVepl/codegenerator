<?php

/*
|--------------------------------------------------------------------------
| Code Generator Configuration
|--------------------------------------------------------------------------
|
| This file contains the configuration for the unique code generation package.
| It allows you to define global default settings and specific patterns
| for different types of codes (e.g., orders, invoices, tracking IDs).
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Default Global Settings
    |--------------------------------------------------------------------------
    | These settings serve as base defaults for all code generation.
    | They can be overridden by specific pattern configurations defined below,
    | or by fluent methods when calling the CodeGenerator facade at runtime.
    */

    'default_type' => 'GEN',
    // (string) A default prefix for generated codes (e.g., 'GEN' for General).
    // This is used for the {TYPE} placeholder if not specified in a pattern.

    'default_location' => 'XX',
    // (string) A default location code (e.g., 'XX' for an unspecified location).
    // This is used for the {LOCATION} placeholder if not specified in a pattern.

    'default_sequence_length' => 4,
    // (int) The default number of digits for sequential numbers ({SEQUENCE}).
    // Codes will be padded with leading zeros to this length (e.g., 4 -> 0001).

    'date_format' => 'ymd',
    // (string) The default PHP date format string for the {DATE} placeholder.
    // Examples: 'ymd' (250609), 'Ymd' (20250609), 'Y-m-d' (2025-06-09).

    'time_format' => 'Hi',
    // (string) The default PHP time format string for the {TIME} placeholder.
    // Examples: 'Hi' (1430 for 2:30 PM), 'His' (143000 for 2:30:00 PM).

    'default_code_length' => null,
    // (int|null) The default *total* length for the generated code.
    // - If an integer is provided, the final generated code will be padded
    //   (e.g., with '0's to the right) or truncated to this exact length.
    // - Set to `null` (default) if you do not want to enforce a total length,
    //   and the code length will be determined solely by its pattern components.

    'max_attempts' => 5,
    // (int) The maximum number of retry attempts for code generation
    // in case of concurrency conflicts (e.g., two processes trying to get
    // the same sequence number simultaneously).

    'retry_delay' => 150,
    // (int) The initial delay in milliseconds before retrying a failed
    // code generation attempt. An exponential backoff strategy is applied
    // (e.g., 150ms, 300ms, 600ms, etc., for subsequent retries).

    /*
    |--------------------------------------------------------------------------
    | Code Generation Patterns
    |--------------------------------------------------------------------------
    | Define specific code types using patterns and their unique configurations.
    | Each key in this array (e.g., 'order', 'invoice') is a `code_type_key`
    | that you will pass to `CodeGenerator::generateFor('code_type_key')`.
    |
    | A `pattern` string defines the structure of the code using placeholders.
    | You can also override any of the `Default Global Settings` within each pattern.
    |
    | Available Placeholders and their Configuration:
    |
    | - `{TYPE}`: Replaced by the `type` setting of the current pattern (or `default_type`).
    |             Example: `ORD`, `INV`, `TRK`.
    |
    | - `{LOCATION}`: Replaced by the `location` setting of the current pattern (or `default_location`).
    |                 Example: `HQ`, `MUM`, `NYC`.
    |
    | - `{DATE:format}`: Replaced by the current date. The `:format` is a PHP date format string.
    |                    If `:format` is omitted (e.g., `{DATE}`), `date_format` from this config is used.
    |                    Example: `{DATE:ymd}` -> `250609`.
    |
    | - `{TIME:format}`: Replaced by the current time. The `:format` is a PHP time format string.
    |                    If `:format` is omitted (e.g., `{TIME}`), `time_format` from this config is used.
    |                    Example: `{TIME:Hi}` -> `1430`.
    |
    | - `{SEQUENCE:length}`: Replaced by a unique, incrementing number.
    |                        The `:length` (integer) specifies padding with leading zeros (e.g., {SEQUENCE:5} -> 00001).
    |                        This component *requires* `use_sequence` to be true (default if {SEQUENCE} is used).
    |                        It relies on the `code_sequences` database table for robust, thread-safe increments.
    |
    | - `{RANDOM:length}`: Replaced by a random alphanumeric string.
    |                      The `:length` (integer) specifies the desired string length (e.g., {RANDOM:8}).
    |                      This component *does NOT* use the `code_sequences` table; it relies on
    |                      cryptographically secure random string generation.
    |
    | - `{UUID}`: Replaced by a Version 4 Universally Unique Identifier (UUID).
    |             Example: `a1b2c3d4-e5f6-7890-1234-567890abcdef`.
    |             This component *does NOT* use the `code_sequences` table; it relies on
    |             the inherent uniqueness properties of UUIDs.
    |
    | Additional Pattern-Specific Settings:
    |
    | - `'use_sequence'` (bool):
    |   - Set to `true` (default for patterns containing `{SEQUENCE}`). Ensures sequence numbers are managed
    |     in the database and are unique.
    |   - Set to `false` (default for patterns containing `{RANDOM}` or `{UUID}`). Skips database
    |     sequence management and relies on the uniqueness properties of random strings or UUIDs.
    |
    | - `'code_length'` (int|null):
    |   - **Optional.** If provided, the final generated code will be explicitly padded or truncated
    |     to this total length.
    |   - If omitted or set to `null`, the code's length will be determined solely by its pattern components.
    |   - Useful for enforcing fixed-length codes where necessary.
    */

    'patterns' => [
        'order' => [
            'pattern' => '{TYPE}-{DATE:ymd}-{LOCATION}-{SEQUENCE:4}', // Example: ORD-250609-HQ-0001
            'type' => 'ORD',            // Specific type prefix for orders
            'location' => 'HQ',         // Default location for orders
            'sequence_length' => 4,     // Orders will have 4-digit sequences
            'code_length' => 15,        // Ensure order codes are always 15 characters total (ORD-YYMMDD-LOC-SSSS)
        ],

        'purchase_order' => [
            'pattern' => 'PO/{LOCATION}/{DATE:Ymd}/{SEQUENCE:5}', // Example: PO/NYC/20250609/00001
            'type' => 'PO',
            'location' => 'NYC',
            'sequence_length' => 5,
            'code_length' => 20, // A fixed length for purchase orders
        ],

        'invoice' => [
            'pattern' => 'INV-{DATE:Ym}-{LOCATION}-{SEQUENCE:5}', // Example: INV-202506-MUM-00001
            'type' => 'INV',
            'location' => 'MUM',
            'date_format' => 'Ym',      // Invoice sequence unique per Year-Month
            'sequence_length' => 5,
            'code_length' => 21,        // Example: INV-YYYYMM-LOC-SSSSS (3+1+6+1+3+1+5 = 20 + 1 buffer for safety)
        ],

        'tracking_id' => [
            'pattern' => 'TRK-{UUID}', // Example: TRK-a1b2c3d4-e5f6-7890-1234-567890abcdef
            'type' => 'TRK',
            'use_sequence' => false,    // This pattern relies purely on UUID, no database sequence needed
            'code_length' => 40,        // UUID is 36 chars + 'TRK-' prefix = 40. Ensures consistent length.
        ],

        'entity_code' => [
            'pattern' => 'ENT-{DATE:Y}-{SEQUENCE:6}', // Example: ENT-2025-000001 (Sequence resets yearly)
            'type' => 'ENT',
            'sequence_length' => 6,
            'date_format' => 'Y',       // Sequence unique per Year
            // location is not relevant for sequence tracking here if not in pattern
            'code_length' => 15,        // ENT-YYYY-SSSSSS (3+1+4+1+6 = 15)
        ],

        'transaction_id' => [
            'pattern' => 'TXN-{DATE:ymdHis}-{RANDOM:8}', // Example: TXN-250609143000-A1B2C3D4
            'type' => 'TXN',
            'use_sequence' => false,    // Uses random string, no database sequence
            'code_length' => 25,        // Example: TXN-YYMMDDHHMMSS-RRRRRRRR (3+1+12+1+8 = 25)
        ],

        'address_code' => [
            'pattern' => 'ADDR-{LOCATION}-{RANDOM:5}', // Example: ADDR-BLR-X9Y2Z
            'type' => 'ADDR',
            'location' => 'BLR',
            'use_sequence' => false,    // Uses random string
            'code_length' => 15,        // Example: ADDR-BLR-RRRRR (4+1+3+1+5 = 14. Pad to 15)
        ],

        'contact_code' => [
            'pattern' => 'CON-{DATE:ymd}-{SEQUENCE:4}', // Example: CON-250609-0001
            'type' => 'CON',
            'sequence_length' => 4,
            'code_length' => 15,        // Example: CON-YYMMDD-SSSS (3+1+6+1+4 = 15)
        ],

        'tax_code' => [
            'pattern' => 'TAX-{LOCATION}-{DATE:Y}-{SEQUENCE:3}', // Example: TAX-PNQ-2025-001 (Unique per location per year)
            'type' => 'TAX',
            'location' => 'PNQ',
            'date_format' => 'Y',
            'sequence_length' => 3,
            'code_length' => 14,        // TAX-LOC-YYYY-SSS (3+1+3+1+4+1+3 = 16). Adjust to 14.
        ],

        'department_code' => [
            'pattern' => 'DEPT-{TYPE}-{RANDOM:3}', // Example: DEPT-SALES-FGH
            'type' => 'SALES',          // Default type for department might be 'SALES', 'HR', etc.
            'use_sequence' => false,    // Uses random string
            'code_length' => 13,        // DEPT-SALES-RRR (4+1+5+1+3 = 14). Adjust to 13.
        ],

        // --- Add More Custom Patterns Below ---
        //
        // Example: Refund Authorization (RMA) Code
        // 'rma' => [
        //     'pattern' => 'RMA-{DATE:YmdHis}-{SEQUENCE:3}', // Example: RMA-20250609143000-001
        //     'type' => 'RMA',
        //     'sequence_length' => 3,
        //     'date_format' => 'YmdHis',
        //     'code_length' => 23, // RMA-YYYYMMDDHHMMSS-SSS (3+1+14+1+3 = 22. Pad to 23)
        // ],
        //
        // Example: Discount Coupon Code (purely random)
        // 'coupon' => [
        //     'pattern' => 'CPN-{RANDOM:10}', // Example: CPN-A1B2C3D4E5
        //     'type' => 'CPN',
        //     'use_sequence' => false,
        //     'code_length' => 14, // CPN-RRRRRRRRRR (3+1+10 = 14)
        // ],
    ],
];
