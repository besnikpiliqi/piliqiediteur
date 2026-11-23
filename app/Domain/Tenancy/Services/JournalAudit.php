<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Exceptions\JournalAuditNonConfigureException;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TeteJournalAudit;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\Reglage;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Le journal d'audit des sociétés (S13) : chaque action importante s'y
 * inscrit, en ajout seul, avec son auteur, ce qu'elle touche et les
 * anciennes et nouvelles valeurs que son action retient (voir
 * ActionAuditee::champsAudites()), jamais un mot de passe, un jeton, un
 * secret ni un code. L'auteur est celui que l'action reçoit, jamais lu
 * dans la session : la personne qui confirme son inscription l'est avant
 * d'être connectée, et une commande n'a que le système pour auteur.
 *
 * Chaque entrée suit la précédente de sa chaîne : celle d'un magasin
 * quand elle le concerne, celle de la société sinon, dans sa catégorie
 * (voir CategorieAudit). Elle en signe l'empreinte : HMAC-SHA256, avec la
 * clé dédiée du journal (config/journal_audit.php), de l'empreinte
 * précédente suivie de son contenu canonique ; une entrée modifiée,
 * insérée ou retirée au milieu se voit (voir VerificateurJournalAudit).
 * La tête de la chaîne (voir TeteJournalAudit) retient la dernière
 * séquence et la dernière empreinte : un effacement des dernières entrées
 * se voit aussi.
 *
 * Une inscription se fait dans la transaction de l'action qu'elle retient
 * : si elle échoue, l'action est annulée, et inversement. La tête de la
 * chaîne s'y verrouille en dernier, après tout autre verrou de l'action
 * (registres, société, document) : deux magasins d'une même société ne
 * s'attendent jamais l'un l'autre sur l'audit. L'adresse IP et le
 * navigateur d'une requête HTTP vont dans la trace de l'entrée (voir
 * TraceAudit), rangée à part dans la même transaction, seulement au
 * journal de la société où le visiteur agit (voir TraceDuVisiteur).
 *
 * L'entrée s'inscrit dans la société active (voir TenantContext) ; celle
 * d'un magasin, dans la chaîne d'un magasin de cette société. Une
 * procédure de l'éditeur s'y inscrit avec son origine, sa justification et
 * ses opérateurs (voir InterventionDeLEditeur), sauf au journal de l'autre
 * partie d'un lien entre un cabinet et un client qu'elle ne vise pas, qui
 * n'en garde que l'origine. Le geste d'une personne d'une autre société
 * sur une demande de lien qui n'est jamais devenue un lien actif s'y
 * inscrit sans rien d'elle, origine « autre société » (voir
 * inscrireLeGesteDeLAutreSociete()).
 */
class JournalAudit
{
    private const ORIGINE_MEMBRE = 'membre';

    private const ORIGINE_EDITEUR = 'editeur';

    private const ORIGINE_SYSTEME = 'systeme';

    private const ORIGINE_AUTRE_SOCIETE = 'autre_societe';

    private const ORIGINE_CLIENT = 'client';

    /**
     * @param  User|null  $auteur  la personne qui fait l'action ; null pour le système et l'éditeur
     * @param  array<string, mixed>|null  $anciennesValeurs
     * @param  array<string, mixed>|null  $nouvellesValeurs
     * @param  InterventionDeLEditeur|null  $editeur  la procédure de l'éditeur qui fait l'action
     * @param  bool  $detailsDeLEditeur  faux au journal de la société qu'une procédure de l'éditeur ne vise pas : sans sa justification ni ses opérateurs
     *
     * @throws JournalAuditNonConfigureException
     * @throws InvalidArgumentException quand une valeur n'est pas retenue par l'action
     */
    public function inscrire(ActionAuditee $action, ?User $auteur, ?Magasin $magasin, ?array $anciennesValeurs, ?array $nouvellesValeurs, ?Model $cible = null, ?InterventionDeLEditeur $editeur = null, bool $detailsDeLEditeur = true): EntreeAudit
    {
        return $this->ecrire($action, match (true) {
            $editeur !== null => self::ORIGINE_EDITEUR,
            $auteur === null => self::ORIGINE_SYSTEME,
            default => self::ORIGINE_MEMBRE,
        }, $auteur, $magasin, $anciennesValeurs, $nouvellesValeurs, $cible, $detailsDeLEditeur ? $editeur : null);
    }

    /**
     * Inscrit, au journal de la société active, le geste qu'une personne
     * d'une autre société a fait sur une demande de lien entre un cabinet
     * et un client qui n'est pas devenue un lien actif (sa désignation, sa
     * demande, son refus, son retrait) : sans rien de cette personne, ni
     * son nom ni son compte, l'origine « autre société » disant seulement
     * que le geste ne vient ni de la société, ni de l'éditeur, ni du
     * système ; les valeurs disent de l'autre société ce que la société en
     * sait.
     *
     * @param  array<string, mixed>|null  $anciennesValeurs
     * @param  array<string, mixed>|null  $nouvellesValeurs
     *
     * @throws JournalAuditNonConfigureException
     * @throws InvalidArgumentException quand une valeur n'est pas retenue par l'action
     */
    public function inscrireLeGesteDeLAutreSociete(ActionAuditee $action, ?array $anciennesValeurs, ?array $nouvellesValeurs, ?Model $cible = null): EntreeAudit
    {
        return $this->ecrire($action, self::ORIGINE_AUTRE_SOCIETE, null, null, $anciennesValeurs, $nouvellesValeurs, $cible, null);
    }

    /**
     * Inscrit, au journal du magasin, la réponse qu'un client du magasin
     * donne à un devis par son lien secret (voir
     * RepondreAuDevisEnLigneAction) : le client n'a pas de compte,
     * l'origine « client » le dit, et les valeurs portent ce qu'il a
     * répondu ; son adresse IP et son navigateur vont dans la trace de
     * l'entrée, comme pour tout visiteur (voir TraceDuVisiteur).
     *
     * @param  array<string, mixed>  $nouvellesValeurs
     *
     * @throws JournalAuditNonConfigureException
     * @throws InvalidArgumentException quand une valeur n'est pas retenue par l'action
     */
    public function inscrireLaReponseDuClient(ActionAuditee $action, Magasin $magasin, array $nouvellesValeurs, Model $cible): EntreeAudit
    {
        return $this->ecrire($action, self::ORIGINE_CLIENT, null, $magasin, null, $nouvellesValeurs, $cible, null);
    }

    /**
     * @param  array<string, mixed>|null  $anciennesValeurs
     * @param  array<string, mixed>|null  $nouvellesValeurs
     * @param  InterventionDeLEditeur|null  $details  la procédure de l'éditeur dont l'entrée garde la justification et les opérateurs
     *
     * @throws JournalAuditNonConfigureException
     * @throws InvalidArgumentException quand une valeur n'est pas retenue par l'action
     */
    private function ecrire(ActionAuditee $action, string $origine, ?User $auteur, ?Magasin $magasin, ?array $anciennesValeurs, ?array $nouvellesValeurs, ?Model $cible, ?InterventionDeLEditeur $details): EntreeAudit
    {
        [$cle, $cleId] = self::cleCourante();
        $societe = app(TenantContext::class)->societe();

        if ($magasin !== null && (int) $magasin->societe_id !== $societe->getKey()) {
            throw new LogicException('Une entrée du journal d\'audit ne s\'inscrit que dans la chaîne d\'un magasin de la société active.');
        }

        self::verifierLesChamps($action, $anciennesValeurs);
        self::verifierLesChamps($action, $nouvellesValeurs);

        $categorie = $action->categorie();
        $maintenant = CarbonImmutable::now()->startOfSecond();
        $duree = $categorie->dureeDeConservation();

        return DB::transaction(function () use ($action, $origine, $magasin, $anciennesValeurs, $nouvellesValeurs, $cible, $cle, $cleId, $societe, $auteur, $categorie, $maintenant, $duree, $details): EntreeAudit {
            $tete = $this->teteVerrouillee(CleDuModele::de($societe), $magasin === null ? null : CleDuModele::de($magasin), $categorie);

            $entree = (new EntreeAudit)->forceFill([
                'societe_id' => CleDuModele::de($societe),
                'magasin_id' => $magasin?->getKey(),
                'categorie' => $categorie,
                'sequence' => $tete->sequence + 1,
                'origine' => $origine,
                'auteur_id' => $auteur?->getKey(),
                'auteur_libelle' => $auteur?->nomPourLHistorique(),
                'action' => $action,
                'cible_type' => $cible?->getMorphClass(),
                'cible_id' => $cible?->getKey(),
                'anciennes_valeurs' => $anciennesValeurs === null ? null : self::trier($anciennesValeurs),
                'nouvelles_valeurs' => $nouvellesValeurs === null ? null : self::trier($nouvellesValeurs),
                'justification' => $details?->justification,
                'operateur' => $details?->operateur(),
                'cle_id' => $cleId,
                'expire_le' => $duree === null ? null : Calendrier::deParis()->decaler($maintenant, $duree),
                'created_at' => $maintenant,
            ]);
            $entree->empreinte = self::empreinte($cle, $tete->empreinte, $entree);
            $entree->save();

            TraceAudit::noterPour($entree);

            $tete->forceFill(['sequence' => $entree->sequence, 'empreinte' => $entree->empreinte])->save();

            return $entree;
        });
    }

    /**
     * Inscrit ce qu'une action a changé : les seuls champs dont la valeur
     * diffère entre avant et après, avec leurs anciennes et leurs nouvelles
     * valeurs ; rien quand rien n'a changé.
     *
     * @param  array<string, mixed>  $avant  les valeurs retenues avant l'action (voir valeur())
     * @param  array<string, mixed>  $apres  les mêmes champs après l'action
     *
     * @throws JournalAuditNonConfigureException
     * @throws InvalidArgumentException quand une valeur n'est pas retenue par l'action
     */
    public function inscrireLesChangements(ActionAuditee $action, ?User $auteur, ?Magasin $magasin, array $avant, array $apres, ?Model $cible = null): ?EntreeAudit
    {
        $champsModifies = array_flip(array_keys(array_diff_assoc(array_map(serialize(...), $apres), array_map(serialize(...), $avant))));

        if ($champsModifies === []) {
            return null;
        }

        return $this->inscrire($action, $auteur, $magasin, array_intersect_key($avant, $champsModifies), array_intersect_key($apres, $champsModifies), $cible);
    }

    /**
     * L'empreinte d'une entrée, chaînée à celle qui la précède dans sa
     * chaîne : HMAC-SHA256 de l'empreinte précédente (vide pour la première
     * entrée de la chaîne), d'un saut de ligne, puis du contenu canonique
     * de l'entrée.
     */
    public static function empreinte(string $cle, ?string $empreintePrecedente, EntreeAudit $entree): string
    {
        return hash_hmac('sha256', ($empreintePrecedente ?? '')."\n".self::contenuCanonique($entree), $cle);
    }

    /**
     * Le contenu de l'entrée tel que son empreinte le signe : chacun de ses
     * champs, dans cet ordre, les valeurs triées par clé, les instants en
     * UTC à la seconde, encodé en JSON une seule fois.
     */
    public static function contenuCanonique(EntreeAudit $entree): string
    {
        return json_encode([
            'societe_id' => (int) $entree->societe_id,
            'magasin_id' => $entree->magasin_id === null ? null : (int) $entree->magasin_id,
            'categorie' => $entree->categorie->value,
            'sequence' => (int) $entree->sequence,
            'origine' => $entree->origine,
            'auteur_id' => $entree->auteur_id === null ? null : (int) $entree->auteur_id,
            'auteur_libelle' => $entree->auteur_libelle,
            'action' => $entree->action->value,
            'cible_type' => $entree->cible_type,
            'cible_id' => $entree->cible_id === null ? null : (int) $entree->cible_id,
            'anciennes_valeurs' => $entree->anciennes_valeurs === null ? null : self::trier($entree->anciennes_valeurs),
            'nouvelles_valeurs' => $entree->nouvelles_valeurs === null ? null : self::trier($entree->nouvelles_valeurs),
            'justification' => $entree->justification,
            'operateur' => $entree->operateur === null ? null : self::trier($entree->operateur),
            'cle_id' => $entree->cle_id,
            'expire_le' => $entree->expire_le === null ? null : Instant::enBase($entree->expire_le),
            'created_at' => Instant::enBase($entree->created_at),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * La clé qui a chaîné une entrée : la clé courante, ou l'une des clés
     * précédentes ; null si elle n'est plus connue.
     */
    public static function cle(string $cleId): ?string
    {
        $courante = config('journal_audit.cle_id') === $cleId ? config('journal_audit.cle') : null;
        $cle = $courante ?? (Reglage::tableau('journal_audit.cles_precedentes')[$cleId] ?? null);

        return is_string($cle) ? self::decoder($cle) : null;
    }

    /**
     * Une valeur telle qu'elle s'inscrit au journal : une énumération par
     * sa valeur, un montant décimal par sa chaîne, une date par son jour,
     * jamais un nombre à virgule flottante.
     */
    public static function valeur(mixed $valeur): mixed
    {
        return match (true) {
            $valeur instanceof BackedEnum => $valeur->value,
            $valeur instanceof \DateTimeInterface => Calendrier::deParis()->jourDe($valeur),
            is_float($valeur) => (string) $valeur,
            is_array($valeur) => array_map(self::valeur(...), $valeur),
            default => $valeur,
        };
    }

    /**
     * Vrai si la clé courante du journal et son identifiant permettent d'y
     * inscrire une entrée, sans rien rendre de l'un ni de l'autre : ce que
     * vérifie caisse:diagnostiquer.
     */
    public static function estConfigure(): bool
    {
        try {
            self::cleCourante();
        } catch (JournalAuditNonConfigureException) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws JournalAuditNonConfigureException
     */
    private static function cleCourante(): array
    {
        $cleId = config('journal_audit.cle_id');
        $cle = is_string(config('journal_audit.cle')) ? self::decoder(config('journal_audit.cle')) : null;

        if (! is_string($cleId) || $cleId === '' || $cle === null || strlen($cle) < 32) {
            throw JournalAuditNonConfigureException::cleAbsente();
        }

        return [$cle, $cleId];
    }

    private static function decoder(string $cle): ?string
    {
        $octets = base64_decode($cle, true);

        return $octets === false ? null : $octets;
    }

    /**
     * Les valeurs d'une action ne retiennent que ses champs, et jamais un
     * champ interdit, même si la liste de l'action en portait un.
     *
     * @param  array<string, mixed>|null  $valeurs
     *
     * @throws InvalidArgumentException
     */
    private static function verifierLesChamps(ActionAuditee $action, ?array $valeurs): void
    {
        foreach (array_keys($valeurs ?? []) as $champ) {
            foreach (ActionAuditee::CHAMPS_INTERDITS as $interdit) {
                if (preg_match('/(^|_)'.preg_quote($interdit, '/').'(_|$)/', (string) $champ) === 1) {
                    throw new InvalidArgumentException("Le champ « {$champ} » ne s'inscrit jamais au journal d'audit.");
                }
            }

            if (! in_array($champ, $action->champsAudites(), true)) {
                throw new InvalidArgumentException("Le champ « {$champ} » n'est pas retenu par l'action « {$action->value} » du journal d'audit.");
            }
        }

        self::refuserCeQuiNeSInscritPas($valeurs ?? [], 'valeurs');
    }

    /**
     * @param  array<array-key, mixed>  $valeurs
     *
     * @throws InvalidArgumentException
     */
    private static function refuserCeQuiNeSInscritPas(array $valeurs, string $chemin): void
    {
        foreach ($valeurs as $cle => $valeur) {
            if (is_array($valeur)) {
                self::refuserCeQuiNeSInscritPas($valeur, "{$chemin}.{$cle}");
            } elseif (! ($valeur === null || is_bool($valeur) || is_int($valeur) || is_string($valeur))) {
                throw new InvalidArgumentException("{$chemin}.{$cle} : seuls null, booléens, entiers, chaînes et tableaux s'inscrivent au journal d'audit (voir JournalAudit::valeur()).");
            }
        }
    }

    /**
     * Les clés triées à chaque niveau : le contenu canonique ne dépend pas
     * de l'ordre dans lequel l'action a écrit ses valeurs, ni de celui que
     * la base rend.
     *
     * @param  array<array-key, mixed>  $valeurs
     * @return array<array-key, mixed>
     */
    private static function trier(array $valeurs): array
    {
        if (! array_is_list($valeurs)) {
            ksort($valeurs, SORT_STRING);
        }

        return array_map(fn (mixed $valeur): mixed => is_array($valeur) ? self::trier($valeur) : $valeur, $valeurs);
    }

    /**
     * La tête de la chaîne, verrouillée (SELECT ... FOR UPDATE), créée à sa
     * première entrée. INSERT IGNORE : une transaction concurrente qui crée
     * la même tête n'est pas une erreur, la lecture verrouillée trouve sa
     * ligne dès qu'elle est validée.
     */
    private function teteVerrouillee(int $societeId, ?int $magasinId, CategorieAudit $categorie): TeteJournalAudit
    {
        $requete = fn () => TeteJournalAudit::query()
            ->where('societe_id', $societeId)
            ->where('categorie', $categorie->value)
            ->when($magasinId === null, fn ($requete) => $requete->whereNull('magasin_id'), fn ($requete) => $requete->where('magasin_id', $magasinId))
            ->lockForUpdate();

        $tete = $requete()->first();

        if ($tete !== null) {
            return $tete;
        }

        TeteJournalAudit::query()->insertOrIgnore([
            'societe_id' => $societeId,
            'magasin_id' => $magasinId,
            'categorie' => $categorie->value,
            'sequence' => 0,
            'created_at' => $maintenant = now(),
            'updated_at' => $maintenant,
        ]);

        return $requete()->firstOrFail();
    }
}
