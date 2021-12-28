<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

class ConferenceController extends AbstractController
{
    private SerializerInterface $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    #[Route('/', name: 'homepage')]
    public function index(): Response
    {
        return new Response(<<<EOF
<html>
    <body>
        <h1>Homepage</h1>
    </body>
</html>
EOF
        );
    }

    #[Route('/api/v1/test', name: 'api_v1_test')]
    public function test(): Response
    {
        $this->serializer->serialize();
        return $this->json([
            'test' => 'api'
        ]);
    }
}
