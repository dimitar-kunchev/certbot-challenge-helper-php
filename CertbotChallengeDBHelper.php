<?php

namespace CertbotChallengeDBHelper;

use MongoDB\Collection;
use MongoDB\Database;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use function DI\get;

class CertbotChallengeDBHelper {
    private ContainerInterface $container;
    private Collection $collection;

    public function __construct (ContainerInterface $container) {
        $this->container = $container;
        /** @var Database $db */
        $db = $this->container->get('db');
        $this->collection = $db->selectCollection('certbotChallenges');
    }

    public function AddChallenge(string $domain, string $token, $validation) : void {
        $this->collection->insertOne([
            'domain' => $domain,
            'token' => $token,
            'validation' => $validation,
            'timestamp' => new MongoDB\BSON\UTCDateTime(),
            'expires' => new MongoDB\BSON\UTCDateTime(strtotime('+1 hour') * 1000)
        ]);
    }

    public function RemoveChallenge(string $domain, string $token) : void {
        $this->collection->deleteOne([
            'domain' => $domain,
            'token' => $token
        ]);
        // remove any expired
        $this->collection->deleteMany([
            'expires' => ['$lt' => new MongoDB\BSON\UTCDateTime()]
        ]);
    }

    public function GetValidation(string $token) : ?string {
        $challenge = $this->collection->findOne(['token' => $token]);

        if ($challenge === null) {
            return null;
        }

        // check if expired
        if ($challenge['expires'] < new MongoDB\BSON\UTCDateTime()) {
            $this->RemoveChallenge($challenge['domain'], $challenge['token']);
            return null;
        }

        return $challenge['validation'];
    }

    public function HandleTokenRequest(ServerRequestInterface $request, ResponseInterface $response, array $args) : ResponseInterface {
        if ($request->getMethod() !== 'GET') {
            return $response->withStatus(405);
        }
        $token = $args['token'];
        $validation = $this->GetValidation($token);
        if ($validation === null) {
            return $response->withStatus(404);
        }
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

        if ($domain === null || $validation === null || $token === null) {
            throw new \Exception('Missing environment variables');
        }

        $this->AddChallenge($domain, $token, $validation);

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

        if ($domain === null || $validation === null || $token === null) {
            throw new \Exception('Missing environment variables');
        }
        $this->RemoveChallenge($domain, $token);

        return 0;
    }
}