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
use App\Ingredient\AccentRestorer;
use App\Memory\MemoryManager;
use App\Prompt\PromptLoader;
use App\Repository\UserMemoryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Génère une recette en JSON strict avec un appel Gemini dédié (responseSchema, prompt recipe_vN, niveau de réflexion
 * propre), puis la valide par le code. En cas d'erreur de validation, un 2e essai reçoit les erreurs.
 * Portions (PortionChecker) et temps (StepTimes) : erreurs renvoyées à Gemini au 1er essai ; au dernier essai, correction
 * déterministe par le code, sans jamais bloquer la proposition.
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
        private readonly AccentRestorer $accents,
        #[Autowire('%app.prompt.recipe%')]
        private readonly string $promptVersion,
        #[Autowire('%app.user_timezone%')]
        private readonly string $userTimezone,
        private readonly PortionChecker $portions,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $appLogger,
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
    public function generate(ShoppingSession $session, string $brief, ?int $mealsCovered, ?int $suggestedDay, ?int $servings = null): GeneratedRecipe
    {
        $system = $this->prompts->load($this->promptVersion)."\n\n".$this->context($session);
        $request = $this->instruction($brief, $mealsCovered, $suggestedDay, $servings);
        $contents = [['role' => 'user', 'parts' => [['text' => $request]]]];

        $errors = [];
        $best = null; // dernière recette valide (hors portions et temps) : corrigée par le code si besoin
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
                    // Gemini oublie parfois les accents (« tortilla de ble ») : restaurés sur les noms d'ingrédients.
                    foreach ($data['ingredients'] as $i => $ingredient) {
                        $data['ingredients'][$i]['name'] = $this->accents->restore($ingredient['name']);
                    }
                    $best = [$data, $attempt];

                    // Portions et temps : l'IA corrige d'abord elle-même (essai suivant, avec les chiffres).
                    $errors = [];
                    if (null !== $mealsCovered && $data['meals_covered'] !== $mealsCovered) {
                        $errors[] = \sprintf('meals_covered vaut %d, mais la consigne demande %d repas : refais la recette pour exactement %d repas (quantités = servings × %d portions).', $data['meals_covered'], $mealsCovered, $mealsCovered, $mealsCovered);
                    }
                    if (null !== $servings && $data['servings'] !== $servings) {
                        $errors[] = \sprintf('servings vaut %d, mais la consigne demande %d personnes : refais la recette pour exactement servings %d (une portion par personne, enfants compris).', $data['servings'], $servings, $servings);
                    }
                    array_push($errors, ...$this->portions->errors($data));
                    if (null !== $timeError = StepTimes::error($data)) {
                        $errors[] = $timeError;
                    }
                    if ([] === $errors || $attempt === self::MAX_ATTEMPTS) {
                        return $this->finalize($session, $data, $attempt, $mealsCovered, $servings);
                    }
                }
            }

            // 2e essai : on renvoie la réponse précédente et les erreurs à corriger.
            $contents[] = ['role' => 'model', 'parts' => [['text' => $response->text()]]];
            $contents[] = ['role' => 'user', 'parts' => [['text' => "Cette recette est invalide. Corrige ces erreurs et renvoie la recette complète :\n- ".implode("\n- ", $errors)]]];
        }

        if (null !== $best) {
            return $this->finalize($session, $best[0], $best[1], $mealsCovered, $servings); // 2e essai invalide : on garde le 1er, corrigé
        }

        throw new RecipeGenerationException($errors);
    }

    /**
     * Correction déterministe de ce que l'IA n'a pas corrigé : portions des familles « base » ramenées au milieu de la
     * plage, temps alignés sur les étapes. Journalisé (familles et chiffres seulement, aucune donnée personnelle).
     *
     * @param array<string, mixed> $data
     */
    private function finalize(ShoppingSession $session, array $data, int $attempts, ?int $mealsCovered, ?int $servings): GeneratedRecipe
    {
        // Nombre de repas imposé par la consigne : quantités remises à l'échelle (pas de restes non demandés).
        if (null !== $mealsCovered && $data['meals_covered'] !== $mealsCovered) {
            $this->appLogger->warning('recipe.meals_corrected', ['before' => $data['meals_covered'], 'after' => $mealsCovered]);
            $data = $this->portions->scaleAll($data, $mealsCovered / $data['meals_covered']);
            $data['meals_covered'] = $mealsCovered;
        }
        // Nombre de personnes imposé (foyer ou consigne) : même remise à l'échelle.
        if (null !== $servings && $data['servings'] !== $servings) {
            $this->appLogger->warning('recipe.servings_corrected', ['before' => $data['servings'], 'after' => $servings]);
            $data = $this->portions->scaleAll($data, $servings / $data['servings']);
            $data['servings'] = $servings;
        }

        [$data, $changes] = $this->portions->correct($data);
        foreach ($changes as $change) {
            $this->appLogger->warning('recipe.portions_corrected', $change + ['prompt_version' => $this->promptVersion]);
        }
        foreach ($this->portions->measure($data) as $measure) {
            if ($measure->isOutOfRange()) { // garniture (fromage, légumes…) : signalée, jamais modifiée
                $this->appLogger->info('recipe.portions_unresolved', ['family' => $measure->family, 'per_portion' => round($measure->perPortion, 1)]);
            }
        }
        if (null !== StepTimes::error($data)) {
            $before = $data['prep_time_min'] + $data['cook_time_min'];
            $data = StepTimes::correct($data);
            $this->appLogger->warning('recipe.times_corrected', ['before_min' => $before, 'after_min' => $data['prep_time_min'] + $data['cook_time_min']]);
        }

        return new GeneratedRecipe($data, $this->equipmentWarnings($session, $data['equipment']), $attempts);
    }

    private function instruction(string $brief, ?int $mealsCovered, ?int $suggestedDay, ?int $servings): string
    {
        $lines = ['Consigne de l\'assistant : '.$brief];
        if (null !== $servings) {
            $lines[] = 'Nombre de personnes : '.$servings.'.';
        }
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
                $errors[] = \sprintf('[ingredients][%d] : une quantité doit avoir une unité (g ou kg pour les solides, ml, cl ou l pour les liquides).', $i);
            }
            // Règle des poids : pas de « 3 carottes » mais « 300 g de carottes ».
            if (\is_array($ingredient) && 'piece' === ($ingredient['unit'] ?? null) && \is_string($ingredient['name'] ?? null) && \is_string($ingredient['category'] ?? null)
                && !WeightRule::pieceAllowed($ingredient['name'], $ingredient['category'])) {
                $errors[] = \sprintf('[ingredients][%d] (« %s ») : l\'unité « piece » est interdite pour ce produit. Donne un poids en g ou kg (liquides : ml, cl ou l). « piece » est réservé aux œufs et à ce qui n\'a pas de poids pertinent (feuille de laurier, cube de bouillon).', $i, $ingredient['name']);
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
            'tips' => $trimList(\is_array($data['tips'] ?? null) ? $data['tips'] : []),
            'equipment' => $trimList($data['equipment']),
            'suggested_day' => $data['suggested_day'],
            'storage_tip' => '' === trim((string) $data['storage_tip']) ? null : trim($data['storage_tip']),
        ];
    }

    /**
     * Ustensile requis absent de la mémoire (hors ustensiles de base) → simple avertissement (fiche 03).
     *
     * Aussi recalculé quand un favori est reproposé (le profil a pu changer depuis).
     *
     * @param list<string> $equipmentList
     *
     * @return list<string>
     */
    public function equipmentWarnings(ShoppingSession $session, array $equipmentList): array
    {
        $owned = array_map(static fn ($item): string => MemoryManager::normalize($item->getValue()), $this->memory->findInCategory($session->getUser(), MemoryCategory::Ustensile));
        $warnings = [];
        foreach ($equipmentList as $equipment) {
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
