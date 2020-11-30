<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
//use Symfony\Component\Security\Core\Security;
//use Twig\Environment;

class CheckinService
{
//    /**
//     * @var Security
//     */
//    private $security;

    private $commonGroundService;

//    private $twig;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params)//, Security $security, Environment $twig)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
//        $this->security = $security;
//        $this->twig = $twig;
    }

    public function processCheckin($checkin)
    {
        $results = [];
        $node = $checkin['node'];

        if ($accommodation = $this->commonGroundService->isResource($node['accommodation'])) {
            if (key_exists('maximumAttendeeCapacity', $accommodation)) {
                $numberOfCheckins = count($this->commonGroundService->getResourceList(['component'=>'chin', 'type'=>'checkins'], ['node.accommodation'=>$node['accommodation']])['hydra:member']);
                $maximumAttendeeCapacity = $accommodation['maximumAttendeeCapacity'];

                // Calculate what percentage of the maximum attendees in checkins there is
                $percentage = round($numberOfCheckins / $maximumAttendeeCapacity * 100, 1, PHP_ROUND_HALF_UP);

                $results['numberOfCheckins'] = $numberOfCheckins;
                $results['maximumAttendeeCapacity'] = $maximumAttendeeCapacity;
                $results['percentage'] = $percentage;

                // Check if the number of active checkins (almost) exceeds the maximum number of attendees and if so, send a email.
                $accommodationData['accommodation'] = $accommodation;
                $accommodationData['numberOfCheckins'] = $numberOfCheckins;
                $nodeUrl = $this->commonGroundService->cleanUrl(['component'=>'chin', 'type'=>'node', 'id'=>$checkin['node']['id']]);
                if ($percentage >= 100) {
                    array_push($results, '100% or more');
                    $messages = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'messages'], ['resource'=>$nodeUrl, 'type'=>'maxCheckinCount'])['hydra:member'];
                    if (count($messages) < 1) {
//                        array_push($results, $this->sendEmail($webHook, $checkin, $accommodationData, 'maxCheckinCount'));
                    } else {
                        array_push($results, 'Email has already been sent');
                    }
                } elseif ($percentage >= 80) {
                    array_push($results, '80% or more');
                    $messages = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'messages'], ['resource'=>$nodeUrl, 'type'=>'highCheckinCount'])['hydra:member'];
                    if (count($messages) < 1) {
//                        array_push($results, $this->sendEmail($webHook, $checkin, $accommodationData, 'highCheckinCount'));
                    } else {
                        array_push($results, 'Email has already been sent');
                    }
                }
            } else {
                return 'De accommodation van de node heeft geen maximumAttendeeCapacity';
            }
        } else {
            return 'De accommodation van de node is geen resource';
        }

        return $results;
    }
}
