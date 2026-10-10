<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Le panier de la caisse de chaque vendeur, enregistré côté serveur à
 * mesure qu'il se remplit (voir Caisse) : une vente commencée n'existe pas
 * seulement dans la page. Recharger la page, en changer ou fermer l'onglet
 * ne l'efface pas : la caisse la reprend à sa réouverture. Le panier
 * enregistré ne perd un article que de trois façons, chacune tracée :
 *  - la vente est encaissée : le ticket est signé, et le panier se vide
 *    dans la même transaction (voir EnregistrerVenteCaisseAction) ;
 *  - la vente est abandonnée, par « Abandonner la vente » ou, restée
 *    ouverte, à la clôture de sa journée (voir CloturerCaisseAction) : JET
 *    320 ;
 *  - un article est retiré avant l'encaissement, ligne retirée, produit
 *    ou service remplacé, quantité baissée (voir LignesRetirees) : JET 323.
 *
 * Toutes ces écritures se font sous le verrou du registre fiscal du
 * magasin, puis du panier : un panier ouvert dans deux onglets ne perd
 * rien entre les deux sans trace.
 */
class PanierDeCaisse
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly LignesRetirees $lignesRetirees,
        private readonly CalculateurLigne $calculateur,
        private readonly ConstructeurLigneDocument $constructeurLigne,
    ) {}

    /**
     * Le panier en cours du vendeur dans le magasin actif, null s'il n'en a
     * aucun.
     */
    public function enCours(User $vendeur): ?PanierCaisse
    {
        return PanierCaisse::query()->where('user_id', $vendeur->getKey())->first();
    }

    /**
     * Enregistre le panier tel que le vendeur le voit : son client, s'il
     * est du magasin, et ses lignes où un produit ou un service est choisi.
     * Chaque article qu'il a perdu depuis son dernier enregistrement
     * s'inscrit au journal des événements (JET 323) ; un panier vide
     * s'efface. Une quantité en cours de saisie (vide, à virgule, nulle)
     * garde celle qu'avait la ligne : elle ne retire rien tant qu'elle
     * n'est pas un nombre d'articles.
     *
     * @param  array<array-key, mixed>  $lignes  les lignes du formulaire de la caisse, par clé du répéteur
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function enregistrer(User $vendeur, mixed $clientId, array $lignes): void
    {
        $enCours = $this->enCours($vendeur);
        $clientId = $this->clientDuMagasin($clientId);

        if ($enCours !== null && $enCours->client_id === $clientId && $enCours->lignes === $this->lignesAEnregistrer($lignes, $enCours->lignes)) {
            return;
        }

        if ($enCours === null && $this->lignesAEnregistrer($lignes, []) === []) {
            return;
        }

        $this->registre->sousVerrou(app(TenantContext::class)->magasin(), function () use ($vendeur, $clientId, $lignes): void {
            $panier = PanierCaisse::query()->where('user_id', $vendeur->getKey())->lockForUpdate()->first();
            $nouvelles = $this->lignesAEnregistrer($lignes, $panier->lignes ?? []);

            foreach ($this->lignesRetirees->entre($this->comparables($panier->lignes ?? []), $this->comparables($nouvelles)) as $retrait) {
                $this->registre->journaliser(CodeEvenement::LigneRetiree, ['origine' => 'caisse', ...$retrait]);
            }

            if ($nouvelles === []) {
                $panier?->delete();

                return;
            }

            ($panier ?? (new PanierCaisse)->forceFill(['user_id' => $vendeur->getKey()]))
                ->forceFill(['client_id' => $clientId, 'lignes' => $nouvelles])
                ->save();
        });
    }

    /**
     * Abandonne la vente en cours du vendeur, telle qu'il la voit : ce
     * qu'elle a perdu depuis son dernier enregistrement d'abord (JET 323),
     * puis son abandon, avec son nombre de lignes et son total TTC en
     * centimes (JET 320). Le panier s'efface.
     *
     * @param  array<array-key, mixed>  $lignes  les lignes du formulaire de la caisse, par clé du répéteur
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function abandonner(User $vendeur, mixed $clientId, array $lignes): void
    {
        $this->registre->sousVerrou(app(TenantContext::class)->magasin(), function () use ($vendeur, $clientId, $lignes): void {
            $this->enregistrer($vendeur, $clientId, $lignes);

            $panier = PanierCaisse::query()->where('user_id', $vendeur->getKey())->lockForUpdate()->first();

            if ($panier === null) {
                return;
            }

            $this->registre->journaliser(CodeEvenement::TransactionAbandonnee, [
                'origine' => 'caisse',
                'nombre_lignes' => count($panier->lignes),
                'total_ttc' => $this->totalTtcEnCentimes($panier),
            ]);

            $panier->delete();
        });
    }

    /**
     * Les ventes restées ouvertes à la clôture de leur journée sont
     * abandonnées : chaque panier enregistré pour la dernière fois ce
     * jour-là ou avant s'inscrit au journal des événements (JET 320), avec
     * son vendeur, et s'efface. À appeler dans la transaction de la
     * clôture, qui tient déjà le verrou du registre fiscal (voir
     * CloturerCaisseAction).
     *
     * @param  string  $journee  la journée clôturée, AAAA-MM-JJ
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function abandonnerLesVentesOuvertesLe(string $journee): void
    {
        $this->ventesOuvertesLe($journee)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (PanierCaisse $panier) use ($journee): void {
                $this->registre->journaliser(CodeEvenement::TransactionAbandonnee, [
                    'origine' => 'cloture',
                    'journee' => $journee,
                    'vendeur_id' => (int) $panier->user_id,
                    'nombre_lignes' => count($panier->lignes),
                    'total_ttc' => $this->totalTtcEnCentimes($panier),
                ]);

                $panier->delete();
            });
    }

    /**
     * Le nombre de ventes que la clôture de la journée abandonnerait (voir
     * abandonnerLesVentesOuvertesLe()), pour le dire avant de clôturer.
     *
     * @param  string  $journee  AAAA-MM-JJ
     */
    public function nombreDeVentesOuvertesLe(string $journee): int
    {
        return $this->ventesOuvertesLe($journee)->count();
    }

    /**
     * Les paniers enregistrés pour la dernière fois ce jour-là ou avant, à
     * l'heure de Paris.
     *
     * @return Builder<PanierCaisse>
     */
    private function ventesOuvertesLe(string $journee): Builder
    {
        return PanierCaisse::query()->where('updated_at', '<', CarbonImmutable::parse($journee, config('app.timezone'))->addDay());
    }

    /**
     * Le panier du vendeur se vide : sa vente vient d'être encaissée, dans
     * la transaction en cours (voir EnregistrerVenteCaisseAction).
     */
    public function vider(User $vendeur): void
    {
        PanierCaisse::query()->where('user_id', $vendeur->getKey())->delete();
    }

    /**
     * Le client choisi, s'il est un client du magasin actif (MagasinScope),
     * archivé compris : sinon aucun.
     */
    private function clientDuMagasin(mixed $clientId): ?int
    {
        $identifiant = self::identifiant($clientId);

        return $identifiant === null ? null : Client::withTrashed()->whereKey($identifiant)->value('id');
    }

    /**
     * Les lignes où un produit ou un service est choisi, par clé du
     * répéteur, réduites à des valeurs simples ; une quantité qui n'est pas
     * encore un nombre d'articles garde celle de la ligne enregistrée sous
     * la même clé pour le même article, sinon compte pour un article.
     *
     * @param  array<array-key, mixed>  $lignes
     * @param  array<string, array<string, mixed>>  $enregistrees
     * @return array<string, array{produit_id: int|null, service_id: int|null, description: string|null, reference: string|null, quantite: int, prix_unitaire_ht: string|null, prix_unitaire_ttc: string|null, taux_tva: string|null, remise_pourcentage: string|null}>
     */
    private function lignesAEnregistrer(array $lignes, array $enregistrees): array
    {
        $resultat = [];

        foreach ($lignes as $cle => $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $produitId = self::identifiant($ligne['produit_id'] ?? null);
            $serviceId = $produitId === null ? self::identifiant($ligne['service_id'] ?? null) : null;

            if ($produitId === null && $serviceId === null) {
                continue;
            }

            $precedente = $enregistrees[(string) $cle] ?? null;
            $memeArticle = $precedente !== null && $precedente['produit_id'] === $produitId && $precedente['service_id'] === $serviceId;

            try {
                $quantite = QuantiteEntiere::depuisSaisie($ligne['quantite'] ?? null);
            } catch (InvalidArgumentException) {
                $quantite = $memeArticle ? $precedente['quantite'] : 1;
            }

            $resultat[(string) $cle] = [
                'produit_id' => $produitId,
                'service_id' => $serviceId,
                'description' => self::texte($ligne['description'] ?? null),
                'reference' => self::texte($ligne['reference'] ?? null),
                'quantite' => $quantite,
                'prix_unitaire_ht' => self::texte($ligne['prix_unitaire_ht'] ?? null),
                'prix_unitaire_ttc' => self::texte($ligne['prix_unitaire_ttc'] ?? null),
                'taux_tva' => self::texte($ligne['taux_tva'] ?? null),
                'remise_pourcentage' => self::texte($ligne['remise_pourcentage'] ?? null),
            ];
        }

        return $resultat;
    }

    /**
     * @param  array<string, array<string, mixed>>  $lignes
     * @return list<array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}>
     */
    private function comparables(array $lignes): array
    {
        return array_values(array_map(
            fn (array $ligne): array => $this->lignesRetirees->comparable($ligne['produit_id'], $ligne['service_id'], $ligne['description'], $ligne['quantite'], $ligne['prix_unitaire_ttc']),
            $lignes,
        ));
    }

    /**
     * Le total TTC des lignes du panier, calculé comme à l'écran de la
     * caisse et à l'encaissement (voir ConstructeurLigneDocument::apercu()),
     * pour son client, en centimes.
     */
    private function totalTtcEnCentimes(PanierCaisse $panier): ?int
    {
        $contexte = ContexteDeVente::pour(Client::withTrashed()->find($panier->client_id), app(TenantContext::class)->magasin());

        try {
            $total = $this->calculateur->totaliser($this->constructeurLigne->apercu($panier->lignes, $contexte))['total_ttc'];

            return $this->calculateur->enCentiemes(number_format($total, 2, '.', ''));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function identifiant(mixed $valeur): ?int
    {
        $identifiant = is_bool($valeur) ? false : filter_var($valeur, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $identifiant === false ? null : $identifiant;
    }

    /**
     * Une valeur saisie en texte, 255 caractères au plus ; null si elle est
     * vide ou n'est pas une valeur simple.
     */
    private static function texte(mixed $valeur): ?string
    {
        if (! is_scalar($valeur) || is_bool($valeur) || (string) $valeur === '') {
            return null;
        }

        return mb_substr((string) $valeur, 0, 255);
    }
}
