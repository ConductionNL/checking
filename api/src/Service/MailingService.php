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

    /**
     * This function sends a mail.
     *
     * @param string $template path to email template.
     * @param string $sender   email of the sender.
     * @param string $receiver email of the receiver.
     * @param array  $data     array used to render the template with twig.
     * @param string $subject  subject of the email.
     *
     * @return array|false created message object.
     */
    public function sendMail(string $template, string $sender, string $receiver, array $data, string $subject)
    {
        $message = [];

        if ($this->params->get('app_env') == 'prod') {
            $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
        } else {
            $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
        }
        $message['status'] = 'queued';
        $message['subject'] = $subject;
        $message['content'] = $this->twig->render($template, $data);
        $message['reciever'] = $receiver;
        $message['sender'] = $sender;

        return $this->commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
    }
}
