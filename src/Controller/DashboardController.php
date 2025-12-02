<?php
// src/Controller/DashboardController.php
namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;

#[Route('/')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{

    protected function getUser(): ?User
    {
        if (!$this->container->has('security.token_storage')) {
            throw new \LogicException('The SecurityBundle is not registered in your application. Try running "composer require symfony/security-bundle".');
        }

        if (null === $token = $this->container->get('security.token_storage')->getToken()) {
            return null;
        }

        return $token->getUser();
    }
    
    #[Route('', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $users = $em->getRepository(User::class)->findBy([], [
        ]);

        foreach ($users as $user) {
            $user->status = match($user->getStatus()){
                'blocked' => 'Заблокирован',
                'active' => 'Активен',
                'unverified' => 'Не подтверждено'
            };
        }


        return $this->render('mainpage/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/action', name: 'app_dashboard_action', methods: ['POST'])]
    public function action(Request $request, EntityManagerInterface $em): Response
    {
        $ids = $request->request->all('selected') ?? [];
        $action = $request->request->get('action');
        $currentUser = $this->getUser();

        if (!$ids || !$action) {
            return $this->redirectToRoute('app_dashboard');
        }
        $users = $em->getRepository(User::class)->findBy(['id' => $ids]);
        $selfDeleted = false;
        foreach ($users as $user) {
            if ($action === 'block') {
                $user->setBlocked(true);
            } elseif ($action === 'unblock') {
                $user->setBlocked(false);
            } elseif ($action === 'delete') {
                if ($user === $currentUser) {
                    $selfDeleted = true;
                }
                $em->remove($user);
            }
        }
        $em->flush();
        if ($selfDeleted) {
            $this->container->get('security.token_storage')->setToken(null);
            $request->getSession()->invalidate();
            return $this->redirectToRoute('app_sign_in');
        }


        return $this->redirectToRoute('app_dashboard');
    }


}