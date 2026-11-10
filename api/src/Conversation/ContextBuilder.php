<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\MessageRole;
use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Memory\OnboardingChecklist;
use App\Prompt\PromptLoader;
use App\Repository\MessageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Construit ce qui est envoyé à Gemini à chaque tour (invisible pour l'utilisateur) :
 * prompt système versionné + contexte (date, session, mémoire, résumé) + historique récent + nouveau message.
 * L'état vient de la base, pas de l'historique : l'IA ne « perd » jamais l'état dans une longue conversation.
 */
final class ContextBuilder
{
    public const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly PromptLoader $prompts,
        private readonly MessageRepository $messages,
        private readonly MemoryFormatter $memoryFormatter,
        private readonly SessionStateFormatter $stateFormatter,
        private readonly OnboardingChecklist $onboardingChecklist,
        #[Autowire('%app.prompt.shopping%')]
        private readonly string $shoppingPrompt,
        #[Autowire('%app.prompt.onboarding%')]
        private readonly string $onboardingPrompt,
        #[Autowire('%app.prompt.kickoff%')]
        private readonly string $kickoffPrompt,
        #[Autowire('%app.user_timezone%')]
        private readonly string $userTimezone,
    ) {
    }

    /**
     * @param string|null $userText null = tour d'accueil (l'IA parle en premier)
     */
    public function build(ShoppingSession $session, ?string $userText): ConversationContext
    {
        $promptVersion = SessionType::Onboarding === $session->getType() ? $this->onboardingPrompt : $this->shoppingPrompt;
        $systemInstruction = $this->prompts->load($promptVersion)."\n\n".$this->contextSection($session);

        $contents = [];
        foreach ($this->messages->findRecentConversation($session, self::HISTORY_LIMIT) as $message) {
            $contents[] = [
                'role' => MessageRole::User === $message->getRole() ? 'user' : 'model',
                'parts' => [['text' => $message->getContent()]],
            ];
        }

        $kickoff = ['role' => 'user', 'parts' => [['text' => $this->prompts->load($this->kickoffPrompt)]]];
        // Gemini attend une conversation qui commence par l'utilisateur : l'accueil de l'IA est précédé du message automatique.
        if ([] !== $contents && 'model' === $contents[0]['role']) {
            array_unshift($contents, $kickoff);
        }
        $contents[] = null === $userText ? $kickoff : ['role' => 'user', 'parts' => [['text' => $userText]]];

        return new ConversationContext($systemInstruction, $contents, $promptVersion);
    }

    private function contextSection(ShoppingSession $session): string
    {
        $tz = new \DateTimeZone($this->userTimezone);
        $now = new \DateTimeImmutable('now', $tz);

        $lines = ['# Contexte (fourni par l\'application, invisible pour l\'utilisateur)'];
        $lines[] = '- Date du jour : '.$this->formatDate($now);

        if (SessionType::Shopping === $session->getType()) {
            $started = $session->getStartedAt()->setTimezone($tz);
            $day = (int) $started->setTime(0, 0)->diff($now->setTime(0, 0))->days + 1;
            $lines[] = \sprintf(
                '- Session de courses démarrée le %s, période prévue : %d jours (on est au jour %d).',
                $this->formatDate($started),
                $session->getPeriodDays(),
                $day,
            );
        } else {
            $lines[] = '- Conversation d\'onboarding (premier contact après la création du compte).';
        }

        $lines[] = '';
        array_push($lines, ...$this->memorySection($session));

        if (null !== $session->getSummary() && '' !== trim($session->getSummary())) {
            $lines[] = '';
            $lines[] = '## Résumé des échanges plus anciens de cette session';
            $lines[] = trim($session->getSummary());
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function memorySection(ShoppingSession $session): array
    {
        $lines = ['## Ce que tu sais de l\'utilisateur (mémoire long terme, modifiable avec update_memory)'];
        array_push($lines, ...$this->memoryFormatter->lines($session->getUser()));

        if (SessionType::Onboarding === $session->getType()) {
            $missing = $this->onboardingChecklist->missing($session->getUser());
            $lines[] = '';
            $lines[] = [] === $missing
                ? 'Minimum de l\'onboarding : atteint (tu peux appeler finish_onboarding si ce n\'est pas déjà fait).'
                : 'Minimum de l\'onboarding encore manquant : '.implode(' ; ', $missing).'.';
        } else {
            $lines[] = '';
            $lines[] = '## État de la session (lu en base, fait foi)';
            array_push($lines, ...$this->stateFormatter->lines($session));
        }

        return $lines;
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $date->getTimezone());

        return (string) $formatter->format($date);
    }
}
