<?php

/**
 * Support contact details surfaced on the help centre and FAQ pages.
 *
 * These were previously hardcoded inside SupportController and rendered into
 * templates/element/Pages/faq/office.php, so the two could disagree. Set the
 * real values in .env:
 *
 *   SUPPORT_PHONE=+255 700 000 000
 *   SUPPORT_EMAIL=support@fastnetstays.com
 *   SUPPORT_AVAILABLE_247=false
 */
return [

    'phone' => env('SUPPORT_PHONE'),

    'email' => env('SUPPORT_EMAIL', 'support@fastnetstays.com'),

    'available_247' => filter_var(env('SUPPORT_AVAILABLE_247', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Office addresses shown on the FAQ page. Each entry is rendered only if
     * supplied, so nothing is invented when unset.
     */
    'offices' => array_values(array_filter([
        array_filter([
            'city' => env('SUPPORT_OFFICE_1_CITY'),
            'address' => env('SUPPORT_OFFICE_1_ADDRESS'),
            'phone' => env('SUPPORT_OFFICE_1_PHONE'),
            'hours' => env('SUPPORT_OFFICE_1_HOURS'),
        ]),
        array_filter([
            'city' => env('SUPPORT_OFFICE_2_CITY'),
            'address' => env('SUPPORT_OFFICE_2_ADDRESS'),
            'phone' => env('SUPPORT_OFFICE_2_PHONE'),
            'hours' => env('SUPPORT_OFFICE_2_HOURS'),
        ]),
    ])),

];