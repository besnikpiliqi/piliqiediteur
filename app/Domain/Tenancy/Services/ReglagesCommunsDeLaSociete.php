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
 *    principal (voir verifierQuIlEstAligne() et EmettreFactureAction) ;
 *  - ne quitte pas sa société, en effaçant ou en changeant son SIREN, tant
 *    qu'il n'est pas aligné : ce serait échapper à ce refus (voir
 *    refusDeQuitterLaSociete()).
 * Ses avoirs, eux, restent possibles : ils corrigent des documents déjà
 * émis, aux taux et avec la franchise de ceux-ci.
 *
 * Un magasin qui prend un SIREN que portent déjà d'autres magasins doit
 * avoir tous les réglages communs de leur principal. Tant qu'il ne l'a pas
 * enregistré, rien ne prouve qu'il appartient à cette société -- le SIREN
 * reste déclaratif jusqu'à sa vérification par la plateforme agréée : son
 * refus ne nomme que les réglages qui diffèrent, jamais leur valeur, et
 * l'identité du magasin en limite le nombre (voir
 * AligneLesReglagesCommuns), pour que le contrôle ne serve pas à deviner
 * les réglages d'une autre société.
 *
 * Un régime de TVA que le principal n'a pas encore choisi ne se compare
 * pas : un magasin qui en a un ne peut pas le vider (l'écran l'exige), il
 * garde le sien et s'aligne quand le principal aura choisi. Tout autre
 * réglage commun que le principal n'a pas se laisse vide.
 *
 * Le mois de fin d'exercice, commun lui aussi, rejoindra CHAMPS avec les
 * clôtures d'exercice de la caisse sécurisée.
 *
 * Seule lecture entre magasins de ces réglages, décidée par J2 :
 * explicite, filtrée par SIREN, limitée aux colonnes de CHAMPS et à celle
 * qui désigne le principal ; ni le nom ni aucune autre donnée d'un autre
 * magasin n'est lue. Seules les valeurs du principal sortent d'ici, pour
 * dire à un magasin de la société celles qu'il doit prendre ; un magasin
 * qui la rejoint n'en apprend que les noms des réglages qui diffèrent. La
 * table magasins n'a pas de MagasinScope (un magasin est le tenant
 * lui-même) ; SoftDeletes en exclut les magasins supprimés. Rien n'est
 * jamais écrit chez un autre magasin : chacun s'aligne lui-même, par ses
 * propres écrans.
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
     * Le message d'un champ de réglage commun qui empêche un magasin de
     * rejoindre la société dont il prend le SIREN : il ne dit pas la valeur
     * du principal (voir AligneLesReglagesCommuns).
     */
    public const MESSAGE_DU_CHAMP_POUR_REJOINDRE = 'Réglage commun aux magasins de cette société, fixé par son magasin principal : le sien est différent.';

    /**
     * Les réglages communs de $magasin, tel qu'il est en mémoire, qui ne
     * sont pas ceux du magasin principal de son SIREN : réglage => valeur
     * du principal, telle que l'écran la montre (null : non renseignée).
     * Vide sans SIREN, quand le magasin est lui-même le principal, ou
     * quand il est le seul de son SIREN. Un SIREN qui vient d'être saisi,
     * pas encore enregistré, se compare au principal de ce SIREN parmi
     * les magasins qui le portent déjà : les valeurs ne se montrent alors
     * pas au magasin (voir rejoint()).
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
     * : réglage => valeur de $reference, telle que l'écran la montre. Le
     * régime de TVA ne se compare pas tant que $reference n'en a pas. La
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
            $valeurDeReference = $reference->getAttribute($champ);

            if ($champ === 'regime_tva' && $valeurDeReference === null) {
                continue;
            }

            if (self::comparable($champ, $magasin->getAttribute($champ)) !== self::comparable($champ, $valeurDeReference)) {
                $ecarts[$champ] = self::lisible($champ, $valeurDeReference);
            }
        }

        return $ecarts;
    }

    /**
     * Le magasin, tel qu'il est en mémoire, rejoint-il une société ? Il
     * prend un SIREN, à sa création ou à la place du sien.
     */
    public static function rejoint(Magasin $magasin): bool
    {
        return filled($magasin->siren) && (! $magasin->exists || $magasin->isDirty('siren'));
    }

    /**
     * Garde de chaque enregistrement d'un magasin (voir Magasin::booted()),
     * dans l'ordre : il ne quitte pas sa société sans y être aligné ; il
     * ne rejoint une société qu'avec tous les réglages communs de son
     * principal ; un réglage commun qui change prend la valeur du
     * principal. Les réglages qui ne changent pas ne se contrôlent pas
     * ici : un magasin que le principal a laissé derrière lui enregistre
     * encore ses autres paramètres, mais n'émet plus (voir
     * verifierQuIlEstAligne()).
     *
     * @throws ReglagesCommunsDifferentsException
     */
    public function refuserUnEcartAEnregistrer(Magasin $magasin): void
    {
        $refus = $this->refusDeQuitterLaSociete($magasin)
            ?? $this->refusDeRejoindre($magasin)
            ?? $this->refusDeChanger($magasin);

        if ($refus !== null) {
            throw new ReglagesCommunsDifferentsException($refus);
        }
    }

    /**
     * Le refus qu'oppose le magasin, tel qu'il est en mémoire, à quitter
     * la société dont il porte le SIREN en base, en l'effaçant ou en en
     * prenant un autre, tant qu'il n'y est pas aligné, d'après ce qu'il a
     * enregistré : il prend d'abord les réglages de son principal (K5).
     * Null quand il ne quitte aucune société ou qu'il y est aligné. Le
     * magasin est encore de la société : le refus dit les valeurs à
     * prendre.
     */
    public function refusDeQuitterLaSociete(Magasin $magasin): ?string
    {
        $siren = $magasin->exists && $magasin->isDirty('siren') ? $magasin->getOriginal('siren') : null;

        if (blank($siren)) {
            return null;
        }

        $ecarts = $this->ecarts((clone $magasin)->setRawAttributes($magasin->getRawOriginal()));

        return $ecarts === [] ? null : "Ce magasin ne quitte pas votre société (SIREN {$siren}) tant qu'il n'a pas enregistré les réglages communs de son magasin principal ; à prendre d'abord : ".self::liste($ecarts, avecLaPage: true).'.';
    }

    /**
     * Le refus qu'oppose aujourd'hui le magasin à toute facture et à tout
     * ticket, ses réglages communs n'étant pas ceux du principal ; null
     * quand il est aligné.
     */
    public function refusDEmission(Magasin $magasin): ?string
    {
        $ecarts = $this->ecarts($magasin);

        return $ecarts === [] ? null : "Ce magasin n'émet ni facture ni ticket tant que ses réglages communs ne sont pas ceux du magasin principal de votre société (SIREN {$magasin->siren}) ; à prendre : ".self::liste($ecarts, avecLaPage: true).'.';
    }

    /**
     * Garde de l'émission d'une facture ou d'un ticket (voir
     * EmettreFactureAction).
     *
     * @throws ReglagesCommunsDifferentsException
     */
    public function verifierQuIlEstAligne(Magasin $magasin): void
    {
        $refus = $this->refusDEmission($magasin);

        if ($refus !== null) {
            throw new ReglagesCommunsDifferentsException($refus);
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
     * pas celle du principal, pour un magasin de la société (voir
     * IdentiteMagasin et ParametresFiscaux) : toujours une valeur que
     * l'écran permet de saisir.
     */
    public static function messageDuChamp(string $siren, ?string $valeurDuPrincipal): string
    {
        $debut = "Réglage commun à tous les magasins de votre société (SIREN {$siren}), fixé par son magasin principal";

        return $valeurDuPrincipal === null
            ? "{$debut}, qui ne l'a pas renseigné : laissez-le vide."
            : "{$debut} : prenez « {$valeurDuPrincipal} ».";
    }

    /**
     * Le refus d'un SIREN que portent déjà d'autres magasins, à un magasin
     * qui n'a pas tous les réglages communs de leur principal : les noms
     * des réglages qui diffèrent, chacun avec sa page, jamais leur valeur.
     *
     * @param  list<string>  $champs
     */
    public static function messageDuSirenPourRejoindre(array $champs): string
    {
        $reglages = array_map(fn (string $champ): string => self::CHAMPS[$champ].' ('.self::page($champ).')', $champs);

        return 'Avant d\'indiquer ce SIREN, prenez les réglages communs du magasin principal de cette société : '.implode(', ', $reglages).'.';
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
                $reglage .= ' ('.self::page($champ).')';
            }

            $reglages[] = $reglage;
        }

        return implode(', ', $reglages);
    }

    /**
     * Le refus d'un magasin qui rejoint une société sans avoir tous les
     * réglages communs de son principal (voir rejoint()).
     */
    private function refusDeRejoindre(Magasin $magasin): ?string
    {
        if (! self::rejoint($magasin)) {
            return null;
        }

        $champs = array_keys($this->ecarts($magasin));

        return $champs === [] ? null : self::messageDuSirenPourRejoindre($champs);
    }

    /**
     * Le refus d'un réglage commun qui change, chez un magasin de la
     * société, sans prendre la valeur du principal.
     */
    private function refusDeChanger(Magasin $magasin): ?string
    {
        if (! $magasin->exists || $magasin->isDirty('siren') || ! $magasin->isDirty(array_keys(self::CHAMPS))) {
            return null;
        }

        $ecarts = array_filter(
            $this->ecarts($magasin),
            fn (string $champ): bool => $magasin->isDirty($champ),
            ARRAY_FILTER_USE_KEY,
        );

        return $ecarts === [] ? null : "Ces réglages sont communs à tous les magasins de votre société (SIREN {$magasin->siren}) et fixés par son magasin principal ; à prendre : ".self::liste($ecarts).'.';
    }

    /**
     * Le magasin principal du SIREN (K5) : celui que l'éditeur a désigné
     * (voir app:designer-magasin-principal), sinon le plus ancien magasin
     * non supprimé du SIREN, par sa date d'inscription, qu'il soit actif,
     * suspendu ou fermé ; un magasin supprimé ne compte plus. Seules les
     * colonnes de CHAMPS et la désignation sont lues : le modèle obtenu,
     * partiel, ne s'enregistre jamais.
     */
    private function magasinPrincipal(string $siren): ?Magasin
    {
        $magasins = Magasin::query()
            ->where('siren', $siren)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'designe_principal', ...array_keys(self::CHAMPS)]);

        return $magasins->firstWhere('designe_principal', true) ?? $magasins->first();
    }

    /**
     * La page où se change un réglage commun.
     */
    private static function page(string $champ): string
    {
        return in_array($champ, self::CHAMPS_DES_PARAMETRES_FISCAUX, true) ? 'Paramètres fiscaux' : 'Identité du magasin';
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
