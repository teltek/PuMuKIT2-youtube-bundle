<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdatePlaylistCommand extends ContainerAwareCommand
{
    const METATAG_PLAYLIST_COD = 'YOUTUBE';
    const METATAG_PLAYLIST_PATH = 'ROOT|YOUTUBE|';
    const DEFAULT_PLAYLIST_COD = 'YOUTUBECONFERENCES';
    const DEFAULT_PLAYLIST_TITLE = 'Conferences';

    private $dm;
    private $tagRepo;
    private $mmobjRepo;
    private $youtubeRepo;

    private $youtubeService;

    private $okUpdates = [];
    private $failedUpdates = [];
    private $errors = [];

    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:playlist')
            ->setDescription('Update Youtube playlists from Multimedia Objects')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List actions')
            ->setHelp(
                <<<'EOT'
Command to update playlist in Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = (true === $input->getOption('dry-run'));

        $this->initParameters();
        $multimediaObjects = $this->createYoutubeQueryBuilder()
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute()
        ;

        $this->youtubeService->syncPlaylistsRelations($dryRun);

        if ($dryRun) {
            return;
        }

        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $outUpdatePlaylists = $this->youtubeService->updatePlaylists($multimediaObject);
                if (0 !== $outUpdatePlaylists) {
                    $errorLog = sprintf('%s [%s] Unknown error in the update of Youtube Playlists of MultimediaObject with id %s: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outUpdatePlaylists);
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUpdates[] = $multimediaObject;
                    $this->errors[] = $errorLog;

                    continue;
                }
                $infoLog = sprintf('%s [%s] Updated all playlists of MultimediaObject with id %s', __CLASS__, __FUNCTION__, $multimediaObject->getId());
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $this->okUpdates[] = $multimediaObject;
            } catch (\Exception $e) {
                $errorLog = sprintf('%s [%s] Error: Couldn\'t update playlists of MultimediaObject with id %s [Exception]:%s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedUpdates[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
        $this->checkResultsAndSendEmail();
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }

    private function createYoutubeQueryBuilder()
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.origin')->notEqual('youtube')
            ->field('properties.pumukit1id')->exists(false);
    }

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('playlist update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }
}
