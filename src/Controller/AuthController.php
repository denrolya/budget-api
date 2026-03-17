<?php

namespace App\Controller;

use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/auth', name: 'api_v2_auth_')]
#[OA\Tag(name: 'Auth')]
class AuthController extends AbstractFOSRestController
{
    #[Route('/token/refresh', name: 'token_refresh', methods:['get'] )]
    #[OA\Get(
        path: '/api/v2/auth/token/refresh',
        summary: 'Refresh JWT token',
        description: 'Issues a fresh JWT token for the currently authenticated user. Send the current valid token in the Authorization header to receive a new one.',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'New JWT token',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    /**
     * @see \App\Tests\AuthenticationTest
     * @tested testRefreshToken_returnsNewToken
     * @tested testRefreshToken_withoutAuth_returns401
     */
    public function refreshToken(JWTTokenManagerInterface $jwtManager): View
    {
        $user = $this->getUser();

        return $this->view(['token' => $jwtManager->create($user)]);
    }
}
