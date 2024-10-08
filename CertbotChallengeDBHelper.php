<?php

namespace CertbotChallengeDBHelper;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\BSON\UTCDateTime;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CertbotChallengeDBHelper {
    private ContainerInterface $container;
    private Collection $collection;

    public function __construct (ContainerInterface $container) {
        $this->container = $container;
        /** @var Database $db */
        $db = $this->container->get('db');
        $this->collection = $db->selectCollection('certbotChallenges');
    }

    private function log(string $message, array $params = []) : void {
        if ($this->container->has('logger')) {
            $this->container->get('logger')->debug('CertbotChallengeDBHelper -> '.$message, $params);
        }
    }

    public function AddChallenge(string $domain, string $token, string $validation, array $allDomains) : void {
        $this->log('AddChallenge ', ['domain' => $domain, 'token' => $token, 'validation' => $validation]);
        $this->collection->insertOne([
            'domain' => strtolower($domain),
            'allDomains' => array_map('strtolower', $allDomains),
            'token' => strtolower($token),
            'validation' => $validation,
            'timestamp' => new UTCDateTime(),
            'expires' => new UTCDateTime(strtotime('+1 hour') * 1000)
        ]);
    }

    public function RemoveChallenge(string $domain, string $token) : void {
        $this->log('RemoveChallenge ', ['domain' => $domain, 'token' => $token]);
        $this->collection->deleteOne([
            'domain' => strtolower($domain),
            'token' => strtolower($token)
        ]);
        // remove any expired
        $this->collection->deleteMany([
            'expires' => ['$lt' => new UTCDateTime()]
        ]);
    }

    public function GetChallenge(string $domain, string $token) : ?string {
        $this->log('GetChallenge ', ['domain' => $domain, 'token' => $token]);

        $challenge = $this->collection->findOne(['token' => strtolower($token)]);

        if ($challenge === null) {
            $this->log('Token not found');
            return null;
        }

        // check if expired
        if ($challenge['expires'] < new UTCDateTime()) {
            $this->log('Token expired', ['expires' => $challenge['expires']]);
            $this->RemoveChallenge($challenge['domain'], $challenge['token']);
            return null;
        }

        $domain = strtolower($domain);
        $allDomainsInToken = array_map('strtolower', (array)$challenge['allDomains']);
        $domainInToken = strtolower($challenge['domain']);
        if (!in_array($domain, $allDomainsInToken) && $domain !== $domainInToken) {
            $this->log('Domain not found in challenge', [
                'domain' => $domain,
                'domainInToken' => $domainInToken,
                'allDomains' => $allDomainsInToken
            ]);
            return null;
        }

        $this->log('Token found');

        return $challenge['validation'];
    }

    public function HandleTokenRequest(ServerRequestInterface $request, ResponseInterface $response, array $args) : ResponseInterface {
        $this->log('HandleTokenRequest ', ['args' => $args, 'method' => $request->getMethod(), 'uri' => $request->getUri()]);
        if ($request->getMethod() !== 'GET') {
            $this->log('Method not GET - Token request end with 405');
            return $response->withStatus(405);
        }
        $token = $args['token'];
        $domain = $request->getUri()->getHost();
	    $validation = $this->GetChallenge($domain, $token);
        if ($validation === null && str_starts_with($domain, "www.")) {
            $this->log("Not found, retry without www. prefix");
            $validation=$this->GetChallenge(substr($domain, 4), $token);
        }
        if ($validation === null) {
            $this->log('No validation found - Token request end with 404');
            return $response->withStatus(404);
        }
        $this->log('Validation OK - Token request end with 200');
        $response->getBody()->write($validation);
        return $response;
    }

    public function ExecuteCertbotPreHook() : int {
        /**
         * from the environment variables we have:
         * CERTBOT_DOMAIN
         * CERTBOT_VALIDATION
         * CERTBOT_TOKEN
         * CERTBOT_REMAINING_CHALLENGES
         * CERTBOT_ALL_DOMAINS
         */

        $domain = getenv('CERTBOT_DOMAIN');
        $validation = getenv('CERTBOT_VALIDATION');
        $token = getenv('CERTBOT_TOKEN');
        $allDomains = explode(',', getenv('CERTBOT_ALL_DOMAINS') ?? '');

        if (!$domain || !$validation || !$token) {
            throw new \Exception('Missing environment variables');
        }

        $this->AddChallenge($domain, $token, $validation, $allDomains);

        return 0;
    }

    public function ExecuteCertbotCleanupHook() : int {
        /**
         * from the environment variables we have:
         * CERTBOT_DOMAIN
         * CERTBOT_VALIDATION
         * CERTBOT_TOKEN
         * CERTBOT_REMAINING_CHALLENGES
         * CERTBOT_ALL_DOMAINS
         */

        $domain = getenv('CERTBOT_DOMAIN');
        $validation = getenv('CERTBOT_VALIDATION');
        $token = getenv('CERTBOT_TOKEN');

        if (!$domain || !$validation || !$token) {
            throw new \Exception('Missing environment variables');
        }
        $this->RemoveChallenge($domain, $token);

        return 0;
    }
}
