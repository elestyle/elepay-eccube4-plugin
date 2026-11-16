<?php

namespace Plugin\elepay42\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Eccube\Controller\AbstractController;
use Plugin\elepay42\Form\Type\Admin\ConfigType;
use Plugin\elepay42\Repository\ConfigRepository;

/**
 * Routes are declared twice: as docblock annotations (read by EC-CUBE 4.2/4.3) and as PHP attributes (read by 4.4, and by 4.2/4.3 on PHP 8).
 * 4.2/4.3 on PHP 8 read both, and the one read later overrides the other, so always keep the two identical.
 * Always write each attribute on a single line, so that PHP 7.4 skips it as a line comment.
 */
class ConfigController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * ConfigController constructor.
     *
     * @param ConfigRepository $configRepository
     */
    public function __construct(
        ConfigRepository $configRepository
    ) {
        $this->configRepository = $configRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/elepay/config", name="elepay42_admin_config")
     * @param Request $request
     * @return Response
     */
    #[Route('/%eccube_admin_route%/elepay/config', name: 'elepay42_admin_config')]
    public function index(Request $request)
    {
        $config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $config = $form->getData();

            // Update the secret key only when one is entered (blank means "keep the current key")
            $secretKey = (string)$form->get('secret_key')->getData();
            if ($secretKey !== '') {
                $config->setSecretKey($secretKey);
            }

            $this->entityManager->persist($config);
            $this->entityManager->flush();

            $this->addSuccess('elepay.admin.save.success', 'admin');
            return $this->redirectToRoute('elepay42_admin_config');

        } else if ($form->isSubmitted()) {
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error;
            }
        }

        return $this->render('@elepay42/admin/config.twig', [
            'form' => $form->createView()
        ]);
    }
}
