<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class OrganizationService
{
    /**
     * @var Security
     */
    private $security;

    private $commonGroundService;

    private $twig;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params, Security $security, Environment $twig)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
        $this->security = $security;
        $this->twig = $twig;
    }

    public function welcomeMail($resource)
    {
        if (strpos($resource['@self'], 'wrc') == false) {
            return;
        }

        $organization = $resource;
        $organizationContact = $this->commonGroundService->getResource($resource['contact']);

        // Create a Place
        $place['name'] = $organizationContact['name'];
        $place['publicAccess'] = true;
        $place['smokingAllowed'] = false;
        $place['openingTime'] = '09:00';
        $place['closingTime'] = '22:00';
        $place['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
        $place = $this->commonGroundService->saveResource($place, ['component' => 'lc', 'type' => 'places']);

        // Create a (example) Place Accommodation
        $accommodation['name'] = $organizationContact['name'];
        $accommodation['place'] = '/places/'.$place['id'];
        $accommodation = $this->commonGroundService->saveResource($accommodation, ['component' => 'lc', 'type' => 'accommodations']);

        // Create a Node
        $node['name'] = $organizationContact['name'];
        $node['accommodation'] = $this->commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'accommodations', 'id' => $accommodation['id']]);
        $node['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
        $node = $this->commonGroundService->saveResource($node, ['component' => 'chin', 'type' => 'nodes']);

        $message = [];

        if ($this->params->get('app_env') == 'prod') {
            $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
        } else {
            $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
        }
        $message['status'] = 'queued';
        $message['subject'] = 'Welcome';
        $html = $this->commonGroundService->getResource(['component'=>'wrc', 'type'=>'templates', 'id'=>'2ca5b662-e941-46c9-ae87-ae0c68d0aa5d'])['content'];

        $template = $this->twig->createTemplate($html);
        $message['content'] = $template->render(['node' => $node]);
        $message['reciever'] = $this->security->getUser()->getUsername();
        $message['sender'] = 'no-reply@conduction.nl';

        $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
    }
}
