<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class CheckinService
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

    public function createCheckin($node, $person = null, $user = null)
    {
        // TODO: Only create a checkin if the current amount of checkins on the node isn't higher than the node.maximumAttendeeCapacity?!

        $checkin = [];
        $checkin['node'] = 'nodes/'.$node['id'];
        if ($this->security->getUser()) {
            if(!isset($user)) {
                $user = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->security->getUser()->getUsername()])['hydra:member'][0];
            }
            if(!isset($person)) {
                $person = $this->commonGroundService->getResource($this->security->getUser()->getPerson());
            }
        } elseif(!isset($person)) {
            return 'Login or provide a person';
        }
        if (isset($user)) {
            $checkin['userUrl'] = $this->commonGroundService->cleanUrl(['component' => 'uc', 'type' => 'users', 'id' => $user['id']]);
        }
        $checkin['person'] = $this->commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);

        $checkin = $this->commonGroundService->createResource($checkin, ['component' => 'chin', 'type' => 'checkins']);

        $results = $this->processCheckin($checkin);
        // Do something with the $results?
        //var_dump($results);die(); // for example: var dump for testing purposes

        return $checkin;
    }

    public function processCheckin($checkin)
    {
        if ($accommodation = $this->commonGroundService->isResource($checkin['node']['accommodation'])) {
            if (key_exists('maximumAttendeeCapacity', $accommodation)) {
                return $this->checkMaxAttendees($checkin, $accommodation);
            } else {
                return 'De accommodation van de node heeft geen maximumAttendeeCapacity';
            }
        } else {
            return 'De accommodation van de node is geen resource';
        }
    }

    public function checkMaxAttendees($checkin, $accommodation)
    {
        $results = [];

        $numberOfCheckins = count($this->commonGroundService->getResourceList(['component'=>'chin', 'type'=>'checkins'], ['node.accommodation'=>$checkin['node']['accommodation']])['hydra:member']);
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
                array_push($results, $this->sendEmail($checkin, $accommodationData, 'maxCheckinCount'));
            } else {
                array_push($results, 'Email has already been sent');
            }
        } elseif ($percentage >= 90) {
            array_push($results, '90% or more');
            $messages = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'messages'], ['resource'=>$nodeUrl, 'type'=>'highCheckinCount'])['hydra:member'];
            if (count($messages) < 1) {
                array_push($results, $this->sendEmail($checkin, $accommodationData, 'highCheckinCount'));
            } else {
                array_push($results, 'Email has already been sent');
            }
        } else {
            array_push($results, 'No need to send a checkinCount email warning');
        }

        return $results;
    }

    public function sendEmail($checkin, $data, $emailType)
    {
        $content = [];
        $subject = '';
        switch ($emailType) {
            case 'highCheckinCount':
                $content = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'templates', 'id' => 'cdad2591-c288-4f54-8fe5-727f67e65949']);
                $subject = 'High checkin count';
                break;
            case 'maxCheckinCount':
                $content = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'templates', 'id' => 'f073f5b5-2853-4cca-8de4-9889f21aa6a2']);
                $subject = 'Max checkin count';
                break;
        }

        if ($this->params->get('app_env') == 'prod') {
            $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
        } else {
            $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
        }
        $message['status'] = 'queued';

        // determining the receiver (organization of the node)
        if ($organization = $this->commonGroundService->isResource($checkin['node']['organization'])) {
            if (isset($organization['administrationContact']) and $organizationContact = $this->commonGroundService->isResource($organization['administrationContact'])) {
                if (isset($organizationContact['emails']) and (count($organizationContact['emails']) > 0)) {
                    $receiver = $organizationContact['@id'];
                } else {
                    return 'No email receiver found [organization contact of the node does not have an email]';
                }
            } else {
                return 'No email receiver found [organization contact of the node is no resource]';
            }
        } else {
            return 'No email receiver found [organization of the node is no resource]';
        }
        $message['reciever'] = $receiver;
        $message['sender'] = 'no-reply@conduction.nl';
        $sender['name'] = 'Checking';

        $message['subject'] = $subject;
        $html = $this->commonGroundService->getResource($content)['content'];
        $template = $this->twig->createTemplate($html);
        $data = array_merge(['checkin' => $checkin, 'sender'=>$sender, 'receiver'=>$this->commonGroundService->getResource($message['reciever'])], $data);
        $message['content'] = $template->render($data);

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
    }
}
