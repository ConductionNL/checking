<?php

// src/Controller/DefaultController.php

namespace App\Controller;

use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
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
     * @Route("/")
     * @Template
     */
    public function indexAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        // On an index route we might want to filter based on user input
        $variables['query'] = array_merge($request->query->all(), $variables['post'] = $request->request->all());

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

            // Save the (new or already existing) node
            $resource['accommodation'] = $commonGroundService->cleanUrl(['component' => 'lc', 'type' => 'accommodations', 'id' => $accommodation['id']]);
            $commonGroundService->saveResource($resource, (['component' => 'chin', 'type' => 'nodes']));

            return $this->redirect($this->generateUrl('app_chin_nodes'));
        }

        return $variables;
    }

    /**
     * @Route("/organization")
     * @Template
     */
    public function organizationAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];

        if ($this->getUser()) {
            $variables['wrc'] = $commonGroundService->getResource($this->getUser()->getOrganization());

            $variables['style'] = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'styles', 'id' => $variables['wrc']['style']['id']]);
            $variables['favicon'] = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'images', 'id' => $variables['wrc']['style']['favicon']['id']]);

            if (isset($variables['wrc']['contact'])) {
                $variables['organization'] = $commonGroundService->getResource($variables['wrc']['contact']);
            }
        }

        if ($request->isMethod('POST') && $request->get('social')) {
            $resource = $request->request->all();
            $organization = [];
            $organization['@id'] = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'organizations', 'id' => $variables['organization']['id']]);
            $organization['id'] = $variables['organization']['id'];
            $organization['socials'][0]['name'] = $variables['organization']['name'];
            $organization['socials'][0]['description'] = $variables['organization']['name'];

            if (isset($resource['website'])) {
                $organization['socials'][0]['website'] = $resource['website'];
            }
            if (isset($resource['twitter'])) {
                $organization['socials'][0]['twitter'] = $resource['twitter'];
            }
            if (isset($resource['facebook'])) {
                $organization['socials'][0]['facebook'] = $resource['facebook'];
            }
            if (isset($resource['instagram'])) {
                $organization['socials'][0]['instagram'] = $resource['instagram'];
            }
            if (isset($resource['linkedin'])) {
                $organization['socials'][0]['linkedin'] = $resource['linkedin'];
            }

            $variables['organization'] = $commonGroundService->saveResource($organization, ['component' => 'cc', 'type' => 'organizations']);
        } elseif ($request->isMethod('POST') && $request->get('info')) {
            $resource = $request->request->all();
            $organization = [];
            $organization['@id'] = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'organizations', 'id' => $variables['organization']['id']]);
            $organization['id'] = $variables['organization']['id'];

            if (isset($resource['name'])) {
                $organization['name'] = $resource['name'];
            }
            if (isset($resource['email'])) {
                $organization['emails'][0]['email'] = $resource['email'];
            }
            if (isset($resource['telephone'])) {
                $organization['telephones'][0]['telephone'] = $resource['telephone'];
            }
            if (isset($resource['street'])) {
                $organization['adresses'][0]['street'] = $resource['street'];
            }
            if (isset($resource['houseNumber'])) {
                $organization['adresses'][0]['houseNumber'] = $resource['houseNumber'];
            }
            if (isset($resource['houseNumberSuffix'])) {
                $organization['adresses'][0]['houseNumberSuffix'] = $resource['houseNumberSuffix'];
            }
            if (isset($resource['postalCode'])) {
                $organization['adresses'][0]['postalCode'] = $resource['postalCode'];
            }
            if (isset($resource['locality'])) {
                $organization['adresses'][0]['locality'] = $resource['locality'];
            }

            $variables['organization'] = $commonGroundService->saveResource($organization, ['component' => 'cc', 'type' => 'organizations']);
        } elseif ($request->isMethod('POST') && $request->get('style')) {
            $resource = $request->request->all();
            $style = $variables['style'];
            $style['organizations'] = ['/organizations/'.$variables['wrc']['id']];
            $style['favicon'] = '/images/'.$variables['favicon']['id'];

            $favicon = $variables['favicon'];
            $favicon['organization'] = '/organizations/'.$variables['wrc']['id'];
            $favicon['style'] = '/styles/'.$variables['style']['id'];

            if (isset($_FILES['base64']) && $_FILES['base64']['error'] !== 4) {
                $path = $_FILES['base64']['tmp_name'];
                $type = filetype($_FILES['base64']['tmp_name']);
                $data = file_get_contents($path);
                $favicon['base64'] = 'data:image/'.$type.';base64,'.base64_encode($data);
                $variables['favicon'] = $commonGroundService->saveResource($favicon, ['component' => 'wrc', 'type' => 'images']);
            }

            $variables['style'] = $commonGroundService->saveResource($style, ['component' => 'wrc', 'type' => 'styles']);
        }

        return $variables;
    }

    /**
     * @Route("/userinfo")
     * @Template
     */
    public function userInfoAction(CommonGroundService $commonGroundService, Request $request, ParameterBagInterface $params)
    {
        $variables = [];

        $variables['person'] = $commonGroundService->getResource($this->getUser()->getPerson());

        if ($request->isMethod('POST') && $request->get('info')) {
            $resource = $request->request->all();
            $person = [];
            $person['@id'] = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $variables['person']['id']]);
            $person['id'] = $variables['person']['id'];

            if (isset($resource['firstName'])) {
                $person['givenName'] = $resource['firstName'];
            }
            if (isset($resource['lastName'])) {
                $person['familyName'] = $resource['lastName'];
            }
            if (isset($resource['birthday']) && $resource['birthday'] !== '') {
                $person['birthday'] = $resource['birthday'];
            }
            if (isset($resource['email'])) {
                $person['emails'][0]['email'] = $resource['email'];
            }
            if (isset($resource['telephone'])) {
                $person['telephones'][0]['telephone'] = $resource['telephone'];
            }
            if (isset($resource['street'])) {
                $person['adresses'][0]['street'] = $resource['street'];
            }
            if (isset($resource['houseNumber'])) {
                $person['adresses'][0]['houseNumber'] = $resource['houseNumber'];
            }
            if (isset($resource['houseNumberSuffix'])) {
                $person['adresses'][0]['houseNumberSuffix'] = $resource['houseNumberSuffix'];
            }
            if (isset($resource['postalCode'])) {
                $person['adresses'][0]['postalCode'] = $resource['postalCode'];
            }
            if (isset($resource['locality'])) {
                $person['adresses'][0]['locality'] = $resource['locality'];
            }

            $variables['person'] = $commonGroundService->saveResource($person, ['component' => 'cc', 'type' => 'people']);
        } elseif ($request->isMethod('POST') && $request->get('password')) {
            $newPassword = $request->get('newPassword');
            $repeatPassword = $request->get('repeatPassword');

            if ($newPassword !== $repeatPassword) {
                $variables['error'] = true;

                return $variables;
            } else {
                $credentials = [
                    'username'   => $this->getUser()->getUsername(),
                    'password'   => $request->request->get('currentPassword'),
                    'csrf_token' => $request->request->get('_csrf_token'),
                ];

                $user = $commonGroundService->createResource($credentials, ['component'=>'uc', 'type'=>'login'], false, true, false, false);

                if (!$user) {
                    $variables['wrongPassword'] = true;

                    return $variables;
                }

                $users = $commonGroundService->getResourceList(['component'=>'uc', 'type'=>'users'], ['username'=> $this->getUser()->getUsername()], true, false, true, false, false)['hydra:member'];
                $user = $users[0];

                $user['password'] = $newPassword;

                $this->addFlash('success', 'wachtwoord aangepast');
                $commonGroundService->updateResource($user);

                $message = [];

                if ($params->get('app_env') == 'prod') {
                    $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
                } else {
                    $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
                }
                $message['status'] = 'queued';
                $message['data'] = ['receiver' => $variables['person']['name']];
                $message['content'] = $commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'templates', 'id'=>'4125221c-74e0-46f9-97c9-3825a2011012']);
                $message['reciever'] = $user['username'];
                $message['sender'] = 'no-reply@conduction.nl';

                $commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
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
