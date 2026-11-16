<?php

namespace Plugin\elepay42\Form\Type\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
            // Pass constraint options as positional arguments: array options are deprecated since Symfony 7.3,
            // and named arguments are not available on PHP 7.4 (EC-CUBE 4.2)
            ->add('public_key', TextType::class, [
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(null, trans('elepay.admin.config.from.validation.public_key')),
                    new Assert\Length(null, null, $this->eccubeConfig['eccube_smtext_len']),
                    new Assert\Regex('/^[[:graph:]]+$/i', 'form_error.graph_only'),
                ],
            ])

            // Never show the secret key again. It is saved only when entered; when left blank, the stored value is kept (see the controller)
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
                /** @var Config|null $config */
                $config = $event->getData();
                $configured = $config !== null && (string)$config->getSecretKey() !== '';

                $constraints = [
                    new Assert\Length(null, null, $this->eccubeConfig['eccube_smtext_len']),
                    new Assert\Regex('/^[[:graph:]]+$/', 'form_error.graph_only'),
                ];
                if (!$configured) {
                    array_unshift($constraints, new Assert\NotBlank(null, trans('elepay.admin.config.from.validation.secret_key')));
                }

                $event->getForm()->add('secret_key', PasswordType::class, [
                    'mapped' => false,
                    'required' => !$configured,
                    'always_empty' => true,
                    'constraints' => $constraints,
                    'attr' => [
                        // Keep the browser from overwriting this field by autofilling the admin's login password
                        'autocomplete' => 'new-password',
                        'placeholder' => $configured ? trans('elepay.admin.config.from.secret_key_placeholder') : '',
                    ],
                ]);
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Config::class,
        ]);
    }
}
