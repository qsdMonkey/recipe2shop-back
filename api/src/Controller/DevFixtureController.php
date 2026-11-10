<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DEV UNIQUEMENT : une SessionDetail factice contenant un exemple de chaque bloc du chat
 * (text, action, recipe, shopping_list, memory, new_session, finish_onboarding), pour construire l'écran de chat
 * avant que les outils correspondants existent (étapes 4 à 6). Route absente en test et en prod.
 */
final class DevFixtureController
{
    #[Route('/dev/chat-fixture', name: 'dev_chat_fixture', methods: ['GET'], env: 'dev')]
    public function __invoke(#[Autowire('%kernel.project_dir%/fixtures/chat-fixture.json')] string $path): JsonResponse
    {
        return JsonResponse::fromJsonString((string) file_get_contents($path));
    }
}
