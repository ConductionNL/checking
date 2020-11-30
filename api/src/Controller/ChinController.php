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
            return $this->redirect($this->generateUrl('app_user_idvault').'?backUrl='.$request->getUri());
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
            $person = $commonGroundService->getResource($this->getUser()->getPerson());
            $personUrl = $commonGroundService->cleanUrl(['component' => 'cc', 'type' => 'people', 'id' => $person['id']]);

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

            $person['emails'][0] = [];
            $person['emails'][0]['email'] = $request->get('email');

            $person = $commonGroundService->updateResource($person);

            $user = $commonGroundService->getResourceList(['component' => 'uc', 'type' => 'users'], ['username' => $this->getUser()->getUsername()])['hydra:member'][0];
            $userUrl = $commonGroundService->cleanUrl(['component' => 'uc', 'type' => 'users', 'id' => $user['id']]);

            $checkIn = [];
            $checkIn['node'] = 'nodes/'.$variables['resource']['id'];
            $checkIn['person'] = $personUrl;
            $checkIn['userUrl'] = $userUrl;
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

            return $this->redirect($this->generateUrl('app_dashboard_reservations'));
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
