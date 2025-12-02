<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use App\Enum\Status;

class RegistrationController extends AbstractController
{
    public function __construct(
        private EmailVerifier $emailVerifier,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer
    ) {}
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
        ]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $name */
            $name = $form->get('name')->getData();
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setName($name);
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $user->setEmailVerified(false);

            $entityManager->persist($user);
            $entityManager->flush();

            $signature = $this->verifyEmailHelper->generateSignature(
                'app_verify_email', 
                $user->getId(),
                $user->getEmail(),
                ['id' => $user->getId()]
            );

            $this->mailer->send(
                (new TemplatedEmail())
                    ->from(new Address('bkabkal14@mail.ru', 'Mail Bot'))
                    ->to((string) $user->getEmail())
                    ->subject('Подтвердите вашу попчту')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
                    ->context([
                        'signedUrl' => $signature->getSignedUrl(),
                        'expiresAt' => $signature->getExpiresAt(),
                    ])
            );

            $this->addFlash('info', 'На ваш email отправлено письмо с ссылкой для подтверждения.');
            return $this->redirectToRoute('app_sign_in');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator
    ): Response {
        $id = $request->query->get('id');
        if (null === $id) {
            return $this->redirectToRoute('app_register');
        }

        $user = $em->getRepository(User::class)->find($id);
        if (null === $user) {
            return $this->redirectToRoute('app_dashboard');
        }
        if($user->isBlocked()){
            return $this->redirectToRoute('app_sign_in');
        }
        if($user->getStatus() == 'active'){
            return $this->redirectToRoute('app_dashboard');
        }
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));
            return $this->redirectToRoute('app_sign_in');
        }

        $user->setEmailVerified(true);
        $em->flush();

        $this->addFlash('success', 'Ваш email успешно подтверждён!');

        return $this->redirectToRoute('app_dashboard');
    }
}
