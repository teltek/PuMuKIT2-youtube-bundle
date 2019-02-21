<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeDeleteCommand extends ContainerAwareCommand
{
    const PUB_CHANNEL_WEBTV = 'PUCHWEBTV';
    const PUB_CHANNEL_YOUTUBE = 'PUCHYOUTUBE';
    const PUB_DECISION_AUTONOMOUS = 'PUDEAUTO';

    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;

    private $youtubeService;

    private $okRemoved = array();
    private $failedRemoved = array();
    private $errors = array();

    private $logger;

    private $syncStatus;

    private $dryRun;

    protected function configure()
    {
        $this
            ->setName('youtube:delete')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List multimedia objects to delete')
            ->setDescription('Command to delete videos from Youtube')
            ->setHelp(
                <<<'EOT'
Command to delete controlled videos from Youtube.
                
EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        if ($this->syncStatus) {
            $status = array(MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN);
        } else {
            $status = array(MultimediaObject::STATUS_PUBLISHED);
        }
        $notPublishedMms = $this->getMultimediaObjectsInYoutubeWithoutStatus($publishedYoutubeIds, $status);
        if (0 != count($notPublishedMms) && !$this->dryRun) {
            $output->writeln('Removing '.count($notPublishedMms).' object(s) with status not published');
            $this->deleteVideosFromYoutube($notPublishedMms, $output);
        } else {
            $state = 'Not published multimedia objects';
            $this->showMultimediaObjects($output, $state, $notPublishedMms);
        }

        $arrayPubTags = $this->getContainer()->getParameter('pumukit_youtube.pub_channels_tags');
        foreach ($arrayPubTags as $tagCode) {
            $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
            $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
            // TODO When tag IMPORTANT is defined as child of PUBLICATION DECISION Tag
            $notCorrectTagMms = $this->getMultimediaObjectsInYoutubeWithoutTagCode($publishedYoutubeIds, $tagCode);
            if (0 != count($notCorrectTagMms) && !$this->dryRun) {
                $output->writeln('Removing '.count($notCorrectTagMms).' object(s) w/o tag '.$tagCode);
                $this->deleteVideosFromYoutube($notCorrectTagMms, $output);
            } else {
                $state = 'Not correct tags multimedia objects';
                $this->showMultimediaObjects($output, $state, $notCorrectTagMms);
            }
        }

        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($publishedYoutubeIds, 'public');
        if (0 != count($notPublicMms) && !$this->dryRun) {
            $output->writeln('Removing '.count($notPublicMms).' object(s) with broadcast not public');
            $this->deleteVideosFromYoutube($notPublicMms, $output);
        } else {
            $state = 'Not public multimedia objects';
            $this->showMultimediaObjects($output, $state, $notPublicMms);
        }

        $orphanYoutubes = $this->youtubeRepo->findBy(array('status' => Youtube::STATUS_TO_DELETE));
        if (0 != count($orphanYoutubes) && !$this->dryRun) {
            $output->writeln('Removing '.count($orphanYoutubes).' orphanYoutube(s) ');
            $this->deleteOrphanVideosFromYoutube($orphanYoutubes, $output);
        } else {
            $state = 'Orphan youtube documents';
            $this->showYoutubeMultimediaObjects($output, $state, $orphanYoutubes);
        }

        if (!$this->dryRun) {
            $this->checkResultsAndSendEmail();
        }
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->syncStatus = $this->getContainer()->getParameter('pumukit_youtube.sync_status');
        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okRemoved = array();
        $this->failedRemoved = array();
        $this->errors = array();

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
        $this->dryRun = (true === $input->getOption('dry-run'));
    }

    private function deleteVideosFromYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  .'] Started removing video from Youtube of MultimediaObject with id "'
                  .$mm->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outDelete = $this->youtubeService->delete($mm);
                if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unknown error in the removal from Youtube of MultimediaObject with id "'
                      .$mm->getId().'": '.$outDelete;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $mm;
                    $this->errors[] = $errorLog;
                    continue;
                }
                $this->okRemoved[] = $mm;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  .'] Removal of video from MultimediaObject with id "'.$mm->getId()
                  .'" has failed. '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function deleteOrphanVideosFromYoutube($orphanYoutubes, OutputInterface $output)
    {
        foreach ($orphanYoutubes as $youtube) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  .'] Started removing orphan video from Youtube with id "'
                  .$youtube->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outDelete = $this->youtubeService->deleteOrphan($youtube);
                if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unknown error in the removal from Youtube id "'
                      .$youtube->getId().'": '.$outDelete;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $youtube;
                    $this->errors[] = $errorLog;
                    continue;
                }
                $this->okRemoved[] = $youtube;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  .'] Removal of video from Youtube with id "'.$youtube->getId()
                  .'" has failed. '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $youtube;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function getStringIds($mongoIds)
    {
        $stringIds = array();
        foreach ($mongoIds as $mongoId) {
            $stringIds[] = $mongoId->__toString();
        }

        return $stringIds;
    }

    private function getMultimediaObjectsInYoutubeWithoutStatus($youtubeIds, $status)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('status')->notIn($status)
            ->getQuery()
            ->execute();
    }

    private function getMultimediaObjectsInYoutubeWithoutTagCode($youtubeIds, $tagCode)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('tags.cod')->notEqual($tagCode)
            ->getQuery()
            ->execute();
    }

    private function getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($youtubeIds, $broadcastTypeId)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('embeddedBroadcast.type')->notEqual('public')
            ->getQuery()
            ->execute();
    }

    private function createYoutubeQueryBuilder($youtubeIds = array())
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->in($youtubeIds)
            ->field('properties.origin')->notEqual('youtube')
            ->field('properties.pumukit1id')->exists(false);
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->tagRepo->findByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            foreach ($this->okRemoved as $mm) {
                if ($mm instanceof MultimediaObject) {
                    if ($mm->containsTagWithCod(self::PUB_CHANNEL_YOUTUBE)) {
                        $this->tagService->removeTagFromMultimediaObject($mm, $youtubeTag->getId(), false);
                    }
                }
            }
            $this->dm->flush();
        }
        if (!empty($this->okRemoved) || !empty($this->failedRemoved)) {
            $this->youtubeService->sendEmail('remove', $this->okRemoved, $this->failedRemoved, $this->errors);
        }
    }

    /**
     * @param OutputInterface $output
     * @param                 $state
     * @param                 $multimediaObjects
     */
    private function showMultimediaObjects(OutputInterface $output, $state, $multimediaObjects)
    {
        $numberMultimediaObjects = count($multimediaObjects);
        $output->writeln(
            array(
                "\n",
                "<info>***** $state ***** ($numberMultimediaObjects)</info>",
                "\n",
            )
        );

        if ($numberMultimediaObjects > 0) {
            foreach ($multimediaObjects as $multimediaObject) {
                $output->writeln($multimediaObject->getId().' - '.$multimediaObject->getProperty('youtubeurl').' - '.$multimediaObject->getProperty('pumukit1id'));
            }
        }
    }

    /**
     * @param OutputInterface $output
     * @param                 $state
     * @param                 $youtubeDocuments
     */
    private function showYoutubeMultimediaObjects(OutputInterface $output, $state, $youtubeDocuments)
    {
        $numberYoutubeDocuments = count($youtubeDocuments);
        $output->writeln(
            array(
                "\n",
                "<info>***** $state ***** ($numberYoutubeDocuments)</info>",
                "\n",
            )
        );

        if ($numberYoutubeDocuments > 0) {
            foreach ($youtubeDocuments as $youtube) {
                $output->writeln($youtube->getMultimediaObjectId().' - '.$youtube->getLink());
            }
        }
    }
}
