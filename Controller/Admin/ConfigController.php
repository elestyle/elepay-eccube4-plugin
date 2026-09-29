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
 * ルートは docblock アノテーション（EC-CUBE 4.2/4.3 が読む）と PHP Attribute（4.4 と PHP 8 上の 4.2/4.3 が読む）の二重定義。
 * PHP 8 上の 4.2/4.3 は両方を読み、後から読んだ方で上書きするため、両者の内容は常に一致させること。
 * PHP 7.4 で Attribute を行コメントとして読み飛ばさせるため、Attribute は必ず 1 行で書くこと。
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
