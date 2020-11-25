<?php

namespace App\Subscriber;

use App\Service\OrganizationService;
use Conduction\CommonGroundBundle\Event\CommonGroundEvents;
use Conduction\CommonGroundBundle\Event\CommongroundUpdateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrganizationSubscriber implements EventSubscriberInterface
{
    private $organizationService;

    public function __construct(OrganizationService $organizationService)
    {
        $this->organizationService = $organizationService;
    }

    public static function getSubscribedEvents()
    {
        return [
            CommonGroundEvents::CREATED  => 'created',
        ];
    }

    public function created(CommongroundUpdateEvent $event)
    {

        // Lets make sure that we are dealing with a Organization resource from WRC
        $resource = $event->getResource();

        // Lets see if we need to do anything with the resource
        if (!$resource || !is_array($resource) || $resource['@type'] != 'Organization') {
            return;
        }

        $this->organizationService->welcomeMail($resource);

        return $event;
    }
}
