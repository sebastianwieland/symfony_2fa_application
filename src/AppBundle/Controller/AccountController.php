<?php
/**
 * Created by PhpStorm.
 * User: Sebastian
 * Date: 2016-10-15
 * Time: 17:12
 */

namespace AppBundle\Controller;


use AppBundle\Entity\Auth\U2FRegistration;
use AppBundle\Entity\Auth\User;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    /**
     * @param Request $request
     * @Route("/account/index", name="account_index")
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $user = $this->getUser();
        return $this->render('account/index.html.twig', array(
            'user' => $user
        ));
    }

    /**
     * @param Request $request
     * @Route("/account/set_up_google_authenticator", name="account_set_up_google_authenticator")
     * @return Response
     */
    public function setUpGoogleAuthenticatorAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->getGoogleAuthenticatorSecret() === null) {
            $secret = $this->get('scheb_two_factor.security.google_authenticator')->generateSecret();
            $user->setGoogleAuthenticatorSecret($secret);
        } else {
            $this->addFlash('notice', 'There is already an authenticator associated to this account. Please remove it first');
            return $this->redirectToRoute('account_index');
        }

        $form = $this->getGoogleAuthenticatorForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $authenticatorCode = $request->get($form->getName())['authenticator_code'];
            if ($this->get('scheb_two_factor.security.google_authenticator')->checkCode($user, $authenticatorCode)) {
                $this->getDoctrine()->getManager()->persist($user);
                $this->getDoctrine()->getManager()->flush();

                $this->addFlash('notice', 'Successfully set up Google 2fa');
                return $this->redirectToRoute('account_index');
            } else {
                $this->addFlash('notice', 'Could not verify code');
            }
        }

        return $this->render('account/set_up_google_authenticator.html.twig', array(
            'qr_url' => $this->get('scheb_two_factor.security.google_authenticator')->getUrl($user),
            'form' => $form->createView(),
            'user' => $user
        ));
    }

    private function getGoogleAuthenticatorForm(User $user) : FormInterface
    {
        return $this->createFormBuilder($user)
            ->setMethod('PATCH')
            ->add('googleAuthenticatorSecret', HiddenType::class)
            ->add('authenticator_code', TextType::class, array(
                'mapped' => false
            ))
            ->add('submit', SubmitType::class, array(
                'label' => 'Add authenticator'
            ))
            ->getForm();
    }

    /**
     * @param Request $request
     * @Route("/account/set_up_u2f_registration", name="account_set_up_u2f_registration")
     * @return Response
     */
    public function setUpU2FRegistrationAction(Request $request)
    {
        $appId = ($request->isSecure() ? 'https' : 'http') . '://' . $this->get('router')->getContext()->getHost();
        $u2fLib = new \u2flib_server\U2F($appId);

        /** @var User $user */
        $user = $this->getUser();

        list($challenge, $signs) = $u2fLib->getRegisterData(array_map(function (U2FRegistration $registration) {
            return $registration->toU2FRegistration();
        }, $user->getU2fRegistrations()->toArray()));

        return $this->render('account/set_up_u2f_registration.html.twig', array(
            'appId' => $appId,
            'challenge' => $challenge,
            'signs' => $signs,
            'user' => $user
        ));
    }
}