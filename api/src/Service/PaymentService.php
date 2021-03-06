<?php

// App\Service\NotificationService.php

namespace App\Service;

use Conduction\BalanceBundle\Service\BalanceService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use GuzzleHttp\Client;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PaymentService
{
    private $params;
    private $commonGroundService;
    private $balanceService;

    public function __construct(CommonGroundService $commonGroundService, ParameterBagInterface $params, BalanceService $balanceService)
    {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
        $this->balanceService = $balanceService;
    }

    /**
     * This function generates an payment url and mollie id and returns it.
     *
     * @param string $amount      amount of money that needs to be paid.
     * @param string $redirectUrl url where mollie needs to send user after payment.
     *
     * @return array|false array containing redirectUrl (where we send the user to) and id (which we use in processPayment function).
     */
    public function createPaymentLink(string $amount, string $redirectUrl)
    {
        $body = [
            'amount'      => [
                'currency' => 'EUR',
                'value'    => $amount,
            ],
            'description' => 'funds for Checking.nu',
            'redirectUrl' => $redirectUrl,
            'locale'      => 'en_US',
        ];

        $headers = [
            'Authorization' => 'Bearer test_H8PeFq62HpNFPQmer4GuEUWupMwSqQ',
            'Accept'        => 'application/json',
        ];

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://api.mollie.com',
            // You can set any number of default request options.
            'timeout'  => 2.0,
        ]);

        $response = $client->request('POST', '/v2/payments', [
            'form_params'  => $body,
            'content_type' => 'application/x-www-form-urlencoded',
            'headers'      => $headers,
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        $info = [];

        $info['id'] = $response['id'];
        $info['redirectUrl'] = $response['_links']['checkout']['href'];

        return $info;
    }

    /**
     * This function gets information of the payment and if paid adds funds to account & creates an invoice.
     *
     * @param string $id           id provided by mollie.
     * @param string $organization id of the organization used for the account.
     *
     * @return string|false string containing info about payment.
     */
    public function processPayment(string $id, string $organization)
    {
        $headers = [
            'Authorization' => 'Bearer test_H8PeFq62HpNFPQmer4GuEUWupMwSqQ',
            'Accept'        => 'application/json',
        ];

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://api.mollie.com',
            // You can set any number of default request options.
            'timeout'  => 2.0,
        ]);

        $response = $client->request('GET', '/v2/payments/'.$id, [
            'headers' => $headers,
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        if ($response['status'] == 'paid') {
            $organizationUrl = $this->commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);

            $amount = ($response['amount']['value'] / (1 + .21)) * 100;
            $this->balanceService->addCredit(Money::EUR($amount), $organizationUrl, $organization['name']);

            $item = [];
            $item['name'] = 'Checking Credit';
            $item['quantity'] = 1;
            $item['price'] = strval($amount / 100);
            $item['priceCurrency'] = 'EUR';

            $invoice = [];
            $invoice['customer'] = $organizationUrl;
            $invoice['name'] = 'Checking wallet funds';
            $invoice['items'][] = $item;
            $invoice['targetOrganization'] = $organizationUrl;
            $invoice['price'] = strval($amount / 100);
            $invoice['priceCurrency'] = 'EUR';
            $invoice['paid'] = true;
            $invoice['reference'] = Uuid::uuid4();

            $invoice = $this->commonGroundService->createResource($invoice, ['component' => 'bc', 'type' => 'invoices']);

            $templateAmount = $amount / 100;

            return 'Payment processed successfully! <br> €'.$templateAmount.'.00 was added to your balance. <br>  Invoice with reference: '.$invoice['reference'].' is created.';
        } else {
            return 'Something went wrong, the status of the payment is: '.$response['status'].' please try again.';
        }
    }
}
