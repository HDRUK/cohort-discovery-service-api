<?php

/**
 * Here we define the system error messages used across the application.
 * Each error message is associated with a unique code for easy reference.
 * This allows for consistent error handling and user feedback.
 *
 * Error codes are structured as follows:
 *
 * - 1000: General errors related to unsupported actions.
 * - 2000: Errors related to translation issues.
 * - 3000: Errors related to database operations.
 * - 4000: Errors related to API interactions.
 * - 5000: Errors related to authentication and authorisation.
 *
 * ...this provides us with 1000 unique error codes to work with for each category.
 */
return [
    'UNSUPPORTED_CONTEXT_TYPE' => [
        'message' => 'Unsupported context type: %s',
        'code' => 1000,
    ],
    'TRANSLATION_ERROR' => [
        'message' => 'Error translating query context: %s',
        'code' => 2000,
    ],
];
