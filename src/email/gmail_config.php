
<?php

require_once __DIR__ . '/../vendor/autoload.php';

function getGmailClient() {
    $client = new Google\Client();

    $client->setClientId('838167772236-n24261gbvd3hc5kgbugeit8a547m5i72.apps.googleusercontent.com');
    $client->setClientSecret('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');

    $client->setRedirectUri(
        'http://localhost/SDC-Hackathon/src/email/oauth_callback.php'
    );

    $client->addScope(
        Google\Service\Gmail::GMAIL_READONLY
    );

    $client->setAccessType('offline');
    $client->setPrompt('consent');

    return $client;
}
