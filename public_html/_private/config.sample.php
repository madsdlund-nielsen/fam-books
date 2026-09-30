<?php
// Kopiér denne fil til config.php (samme mappe) og udfyld værdierne.
// config.php må ALDRIG committes — den er i .gitignore.

return [
    // Den adresse siden ligger på, uden skråstreg til sidst.
    // Brug punycode for æøå-domæner: familiebøger.dk = xn--familiebger-ngb.dk
    'site_url' => 'https://xn--familiebger-ngb.dk',

    // simply.com kontrolpanel → Databaser (MySQL)
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
    ],

    'stripe' => [
        // Hemmelig nøgle fra Stripe → Udviklere → API-nøgler.
        // Brug sk_test_... mens I tester, og skift til sk_live_... (eller en
        // begrænset rk_live_... nøgle med "Checkout Sessions: Write" og
        // "Customer portal: Write") ved lancering.
        'secret_key' => 'sk_test_REPLACE_ME',

        // Produktet "Familiebøger" som årligt abonnement. Beløbet er i øre inkl. moms
        // og bruges kun, hvis price_id er tomt (så oprettes en årlig pris automatisk).
        // Produkt-id'et er forskelligt i test- og live-tilstand, så brug det
        // der passer til secret_key ovenfor.
        'product_id' => 'prod_VM9Qxqvr80ceiw',
        'amount' => 89900,
        'currency' => 'dkk',

        // Den årlige, tilbagevendende pris (899 kr./år) på produktet. Når det er
        // udfyldt, bruges det i stedet for product_id + amount.
        'price_id' => 'price_1ULR7bECicFapRox9jg8nyAh',

        // Signeringsnøgle fra Stripe → Udviklere → Webhooks → dit endpoint
        // (https://<site_url>/stripe-webhook.php). Anbefales, så betalinger
        // registreres, selv hvis køberen lukker browseren før tak-siden.
        'webhook_secret' => '',
    ],

    // Startdatoer: første mulige start er mandagen 3 uger efter ugen med
    // sidens første salg (før første salg: 3 uger efter indeværende uge).
    // Når den dato er passeret, er første mulige start den kommende mandag.
    'start_dates' => [
        'weeks_after_first_sale' => 3,
        'options' => 8, // antal mandage køberen kan vælge imellem
    ],

    'timezone' => 'Europe/Copenhagen',

    // Tilfældig tekst — bruges til at hashe IP-adresser på ventelisten.
    'ip_salt' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // Sæt til true midlertidigt for at se fejl i browseren. Aldrig i drift.
    'debug' => false,
];
