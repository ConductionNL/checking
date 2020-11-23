<?php

// src/Controller/ProcessController.php

namespace App\Controller;

use Conduction\CommonGroundBundle\Security\User\CommongroundUser;
use Conduction\CommonGroundBundle\Service\ApplicationService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
//use App\Service\RequestService;
use Endroid\QrCode\Factory\QrCodeFactoryInterface;
//use App\Service\RequestService;
use function GuzzleHttp\Promise\all;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The Procces test handles any calls that have not been picked up by another test, and wel try to handle the slug based against the wrc.
 *
 * Class ProcessController
 *
 * @Route("/chin")
 */
class ChinController extends AbstractController
{
    /**
     * @Route("/checkin/user")
     * @Template
     */
    public function checkinUserAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, string $slug = 'home')
    {
        $variables = [];
        $variables['checkins'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $this->getUser()->getPerson(), 'order[dateCreated]' => 'desc'])['hydra:member'];

        return $variables;
    }

    /**
     * @Route("/checkin/organisation")
     * @Security("is_granted('ROLE_group.admin') or is_granted('ROLE_group.organization_admin')")
     *
     * @Template
     */
    public function checkinOrganizationAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, string $slug = 'home')
    {
        $variables = [];
        $variables['checkins'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $this->getUser()->getOrganization(), 'order[dateCreated]' => 'desc'])['hydra:member'];

        return $variables;
    }

    /**
     * @Route("/checkin/statistics")
     * @Security("is_granted('ROLE_group.admin') or is_granted('ROLE_group.organization_admin')")
     * @Template
     */
    public function checkinStatisticsAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, string $slug = 'home')
    {
        $variables = [];
        $variables['checkins'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $this->getUser()->getOrganization(), 'order[dateCreated]' => 'desc'])['hydra:member'];

        return $variables;
    }

    /**
     * @Route("/checkin/reservations")
     * @Template
     */
    public function checkinReservationsAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, string $slug = 'home')
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
     * @Route("/nodes")
     * @Security("is_granted('ROLE_scope.chin.node.write')")
     * @Template
     */
    public function nodesAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, string $slug = 'home')
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
     * This function shows all available locations.
     *
     * @Route("/")
     * @Security("is_granted('ROLE_group.admin') or is_granted('ROLE_group.organization_admin')")
     * @Template
     */
    public function indexAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        $variables = $applicationService->getVariables();
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'cmc', 'type' => 'contact_moments'], ['receiver' => $this->getUser()->getPerson()])['hydra:member'];

        return $variables;
    }

    /**
     * This function will render a qr code.
     *
     * It provides the following optional query parameters
     * size: the size of the image renderd, default  300
     * margin: the maring on the image in pixels, default 10
     * file: the file type renderd, default png
     * encoding: the encoding used for the file, default: UTF-8
     *
     * @Route("/render/{id}")
     */
    public function renderAction(Session $session, $id, Request $request, FlashBagInterface $flash, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, QrCodeFactoryInterface $qrCodeFactory)
    {
        $node = $commonGroundService->getResource(['component' => 'chin', 'type' => 'nodes', 'id'=>$id]);

        $url = $this->generateUrl('app_chin_checkin', ['code'=>$node['reference']], UrlGeneratorInterface::ABSOLUTE_URL);

        $configuration = $node['qrConfig'];
        if ($request->query->get('size')) {
            $configuration['size'] = $request->query->get('size', 300);
        }
        if ($request->query->get('margin')) {
            $configuration['margin'] = $request->query->get('margin', 10);
        }

        $qrCode = $qrCodeFactory->create($url, $configuration);

        // Set advanced options
        $qrCode->setWriterByName($request->query->get('file', 'png'));
        $qrCode->setEncoding($request->query->get('encoding', 'UTF-8'));
        //$qrCode->setErrorCorrectionLevel(ErrorCorrectionLevel::HIGH());

        $response = new Response($qrCode->writeString());
        $response->headers->set('Content-Type', $qrCode->getContentType());
        $response->setStatusCode(Response::HTTP_NOT_FOUND);

        return $response;
    }

    /**
     * This function will prompt a downloaden for the qr code.
     *
     * It provides the following optional query parameters
     * size: the size of the image renderd, default  300
     * margin: the maring on the image in pixels, default 10
     * file: the file type renderd, default png
     * encoding: the encoding used for the file, default: UTF-8
     *
     * @Route("/download/{id}")
     */
    public function downloadAction(Session $session, $id, $type = 'png', Request $request, FlashBagInterface $flash, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, QrCodeFactoryInterface $qrCodeFactory)
    {
        $splits = explode('.', $id);
        $id = $splits[0];
        $extention = $splits[1];
        $node = $commonGroundService->getResource(['component' => 'chin', 'type' => 'nodes', 'id'=>$id]);

        $url = $this->generateUrl('app_chin_checkin', ['code'=>$node['reference']], UrlGeneratorInterface::ABSOLUTE_URL);

        $configuration = $node['qrConfig'];
        if ($request->query->get('size')) {
            $configuration['size'] = $request->query->get('size', 300);
        }
        if ($request->query->get('margin')) {
            $configuration['margin'] = $request->query->get('margin', 10);
        }

        $qrCode = $qrCodeFactory->create($url, $configuration);

        // Set advanced options
        $qrCode->setWriterByName($request->query->get('file', $extention));
        $qrCode->setEncoding($request->query->get('encoding', 'UTF-8'));
        //$qrCode->setErrorCorrectionLevel(ErrorCorrectionLevel::HIGH());

        $filename = 'qr-code.'.$extention;

        $response = new Response($qrCode->writeString());
        // Create the disposition of the file
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        // Set the content disposition
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * This function will kick of the suplied proces with given values.
     *
     * @Route("/checkin/{code}")
     * @Template
     */
    public function checkinAction(Session $session, $code = null, Request $request, FlashBagInterface $flash, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];
        $session->set('code', $code);
        $variables['code'] = $code;

        // Oke we want a user so lets check if we have one
        if (!$this->getUser()) {
            return $this->redirect($this->generateUrl('app_chin_login', ['code'=>$code]));
        }

        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        // We want this resource to be a checkin
        if ($variables['resource']['type'] != 'checkin') {
            switch ($variables['resource']['type']) {
                case 'reservation':
                    return $this->redirect($this->generateUrl('app_chin_reservation', ['code'=>$code]));
                    break;
                case 'clockin':
                    return $this->redirect($this->generateUrl('app_chin_clockin', ['code'=>$code]));
                    break;
                case 'mailing':
                    return $this->redirect($this->generateUrl('app_chin_mailing', ['code'=>$code]));
                    break;
                default:
                    $this->addFlash('warning', 'Could not find a valid type for reference '.$code);

                    return $this->redirect($this->generateUrl('app_default_index'));
            }
        }

        $variables['code'] = $code;

        if ($request->isMethod('POST') && $request->request->get('method') == 'checkin') {

            //update person
            $name = $request->request->get('name');
            $email = $request->request->get('email');
            $tel = $request->request->get('telephone');

            $person = $commonGroundService->getResource($this->getUser()->getPerson());

            // Wat doet dit?
            $user = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['person' => $this->getUser()->getPerson()])['hydra:member'];
            $user = $user[0];

            if (isset($person['emails'][0])) {
                //$emailResource = $person['emails'][0];
                //$emailResource['email'] = $email;
                // @Hotfix
                //$emailResource['@id'] = $commonGroundService->cleanUrl(['component'=>'cc', 'type'=>'emails', 'id'=>$emailResource['id']]);
                //$emailResource = $commonGroundService->updateResource($emailResource);
                //$person['emails'][0] = 'emails/'.$emailResource['id'];
            } else {
                $emailObject['email'] = $email;
                $emailObject = $commonGroundService->createResource($emailObject, ['component' => 'cc', 'type' => 'emails']);
                $person['emails'][0] = 'emails/'.$emailObject['id'];
            }

            if (isset($person['telephones'][0])) {
                //$telephoneResource = $person['telephones'][0];
                //$telephoneResource['telephone'] = $tel;
                // @Hotfix
                //$telephoneResource['@id'] = $commonGroundService->cleanUrl(['component'=>'cc', 'type'=>'telephones', 'id'=>$telephoneResource['id']]);
                //$telephoneObject = $commonGroundService->updateResource($telephoneResource);
                //$person['telephones'][0] = 'telephones/'.$telephoneObject['id'];
            } elseif ($tel) {
                $telephoneObject['telephone'] = $tel;
                $telephoneObject = $commonGroundService->createResource($telephoneObject, ['component' => 'cc', 'type' => 'telephones']);
                $person['telephones'][0] = 'telephones/'.$telephoneObject['id'];
            }

            // @Hotfix
            $person['@id'] = $commonGroundService->cleanUrl(['component'=>'cc', 'type'=>'people', 'id'=>$person['id']]);
            //$person = $commonGroundService->updateResource($person);

            // Lets see if there if there is an active checking
            $checkIns = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $person['@id'], 'node' => 'nodes/'.$variables['resource']['id'], 'order[dateCreated]' => 'desc'])['hydra:member'];

            if ((count($checkIns) > 1) && $checkIns[0]['dateCheckedOut'] == null) {
                $hourDiff = round((strtotime('now') - strtotime($checkIns[0]['dateCreated'])) / 3600);
                // edit this number to determine how many hours before you are not seens as checked in anymore
                $hoursForCheckout = 4;
                if ($hourDiff < $hoursForCheckout) {
                    return $this->redirect($this->generateUrl('app_chin_checkout', ['code'=>$code]));
                }
            }

            // Create check-in
            $checkIn = [];
            $checkIn['node'] = 'nodes/'.$variables['resource']['id'];
            $checkIn['person'] = $person['@id'];
            $checkIn['userUrl'] = $user['@id'];
            if ($session->get('checkingProvider')) {
                $checkIn['provider'] = $session->get('checkingProvider');
            } else {
                $checkIn['provider'] = 'session';
            }

            $checkIn = $commonGroundService->createResource($checkIn, ['component' => 'chin', 'type' => 'checkins']);

            return $this->redirect($this->generateUrl('app_chin_confirmation', ['id'=>$checkIn['id']]));
        }

        return $variables;
    }

    /**
     * @Route("/edit")
     * @Template
     */
    public function editAction(Session $session, Request $request, CommonGroundService $commonGroundService)
    {
        $variables['code'] = $session->get('code');
        $nodes = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $variables['code']])['hydra:member'];
        $variables['person'] = $commonGroundService->getResource($this->getUser()->getPerson());

        if (count($nodes) > 0) {
            $variables['node'] = $nodes[0];
        }

        if ($request->isMethod('POST')) {
            $person = $variables['person'];

            $telephone = $request->get('telephone');
            $email = $request->get('email');

            $person['givenName'] = $request->get('givenName');
            $person['familyName'] = $request->get('familyName');
            if (isset($telephone)) {
                $person['telephones'][0] = [];
                $person['telephones'][0]['telephone'] = $telephone;
            }
            if (isset($email)) {
                $person['emails'][0] = [];
                $person['emails'][0]['email'] = $email;
            }

            $person = $commonGroundService->updateResource($person);

            $backUrl = $session->get('backUrl', false);
            if ($backUrl) {
                return $this->redirect($backUrl);
            } else {
                return $this->redirect($this->generateUrl('app_default_index'));
            }
        }

        return $variables;
    }

    /**
     * @Route("/reset/{token}")
     * @Template
     */
    public function resetAction(Session $session, Request $request, CommonGroundService $commonGroundService, ParameterBagInterface $params, $token = null)
    {
        $variables['code'] = $session->get('code');
        $nodes = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $variables['code']])['hydra:member'];
        if ($token) {
            $application = $commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id' => $params->get('app_id')]);
            $providers = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'token', 'application' => $params->get('app_id')])['hydra:member'];
            $tokens = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'tokens'], ['token' => $token, 'provider.name' => $providers[0]['name']])['hydra:member'];
            if (count($tokens) > 0) {
                $variables['token'] = $tokens[0];
                $userUlr = $commonGroundService->cleanUrl(['component'=>'uc', 'type'=>'users', 'id'=>$tokens[0]['user']['id']]);
                $variables['selectedUser'] = $userUlr;
            }
        }

        if (count($nodes) > 0) {
            $variables['node'] = $nodes[0];
        }

        if ($request->isMethod('POST') && $request->get('password')) {
            $user = $commonGroundService->getResource($request->get('selectedUser'));
            $password = $request->get('password');

            $user['password'] = $password;

            $commonGroundService->updateResource($user);

            $variables['reset'] = true;
        } elseif ($request->isMethod('POST')) {
            $variables['message'] = true;
            $username = $request->get('email');
            $users = $commonGroundService->getResourceList(['component'=>'uc', 'type'=>'users'], ['username'=> $username], true, false, true, false, false);
            $users = $users['hydra:member'];

            $application = $commonGroundService->getResource(['component'=>'wrc', 'type'=>'applications', 'id' => $params->get('app_id')]);
            $organization = $application['organization']['@id'];
            $providers = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'providers'], ['type' => 'token', 'application' => $params->get('app_id')])['hydra:member'];

            if (count($users) > 0) {
                $user = $users[0];
                $person = $commonGroundService->getResource($user['person']);

                $validChars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $code = substr(str_shuffle(str_repeat($validChars, ceil(3 / strlen($validChars)))), 1, 5);

                $token = [];
                $token['token'] = $code;
                $token['user'] = 'users/'.$user['id'];
                $token['provider'] = 'providers/'.$providers[0]['id'];
                $token = $commonGroundService->createResource($token, ['component' => 'uc', 'type' => 'tokens']);

                $url = $request->getUri();
                $link = $url.'/'.$token['token'];

                $message = [];

                if ($params->get('app_env') == 'prod') {
                    $message['service'] = '/services/eb7ffa01-4803-44ce-91dc-d4e3da7917da';
                } else {
                    $message['service'] = '/services/1541d15b-7de3-4a1a-a437-80079e4a14e0';
                }
                $message['status'] = 'queued';
                $message['data'] = ['resource' => $link, 'sender'=> 'no-reply@conduction.nl'];
                $message['content'] = $commonGroundService->cleanUrl(['component'=>'wrc', 'type'=>'templates', 'id'=>'60314e20-3760-4c17-9b18-3a99a11cbc5f']);
                $message['reciever'] = $user['username'];
                $message['sender'] = 'no-reply@conduction.nl';

                $commonGroundService->createResource($message, ['component'=>'bs', 'type'=>'messages']);
            }
        }

        return $variables;
    }

    /**
     * This function will kick of the suplied proces with given values.
     *
     * @Route("/reservation/{code}")
     * @Template
     */
    public function reservationAction(Session $session, $code = null, Request $request, FlashBagInterface $flash, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];
        $session->set('code', $code);
        $variables['code'] = $code;

        // Oke we want a user so lets check if we have one
        if (!$this->getUser()) {
            return $this->redirect($this->generateUrl('app_chin_login', ['code'=>$code]));
        }
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }
        $variables['nodes'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['organization' => $variables['resource']['organization'], 'type' => 'reservation'])['hydra:member'];

        // We want this resource to be a checkin
        if ($variables['resource']['type'] != 'reservation') {
            switch ($variables['resource']['type']) {
                case 'checkin':
                    return $this->redirect($this->generateUrl('app_chin_checkin', ['code'=>$code]));
                    break;
                case 'mailing':
                    return $this->redirect($this->generateUrl('app_chin_mailing', ['code'=>$code]));
                    break;
                case 'clockin':
                    return $this->redirect($this->generateUrl('app_chin_clockin', ['code'=>$code]));
                    break;
                default:
                    $this->addFlash('warning', 'Could not find a valid type for reference '.$code);

                    return $this->redirect($this->generateUrl('app_default_index'));
            }
        }

        $variables['code'] = $code;
        $variables['organization'] = $commonGroundService->getResource($variables['resource']['organization']);

        $calendars = $commonGroundService->getResourceList(['component' => 'arc', 'type' => 'calendars'], ['resource' => $variables['resource']['accommodation']])['hydra:member'];

        if (count($calendars) > 0) {
            $variables['calendar'] = $calendars[0];
        } else {
            $variables['error'] = 'Something went wrong';
        }

        if ($request->isMethod('POST')) {
            $validChars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $name = substr(str_shuffle(str_repeat($validChars, ceil(3 / strlen($validChars)))), 1, 5);

            $amount = $request->get('amount');
            $person = $commonGroundService->getResource($this->getUser()->getPerson());

            // Create reservation
            $reservation = [];
            $reservation['name'] = $name;
            $reservation['underName'] = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);
            $reservation['numberOfParticipants'] = intval($amount);
            $reservation['comment'] = $request->get('comment');
            $organization = $commonGroundService->getResource($variables['resource']['organization']);
            $organization = $commonGroundService->cleanUrl(['component' => 'wrc', 'type' => 'organizations', 'id' => $organization['id']]);
            $reservation['provider'] = $organization;
            //reservation event part

            $date = \DateTime::createFromFormat('Y-m-d H:i', $request->get('date').$request->get('time'));

            $reservation['event']['name'] = $name;
            $reservation['event']['startDate'] = $date->format('Y-m-d H:i');
            $reservation['event']['endDate'] = $date->format('Y-m-d H:i');
            $reservation['event']['calendar'] = '/calendars/'.$variables['calendar']['id'];
            $reservation = $commonGroundService->createResource($reservation, ['component' => 'arc', 'type' => 'reservations']);

            return $this->redirect($this->generateUrl('app_chin_checkinreservations'));
        }

        return $variables;
    }

    /**
     * This function shows all available locations.
     *
     * @Route("/login/{code}")
     * @Template
     */
    public function loginAction(Session $session, $code = null, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;

        // If we have a valid user then we do not need to login
        if ($this->getUser()) {
            $session->set('checkingProvider', 'session');

            return $this->redirect($this->generateUrl('app_chin_checkin', ['code'=>$code]));
        }

        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables['code'] = $code;

        if ($request->isMethod('POST') && $request->request->get('method')) {
            $method = $request->request->get('method');

            switch ($method) {
                case 'idin':
                    return $this->redirect($this->generateUrl('app_user_idin', ['backUrl'=>$this->generateUrl('app_chin_checkin', ['code'=>$code], urlGeneratorInterface::ABSOLUTE_URL)]));
                case 'idinLogin':
                    return $this->redirect($this->generateUrl('app_user_idinlogin', ['backUrl'=>$this->generateUrl('app_chin_checkin', ['code'=>$code], urlGeneratorInterface::ABSOLUTE_URL)]));
                case 'facebook':
                    return $this->redirect($this->generateUrl('app_user_facebook', ['backUrl'=>$this->generateUrl('app_chin_checkin', ['code'=>$code], urlGeneratorInterface::ABSOLUTE_URL)]));
                case 'google':
                    return $this->redirect($this->generateUrl('app_user_gmail', ['backUrl'=>$this->generateUrl('app_chin_checkin', ['code'=>$code], urlGeneratorInterface::ABSOLUTE_URL)]));
                case 'acount':
                    return $this->redirect($this->generateUrl('app_chin_acount', ['code'=>$code]));
            }
        }

        return $variables;
    }

    /**
     * This function shows all available locations.
     *
     * @Route("/acount/{code}")
     * @Template
     */
    public function acountAction(Session $session, $code = null, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables['code'] = $code;

        // Lets handle a post
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $username = $request->request->get('email');
            $tel = $request->request->get('telephone');
            $password = $request->request->get('password');
            $crf = $request->request->get('_csrf_token');

            $users = $commonGroundService->getResourceList(['component'=>'uc', 'type'=>'users'], ['username'=> $username], true, false, true, false, false);
            $users = $users['hydra:member'];

            // Exsisting user
            if (count($users) > 0) {
                $user = $users[0];
                $person = $commonGroundService->getResource($user['person']);

                $credentials = [
                    'username'   => $username,
                    'password'   => $password,
                    'csrf_token' => $crf,
                ];

                $user = $commonGroundService->createResource($credentials, ['component'=>'uc', 'type'=>'login'], false, true, false, false);

                // validate user
                if (!$user) {
                    $variables['password_error'] = 'invalid password';
                    $variables['user_info']['name'] = $name;
                    $variables['user_info']['email'] = $username;
                    $variables['user_info']['telephone'] = $tel;

                    return $variables;
                }

                // Login the user
                $userObject = new CommongroundUser($user['username'], $password, $person['name'], null, $user['roles'], $user['person'], null, 'user');

                $token = new UsernamePasswordToken($userObject, null, 'main', $userObject->getRoles());
                $this->container->get('security.token_storage')->setToken($token);
                $this->container->get('session')->set('_security_main', serialize($token));
            }
            // Non-Exsisting user
            else {
                //create email
                $email = [];
                $email['name'] = 'Email';
                $email['email'] = $username;
                //$email = $commonGroundService->createResource($email, ['component' => 'cc', 'type' => 'emails']);

                $telephone = [];
                $telephone['name'] = 'Phone';
                $telephone['telephone'] = $tel;
                //$telephone = $commonGroundService->createResource($telephone, ['component' => 'cc', 'type' => 'telephones']);

                //create person
                $names = explode(' ', $name);
                $person = [];
                $person['givenName'] = $names[0];
                if ($names[0] != end($names)) {
                    $person['familyName'] = end($names);
                }
                $person['emails'] = [$email];
                if ($tel) {
                    $person['telephones'] = [$telephone];
                }

                $person = $commonGroundService->createResource($person, ['component' => 'cc', 'type' => 'people']);

                //create user
                $application = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'applications', 'id' => $params->get('app_id')]);
                $user = [];
                $user['username'] = $username;
                $user['password'] = $password;
                $user['person'] = $person['@id'];
                $user['organization'] = $application['organization']['@id'];
                $user = $commonGroundService->createResource($user, ['component' => 'uc', 'type' => 'users']);

                $userObject = new CommongroundUser($user['username'], $password, $person['name'], null, $user['roles'], $user['person'], null, 'user');

                $token = new UsernamePasswordToken($userObject, null, 'main', $userObject->getRoles());
                $this->container->get('security.token_storage')->setToken($token);
                $this->container->get('session')->set('_security_main', serialize($token));
            }

            // Lets see if there if there is an active checking
            $checkIns = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $person['@id'], 'node' => 'nodes/'.$variables['resource']['id'], 'order[dateCreated]' => 'desc'])['hydra:member'];

            if ((count($checkIns) > 1) && $checkIns[0]['dateCheckedOut'] == null) {
                $hourDiff = round((strtotime('now') - strtotime($checkIns[0]['dateCreated'])) / 3600);
                // edit this number to determine how many hours before you are not seens as checked in anymore
                $hoursForCheckout = 4;
                if ($hourDiff < $hoursForCheckout) {
                    return $this->redirect($this->generateUrl('app_chin_checkout', ['code'=>$code]));
                }
            }

            $checkIn['node'] = 'nodes/'.$variables['resource']['id'];
            $checkIn['person'] = $person['@id'];
            $checkIn['provider'] = 'email';
            $checkIn['userUrl'] = $user['@id'];

            $checkIn = $commonGroundService->createResource($checkIn, ['component' => 'chin', 'type' => 'checkins']);

            return $this->redirect($this->generateUrl('app_chin_confirmation', ['id'=>$checkIn['id']]));
        }

        return $variables;
    }

    /**
     * This function shows all available locations.
     *
     * @Route("/confirmation/{id}")
     * @Template
     */
    public function confirmationAction(Session $session, $id = null, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$id) {
            $id = $request->query->get('id');
        }
        if (!$id) {
            $id = $request->request->get('id');
        }
        if (!$id) {
            $this->addFlash('warning', 'No checking id supplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $variables['checkin'] = $commonGroundService->getResource(['component' => 'chin', 'type' => 'checkins', 'id' => $id]);

        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $variables['checkin']['node']['reference']])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$variables['checkin']['node']['reference']);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        // Lets handle a post
        if ($request->isMethod('POST')) {
        }

        return $variables;
    }

    /**
     * This function shows all available locations.
     *
     * @Route("/authorization/{code}")
     * @Template
     */
    public function authorizationAction(Session $session, $code = null, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];

        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        if ($request->isMethod('POST')) {
            $node = $request->request->get('node');
            $name = $request->request->get('name');

            $email = $request->request->get('email');
            $tel = $request->request->get('telephone');
            $name = explode(' ', $name);

            if (count($name) < 2) {
                $firstName = $name[0];
                $additionalName = '';
                $lastName = $name[0];
            } elseif (count($name) < 3) {
                $firstName = $name[0];
                $additionalName = '';
                $lastName = $name[1];
            } else {
                $firstName = $name[0];
                $additionalName = $name[1];
                $lastName = $name[2];
            }

            $emailObject['email'] = $email;
            $emailObject = $commonGroundService->createResource($emailObject, ['component' => 'cc', 'type' => 'emails']);

            $telObject['telephone'] = $tel;
            $telObject = $commonGroundService->createResource($telObject, ['component' => 'cc', 'type' => 'telephones']);

            $person['givenName'] = $firstName;
            $person['additionalName'] = $additionalName;
            $person['familyName'] = $lastName;
            $person['emails'][0] = $emailObject['@id'];
            $person['telephones'][0] = $telObject['@id'];
            $person = $commonGroundService->createResource($person, ['component' => 'cc', 'type' => 'people']);

            $application = $commonGroundService->getResource(['component' => 'wrc', 'type' => 'applications', 'id' => $params->get('app_id')]);
            $validChars = '0123456789abcdefghijklmnopqrstuvwxyz';
            $password = substr(str_shuffle(str_repeat($validChars, ceil(3 / strlen($validChars)))), 1, 8);
            $user = [];
            $user['username'] = $email;
            $user['password'] = $password;
            $user['person'] = $person['@id'];
            $user['organization'] = $application['organization']['@id'];

            $user = $commonGroundService->createResource($user, ['component' => 'uc', 'type' => 'users']);

            $checkIn['node'] = $node;
            $checkIn['person'] = $person['@id'];

            $checkIn = $commonGroundService->createResource($checkIn, ['component' => 'chin', 'type' => 'checkins']);

            $node = $commonGroundService->getResource($node);

            $session->set('newcheckin', true);
            $session->set('person', $person);

            $test = new CommongroundUser($user['username'], $password, $person['name'], null, $user['roles'], $user['person'], null, 'user');

            $token = new UsernamePasswordToken($test, null, 'main', $test->getRoles());
            $this->container->get('security.token_storage')->setToken($token);
            $this->container->get('session')->set('_security_main', serialize($token));

            if (isset($application['defaultConfiguration']['configuration']['userPage'])) {
                return $this->redirect('/'.$application['defaultConfiguration']['configuration']['userPage']);
            } else {
                return $this->redirect($this->generateUrl('app_default_index'));
            }
        }

        $variables['code'] = $code;
    }

    /**
     * This function shows all available locations.
     *
     * @Route("/checkout/{code}")
     * @Template
     */
    public function checkoutAction(Session $session, $code = null, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params)
    {
        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables['code'] = $code;

        if ($request->isMethod('POST') && $request->get('confirmation')) {
            $person = $commonGroundService->getResource($this->getUser()->getPerson());
            $checkIns = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'checkins'], ['person' => $person['@id'], 'node' => 'nodes/'.$variables['resource']['id'], 'order[dateCreated]' => 'desc'])['hydra:member'];

            $checkIn = $checkIns[0];
            $date = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
            $checkIn['dateCheckedOut'] = $date->format('Y-m-d H:i:s');
            $checkIn['node'] = 'nodes/'.$checkIn['node']['id'];
            $commonGroundService->updateResource($checkIn);

            $variables['checkout'] = true;
        }

        return $variables;
    }

    /**
     * @Route("/clockin/{code}")
     * @Template
     */
    public function clockinAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, $code = null)
    {

        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables['code'] = $code;

        return $variables;
    }

    /**
     * @Route("/organization")
     * @Template
     */
    public function organizationAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, $code = null)
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
     * @Route("/cancel/{code}/{reservation}")
     * @Template
     */
    public function cancelAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, $code = null, $reservation = null)
    {

        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables['code'] = $code;
        $variables['reservation'] = $commonGroundService->getResource(['component' => 'arc', 'type' => 'reservations', 'id' => $reservation]);

        if ($request->isMethod('POST')) {
            $reservation = $commonGroundService->getResource(['component' => 'arc', 'type' => 'reservations', 'id' => $request->get('reservationId')]);

            $event = $reservation['event'];
            $event['status'] = 'cancelled';
            $event['calendar'] = '/calendars/'.$event['calendar']['id'];

            $commonGroundService->updateResource($event);
            $variables['cancelled'] = true;
        }

        return $variables;
    }

    /**
     * @Route("/mailing/{code}")
     * @Template
     */
    public function mailingAction(Session $session, Request $request, CommonGroundService $commonGroundService, ApplicationService $applicationService, ParameterBagInterface $params, $code = null, $reservation = null)
    {

        // Fallback options of establishing
        if (!$code) {
            $code = $request->query->get('code');
        }
        if (!$code) {
            $code = $request->request->get('code');
        }
        if (!$code) {
            $code = $session->get('code');
        }
        if (!$code) {
            $this->addFlash('warning', 'No node reference suplied');

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables = [];

        $session->set('code', $code);
        $variables['code'] = $code;
        $variables['resources'] = $commonGroundService->getResourceList(['component' => 'chin', 'type' => 'nodes'], ['reference' => $code])['hydra:member'];
        if (count($variables['resources']) > 0) {
            $variables['resource'] = $variables['resources'][0];
        } else {
            $this->addFlash('warning', 'Could not find a valid node for reference '.$code);

            return $this->redirect($this->generateUrl('app_default_index'));
        }

        $variables['code'] = $code;

        if ($request->isMethod('POST')) {
            $user = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['person' => $this->getUser()->getPerson()])['hydra:member'];
            $user = $user[0];

            $person = $commonGroundService->getResource($this->getUser()->getPerson());
            $person['@id'] = $commonGroundService->cleanUrl(['component'=>'cc', 'type'=>'people', 'id'=>$person['id']]);

            $checkIn = [];
            $checkIn['node'] = 'nodes/'.$variables['resource']['id'];
            $checkIn['person'] = $person['@id'];
            $checkIn['userUrl'] = $user['@id'];
            if ($session->get('checkingProvider')) {
                $checkIn['provider'] = $session->get('checkingProvider');
            } else {
                $checkIn['provider'] = 'session';
            }

            $checkIn = $commonGroundService->createResource($checkIn, ['component' => 'chin', 'type' => 'checkins']);

            $variables['subscribed'] = true;
        }

        return $variables;
    }
}
