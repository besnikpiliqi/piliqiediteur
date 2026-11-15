<?php

namespace App\Domain\Charges\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce que les charges de la société laissent à faire (décision de l'éditeur
 * du 14/11/2026, lot CHARGES), compté en une requête parmi les charges que
 * l'accès voit (voir ChargesDeLaSociete) :
 * - pour qui valide (le comptable, ou le propriétaire sans comptable) :
 *   « N charges à valider », pièces sans chiffres comprises, qu'il saisit
 *   avant de les valider (« dont M pièces sans chiffres à saisir ») ;
 * - pour qui les ajoute sans être le comptable (le propriétaire,
 *   l'administrateur ou le responsable coché) : « N charges renvoyées par
 *   votre comptable ».
 * Pour l'espace d'un cabinet, le nombre de charges à valider de chacun de
 * ses clients qui les lui accorde, en une requête, filtrée par client et
 * par les magasins réels qu'il ouvre au compte.
 */
final class ChargesAFaire
{
    public function __construct(
        private readonly ChargesDeLaSociete $charges,
        private readonly AccesDesCabinets $cabinets,
    ) {}

    /**
     * Les charges qui attendent la validation (voir
     * EtatDeLaCharge::attendantLaValidation()), dont les pièces sans
     * chiffres, et les charges renvoyées au client.
     *
     * @return array{a_valider: int, pieces_sans_chiffres: int, renvoyees: int}
     */
    public function compter(AccesAuxCharges $acces): array
    {
        $nombres = $this->charges->visibles($acces)->toBase()
            ->selectRaw('COUNT(*) FILTER (WHERE charges.etat IN (?, ?)) AS a_valider, COUNT(*) FILTER (WHERE charges.etat = ?) AS pieces_sans_chiffres, COUNT(*) FILTER (WHERE charges.etat = ?) AS renvoyees', [...EtatDeLaCharge::attendantLaValidation(), EtatDeLaCharge::PieceSansChiffres->value, EtatDeLaCharge::Renvoyee->value])
            ->first();

        return [
            'a_valider' => ValeursLues::entier($nombres->a_valider ?? 0),
            'pieces_sans_chiffres' => ValeursLues::entier($nombres->pieces_sans_chiffres ?? 0),
            'renvoyees' => ValeursLues::entier($nombres->renvoyees ?? 0),
        ];
    }

    /**
     * Les lignes de l'« À faire » de cet accès, menant à la page de ses
     * charges (cette adresse), filtrée sur la vue qui les montre.
     *
     * @param  \Closure(string): string  $page  l'adresse de la page des charges pour une vue
     * @return list<ChoseAFaire>
     */
    public function choses(AccesAuxCharges $acces, \Closure $page): array
    {
        $nombres = $this->compter($acces);
        $choses = [];

        if ($acces->valide() && $nombres['a_valider'] > 0) {
            $choses[] = new ChoseAFaire(
                cle: 'charges_a_valider',
                groupe: 'Charges',
                urgence: UrgenceAFaire::Orange,
                nombre: $nombres['a_valider'],
                phrase: trans_choice('{1} 1 charge à valider|[2,*] :count charges à valider', $nombres['a_valider'])
                    .($nombres['pieces_sans_chiffres'] > 0 ? ', '.trans_choice('{1} dont 1 pièce sans chiffres à saisir|[2,*] dont :count pièces sans chiffres à saisir', $nombres['pieces_sans_chiffres']) : '')
                    .' : leur TVA déductible ne compte qu\'en estimation tant qu\'elles ne le sont pas.',
                url: $page('a_valider'),
                bouton: 'Valider',
                aSurveiller: false,
            );
        }

        if (! $acces->estComptable() && $nombres['renvoyees'] > 0) {
            $choses[] = new ChoseAFaire(
                cle: 'charges_renvoyees',
                groupe: 'Charges',
                urgence: UrgenceAFaire::Rouge,
                nombre: $nombres['renvoyees'],
                phrase: trans_choice('{1} 1 charge renvoyée par votre comptable|[2,*] :count charges renvoyées par votre comptable', $nombres['renvoyees']).' : corrigez ou remplacez sa pièce, elle repassera « à valider ».',
                url: $page('renvoyees'),
                bouton: 'Corriger',
                aSurveiller: false,
            );
        }

        return $choses;
    }

    /**
     * Le nombre de charges à valider, pièces sans chiffres comprises, de
     * chacun de ces clients du cabinet qui lui accorde « Charges : voir,
     * ajouter, saisir et valider » : celles
     * de « Toute la société » et des magasins réels que le client ouvre au
     * compte. Une lecture hors de la société courante, filtrée par client.
     *
     * @param  list<int>  $clients
     * @return array<int, int> par client
     */
    public function aValiderParClient(User $compte, array $clients): array
    {
        $parClient = $this->cabinets->autorisationsParClient($compte);
        $accordes = array_values(array_filter($clients, fn (int $client): bool => in_array(PermissionRegistry::CHARGES_VALIDER, $parClient[$client] ?? [], true)));

        if ($accordes === []) {
            return [];
        }

        $ouverts = $this->cabinets->idsDesMagasinsOuverts($compte);

        $nombres = [];
        $lus = Charge::query()->withoutGlobalScope(SocieteScope::class)
            ->whereIn('charges.societe_id', $accordes)
            ->whereIn('charges.etat', EtatDeLaCharge::attendantLaValidation())
            ->where(fn (Builder $requete): Builder => $requete->whereNull('charges.magasin_id')->orWhereIn('charges.magasin_id', $ouverts))
            ->groupBy('charges.societe_id')
            ->toBase()
            ->selectRaw('charges.societe_id, COUNT(*) AS nombre')
            ->pluck('nombre', 'societe_id');

        foreach ($lus as $client => $nombre) {
            $nombres[ValeursLues::entier($client)] = ValeursLues::entier($nombre);
        }

        return $nombres;
    }
}
