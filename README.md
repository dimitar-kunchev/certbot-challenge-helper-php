# Store certbot validation challendges in a Mongo database so a public server can validate them

This package allows you to have multiple servers obtaining a Let's Encrypt certificate for the same domain by
storing the validation token in a database and having a public server confirm it in the HTTP-01 challenge.

Use at your own risk. There are other ways to have multiple edge servers with the same domain and SSL certificate.
This approach has some security implications and is somewhat more complex to set up.

## Installation

```bash
composer require dimitar-kunchev/certbot-challenge-helper-php
```

In order to integrate the helper your container will need to provide the db connection (and optionally a logger).

Next, you will also need to handle the .well-known/acme-challenge/ requests in your web server and forward them to the helper. For example:

```php
$app->get('/.well-known/acme-challenge/{token}', [\CertbotChallengeDBHelper\CertbotChallengeDBHelper::class, 'HandleTokenRequest']);
```

You will also need some CLI commands implementation for certbot to call. There are Symfony Console commands in the package that you can include:

```php
$application->add(new \CertbotChallengeDBHelper\Commands\PreHook($container));
$application->add(new \CertbotChallengeDBHelper\Commands\CleanupHook($container));
```

To integrate with certbot it needs to know you are using manual auth hooks:

```bash
sudo certbot certonly -d mydomain.com --manual --manual-auth-hook "/path/to/cli.php certbot:pre-hook" --manual-cleanup-hook "/path/to/cli.php certbot:cleanup-hook"
```

From there on any server that needs new certificates will write the token to the DB so the edge servers can validate it.
