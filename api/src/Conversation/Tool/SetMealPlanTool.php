<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use Doctrine\ORM\EntityManagerInterface;

/** set_meal_plan({total_meals, dinners?, lunches?, lunchboxes?, notes?}) : les repas prévus de la session. */
final class SetMealPlanTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'set_meal_plan';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Enregistre (ou remplace) les repas à prévoir pour la période de la session. À appeler dès que l\'utilisateur les a donnés, et à chaque changement.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'total_meals' => ['type' => 'integer', 'description' => 'Nombre total de repas à couvrir par les recettes (dîners + déjeuners + gamelles).'],
                    'dinners' => ['type' => 'integer'],
                    'lunches' => ['type' => 'integer'],
                    'lunchboxes' => ['type' => 'integer', 'description' => 'Gamelles pour le travail.'],
                    'notes' => ['type' => 'string', 'description' => 'Invités, soirs sans cuisine, contraintes de la semaine…'],
                ],
                'required' => ['total_meals'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $total = $args['total_meals'] ?? null;
        if (!\is_int($total) || $total < 1 || $total > 60) {
            return ToolResult::error('"total_meals" doit être un entier entre 1 et 60.');
        }

        $details = [];
        foreach (['dinners', 'lunches', 'lunchboxes'] as $key) {
            if (\is_int($args[$key] ?? null) && $args[$key] >= 0) {
                $details[$key] = $args[$key];
            }
        }
        if (\is_string($args['notes'] ?? null) && '' !== trim($args['notes'])) {
            $details['notes'] = mb_substr(trim($args['notes']), 0, 500);
        }

        $context->session->setMealPlan($total, $details);
        $this->em->flush();

        return ToolResult::ok(['status' => 'ok', 'total_meals' => $total]);
    }
}
