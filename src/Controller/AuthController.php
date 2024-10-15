<?php

namespace App\Controller;

use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/auth', name: 'api_v2_auth_')]
class AuthController extends AbstractFOSRestController
{
    #[Route('/token/refresh', name: 'token_refresh', methods:['get'] )]
    public function refreshToken(JWTTokenManagerInterface $jwtManager): View
    {
        $user = $this->getUser();

        return $this->view(['token' => $jwtManager->create($user)]);
    }
}
