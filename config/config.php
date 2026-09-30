<?php
/**
 * ---------------------------------------------------------------------------
 * FlatMate  |  Application Configuration
 * ---------------------------------------------------------------------------
 * Edit the DB_* values below to match your MySQL/MariaDB server.
 * The defaults match a stock XAMPP / WAMP / MAMP installation.
 */

declare(strict_types=1);

return [

    /* ------------------------------------------------------------------ */
    /*  DATABASE  —  DEMO CONNECTION CREDENTIALS                              */
    /* ------------------------------------------------------------------ */
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'flatmate_db',
        'username' => 'root',
        'password' => '',            // XAMPP default is an empty root password
        'charset'  => 'utf8mb4',

        // PDO driver + options applied to every connection
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ],
    ],

    /* ------------------------------------------------------------------ */
    /*  APPLICATION                                                        */
    /* ------------------------------------------------------------------ */
    'app' => [
        'name'          => 'FlatMate',
        'tagline'       => 'Apartment / Shared Flat Management',
        'version'       => '1.0.0',
        'env'           => 'local',            // local | production
        'timezone'      => 'Asia/Dhaka',

        // Absolute URL to the folder that contains index.php
        'base_url'      => '/flatmate',

        'currency'      => '৳',               // BDT
        'currency_code' => 'BDT',

        // Session tuning
        'session_name'  => 'FLATMATE_SESSID',
        'session_life'  => 60 * 60 * 24 * 14,   // 14 days "remember me"

        // Pagination
        'per_page'      => 20,

        // Duty scheduler generation horizon (days forward / back)
        'chore_horizon_forward' => 21,
        'chore_horizon_back'    => 14,
    ],

    /* ------------------------------------------------------------------ */
    /*  FEATURE FLAGS                                                      */
    /* ------------------------------------------------------------------ */
    'features' => [
        'auto_generate_chores'   => true,
        'strict_offboarding'     => true,   // block offboarding on non-zero balance
        'min_debtors_for_optimal'=> 10,     // above this, fall back to greedy settle
        'allow_duplicate_split'  => false,
    ],
];
