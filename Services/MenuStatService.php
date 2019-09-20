<?php

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuStatService implements ItemInterface
{
    public function getName(): string
    {
        return 'Youtube Stat';
    }

    public function getUri(): string
    {
        return 'pumukit_youtube_stat_index';
    }

    public function getAccessRole(): string
    {
        return 'ROLE_ACCESS_YOUTUBE';
    }
}
