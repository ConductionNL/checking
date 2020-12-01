<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\BalanceBundle\Service\BalanceService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Money\Money;
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
    private $mailingService;
    private $twig;
    private $balanceService;

    public function __construct(CommonGroundService $commonGroundService, MailingService $mailingService, ParameterBagInterface $params, Security $security, Environment $twig, BalanceService $balanceService)
    {
        $this->commonGroundService = $commonGroundService;
        $this->mailingService = $mailingService;
        $this->params = $params;
        $this->security = $security;
        $this->twig = $twig;
        $this->balanceService = $balanceService;
    }

    public function createCheckin($node, $person = null, $user = null)
    {
        // TODO: Only create a checkin if the current amount of checkins on the node isn't higher than the node.maximumAttendeeCapacity?!
        $checkin = [];
        $checkin['node'] = 'nodes/'.$node['id'];
        if ($this->security->getUser()) {
            if (!isset($user)) {
                $user = $this->commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->security->getUser()->getUsername()])['hydra:member'][0];
            }
            if (!isset($person)) {
                $person = $this->commonGroundService->getResource($this->security->getUser()->getPerson());
            }
        } elseif (!isset($person)) {
            return 'Login or provide a person';
        }
        if (isset($user)) {
            $checkin['userUrl'] = $this->commonGroundService->cleanUrl(['component' => 'uc', 'type' => 'users', 'id' => $user['id']]);
        }
        $checkin['person'] = $this->commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);

        $checkin = $this->commonGroundService->createResource($checkin, ['component' => 'chin', 'type' => 'checkins']);

        $this->removeBalance($node);

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

    public function removeBalance($node) {

        $organization = $this->commonGroundService->getResource($node['organization']);
        $organizationUrl = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);

        $this->balanceService->removeCredit(Money::EUR(5), $organizationUrl, $organization['name']);
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
                array_push($results, $this->checkinCountMail($checkin, $accommodationData, 'maxCheckinCount'));
            } else {
                array_push($results, 'Email has already been sent');
            }
        } elseif ($percentage >= 90) {
            array_push($results, '90% or more');
            $messages = $this->commonGroundService->getResourceList(['component'=>'bs', 'type'=>'messages'], ['resource'=>$nodeUrl, 'type'=>'highCheckinCount'])['hydra:member'];
            if (count($messages) < 1) {
                array_push($results, $this->checkinCountMail($checkin, $accommodationData, 'highCheckinCount'));
            } else {
                array_push($results, 'Email has already been sent');
            }
        } else {
            array_push($results, 'No need to send a checkinCount email warning');
        }

        return $results;
    }

    public function checkinCountMail($checkin, $data, $emailType)
    {
        $template = [];
        $subject = '';
        switch ($emailType) {
            case 'highCheckinCount':
                $template = 'mails/high_checkin_count.html.twig';
                $subject = 'High checkin count';
                break;
            case 'maxCheckinCount':
                $template = 'mails/max_checkin_count.html.twig';
                $subject = 'Max checkin count';
                break;
        }

        // determining the receiver (organization of the node)
        if ($organization = $this->commonGroundService->isResource($checkin['node']['organization'])) {
            if (isset($organization['administrationContact']) and $organizationContact = $this->commonGroundService->isResource($organization['administrationContact'])) {
                if (isset($organizationContact['emails']) and (count($organizationContact['emails']) > 0)) {
                    $receiver = $organizationContact['@id'];
                } else {
                    return 'No email receiver found [organization administrationContact of the node does not have an email]';
                }
            } else {
                return 'No email receiver found [organization administrationContact of the node is no resource]';
            }
        } else {
            return 'No email receiver found [organization of the node is no resource]';
        }

        $sender['name'] = 'Checking';
        $data = array_merge(['checkin' => $checkin, 'sender'=>$sender, 'receiver'=>$this->commonGroundService->getResource($receiver)], $data);

        return $this->mailingService->sendMail($template, 'no-reply@conduction.nl', $receiver, $data, $subject);
    }

    public function checkBalance($organization) {

    }
}
