<?php

namespace App\Security;

use App\Enum\Status;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class BannedUserListener implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onKernelController(ControllerEvent $event): void
    {
        $token = $this->tokenStorage->getToken();

        if (!$token || !is_object($user = $token->getUser())) {
            return;
        }

        $email = $user->getEmail();
        $status = $user->getStatus();

        // $statusValue = $status instanceof Status ? $status->value : (is_string($status) ? $status : 'unknown');
        // $statusType = is_object($status) ? get_class($status) : gettype($status);

        $isBlocked = false;
        if ($status === 'blocked') {
            $isBlocked = true;
        } elseif (is_string($status) && strtolower($status) === 'blocked') {
            $isBlocked = true;
        }

        if ($isBlocked) {
            $this->tokenStorage->setToken(null);
            $request = $event->getRequest();
            $request->getSession()->invalidate();
            $request->getSession()->getFlashBag()->add(
                'error',
                'Ваш аккаунт заблокирован. Обратитесь к администратору.'
            );

            $response = new RedirectResponse($this->urlGenerator->generate('app_sign_in'));
            $event->setController(fn() => $response);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 100],
        ];
    }
}