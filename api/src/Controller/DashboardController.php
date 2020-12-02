<?php

// src/Controller/DefaultController.php

namespace App\Controller;

use App\Service\PaymentService;
use Conduction\BalanceBundle\Service\BalanceService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Money\Money;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The Procces test handles any calls that have not been picked up by another test, and wel try to handle the slug based against the wrc.
 *
 * Class DefaultController
 *
 * @Route("/dashboard")
 */
class DashboardController extends AbstractController
{
    /**
     * @var FlashBagInterface
     */
    private $flash;

    public function __construct(FlashBagInterface $flash)
    {
        $this->flash = $flash;
    }

    /**
     * @Route("/")
     * @Template
     */
    public function indexAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];

        $person = $commonGroundService->getResource($this->getUser()->getPerson());

        $personUrl = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);

        $variables['checkins'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $personUrl])['hydra:member'];

        return $variables;
    }

    /**
     * @Route("/codes")
     * @Template
     */
    public function codesAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];
        $variables['organization'] = $commonGroundService->getResource($this->getUser()->getOrganization());
        $variables['accommodations'] = $commonGroundService->getResourceList(['component' => 'lc', 'type' => 'accommodations'], ['place.organization' => $variables['organization']['id']])['hydra:member'];
        $variables['nodes'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['organization' => $variables['organization']['id']])['hydra:member'];

        //set rgb values to hex and place them in temp property
        foreach ($variables['nodes'] as &$node) {
            if (isset($node['qrConfig'])) {
                if (isset($node['qrConfig']['foreground_color'])) {
                    $colors = $node['qrConfig']['foreground_color'];
                    $node['foregroundColor'] = sprintf('#%02x%02x%02x', $colors['r'], $colors['g'], $colors['b']);
                }

                if (isset($node['qrConfig']['background_color'])) {
                    $colors = $node['qrConfig']['background_color'];
                    $node['backgroundColor'] = sprintf('#%02x%02x%02x', $colors['r'], $colors['g'], $colors['b']);
                }

                if (isset($node['qrConfig']['logo_path'])) {
                    $node['logo'] = $node['qrConfig']['logo_path'];
                }
            }
        }

        if ($request->isMethod('POST')) {
            $resource = $request->request->all();

            // Check if the accommodation already exists
            if (key_exists('accommodation', $resource) and !empty($resource['accommodation'])) {
                $accommodation = $commonGroundService->getResource($resource['accommodation']);
                // Check if the place already exists
                if (key_exists('place', $accommodation) and !empty($accommodation['place'])) {
                    $place = $commonGroundService->getResource($commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'places', 'id' => $accommodation['place']['id']]));
                    if (key_exists('address', $place) and !empty($place['address'])) {
                        $address = $commonGroundService->getResource($commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'addresses', 'id' => $place['address']['id']]));
                    }
                }
            }

            // Create a new address or update the existing one for the place of this node
            $address['name'] = $resource['name'];
            if (key_exists('address', $resource)) {
                $address['street'] = $resource['address']['street'];
                $address['houseNumber'] = $resource['address']['houseNumber'];
                $address['houseNumberSuffix'] = $resource['address']['houseNumberSuffix'];
                $address['postalCode'] = $resource['address']['postalCode'];
                $address['locality'] = $resource['address']['locality'];
                // Check if address is set and if so, unset it in the resource used for creating a node
                unset($resource['address']);
            }
            $address = $commonGroundService->saveResource($address, (['component' => 'lc', 'type' => 'addresses']));

            // Create a new place or update the existing one for this node
            $place['name'] = $resource['name'];
            $place['description'] = $resource['description'];
            $place['publicAccess'] = true;
            $place['smokingAllowed'] = false;
            $place['openingTime'] = '09:00';
            $place['closingTime'] = '22:00';
            if (key_exists('accommodation', $resource) and !empty($resource['accommodation'])) {
                $place['accommodations'] = ['/accommodations/'.$accommodation['id']];
            }
            $place['organization'] = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $variables['organization']['id']]);
            $place['address'] = '/addresses/'.$address['id'];
            $place = $commonGroundService->saveResource($place, (['component' => 'lc', 'type' => 'places']));

            // Create a new accommodation or update the existing one for this node
            $accommodation['name'] = $resource['name'];
            $accommodation['description'] = $resource['description'];
            $accommodation['place'] = '/places/'.$place['id'];
            if (key_exists('maximumAttendeeCapacity', $resource) and !empty($resource['maximumAttendeeCapacity'])) {
                $accommodation['maximumAttendeeCapacity'] = (int) $resource['maximumAttendeeCapacity'];
                // Check if maximumAttendeeCapacity is set and if so, unset it in the resource used for creating a node
                unset($resource['maximumAttendeeCapacity']);
            }
            $accommodation = $commonGroundService->saveResource($accommodation, (['component' => 'lc', 'type' => 'accommodations']));

            // Node configuration/personalization
            if (key_exists('qrConfig', $resource)) {
                // Convert hex color to rgb
                list($r, $g, $b) = sscanf($resource['qrConfig']['foreground_color'], '#%02x%02x%02x');
                $resource['qrConfig']['foreground_color'] = ['r'=>$r, 'g'=>$g, 'b'=>$b];
                list($r, $g, $b) = sscanf($resource['qrConfig']['background_color'], '#%02x%02x%02x');
                $resource['qrConfig']['background_color'] = ['r'=>$r, 'g'=>$g, 'b'=>$b];
            }

            if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== 4) {
                $path = $_FILES['logo']['tmp_name'];
                $type = filetype($_FILES['logo']['tmp_name']);
                $data = file_get_contents($path);
                $resource['qrConfig']['logo_path'] = 'data:image/'.$type.';base64,'.base64_encode($data);
            }

            // Save the (new or already existing) node
            $resource['accommodation'] = $commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'accommodations', 'id' => $accommodation['id']]);
            $commonGroundService->saveResource($resource, (['component' => 'chin', 'type' => 'nodes']));

            return $this->redirect($this->generateUrl('app_dashboard_codes'));
        }

        return $variables;
    }

    /**
     * @Route("/organizations")
     * @Template
     */
    public function organizationsAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];

        if ($request->isMethod('POST') && $request->get('active')) {
            $organizationUrl = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $request->get('organization')]);
            $user = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->getUser()->getUsername()])['hydra:member'][0];

            $user['organization'] = $organizationUrl;

            foreach ($user['userGroups'] as &$userGroup) {
                $userGroup = '/groups/'.$userGroup['id'];
            }

            $user = $commonGroundService->updateResource($user);

            return $this->redirect($this->generateUrl('app_dashboard_organizations'));
        } elseif ($request->isMethod('POST')) {
            $kvk = ' ';
            $name = $request->get('name');
            if ($request->get('kvk')) {
                $kvk = $request->get('kvk');
            }
            $description = $request->get('description');

            $cc = [];
            $cc['name'] = $name;
            $cc['coc'] = $kvk;
            $cc['description'] = $description;
            $cc['adresses'][0]['name'] = 'address for '.$name;

            $cc = $commonGroundService->createResource($cc, ['component' => 'cc', 'type' => 'organizations']);

            $wrc = [];
            $wrc['rsin'] = ' ';
            $wrc['chamberOfComerce'] = $kvk;
            $wrc['name'] = $name;
            $wrc['description'] = $description;
            $wrc['contact'] = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'organizations', 'id' => $cc['id']]);
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== 4) {
                $path = $_FILES['logo']['tmp_name'];
                $type = filetype($_FILES['logo']['tmp_name']);
                $data = file_get_contents($path);
                $wrc['style']['name'] = 'style for '.$name;
                $wrc['style']['description'] = 'style for '.$name;
                $wrc['style']['css'] = ' ';
                $wrc['style']['favicon']['name'] = 'logo for '.$name;
                $wrc['style']['favicon']['description'] = 'logo for '.$name;
                $wrc['style']['favicon']['base64'] = 'data:image/'.$type.';base64,'.base64_encode($data);
            }

            $users = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->getUser()->getUsername()])['hydra:member'];
            if (count($users) > 0) {
                $userUrl = $commonGroundService->cleanUrl(['component' => 'uc', 'type' => 'users', 'id' => $users[0]['id']]);
                $wrc['privacyContact'] = $userUrl;
                $wrc['technicalContact'] = $userUrl;
                $wrc['administrationContact'] = $userUrl;
            }

            $wrc = $commonGroundService->createResource($wrc, ['component' => 'wrc', 'type' => 'organizations']);

            $userGroup = [];
            $userGroup['name'] = 'organization-'.$name;
            $userGroup['title'] = 'organization-'.$name;
            $userGroup['description'] = 'organization group for '.$name;
            $userGroup['organization'] = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $wrc['id']]);

            $group = $commonGroundService->createResource($userGroup, ['component' => 'uc', 'type' => 'groups']);

            $users = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->getUser()->getUsername()])['hydra:member'];
            if (count($users) > 0) {
                $organizations = [];
                $user = $users[0];

                $userGroups = [];
                foreach ($user['userGroups'] as $userGroup) {
                    array_push($userGroups, '/groups/'.$userGroup['id']);
                }

                $user['userGroups'] = $userGroups;
                $user['userGroups'][] = '/groups/'.$group['id'];
                $wrcUrl = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $wrc['id']]);
                $user['organization'] = $wrcUrl;

                $commonGroundService->updateResource($user);
            }
        }

        if ($this->getUser()) {
            $application = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'applications', 'id' => $params->get('app_id')]);
            $users = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->getUser()->getUsername()])['hydra:member'];
            if (count($users) > 0) {
                $organizations = [];
                $user = $users[0];
                foreach ($user['userGroups'] as $group) {
                    $organization = $commonGroundService->getResource($group['organization']);
                    if (!in_array($organization, $organizations) && $organization['id'] !== $application['organization']['id']) {
                        $organizations[] = $organization;
                    }
                }
                $variables['organizations'] = $organizations;
            }
        }

        return $variables;
    }

    /**
     * @Route("/organizations/{id}")
     * @Template
     */
    public function organizationAction(CommonGroundService $commonGroundService, BalanceService $balanceService, Request $request, ParameterBagInterface $params, $id)
    {
        $variables = [];

        $variables['organization'] = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'organizations', 'id' => $id]);

        $organizationUrl = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $id]);

        $account = $balanceService->getAcount($organizationUrl);

        if ($account !== false) {
            $account['balance'] = $balanceService->getBalance($organizationUrl);
            $variables['account'] = $account;
            $variables['payments'] = $commonGroundService->getResourceList(['component' => 'bare', 'type' => 'payments'],['acount.id' => $account['id'], 'order[dateCreated]' => 'desc'])['hydra:member'];
        }

        $groups = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'groups'], ['organization' => $organizationUrl])['hydra:member'];
        if (count($groups) > 0) {
            $group = $groups[0];
            $variables['users'] = $group['users'];
        }
        if (key_exists('contact', $variables['organization']) and !empty($variables['organization']['contact'])) {
            $variables['cc'] = $commonGroundService->getResource($variables['organization']['contact']);
        }

        if ($request->isMethod('POST') && $request->get('updateInfo')) {
            $name = $request->get('name');
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== 4) {
                if (key_exists('style', $variables['organization']) and !empty($variables['organization']['style'])) {
                    if (key_exists('favicon', $variables['organization']['style']) and !empty($variables['organization']['style']['favicon'])) {
                        $icon = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'images', 'id' => $variables['organization']['style']['favicon']['id']]);
                    } else {
                        // create icon for the style ?
                    }
                } else {
                    // create style and icon ?
                }
                $path = $_FILES['logo']['tmp_name'];
                $type = filetype($_FILES['logo']['tmp_name']);
                $data = file_get_contents($path);
                $icon['name'] = 'logo for '.$name;
                $icon['description'] = 'logo for '.$name;
                $icon['base64'] = 'data:image/'.$type.';base64,'.base64_encode($data);
                $commonGroundService->saveResource($icon);
            }

            $organization = $variables['organization'];
            $organization['name'] = $name;
            $organization['description'] = $request->get('description');
            $organization['privacyContact'] = $request->get('privacyContact');
            $organization['administrationContact'] = $request->get('administrationContact');
            $organization['technicalContact'] = $request->get('technicalContact');
            if (key_exists('style', $organization) and !empty($organization['style'])) {
                $organization['style'] = '/styles/'.$organization['style']['id'];
            }
            $commonGroundService->updateResource($organization);

            if (key_exists('cc', $variables)) {
                $cc = $variables['cc'];
                $cc['name'] = $name;
                $cc['coc'] = $request->get('coc');
                $address = [];
                $address['name'] = 'address for '.$name;
                $address['street'] = $request->get('street');
                $address['houseNumber'] = $request->get('houseNumber');
                $address['houseNumberSuffix'] = $request->get('houseNumberSuffix');
                $address['postalCode'] = $request->get('postalCode');
                $address['locality'] = $request->get('locality');
                $cc['adresses'][0] = $address;
                $commonGroundService->updateResource($cc);

                $variables['organization'] = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'organizations', 'id' => $id]);
                $variables['cc'] = $commonGroundService->getResource($variables['organization']['contact']);
            }
        }

        return $variables;
    }

    /**
     * @Route("/reservations")
     * @Template
     */
    public function ReservationsAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];
        if (in_array('group.admin', $this->getUser()->getRoles())) {
            $organization = $commonGroundService->getResource($this->getUser()->getOrganization());
            $organization = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
            $variables['reservations'] = $commonGroundService->getResourceList(['component' => 'arc', 'type' => 'reservations'], ['provider' => $organization, 'order[dateCreated]' => 'desc'])['hydra:member'];
        } else {
            $person = $commonGroundService->getResource($this->getUser()->getPerson());
            $person = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);
            $variables['reservations'] = $commonGroundService->getResourceList(['component' => 'arc', 'type' => 'reservations'], ['underName' => $person, 'order[dateCreated]' => 'desc'])['hydra:member'];

            foreach ($variables['reservations'] as &$reservation) {
                $nodes = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['accommodation' => $reservation['event']['calendar']['resource']])['hydra:member'];
                if (count($nodes) > 0) {
                    $reservation['node'] = $nodes[0];
                }

                if (isset($nodes[0]['configuration']['cancelable'])) {
                    $hourDiff = round((strtotime('now') - strtotime($reservation['event']['startDate'])) / 3600);
                    $dayDiff = round((strtotime($reservation['event']['startDate']) - strtotime('now')) / (60 * 60 * 24));

                    if ($hourDiff < (float) $nodes[0]['configuration']['cancelable'] && $dayDiff == 0) {
                        $reservation['cantCancel'] = true;
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * @Route("/transactions/{organization}")
     * @Template
     */
    public function TransactionsAction(Session $session, CommonGroundService $commonGroundService, BalanceService $balanceService, PaymentService $paymentService, Request $request, ParameterBagInterface $params, $organization)
    {
        // On an index route we might want to filter based on user input
        $variables = [];

        $organization = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization]);
        $organizationUrl = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
        $variables['organization'] = $organization;

        if ($session->get('mollieCode')) {
            $mollieCode = $session->get('mollieCode');
            $session->remove('mollieCode');
            $variables['message'] = $paymentService->processPayment($mollieCode, $organization);
        }

        $account = $balanceService->getAcount($organizationUrl);

        if ($account !== false) {
            $account['balance'] = $balanceService->getBalance($organizationUrl);
            $variables['account'] = $account;
            $variables['payments'] = $commonGroundService->getResourceList(['component' => 'bare', 'type' => 'payments'],['acount.id' => $account['id'], 'order[dateCreated]' => 'desc'])['hydra:member'];
        }

        if ($request->isMethod('POST')) {
            $amount = $request->get('amount') * 1.21;
            $amount = (number_format($amount, 2));

            $payment = $paymentService->createPaymentLink($amount, $request->get('redirectUrl'));
            $session->set('mollieCode', $payment['id']);

            return $this->redirect($payment['redirectUrl']);
        }

        return $variables;
    }

    /**
     * @Route("/checkins")
     * @Template
     */
    public function CheckinsAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        // On an index route we might want to filter based on user input
        $variables['query'] = array_merge($request->query->all(), $variables['post'] = $request->request->all());

        $person = $commonGroundService->getResource($this->getUser()->getPerson());

        $personUrl = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);

        $variables['checkins'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $personUrl])['hydra:member'];

        return $variables;
    }
}
