<?php

// src/Controller/UserController.php

namespace App\Controller;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The Procces test handles any calls that have not been picked up by another test, and wel try to handle the slug based against the wrc.
 *
 * Class UserController
 *
 * @Route("/users")
 */
class UserController extends AbstractController
{
    /**
     * @Route("/")
     * @Template
     */
    public function indexAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/logout")
     * @Template
     */
    public function logoutAction(Session $session, Request $request)
    {
        $session->set('requestType', null);
        $session->set('request', null);
        $session->set('contact', null);
        $session->set('organisation', null);
        $session->invalidate();

        $text = $this->translator->trans('U bent uitgelogd');

        // Throw te actual flash
        $this->flash->add('error', $text);

        return $this->redirect($this->generateUrl('app_default_index'));
    }

    /**
     * @Route("/auth/idvault")
     * @Template
     */
    public function idvaultAction(Session $session, Request $request, CommonGroundService $commonGroundService, ParameterBagInterface $params, EventDispatcherInterface $dispatcher)
    {
        $session->set('backUrl', $request->query->get('backUrl'));

        $providers = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'id-vault', 'application' => $params->get('app_id')])['hydra:member'];
        $provider = $providers[0];

        $redirect = $request->getUri();

        if (strpos($redirect, '?') == true) {
            $redirect = substr($redirect, 0, strpos($redirect, '?'));
        }

        if (isset($provider['configuration']['app_id']) && isset($provider['configuration']['secret'])) {
            return $this->redirect('http://id-vault.com/oauth/authorize?client_id='.$provider['configuration']['app_id'].'&response_type=code&scopes=schema.person.email+schema.person.given_name+schema.person.family_name&state=12345&redirect_uri='.$redirect);
        } else {
            return $this->render('500.html.twig');
        }
    }
}
