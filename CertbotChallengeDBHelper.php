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

    public function AddChallenge(string $domain, string $token, $validation) : void {
        $this->collection->insertOne([
            'domain' => $domain,
            'token' => $token,
            'validation' => $validation,
            'timestamp' => new UTCDateTime(),
            'expires' => new UTCDateTime(strtotime('+1 hour') * 1000)
        ]);
    }

    public function RemoveChallenge(string $domain, string $token) : void {
        $this->collection->deleteOne([
            'domain' => $domain,
            'token' => $token
        ]);
        // remove any expired
        $this->collection->deleteMany([
            'expires' => ['$lt' => new UTCDateTime()]
        ]);
    }

    public function GetValidation(string $domain, string $token) : ?string {
        $challenge = $this->collection->findOne(['token' => $token]);

        if ($challenge === null) {
            return null;
        }

        // check if expired
        if ($challenge['expires'] < new UTCDateTime()) {
            $this->RemoveChallenge($challenge['domain'], $challenge['token']);
            return null;
        }

        return $challenge['validation'];
    }

    public function HandleTokenRequest(ServerRequestInterface $request, ResponseInterface $response, array $args) : ResponseInterface {
        $logger = $this->container->has('logger') ? $this->container->get('logger') : null;
        if ($logger !== null) {
            $logger->debug('HandleTokenRequest ', ['args' => $args, 'method' => $request->getMethod(), 'uri' => $request->getUri()]);
        }
        return $response->withStatus(405);
        if ($request->getMethod() !== 'GET') {
            return $response->withStatus(405);
        }
        $token = $args['token'];
        $domain = $request->getUri()->getHost();
        $validation = $this->GetValidation($domain, $token);
        if ($validation === null) {
            if ($logger !== null) {
                $logger->debug('Token not found');
            }
            return $response->withStatus(404);
        }
        if ($logger !== null) {
            $logger->debug('Token found!');
        }
        if ($validation->getExpires() < new UTCDateTime()) {
            if ($logger !== null) {
                $logger->debug('Token expired');
            }
            $this->RemoveChallenge($domain, $token);
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
