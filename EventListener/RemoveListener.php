<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\DependencyInjection\ContainerInterface;

class RemoveListener
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function preRemove(LifecycleEventArgs $args)
    {
        $document = $args->getDocument();

        if ($document instanceof MultimediaObject) {
            $dm = $this->container->get('doctrine_mongodb.odm.document_manager');
            $youtubeRepo = $dm->getRepository('PumukitYoutubeBundle:Youtube');
            if (null !== $youtubeId = $document->getProperty('youtube')) {
                if (null != $youtube = $youtubeRepo->find($youtubeId)) {
                    $youtube->setStatus(Youtube::STATUS_TO_DELETE);
                    $dm->persist($youtube);
                    $dm->flush();
                }
            }
        }
    }
}
