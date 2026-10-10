<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Exceptions\ReglagesCommunsDifferentsException;
use App\Domain\Tenancy\Models\Magasin;
use BackedEnum;

/**
 * Les réglages communs aux magasins d'une même société (J2, K5). Un SIREN
 * est un seul assujetti -- un régime de TVA, une option pour les débits,
 * un n° de TVA -- et une seule personne morale -- une raison sociale, une
 * forme juridique, un capital, une immatriculation. Chaque magasin garde
 * ces réglages sur sa propre fiche, qu'impriment ses documents, mais seul
 * le magasin principal du SIREN les fixe (voir magasinPrincipal()). Un
 * autre magasin du SIREN :
 *  - n'enregistre que les valeurs du principal, par quelque écran ou
 *    commande que ce soit (voir refuserUnEcartAEnregistrer(), appelé à
 *    chaque enregistrement d'un magasin) ; les pages Identité du magasin
 *    et Paramètres fiscaux disent, champ par champ, la valeur à prendre ;
 *  - n'émet ni facture ni ticket tant qu'il n'est pas aligné sur lui, par
 *    exemple après un changement du principal ou la désignation d'un autre
 *    principal (voir verifierQuIlEstAligne() et EmettreFactureAction).
 * Ses avoirs, eux, restent possibles : ils corrigent des documents déjà
 * émis, aux taux et avec la franchise de ceux-ci.
 *
 * Le mois de fin d'exercice, commun lui aussi, rejoindra CHAMPS avec les
 * clôtures d'exercice de la caisse sécurisée.
 *
 * Seule lecture entre magasins de ces réglages, décidée par J2 :
 * explicite, filtrée par SIREN, limitée aux colonnes de CHAMPS et à celle
 * qui désigne le principal ; ni le nom ni aucune autre donnée d'un autre
 * magasin n'est lue, et seules les valeurs du principal sortent d'ici,
 * pour dire au magasin celles qu'il doit prendre. La table magasins n'a
 * pas de MagasinScope (un magasin est le tenant lui-même) ; SoftDeletes en
 * exclut les magasins supprimés. Rien n'est jamais écrit chez un autre
 * magasin : chacun s'aligne lui-même, par ses propres écrans.
 */
class ReglagesCommunsDeLaSociete
{
    /**
     * Les réglages communs, avec leur nom dans les messages, dans l'ordre
     * où ils se citent.
     */
    public const CHAMPS = [
        'regime_tva' => 'régime de TVA',
        'option_debits_tva' => 'option pour les débits',
        'numero_tva' => 'n° de TVA intracommunautaire',
        'raison_sociale' => 'raison sociale',
        'forme_juridique' => 'forme juridique',
        'capital_social' => 'capital social',
        'immatriculation' => 'immatriculation',
    ];

    /**
     * Ceux qui se règlent dans Paramètres fiscaux ; les autres, dans
     * l'identité du magasin.
     */
    public const CHAMPS_DES_PARAMETRES_FISCAUX = ['regime_tva', 'option_debits_tva'];

    /**
     * Les réglages communs de $magasin, tel qu'il est en mémoire, qui ne
     * sont pas ceux du magasin principal de son SIREN : réglage => valeur
     * du principal, telle que l'écran la montre (null : non renseignée).
     * Vide sans SIREN, quand le magasin est lui-même le principal, ou
     * quand il est le seul de son SIREN. Un SIREN qui vient d'être saisi,
     * pas encore enregistré, se compare au principal de ce SIREN parmi
     * les magasins qui le portent déjà.
     *
     * @return array<string, string|null>
     */
    public function ecarts(Magasin $magasin): array
    {
        if (blank($magasin->siren)) {
            return [];
        }

        $principal = $this->magasinPrincipal($magasin->siren);

        if ($principal === null || $principal->is($magasin)) {
            return [];
        }

        return self::ecartsAvec($magasin, $principal);
    }

    /**
     * Les réglages communs de $magasin qui ne sont pas ceux de $reference
     * : réglage => valeur de $reference, telle que l'écran la montre. La
     * commande de l'éditeur qui désigne un nouveau principal s'en sert
     * pour dire, avant de le désigner, quels magasins devront s'aligner
     * sur lui.
     *
     * @return array<string, string|null>
     */
    public static function ecartsAvec(Magasin $magasin, Magasin $reference): array
    {
        $ecarts = [];

        foreach (array_keys(self::CHAMPS) as $champ) {
            if (self::comparable($champ, $magasin->getAttribute($champ)) !== self::comparable($champ, $reference->getAttribute($champ))) {
                $ecarts[$champ] = self::lisible($champ, $reference->getAttribute($champ));
            }
        }

        return $ecarts;
    }

    /**
     * Garde de chaque enregistrement d'un magasin (voir Magasin::booted())
     * : un réglage commun qui change doit prendre la valeur du principal.
     * Un magasin qui prend un SIREN déjà porté par d'autres doit avoir
     * tous les siens. Les réglages qui ne changent pas ne se contrôlent
     * pas ici : un magasin que le principal a laissé derrière lui
     * enregistre encore ses autres paramètres, mais n'émet plus (voir
     * verifierQuIlEstAligne()).
     *
     * @throws ReglagesCommunsDifferentsException
     */
    public function refuserUnEcartAEnregistrer(Magasin $magasin): void
    {
        $rejoint = ! $magasin->exists || $magasin->isDirty('siren');

        if (! $rejoint && ! $magasin->isDirty(array_keys(self::CHAMPS))) {
            return;
        }

        $ecarts = array_filter(
            $this->ecarts($magasin),
            fn (string $champ): bool => $rejoint || $magasin->isDirty($champ),
            ARRAY_FILTER_USE_KEY,
        );

        if ($ecarts !== []) {
            throw ReglagesCommunsDifferentsException::aLEnregistrement($magasin->siren, $ecarts);
        }
    }

    /**
     * Le refus qu'oppose aujourd'hui le magasin à toute facture et à tout
     * ticket, ses réglages communs n'étant pas ceux du principal ; null
     * quand il est aligné.
     */
    public function refusDEmission(Magasin $magasin): ?string
    {
        $ecarts = $this->ecarts($magasin);

        return $ecarts === [] ? null : ReglagesCommunsDifferentsException::aLEmission($magasin->siren, $ecarts)->getMessage();
    }

    /**
     * Garde de l'émission d'une facture ou d'un ticket (voir
     * EmettreFactureAction).
     *
     * @throws ReglagesCommunsDifferentsException
     */
    public function verifierQuIlEstAligne(Magasin $magasin): void
    {
        $ecarts = $this->ecarts($magasin);

        if ($ecarts !== []) {
            throw ReglagesCommunsDifferentsException::aLEmission($magasin->siren, $ecarts);
        }
    }

    /**
     * L'identifiant du magasin principal du SIREN, pour la commande de
     * l'éditeur qui en désigne un autre ; null sans magasin.
     */
    public function idDuMagasinPrincipal(string $siren): ?int
    {
        return $this->magasinPrincipal($siren)?->getKey();
    }

    /**
     * Le message d'un champ de réglage commun dont la valeur saisie n'est
     * pas celle du principal (voir IdentiteMagasin et ParametresFiscaux).
     */
    public static function messageDuChamp(string $siren, string $champ, ?string $valeurDuPrincipal): string
    {
        $debut = "Réglage commun à tous les magasins de votre société (SIREN {$siren}), fixé par son magasin principal";

        return match (true) {
            $valeurDuPrincipal !== null => "{$debut} : prenez « {$valeurDuPrincipal} ».",
            $champ === 'regime_tva' => "{$debut}, qui ne l'a pas encore choisi : il le choisit en premier.",
            default => "{$debut}, qui ne l'a pas renseigné : laissez-le vide.",
        };
    }

    /**
     * Le message du SIREN d'une société dont le magasin principal a
     * d'autres paramètres fiscaux que le magasin qui le saisit (voir
     * IdentiteMagasin).
     *
     * @param  array<string, string|null>  $ecarts
     */
    public static function messageDuSiren(array $ecarts): string
    {
        return 'Avant d\'indiquer ce SIREN, prenez dans Paramètres fiscaux les réglages du magasin principal de cette société : '.self::liste($ecarts).'.';
    }

    /**
     * Les réglages qui diffèrent, chacun avec la valeur du principal :
     * « régime de TVA « Réel normal (déclaration mensuelle) », capital
     * social vide », et la page où chacun se change si $avecLaPage.
     *
     * @param  array<string, string|null>  $ecarts
     */
    public static function liste(array $ecarts, bool $avecLaPage = false): string
    {
        $reglages = [];

        foreach ($ecarts as $champ => $valeur) {
            $reglage = self::CHAMPS[$champ].($valeur === null ? ' vide' : " « {$valeur} »");

            if ($avecLaPage) {
                $reglage .= in_array($champ, self::CHAMPS_DES_PARAMETRES_FISCAUX, true) ? ' (Paramètres fiscaux)' : ' (Identité du magasin)';
            }

            $reglages[] = $reglage;
        }

        return implode(', ', $reglages);
    }

    /**
     * Le magasin principal du SIREN : celui que l'éditeur a désigné (voir
     * app:designer-magasin-principal), sinon le magasin qui porte ce SIREN
     * depuis le plus longtemps (voir Magasin::booted()), qu'il soit actif,
     * suspendu ou fermé ; un magasin supprimé ne compte plus. Un magasin
     * qui prend après d'autres le SIREN de leur société ne devient donc
     * jamais principal à leur place, quelle que soit la date de son
     * inscription. Seules les colonnes de CHAMPS et la désignation sont
     * lues : le modèle obtenu, partiel, ne s'enregistre jamais.
     */
    private function magasinPrincipal(string $siren): ?Magasin
    {
        $magasins = Magasin::query()
            ->where('siren', $siren)
            ->orderBy('siren_renseigne_le')
            ->orderBy('id')
            ->get(['id', 'designe_principal', ...array_keys(self::CHAMPS)]);

        return $magasins->firstWhere('designe_principal', true) ?? $magasins->first();
    }

    /**
     * Une valeur telle qu'elle se compare : une énumération par sa valeur,
     * l'option par un booléen, un texte en majuscules, sans espace autour
     * ni espaces doublés (« SARL  Dupont » est « sarl dupont »), le capital
     * par son montant au centime (le cast decimal:2 l'écrit « 10000.00 »,
     * même saisi « 10000 ») ; vide, null.
     */
    private static function comparable(string $champ, mixed $valeur): string|bool|null
    {
        return match (true) {
            $valeur instanceof BackedEnum => $valeur->value,
            $champ === 'option_debits_tva' => (bool) $valeur,
            blank($valeur) => null,
            default => preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $valeur))),
        };
    }

    /**
     * Une valeur du principal telle que l'écran la montre.
     */
    private static function lisible(string $champ, mixed $valeur): ?string
    {
        return match (true) {
            $champ === 'option_debits_tva' => $valeur ? 'activée' : 'désactivée',
            blank($valeur) => null,
            $valeur instanceof RegimeTva => $valeur->getLabel(),
            $champ === 'capital_social' => number_format((float) $valeur, 2, ',', ' ').' €',
            default => (string) $valeur,
        };
    }
}
