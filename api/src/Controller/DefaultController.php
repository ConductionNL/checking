<?php

// src/Controller/DefaultController.php

namespace App\Controller;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Procces test handles any calls that have not been picked up by another test, and wel try to handle the slug based against the wrc.
 *
 * Class DefaultController
 *
 * @Route("/")
 */
class DefaultController extends AbstractController
{
    /**
     * @Route("/")
     * @Template
     */
    public function indexAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        // On an index route we might want to filter based on user input
        $variables['query'] = array_merge($request->query->all(), $variables['post'] = $request->request->all());

        return $variables;
    }

    /**
     * @Route("/login")
     * @Template
     */
    public function loginAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/register")
     * @Template
     */
    public function registerAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/privacy")
     * @Template
     */
    public function privacyAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/contact")
     * @Template
     */
    public function contactAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];

        if ($request->isMethod('POST')) {
            $message = [];

            if ($params->get('app_env') == 'prod') {
                $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
            } else {
                $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
            }
            $message['status'] = 'queued';
            $message['subject'] = 'Contact Form message from'.$request->get('name');
            $html = '<p>';
            $html .= $request->get('message');
            $html .= '</p>';
            $message['content'] = $html;
            $message['reciever'] = 'info@conduction.nl';
            $message['sender'] = $request->get('email');

            $commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
        }

        return $variables;
    }

    /**
     * @Route("/about")
     * @Template
     */
    public function aboutAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/terms")
     * @Template
     */
    public function termsAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/organization")
     * @Template
     */
    public function organizationAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/tech")
     * @Template
     */
    public function techAction(CommonGroundService $commonGroundService, Request $request)
    {
        $variables = [];

        return $variables;
    }

    /**
     * @Route("/newsletter")
     * @Template
     */
    public function newsletterAction(Session $session, Request $request, CommonGroundService $commonGroundService, ParameterBagInterface $params, EventDispatcherInterface $dispatcher)
    {
        // TODO: use email used in form to subscribe to the newsletter?

        $session->set('backUrl', $request->query->get('backUrl'));

        $providers = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'id-vault', 'application' => $params->get('app_id')])['hydra:member'];
        $provider = $providers[0];

        $redirect = $this->generateUrl('app_default_index', ['message' => 'you have successfully signed up for the newsletter!'], UrlGeneratorInterface::ABSOLUTE_URL);

        if (isset($provider['configuration']['app_id']) && isset($provider['configuration']['secret'])) {
            $dev = '';
            if ($params->get('app_env') == 'dev') {
                $dev = 'dev.';
            }

            return $this->redirect('http://'.$dev.'id-vault.com/sendlist/authorize?client_id='.$provider['configuration']['app_id'].'&send_lists=98e72bec-e632-4b61-ba0f-53452ffe5ed9&redirect_uri='.$redirect);
        } else {
            return $this->render('500.html.twig');
        }
    }
}
