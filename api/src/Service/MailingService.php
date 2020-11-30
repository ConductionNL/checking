<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class MailingService
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

    public function sendMail($template, $sender, $receiver, $data, $subject)
    {
        $message = [];

        if ($this->params->get('app_env') == 'prod') {
            $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
        } else {
            $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
        }
        $message['status'] = 'queued';
        $message['subject'] = $subject;
        $message['content'] = $this->twig->render($template, $data);
        $message['reciever'] = $receiver;
        $message['sender'] = $sender;

        $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
    }
}
