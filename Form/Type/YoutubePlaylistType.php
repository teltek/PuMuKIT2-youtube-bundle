<?php

namespace Pumukit\YoutubeBundle\Form\Type;

use Pumukit\NewAdminBundle\Form\Type\Base\TextI18nType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Translation\TranslatorInterface;

class YoutubePlaylistType extends AbstractType
{
    private $translator;
    private $locale;

    public function __construct(TranslatorInterface $translator, $locale = 'en')
    {
        $this->translator = $translator;
        $this->locale = $locale;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('i18n_title', TextI18nType::class, [
            'label' => $this->translator->trans('Title', [], null, $this->locale),
            'attr' => ['class' => 'form-control'],
            'required' => true,
        ]);
    }

    public function getBlockPrefix()
    {
        return 'pumukit_youtube_playlist';
    }
}
