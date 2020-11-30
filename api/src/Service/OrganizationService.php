<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;
use App\Service\MailingService;

class OrganizationService
{
    /**
     * @var Security
     */
    private $security;

    private $commonGroundService;

    private $twig;

    private $mailingService;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params, Security $security, Environment $twig, MailingService $mailingService)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
        $this->security = $security;
        $this->twig = $twig;
        $this->mailingService = $mailingService;
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

        $data = [];
        $data['node'] = $node;

        $this->mailingService->sendMail('mails/welcome_organization.html.twig', 'no-reply@conduction.nl', $this->security->getUser()->getUsername(), $data, 'Welcome');

    }
}
