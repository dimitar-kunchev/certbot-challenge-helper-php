<?php

namespace CertbotChallengeDBHelper\Commands;

use CertbotChallengeDBHelper\CertbotChallengeDBHelper;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupHook extends Command {
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
        parent::__construct();
    }

    protected function configure() : void {
        $this->setName('certbot:cleanup-hook')->setDescription('Certbot cleanup hook');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int {
        $helper = new CertbotChallengeDBHelper($this->container);
        return $helper->ExecuteCertbotCleanupHook();
    }
}