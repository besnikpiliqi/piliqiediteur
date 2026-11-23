<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Messages\MessagesSignes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Le site réel des tests de la démonstration (lot DEMO-COPIE, partie B) :
 * un site de démonstration réglé (adresse, nom, clé des messages tirée
 * pour le test) et simulé par Http::fake(), qui répond à chaque message
 * l'état voulu ; aucune requête ne sort.
 */
trait DemonstrationDuSiteReel
{
    protected const ORIGINE_DE_LA_DEMONSTRATION = 'https://piliqi-demo.test';

    /**
     * La clé publique du test, par identifiant, pour vérifier les messages
     * envoyés.
     *
     * @var array<string, string>
     */
    protected array $clesPubliquesDuTest = [];

    /**
     * Ce que le site de démonstration simulé répond à chaque message : son
     * code et son corps.
     *
     * @var array{0: int, 1: array<string, mixed>}
     */
    protected array $reponseDuSiteDeDemonstration = [200, []];

    /**
     * Ce que le site de démonstration simulé répond à une action, à la
     * place de la réponse commune : son code et son corps, par action.
     *
     * @var array<string, array{0: int, 1: array<string, mixed>}>
     */
    protected array $reponsesParActionDuSiteDeDemonstration = [];

    /**
     * Règle le site de démonstration et le fait répondre, à chaque
     * message, ce code et ce corps (par défaut : prête, génération 1).
     *
     * @param  array<string, mixed>  $corps
     */
    protected function reglerLeSiteDeDemonstration(int $code = 200, array $corps = ['etat' => 'prete', 'generation' => 1]): void
    {
        $paire = MessagesSignes::genererUnePaire();
        $this->clesPubliquesDuTest = [$paire['cle_id'] => $paire['cle_publique']];
        config([
            'demonstration.url' => self::ORIGINE_DE_LA_DEMONSTRATION,
            'demonstration.nom_du_site' => 'piliqi-demonstration',
            'demonstration.messages.cle_privee' => $paire['cle_privee'],
            'demonstration.messages.cle_id' => $paire['cle_id'],
        ]);
        $this->leSiteDeDemonstrationRepond($code, $corps);
        Http::fake([self::ORIGINE_DE_LA_DEMONSTRATION.'/interne/messages' => function (Request $requete): mixed {
            $message = json_decode((string) $requete['message'], true);
            [$code, $corps] = $this->reponsesParActionDuSiteDeDemonstration[is_array($message) ? (string) ($message['quoi'] ?? '') : ''] ?? $this->reponseDuSiteDeDemonstration;

            return Http::response($corps, $code);
        }]);
    }

    /**
     * @param  array<string, mixed>  $corps
     */
    protected function leSiteDeDemonstrationRepond(int $code, array $corps): void
    {
        $this->reponseDuSiteDeDemonstration = [$code, $corps];
    }

    /**
     * Le site de démonstration simulé répond ce code et ce corps à cette
     * seule action (creer, etat…), la réponse commune aux autres.
     *
     * @param  array<string, mixed>  $corps
     */
    protected function leSiteDeDemonstrationRepondA(string $quoi, int $code, array $corps): void
    {
        $this->reponsesParActionDuSiteDeDemonstration[$quoi] = [$code, $corps];
    }

    /**
     * Les messages reçus par le site de démonstration simulé, signature
     * vérifiée, dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    protected function messagesRecus(): array
    {
        return array_values(Http::recorded(fn (Request $requete): bool => str_ends_with($requete->url(), '/interne/messages'))
            ->map(fn (array $echange): Request => $echange[0])
            ->map(fn (Request $requete): array => MessagesSignes::verifier((string) $requete['message'], (string) $requete['signature'], $this->clesPubliquesDuTest))
            ->all());
    }
}
