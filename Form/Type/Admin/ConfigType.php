<?php

namespace Plugin\elepay42\Form\Type\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Eccube\Common\EccubeConfig;
use Plugin\elepay42\Entity\Config;

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
            // 制約は位置引数で渡す. 配列オプションは Symfony 7.3 以降で非推奨、
            // 名前付き引数は PHP 7.4（EC-CUBE 4.2）で使えないため
            ->add('public_key', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(null, trans('elepay.admin.config.from.validation.public_key')),
                    new Assert\Length(null, null, $this->eccubeConfig['eccube_smtext_len']),
                    new Assert\Regex('/^[[:graph:]]+$/i', 'form_error.graph_only'),
                ],
            ])

            ->add('secret_key', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(null, trans('elepay.admin.config.from.validation.secret_key')),
                    new Assert\Length(null, null, $this->eccubeConfig['eccube_smtext_len']),
                    new Assert\Regex('/^[[:graph:]]+$/', 'form_error.graph_only'),
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
