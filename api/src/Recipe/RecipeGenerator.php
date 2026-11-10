<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Conversation\GenerationLogger;
use App\Conversation\MemoryFormatter;
use App\Conversation\SessionStateFormatter;
use App\Entity\MemoryCategory;
use App\Entity\ShoppingSession;
use App\Gemini\GeminiClient;
use App\Gemini\GeminiException;
use App\Gemini\GeminiPurpose;
use App\Gemini\GeminiRequest;
use App\Gemini\ThinkingLevels;
use App\Memory\MemoryManager;
use App\Prompt\PromptLoader;
use App\Repository\UserMemoryRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Génère une recette en JSON strict avec un appel Gemini dédié (responseSchema, prompt recipe_vN, niveau de réflexion
 * propre), puis la valide par le code. En cas d'erreur de validation, un 2e essai reçoit les erreurs.
 * « L'IA génère, le code calcule » : rien n'est enregistré ici.
 */
final class RecipeGenerator
{
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly PromptLoader $prompts,
        private readonly MemoryFormatter $memoryFormatter,
        private readonly SessionStateFormatter $stateFormatter,
        private readonly UserMemoryRepository $memory,
        private readonly ValidatorInterface $validator,
        private readonly GenerationLogger $logger,
        private readonly ThinkingLevels $thinkingLevels,
        #[Autowire('%app.prompt.recipe%')]
        private readonly string $promptVersion,
        #[Autowire('%app.user_timezone%')]
        private readonly string $userTimezone,
    ) {
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    /**
     * @throws GeminiException           Gemini indisponible (le tour échoue : 503)
     * @throws RecipeGenerationException recette toujours invalide après MAX_ATTEMPTS
     */
    public function generate(ShoppingSession $session, string $brief, ?int $mealsCovered, ?int $suggestedDay): GeneratedRecipe
    {
        $system = $this->prompts->load($this->promptVersion)."\n\n".$this->context($session);
        $request = $this->instruction($brief, $mealsCovered, $suggestedDay);
        $contents = [['role' => 'user', 'parts' => [['text' => $request]]]];

        $errors = [];
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $start = hrtime(true);
            try {
                $response = $this->gemini->generate(new GeminiRequest(
                    systemInstruction: $system,
                    contents: $contents,
                    thinkingLevel: $this->thinkingLevels->for(GeminiPurpose::ProposeRecipe),
                    responseSchema: RecipeSchema::gemini(),
                ));
            } catch (GeminiException $e) {
                $this->logger->record($session, GeminiPurpose::ProposeRecipe->value, $this->gemini->model(), $this->promptVersion, null, (int) ((hrtime(true) - $start) / 1_000_000), $e->getMessage());
                throw $e;
            }
            $this->logger->record($session, GeminiPurpose::ProposeRecipe->value, $this->gemini->model(), $this->promptVersion, $response, $response->durationMs);

            try {
                $data = $response->json();
            } catch (GeminiException $e) {
                $data = null;
                $errors = [$e->getMessage()];
            }

            if (null !== $data) {
                $errors = $this->validate($data, $session->getPeriodDays());
                if ([] === $errors) {
                    $data = self::normalize($data);

                    return new GeneratedRecipe($data, $this->warnings($session, $data), $attempt);
                }
            }

            // 2e essai : on renvoie la réponse précédente et les erreurs à corriger.
            $contents[] = ['role' => 'model', 'parts' => [['text' => $response->text()]]];
            $contents[] = ['role' => 'user', 'parts' => [['text' => "Cette recette est invalide. Corrige ces erreurs et renvoie la recette complète :\n- ".implode("\n- ", $errors)]]];
        }

        throw new RecipeGenerationException($errors);
    }

    private function instruction(string $brief, ?int $mealsCovered, ?int $suggestedDay): string
    {
        $lines = ['Consigne de l\'assistant : '.$brief];
        if (null !== $mealsCovered) {
            $lines[] = 'Nombre de repas à couvrir : '.$mealsCovered.'.';
        }
        if (null !== $suggestedDay) {
            $lines[] = 'Jour de la période prévu pour cette recette : '.$suggestedDay.'.';
        }

        return implode("\n", $lines);
    }

    private function context(ShoppingSession $session): string
    {
        $tz = new \DateTimeZone($this->userTimezone);
        $now = new \DateTimeImmutable('now', $tz);
        $day = (int) $session->getStartedAt()->setTimezone($tz)->setTime(0, 0)->diff($now->setTime(0, 0))->days + 1;

        $lines = ['# Contexte'];
        $lines[] = \sprintf('- Période de la session : %d jours (on est au jour %d). suggested_day doit être entre 1 et %d.', $session->getPeriodDays(), min($day, $session->getPeriodDays()), $session->getPeriodDays());
        $lines[] = '';
        $lines[] = '## Profil de l\'utilisateur';
        array_push($lines, ...$this->memoryFormatter->lines($session->getUser(), withUnknown: false));
        $lines[] = '';
        $lines[] = '## État de la session';
        array_push($lines, ...$this->stateFormatter->lines($session));

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validate(array $data, int $periodDays): array
    {
        $errors = [];
        foreach ($this->validator->validate($data, RecipeSchema::constraints($periodDays)) as $violation) {
            $errors[] = $violation->getPropertyPath().' : '.$violation->getMessage();
        }

        // Une quantité sans unité est ambiguë (sauf « à volonté » : quantité et unité nulles).
        foreach (\is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [] as $i => $ingredient) {
            if (\is_array($ingredient) && null !== ($ingredient['quantity'] ?? null) && null === ($ingredient['unit'] ?? null)) {
                $errors[] = \sprintf('[ingredients][%d] : une quantité doit avoir une unité (utilise « piece » pour les pièces).', $i);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        $trimList = static fn (array $list): array => array_values(array_map(static fn (string $s): string => trim($s), $list));

        return [
            'title' => trim($data['title']),
            'description' => '' === trim((string) $data['description']) ? null : trim($data['description']),
            'servings' => $data['servings'],
            'meals_covered' => $data['meals_covered'],
            'meal_tags' => array_values(array_unique($data['meal_tags'])),
            'prep_time_min' => $data['prep_time_min'],
            'cook_time_min' => $data['cook_time_min'],
            'difficulty' => $data['difficulty'],
            'uses_inventory' => $trimList($data['uses_inventory']),
            'ingredients' => array_map(static fn (array $i): array => [
                'name' => mb_strtolower(trim($i['name'])),
                'quantity' => null === $i['quantity'] ? null : 0 + $i['quantity'],
                'unit' => $i['unit'],
                'category' => $i['category'],
                'optional' => $i['optional'],
            ], array_values($data['ingredients'])),
            'steps' => $trimList($data['steps']),
            'equipment' => $trimList($data['equipment']),
            'suggested_day' => $data['suggested_day'],
            'storage_tip' => '' === trim((string) $data['storage_tip']) ? null : trim($data['storage_tip']),
        ];
    }

    /**
     * Ustensile requis absent de la mémoire (hors ustensiles de base) → simple avertissement (fiche 03).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function warnings(ShoppingSession $session, array $data): array
    {
        $owned = array_map(static fn ($item): string => MemoryManager::normalize($item->getValue()), $this->memory->findInCategory($session->getUser(), MemoryCategory::Ustensile));
        $warnings = [];
        foreach ($data['equipment'] as $equipment) {
            $normalized = MemoryManager::normalize($equipment);
            if (\in_array($normalized, RecipeVocabulary::BASIC_EQUIPMENT, true)) {
                continue;
            }
            foreach ($owned as $item) {
                if (str_contains($normalized, $item) || str_contains($item, $normalized)) {
                    continue 2;
                }
            }
            $warnings[] = 'Ustensile absent de ton profil : '.$equipment;
        }

        return $warnings;
    }
}
