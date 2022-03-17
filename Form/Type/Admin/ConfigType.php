<?php

namespace Plugin\Elepay\Form\Type\Admin;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Eccube\Common\EccubeConfig;
use Plugin\Elepay\Entity\Config;

class ConfigType extends AbstractType
{

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * ConfigType constructor.
     *
     * @param EccubeConfig $eccubeConfig
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function __construct(
        EccubeConfig $eccubeConfig
    )
    {
        $this->eccubeConfig = $eccubeConfig;
    }

    /**
     * Build config type form
     *
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            // ChoiceType Document
            // https://symfony.com/doc/current/reference/forms/types/choice.html
            ->add('public_key', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => trans('elepay.admin.config.from.validation.public_key')]),
                    new Assert\Length(['max' => $this->eccubeConfig['eccube_smtext_len']]),
                    new Assert\Regex([
                        'pattern' => '/^[[:graph:]]+$/i',
                        'message' => 'form_error.graph_only',
                    ]),
                ],
            ])

            ->add('secret_key', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => trans('elepay.admin.config.from.validation.secret_key')]),
                    new Assert\Length(['max' => $this->eccubeConfig['eccube_smtext_len']]),
                    new Assert\Regex([
                        'pattern' => '/^[[:graph:]]+$/',
                        'message' => 'form_error.graph_only',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Config::class,
        ]);
    }
}
