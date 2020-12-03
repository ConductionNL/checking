<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\BalanceBundle\Service\BalanceService;
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

    private $mailingService;

    private $balanceService;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params, Security $security, Environment $twig, MailingService $mailingService, BalanceService $balanceService)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
        $this->security = $security;
        $this->twig = $twig;
        $this->mailingService = $mailingService;
        $this->balanceService = $balanceService;
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
        $accommodation['maximumAttendeeCapacity'] = 10;
        $accommodation = $this->commonGroundService->saveResource($accommodation, ['component' => 'lc', 'type' => 'accommodations']);

        // Create a Node
        $node['name'] = $organizationContact['name'];
        $node['accommodation'] = $this->commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'accommodations', 'id' => $accommodation['id']]);
        $node['organization'] = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
        $node = $this->commonGroundService->saveResource($node, ['component' => 'chin', 'type' => 'nodes']);

        $data = [];
        $data['node'] = $node;

        $organizationUrl = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);

        $this->balanceService->createAccount($organizationUrl, 1000);

        $this->mailingService->sendMail('mails/welcome_organization.html.twig', 'no-reply@conduction.nl', 'gino@conduction.nl', $data, 'Welcome');
    }
}
