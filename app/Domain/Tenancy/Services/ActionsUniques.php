<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\ActionTraitee;
use App\Models\User;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Le jeton unique des demandes (R3) : une émission, une vente à la caisse,
 * une émission de devis, un paiement, un encaissement en plusieurs modes,
 * une contre-passation, un avoir, un envoi, une fermeture de caisse, une
 * clôture du jour et une validation d'achat ou d'avoir fournisseur portent
 * chacune un jeton, que l'écran crée à l'ouverture du formulaire ou de la
 * fenêtre (le panier de la caisse a le sien, voir PanierDeCaisse) et garde
 * jusqu'à son succès.
 * Une demande répétée après une réponse perdue renvoie le résultat déjà
 * enregistré sous son jeton, sans rien refaire ; le même jeton avec une
 * demande différente (une autre action, un autre compte, un autre
 * contenu) est refusé.
 *
 * Seule l'action la plus externe consomme le jeton : celles qu'elle
 * appelle (l'émission et les paiements d'une vente à la caisse, les
 * paiements d'un encaissement en plusieurs modes, l'émission d'un avoir
 * enregistré, celle d'un devis envoyé, la clôture de chaque journée)
 * reçoivent un jeton nul. Une action sans jeton s'exécute
 * simplement : les gestes du système et des tests n'en portent pas.
 *
 * Le jeton se cherche dans la transaction de l'action, juste après son
 * premier verrou (celui du registre fiscal pour une opération qui l'écrit,
 * la ligne de son auteur sinon) : deux demandes du même jeton, qui ont
 * toutes deux le même auteur, passent donc l'une après l'autre, et la
 * seconde trouve le résultat de la première. L'index unique (magasin,
 * jeton) arrête en plus toute course : la demande qui perd est annulée en
 * entier, puis relit le jeton et renvoie le résultat de l'autre. Une
 * action qui échoue n'inscrit rien : son jeton resservira.
 *
 * Une action faite en plusieurs transactions, une par étape (la clôture
 * des journées en retard, chacune dans la sienne), cherche le jeton à
 * chaque étape et ne l'inscrit qu'avec la dernière (voir
 * executerParEtapes()) : une erreur laisse faites les étapes qui la
 * précèdent, et la demande répétée reprend à l'étape en échec.
 */
class ActionsUniques
{
    public const REFUS = 'Une demande différente a déjà été enregistrée sous ce jeton : rechargez la page, puis recommencez.';

    public const EMETTRE_FACTURE = 'facture.emettre';

    public const VENDRE_A_LA_CAISSE = 'caisse.vendre';

    public const EMETTRE_DEVIS = 'devis.emettre';

    public const ENREGISTRER_PAIEMENT = 'paiement.enregistrer';

    public const ENCAISSER_EN_PLUSIEURS_MODES = 'facture.encaisser';

    public const CONTRE_PASSER_PAIEMENT = 'paiement.contre_passer';

    public const ENREGISTRER_AVOIR = 'avoir.enregistrer';

    public const ENVOYER_DOCUMENT = 'document.envoyer';

    public const RELANCER_FACTURE = 'facture.relancer';

    public const FERMER_UNE_CAISSE = 'caisse_personnelle.fermer';

    public const CLOTURER_LA_JOURNEE = 'journee.cloturer';

    public const VALIDER_FACTURE_ACHAT = 'facture_achat.valider';

    public const ENREGISTRER_AVOIR_FOURNISSEUR = 'avoir_fournisseur.enregistrer';

    /**
     * Exécute l'opération une seule fois pour ce jeton dans le magasin
     * actif, et rend son résultat ; une demande répétée rend le résultat
     * déjà enregistré, relu en base.
     *
     * @template TResultat of Model|null
     *
     * @param  string|null  $jeton  null pour une action interne ou du système, qui ne consomme aucun jeton
     * @param  array<array-key, mixed>  $parametres  le contenu de la demande, ce qui la distingue d'une autre
     * @param  Closure(): mixed  $verrou  le premier verrou de l'action, pris avant de chercher le jeton
     * @param  Closure(): TResultat  $operation  l'action elle-même
     * @return TResultat
     *
     * @throws InvalidArgumentException quand le jeton n'est pas un identifiant unique
     * @throws LogicException quand une demande différente a déjà été enregistrée sous ce jeton
     */
    public function executer(?string $jeton, string $action, User $auteur, array $parametres, Closure $verrou, Closure $operation): ?Model
    {
        if ($jeton === null) {
            return $operation();
        }

        if (! Str::isUuid($jeton)) {
            throw new InvalidArgumentException('Cette demande ne porte pas de jeton valable : rechargez la page, puis recommencez.');
        }

        $empreinte = self::empreinte($parametres);

        try {
            return DB::transaction(function () use ($jeton, $action, $auteur, $empreinte, $verrou, $operation): ?Model {
                $verrou();

                $traitee = $this->traitee($jeton);

                if ($traitee !== null) {
                    return $this->resultatDeja($traitee, $action, $auteur, $empreinte);
                }

                return $this->inscrire($jeton, $action, $auteur, $empreinte, $operation());
            });
        } catch (UniqueConstraintViolationException $doublon) {
            return $this->resultatApresUneCourse($doublon, $jeton, $action, $auteur, $empreinte);
        }
    }

    /**
     * Comme executer(), pour une action faite en plusieurs transactions,
     * une par étape : chaque étape prend le premier verrou de l'action,
     * cherche le jeton, puis se fait ; seule la dernière inscrit le jeton,
     * avec son résultat, dans sa propre transaction. Une erreur laisse
     * faites les étapes qui la précèdent, sans inscrire le jeton : la
     * demande répétée reprend à l'étape en échec. Deux demandes du même
     * jeton se suivent étape par étape, et celle qui arrive après la
     * dernière étape de l'autre en rend le résultat, sans rien refaire.
     *
     * @template TResultat of Model|null
     *
     * @param  string|null  $jeton  null pour une action du système ou des tests, qui ne consomme aucun jeton
     * @param  array<array-key, mixed>  $parametres  le contenu de la demande, ce qui la distingue d'une autre
     * @param  Closure(): mixed  $verrou  le premier verrou de l'action, pris au début de chaque étape
     * @param  Closure(): array{0: TResultat, 1: bool}  $etape  une étape, dans sa transaction : son résultat, et si elle est la dernière
     * @return array{resultat: TResultat, deja_enregistree: bool} le résultat de la dernière étape, et si la demande l'avait déjà obtenu
     *
     * @throws InvalidArgumentException quand le jeton n'est pas un identifiant unique
     * @throws LogicException quand une demande différente a déjà été enregistrée sous ce jeton
     */
    public function executerParEtapes(?string $jeton, string $action, User $auteur, array $parametres, Closure $verrou, Closure $etape): array
    {
        if ($jeton !== null && ! Str::isUuid($jeton)) {
            throw new InvalidArgumentException('Cette demande ne porte pas de jeton valable : rechargez la page, puis recommencez.');
        }

        $empreinte = self::empreinte($parametres);

        do {
            try {
                [$resultat, $derniere, $dejaEnregistree] = DB::transaction(function () use ($jeton, $action, $auteur, $empreinte, $verrou, $etape): array {
                    $verrou();

                    $traitee = $jeton === null ? null : $this->traitee($jeton);

                    if ($traitee !== null) {
                        return [$this->resultatDeja($traitee, $action, $auteur, $empreinte), true, true];
                    }

                    [$resultat, $derniere] = $etape();

                    return [$derniere && $jeton !== null ? $this->inscrire($jeton, $action, $auteur, $empreinte, $resultat) : $resultat, $derniere, false];
                });
            } catch (UniqueConstraintViolationException $doublon) {
                if ($jeton === null) {
                    throw $doublon;
                }

                [$resultat, $derniere, $dejaEnregistree] = [$this->resultatApresUneCourse($doublon, $jeton, $action, $auteur, $empreinte), true, true];
            }
        } while (! $derniere);

        return ['resultat' => $resultat, 'deja_enregistree' => $dejaEnregistree];
    }

    /**
     * Le premier verrou d'une action qui n'écrit pas au registre fiscal :
     * la ligne de son auteur, comme la verrouille sa relecture (voir
     * LecteurDesDroits::exigerSousVerrou()).
     *
     * @return Closure(): mixed
     */
    public static function verrouDe(User ...$comptes): Closure
    {
        return static fn (): mixed => User::query()
            ->whereKey(collect($comptes)->map(fn (User $compte): int => (int) $compte->getKey())->unique()->sort()->values()->all())
            ->orderBy('id')
            ->lock('for no key update')
            ->get();
    }

    /**
     * Le dernier résultat de cette action demandée par ce compte dans le
     * magasin actif, null s'il n'y en a pas : la caisse rechargée après une
     * réponse perdue montre la dernière vente enregistrée.
     */
    public function dernierResultat(string $action, User $auteur): ?Model
    {
        return ActionTraitee::query()
            ->where('action', $action)
            ->where('user_id', $auteur->getKey())
            ->whereNotNull('resultat_id')
            ->orderByDesc('id')
            ->first()
            ?->resultat;
    }

    /**
     * Ce jeton a-t-il déjà servi dans le magasin actif ?
     */
    public function aServi(string $jeton): bool
    {
        return Str::isUuid($jeton) && $this->traitee($jeton) !== null;
    }

    /**
     * L'empreinte d'une demande : SHA-256 de ses paramètres en JSON, les
     * clés triées à chaque niveau, un nombre à virgule écrit en texte.
     *
     * @param  array<array-key, mixed>  $parametres
     */
    public static function empreinte(array $parametres): string
    {
        return hash('sha256', json_encode(self::canonique($parametres), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function traitee(string $jeton): ?ActionTraitee
    {
        return ActionTraitee::query()->where('jeton', $jeton)->first();
    }

    /**
     * Inscrit la demande et son résultat sous son jeton, dans la
     * transaction de l'action, et rend ce résultat.
     *
     * @template TResultat of Model|null
     *
     * @param  TResultat  $resultat
     * @return TResultat
     */
    private function inscrire(string $jeton, string $action, User $auteur, string $empreinte, ?Model $resultat): ?Model
    {
        (new ActionTraitee)->forceFill([
            'jeton' => $jeton,
            'action' => $action,
            'user_id' => $auteur->getKey(),
            'empreinte_demande' => $empreinte,
            'resultat_type' => $resultat?->getMorphClass(),
            'resultat_id' => $resultat?->getKey(),
            'created_at' => now(),
        ])->save();

        return $resultat;
    }

    /**
     * Une autre demande du même jeton a été inscrite pendant celle-ci,
     * annulée : le résultat de l'autre, pourvu qu'elle soit la même.
     *
     * @throws UniqueConstraintViolationException quand l'index unique a arrêté autre chose que le jeton
     * @throws LogicException
     */
    private function resultatApresUneCourse(UniqueConstraintViolationException $doublon, string $jeton, string $action, User $auteur, string $empreinte): ?Model
    {
        $traitee = $this->traitee($jeton);

        if ($traitee === null) {
            throw $doublon;
        }

        return $this->resultatDeja($traitee, $action, $auteur, $empreinte);
    }

    /**
     * Le résultat enregistré sous ce jeton, relu dans le magasin actif,
     * pourvu que la demande soit la même : même action, même compte, même
     * contenu.
     *
     * @throws LogicException
     */
    private function resultatDeja(ActionTraitee $traitee, string $action, User $auteur, string $empreinte): ?Model
    {
        if ($traitee->action !== $action || $traitee->user_id !== (int) $auteur->getKey() || ! hash_equals($traitee->empreinte_demande, $empreinte)) {
            throw new LogicException(self::REFUS);
        }

        if ($traitee->resultat_type === null) {
            return null;
        }

        $classe = Relation::getMorphedModel($traitee->resultat_type) ?? $traitee->resultat_type;

        return $classe::query()->findOrFail($traitee->resultat_id);
    }

    /**
     * @param  array<array-key, mixed>  $valeurs
     * @return array<array-key, mixed>
     */
    private static function canonique(array $valeurs): array
    {
        if (! array_is_list($valeurs)) {
            ksort($valeurs, SORT_STRING);
        }

        return array_map(fn (mixed $valeur): mixed => match (true) {
            is_array($valeur) => self::canonique($valeur),
            is_float($valeur) => (string) $valeur,
            $valeur instanceof BackedEnum => $valeur->value,
            default => $valeur,
        }, $valeurs);
    }
}
