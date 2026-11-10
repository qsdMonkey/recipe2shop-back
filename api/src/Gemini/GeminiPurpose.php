<?php

declare(strict_types=1);

namespace App\Gemini;

/**
 * Type d'appel à Gemini : sert au réglage du niveau de réflexion et à la trace (generation_log.purpose).
 */
enum GeminiPurpose: string
{
    /** Conversation (tours de chat et message d'accueil). */
    case Chat = 'chat';
    /** Génération d'une recette (étape 5). */
    case ProposeRecipe = 'propose_recipe';
    /** Relecture mémoire de fin de session (étape 5, asynchrone). */
    case MemoryReview = 'memory_review';
}
