<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InventoryItem;
use App\Entity\Recipe;
use App\Entity\SessionRecipe;
use App\Entity\SessionRecipeStatus;
use App\Entity\ShoppingSession;
use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\ORM\EntityManagerInterface;

final class ShoppingListTest extends ApiTestCase
{
    private string $token;

    /** @var array<string, mixed> */
    private array $session;

    private int $position = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser();
        FakeGemini::queueText('Salut !');
        $this->session = $this->request('POST', '/sessions', ['type' => 'shopping'], $this->token);
    }

    public function testMainListAggregatesConvertsDeductsAndGroupsByAisle(): void
    {
        $this->givenAcceptedRecipe('Chili', [
            ['oignons', 2, 'piece', 'fruits_legumes'],
            ['oignon', 150, 'g', 'fruits_legumes'],          // 150 g ÷ 150 g/pièce = 1 pièce
            ['riz', 400, 'g', 'epicerie'],
            ['crème fraîche', 20, 'cl', 'cremerie', true],
            ['sel', null, null, 'epicerie'],
            ['huile d\'olive', 2, 'cas', 'epicerie'],
            ['cumin', 1, 'cac', 'epicerie'],
            ['cumin', 1, 'pincee', 'epicerie'],
        ]);
        $this->givenAcceptedRecipe('Gratin', [
            ['Oignon', 1, 'piece', 'fruits_legumes'],
            ['riz', 0.5, 'kg', 'epicerie'],
            ['crème liquide', 10, 'cl', 'cremerie'],
            ['pommes de terre', 2, 'piece', 'fruits_legumes'], // 2 × 150 g
            ['pomme de terre', 500, 'g', 'fruits_legumes'],
            ['Coriandre', 1, 'botte', 'fruits_legumes', true],
            ['persil', 2, 'cas', 'fruits_legumes'],             // herbes en botte : 1 botte par recette
        ]);
        $this->givenAcceptedRecipe('Refusée', [['saumon', 300, 'g', 'boucherie_poissonnerie']], SessionRecipeStatus::Rejected);
        $this->givenInventory([['riz', 500, 'g'], ['sel', null, null], ['courgette', null, null]]);

        $list = $this->generateMainList();

        self::assertSame('main', $list['type']);
        self::assertSame('Courses de la semaine', $list['name']);
        self::assertFalse($list['outdated']);
        self::assertSame(['fruits_legumes', 'cremerie', 'epicerie'], array_column($list['sections'], 'category'));

        $items = $this->itemsByLabel($list);
        self::assertSame([4, 'piece'], [$items['oignon']['quantity'], $items['oignon']['unit']]);
        self::assertCount(3, $items['oignon']['sources']);
        self::assertSame(['Chili', 2, 'piece'], [$items['oignon']['sources'][0]['recipe_title'], $items['oignon']['sources'][0]['quantity'], $items['oignon']['sources'][0]['unit']]);
        self::assertSame([400, 'g', ['quantity' => 500, 'unit' => 'g']], [$items['riz']['quantity'], $items['riz']['unit'], $items['riz']['inventory_deducted']]);
        self::assertSame([30, 'cl', false], [$items['crème fraîche']['quantity'], $items['crème fraîche']['unit'], $items['crème fraîche']['optional']]);
        self::assertSame([800, 'g'], [$items['pommes de terre']['quantity'], $items['pommes de terre']['unit']]);
        self::assertSame([1, 'botte', true], [$items['coriandre']['quantity'], $items['coriandre']['unit'], $items['coriandre']['optional']]);
        self::assertSame([1, 'botte'], [$items['persil']['quantity'], $items['persil']['unit']]);
        self::assertArrayNotHasKey('saumon', $items);
        self::assertSame('recipe', $items['oignon']['origin']);

        // Unités non convertibles : 2 lignes pour le cumin.
        $cumin = array_values(array_filter($list['sections'][2]['items'], static fn (array $i): bool => 'cumin' === $i['label']));
        self::assertEqualsCanonicalizing(['cac', 'pincee'], array_column($cumin, 'unit'));

        // Produits de base à part ; « déjà chez toi » pour ce que couvre l'inventaire.
        self::assertSame(['huile d\'olive'], array_column($list['basics'], 'label'));
        self::assertSame(['sel'], array_column($list['already_have'], 'label'));
        self::assertSame(8, $list['item_count']);
        self::assertSame(0, $list['checked_count']);

        // Ordre alphabétique dans un rayon.
        self::assertSame(['coriandre', 'oignon', 'persil', 'pommes de terre'], array_column($list['sections'][0]['items'], 'label'));
    }

    public function testRegenerationKeepsManualItemsCheckedStateAndExclusions(): void
    {
        $this->givenAcceptedRecipe('Chili', [['oignon', 2, 'piece', 'fruits_legumes'], ['riz', 400, 'g', 'epicerie'], ['ail', 2, 'gousse', 'fruits_legumes']]);
        $list = $this->generateMainList();
        $items = $this->itemsByLabel($list);
        $base = '/shopping-lists/'.$list['id'];

        $checked = $this->request('PATCH', $base.'/items/'.$items['oignon']['id'], ['checked' => true], $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(['item_count' => 3, 'checked_count' => 1], $checked['list']);
        $this->request('DELETE', $base.'/items/'.$items['ail']['id'], null, $this->token);
        self::assertResponseStatusCodeSame(204);
        $manual = $this->request('POST', $base.'/items', ['label' => 'Lait', 'quantity' => 1, 'unit' => 'l'], $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['manual', 'cremerie', []], [$manual['item']['origin'], $manual['item']['category'], $manual['item']['sources']]);

        $this->givenAcceptedRecipe('Wok', [['oignon', 1, 'piece', 'fruits_legumes'], ['ail', 1, 'gousse', 'fruits_legumes'], ['courgette', 2, 'piece', 'fruits_legumes']]);
        self::assertTrue($this->request('GET', $base, null, $this->token)['outdated']);

        $list = $this->request('POST', $base.'/regenerate', null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertFalse($list['outdated']);
        $items = $this->itemsByLabel($list);
        self::assertSame([3, true], [$items['oignon']['quantity'], $items['oignon']['checked']]); // quantité recalculée, case gardée
        self::assertArrayNotHasKey('ail', $items);                                               // exclu par l'utilisateur
        self::assertSame([1, 'l', 'manual'], [$items['Lait']['quantity'], $items['Lait']['unit'], $items['Lait']['origin']]);
        self::assertSame(2, $items['courgette']['quantity']);
    }

    public function testItemRoutes(): void
    {
        $this->givenAcceptedRecipe('Chili', [['oignon', 2, 'piece', 'fruits_legumes'], ['huile d\'olive', 1, 'cas', 'epicerie']]);
        $list = $this->generateMainList();
        $base = '/shopping-lists/'.$list['id'];
        $onion = $this->itemsByLabel($list)['oignon'];

        $body = $this->request('PATCH', $base.'/items/'.$onion['id'], ['quantity' => 5], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('recipe_item_readonly', $body['error']);

        // Produit de base coché : réponse {item, list}, compteurs inchangés (hors basics).
        $body = $this->request('PATCH', $base.'/items/'.$list['basics'][0]['id'], ['checked' => true], $this->token);
        self::assertResponseIsSuccessful();
        self::assertTrue($body['item']['checked']);
        self::assertSame(['item_count' => 1, 'checked_count' => 0], $body['list']);

        $first = $this->request('POST', $base.'/items', ['label' => 'Papier toilette'], $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['hygiene_entretien', null, null], [$first['item']['category'], $first['item']['quantity'], $first['item']['unit']]);
        $this->request('POST', $base.'/items', ['label' => 'yaourt nature', 'quantity' => 4, 'unit' => 'piece'], $this->token);
        $merged = $this->request('POST', $base.'/items', ['label' => 'Yaourt nature', 'quantity' => 2], $this->token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(6, $merged['item']['quantity']);
        $other = $this->request('POST', $base.'/items', ['label' => 'Bougies d\'anniversaire'], $this->token);
        self::assertSame('autre', $other['item']['category']);

        $edited = $this->request('PATCH', $base.'/items/'.$merged['item']['id'], ['label' => 'yaourt grec', 'quantity' => null, 'category' => 'cremerie'], $this->token);
        self::assertSame(['yaourt grec', null, null], [$edited['item']['label'], $edited['item']['quantity'], $edited['item']['unit']]);

        $this->request('POST', $base.'/items', ['label' => '', 'unit' => 'brouette'], $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->request('PATCH', $base.'/items/'.$onion['id'], ['checked' => 'oui'], $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->request('PATCH', $base.'/items/999999', ['checked' => true], $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testExportText(): void
    {
        $this->givenAcceptedRecipe('Chili', [
            ['oignon', 3, 'piece', 'fruits_legumes'],
            ['coriandre', 1, 'botte', 'fruits_legumes', true],
            ['haricots rouges', 800, 'g', 'epicerie'],
            ['crème fraîche', 20, 'cl', 'cremerie'],
            ['cumin', 1, 'cac', 'epicerie'],
            ['ail', 2, 'gousse', 'fruits_legumes'],
            ['sel', null, null, 'epicerie'],
            ['poivre', null, null, 'epicerie'],
        ]);
        $list = $this->generateMainList();
        $items = $this->itemsByLabel($list);
        $this->request('PATCH', '/shopping-lists/'.$list['id'].'/items/'.$items['ail']['id'], ['checked' => true], $this->token);
        $this->request('POST', '/shopping-lists/'.$list['id'].'/items', ['label' => 'PQ'], $this->token);

        $this->client->request('GET', '/shopping-lists/'.$list['id'].'/export?format=text', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/plain; charset=utf-8');
        self::assertSame(<<<TXT
            Courses de la semaine (6 articles)

            Fruits et légumes
            - coriandre : 1 botte (facultatif)
            - oignon : 3

            Crèmerie
            - crème fraîche : 20 cl

            Épicerie
            - cumin : 1 c. à c.
            - haricots rouges : 800 g

            Hygiène et entretien
            - PQ

            À vérifier dans tes placards : poivre, sel

            TXT, $this->client->getResponse()->getContent());

        $this->request('GET', '/shopping-lists/'.$list['id'].'/export?format=pdf', null, $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTopUpAndEventListsThroughTools(): void
    {
        FakeGemini::queueFunctionCall('generate_shopping_list', ['type' => 'event', 'name' => 'Apéro samedi (10 pers.)']);
        FakeGemini::queueFunctionCall('add_list_items', ['list_id' => 0, 'items' => []]); // list_id inconnu → erreur renvoyée
        FakeGemini::queueText('C\'est prêt.');
        $turn = $this->say('Apéro samedi pour 10');
        $card = $turn['messages'][1]['blocks'][0];
        self::assertSame(['shopping_list', 'event', 'Apéro samedi (10 pers.)', 0], [$card['type'], $card['list']['type'], $card['list']['name'], $card['list']['item_count']]);
        $listId = $card['list']['id'];

        FakeGemini::queueFunctionCall('add_list_items', ['list_id' => $listId, 'items' => [['label' => 'chips', 'quantity' => 4, 'unit' => 'sachet', 'category' => 'epicerie'], ['label' => 'bière', 'quantity' => 12]]]);
        FakeGemini::queueFunctionCall('add_list_items', ['list_id' => $listId, 'items' => [['label' => 'citron vert', 'quantity' => 3]]]);
        FakeGemini::queueText('Ajouté !');
        $blocks = $this->say('Ajoute chips, bières et citrons verts')['messages'][1]['blocks'];

        self::assertSame(['shopping_list', 'text'], array_column($blocks, 'type')); // une seule carte par liste
        self::assertSame(3, $blocks[0]['list']['item_count']);
        $list = $this->request('GET', '/shopping-lists/'.$listId, null, $this->token);
        self::assertNull($list['generated_at']);
        self::assertSame(['fruits_legumes', 'epicerie', 'boissons'], array_column($list['sections'], 'category'));
        self::assertSame('assistant', $list['sections'][0]['items'][0]['origin']);

        $detail = $this->request('GET', '/sessions/'.$this->session['id'], null, $this->token);
        self::assertSame([['id' => $listId, 'type' => 'event', 'name' => 'Apéro samedi (10 pers.)', 'item_count' => 3, 'checked_count' => 0]], $detail['shopping_lists']);

        // Le contexte de l'IA connaît la liste.
        FakeGemini::queueText('OK');
        $this->say('Merci');
        self::assertStringContainsString('event « Apéro samedi (10 pers.) » (list_id '.$listId.', 3 articles dont 0 cochés)', end(FakeGemini::$requests)['body']['systemInstruction']['parts'][0]['text']);
    }

    public function testMainListWithoutAcceptedRecipeIsRefusedToTheAssistant(): void
    {
        FakeGemini::queueFunctionCall('generate_shopping_list', ['type' => 'main']);
        FakeGemini::queueText('Validons d\'abord des recettes.');

        $blocks = $this->say('Fais la liste')['messages'][1]['blocks'];

        self::assertSame(['text'], array_column($blocks, 'type'));
        $response = end(FakeGemini::$requests[1 + 1]['body']['contents'])['parts'][0]['functionResponse']['response'];
        self::assertStringContainsString('Aucune recette acceptée', $response['error']);
    }

    public function testMarkRecipeCookedAndShelfLifeInContext(): void
    {
        $recipe = $this->givenAcceptedRecipe('Saumon grillé', [['saumon', 300, 'g', 'boucherie_poissonnerie'], ['riz', 200, 'g', 'epicerie']]);
        FakeGemini::queueFunctionCall('mark_recipe_cooked', ['recipe_id' => $recipe->getId()]);
        FakeGemini::queueText('Bon appétit !');

        $this->say('J\'ai fait le saumon');

        $system = FakeGemini::$requests[1]['body']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('Durées de conservation indicatives (référentiel) des produits frais de la session : saumon 2 j', $system);
        self::assertTrue($this->request('GET', '/sessions/'.$this->session['id'], null, $this->token)['recipes'][0]['cooked']);
    }

    public function testAccessControlAndArchivedSessions(): void
    {
        $this->givenAcceptedRecipe('Chili', [['oignon', 2, 'piece', 'fruits_legumes']]);
        $list = $this->generateMainList();

        $bob = $this->registerUser('bob@example.com');
        $this->request('GET', '/shopping-lists/'.$list['id'], null, $bob);
        self::assertResponseStatusCodeSame(404);

        FakeGemini::queueText('Nouvelle session');
        $this->request('POST', '/sessions', ['type' => 'shopping'], $this->token); // archive la session
        $archived = $this->request('GET', '/shopping-lists/'.$list['id'], null, $this->token);
        self::assertTrue($archived['read_only']);
        $body = $this->request('POST', '/shopping-lists/'.$list['id'].'/items', ['label' => 'lait'], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('session_archived', $body['error']);
    }

    /** @return array<string, mixed> */
    private function generateMainList(): array
    {
        FakeGemini::queueFunctionCall('generate_shopping_list', ['type' => 'main']);
        FakeGemini::queueText('Voici ta liste.');
        $turn = $this->say('Fais-moi la liste de courses');
        $card = $turn['messages'][1]['blocks'][0];
        self::assertSame('shopping_list', $card['type']);

        $list = $this->request('GET', '/shopping-lists/'.$card['list']['id'], null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame($card['list']['item_count'], $list['item_count']);

        return $list;
    }

    /**
     * @param list<array{0: string, 1: int|float|null, 2: ?string, 3: string, 4?: bool}> $ingredients
     */
    private function givenAcceptedRecipe(string $title, array $ingredients, SessionRecipeStatus $status = SessionRecipeStatus::Accepted): Recipe
    {
        $em = $this->em();
        $session = $em->find(ShoppingSession::class, $this->session['id']);
        $data = RecipeFixtures::chili(['title' => $title, 'ingredients' => array_map(static fn (array $i): array => ['name' => $i[0], 'quantity' => $i[1], 'unit' => $i[2], 'category' => $i[3], 'optional' => $i[4] ?? false], $ingredients)]);
        $recipe = new Recipe($data, $session->getUser(), 'recipe_v1');
        $sessionRecipe = new SessionRecipe($session, $recipe, 1, ++$this->position, []);
        $sessionRecipe->decide($status);
        $em->persist($recipe);
        $em->persist($sessionRecipe);
        $em->flush();

        return $recipe;
    }

    /** @param list<array{0: string, 1: int|float|null, 2: ?string}> $items */
    private function givenInventory(array $items): void
    {
        $em = $this->em();
        $session = $em->find(ShoppingSession::class, $this->session['id']);
        foreach ($items as [$label, $quantity, $unit]) {
            $em->persist(new InventoryItem($session, $label, null === $quantity ? null : (float) $quantity, $unit));
        }
        $em->flush();
    }

    /** @return array<string, array<string, mixed>> */
    private function itemsByLabel(array $list): array
    {
        $items = [];
        foreach ($list['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $items[$item['label']] = $item;
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function say(string $text): array
    {
        $turn = $this->request('POST', \sprintf('/sessions/%d/messages', $this->session['id']), ['text' => $text], $this->token);
        self::assertResponseIsSuccessful();

        return $turn;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
