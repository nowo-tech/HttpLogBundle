<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        return $this->render('demo/index.html.twig', [
            'generatedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/api/ping', name: 'demo_api_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return new JsonResponse([
            'status'    => 'ok',
            'timestamp' => time(),
            'message'   => 'HttpLogBundle demo JSON response',
        ]);
    }

    #[Route('/api/echo', name: 'demo_api_echo', methods: ['POST'])]
    public function echo(): JsonResponse
    {
        $payload = json_decode((string) file_get_contents('php://input'), true);

        return new JsonResponse([
            'received' => is_array($payload) ? $payload : null,
        ]);
    }
}
