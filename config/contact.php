<?php

/*
 * The «تماس با ما» page (backlog item 173, step 14.431). Messages sent with its form go to the
 * panel's feedback queue (FieldSuggestion kind "feedback", category "contact").
 *
 * phones: number in E.164 without spaces; whatsapp/telegram links are built from it,
 * eitaa only when a username is given (Eitaa has no link by phone number).
 */
return [
    'email' => env('CONTACT_EMAIL', 'info@fahares.net'),

    'phones' => [
        ['number' => '+989192535021', 'label' => 'ایران', 'whatsapp' => true, 'telegram' => true, 'eitaa' => 'tabib2m'],
        ['number' => '+9647840001820', 'label' => 'عراق', 'whatsapp' => true, 'telegram' => true, 'eitaa' => null],
    ],
];
