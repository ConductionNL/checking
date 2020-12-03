<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Conduction\IdVaultBundle\IdVaultBundle;
use Conduction\IdVaultBundle\Service\IdVaultService;
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
    private $idVaultService;

    public function __construct(CommonGroundService $commonGroundService, IdVaultService $idVaultService, ParameterBagInterface $params, Security $security, Environment $twig)
    {
        $this->commonGroundService = $commonGroundService;
        $this->idVaultService = $idVaultService;
        $this->params = $params;
        $this->security = $security;
        $this->twig = $twig;
    }

    /**
     * This function renders an twig template (optionally with data) and sends the mail request to ID-Vault.
     *
     * @param string $template path to email template.
     * @param string $sender   email of the sender.
     * @param string $receiver email of the receiver.
     * @param array  $data     (optional) array used to render the template with twig.
     * @param string $subject  subject of the email.
     *
     * @return array|false array response from id-vault or false if failed.
     */
    public function sendMail(string $template, string $sender, string $receiver, string $subject, array $data = [])
    {
        $body = $this->twig->render($template, $data);

        $response = $this->idVaultService->sendMail('455f782e-4b2d-4cbb-ae83-6e297e25fef9', $body, $subject, $receiver, $sender);

        return $response;
    }
}
