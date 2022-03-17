<?php

namespace Plugin\Elepay\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Eccube\Common\EccubeConfig;
use Eccube\Controller\AbstractController;
use Plugin\Elepay\Form\Type\Admin\ConfigType;
use Plugin\Elepay\Repository\ConfigRepository;

class ConfigController extends AbstractController
{
    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * ConfigController constructor.
     *
     * @param EccubeConfig $eccubeConfig
     * @param ConfigRepository $configRepository
     */
    public function __construct(
        EccubeConfig $eccubeConfig,
        ConfigRepository $configRepository
    ) {
        $this->eccubeConfig = $eccubeConfig;
        $this->configRepository = $configRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/elepay/config", name="elepay_admin_config")
     * @Template("@Elepay/admin/config.twig")
     * @param Request $request
     * @return array|RedirectResponse
     */
    public function index(Request $request)
    {
        $config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $config = $form->getData();

            $this->entityManager->persist($config);
            $this->entityManager->flush();

            $this->addSuccess('elepay.admin.save.success', 'admin');
            return $this->redirectToRoute('elepay_admin_config');

        } else if ($form->isSubmitted()) {
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error;
            }
        }

        return [
            'form' => $form->createView()
        ];
    }
}
