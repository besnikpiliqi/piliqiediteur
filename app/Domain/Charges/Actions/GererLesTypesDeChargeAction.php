<?php

namespace App\Domain\Charges\Actions;

use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\TypeDeChargeDeLaSociete;
use App\Domain\Charges\Services\GestesSurLesCharges;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Les types de charge de la société (décision de l'éditeur du 14/11/2026,
 * lot CHARGES ; voir TypesDeCharge) : ajouter un type au nom libre, unique
 * dans la société sans tenir compte de la casse ni des accents, et la
 * nature de ce qu'il paie ; supprimer un type qu'aucune charge ne porte
 * (créé par erreur) ; archiver un type déjà utilisé, qui n'est plus
 * proposé mais reste celui de ses charges, et le restaurer ; cocher les
 * types de la liste de PILIQI « habituels ». Des réglages de la société :
 * par son propriétaire et l'administrateur à qui il coche « Charges :
 * ajouter » (voir AccesAuxCharges::$gereLesTypes) ; jamais un responsable,
 * ni le cabinet lié ni l'expert-comptable déclaré, qui choisissent parmi
 * les types proposés (décision de Claude, D76). Chaque geste s'inscrit au
 * journal d'audit de la société.
 */
class GererLesTypesDeChargeAction
{
    public const REFUS_DROIT = 'Vous ne gérez pas les types de charge de cette société.';

    public const REFUS_NOM = 'Écrivez le nom du type de charge.';

    public const REFUS_DEJA = 'Ce type de charge existe déjà dans la société.';

    public const REFUS_DE_PILIQI = 'Ce type de charge est déjà dans la liste de PILIQI.';

    public const REFUS_INTROUVABLE = 'Ce type de charge n\'existe pas dans cette société.';

    public const REFUS_UTILISE = 'Ce type de charge est déjà porté par une charge : il ne se supprime pas, il s\'archive.';

    public function __construct(
        private readonly GestesSurLesCharges $gestes,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function ajouter(User $auteur, Societe $societe, string $nom, NatureDeCharge $nature): TypeDeChargeDeLaSociete
    {
        $nom = Str::limit(Str::squish($nom), 80, '');

        if ($nom === '') {
            throw new InvalidArgumentException(self::REFUS_NOM);
        }

        return $this->gestes->executerSansJeton($auteur, $societe, function (AccesAuxCharges $acces) use ($nom, $nature): TypeDeChargeDeLaSociete {
            $this->exigerLeDroit($acces);
            $cle = TypeDeChargeDeLaSociete::cleDuNom($nom);

            foreach (TypeDeCharge::cases() as $dePiliqi) {
                if (TypeDeChargeDeLaSociete::cleDuNom($dePiliqi->getLabel()) === $cle) {
                    throw new InvalidArgumentException(self::REFUS_DE_PILIQI);
                }
            }

            if (TypeDeChargeDeLaSociete::query()->where('nom_cle', $cle)->exists()) {
                throw new InvalidArgumentException(self::REFUS_DEJA);
            }

            $type = (new TypeDeChargeDeLaSociete)->forceFill(['nom' => $nom, 'nature' => $nature, 'cree_par' => $acces->compte->getKey()]);
            $type->save();
            $this->journalAudit->inscrire(ActionAuditee::TypeDeChargeAjoute, $acces->compte, null, null, ['type' => $type->nom, 'nature' => $nature->value], $type);

            return $type;
        });
    }

    /**
     * Archive ce type de la société, ou le restaure.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function archiver(User $auteur, Societe $societe, string $uuid, bool $archive): TypeDeChargeDeLaSociete
    {
        return $this->gestes->executerSansJeton($auteur, $societe, function (AccesAuxCharges $acces) use ($uuid, $archive): TypeDeChargeDeLaSociete {
            $this->exigerLeDroit($acces);
            $type = TypeDeChargeDeLaSociete::query()->where('uuid', Str::isUuid($uuid) ? $uuid : null)->lockForUpdate()->first()
                ?? throw new LogicException(self::REFUS_INTROUVABLE);

            if ($type->estArchive() === $archive) {
                return $type;
            }

            $type->forceFill(['archive_le' => $archive ? now() : null])->save();
            $this->journalAudit->inscrire($archive ? ActionAuditee::TypeDeChargeArchive : ActionAuditee::TypeDeChargeRestaure, $acces->compte, null, null, ['type' => $type->nom, 'nature' => $type->nature->value], $type);

            return $type;
        });
    }

    /**
     * Supprime ce type de la société qu'aucune charge ne porte, sous son
     * verrou : une charge qui le choisit en même temps attend, puis la clé
     * étrangère la refuse ; un type déjà porté ne se supprime pas, il
     * s'archive.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function supprimer(User $auteur, Societe $societe, string $uuid): void
    {
        $this->gestes->executerSansJeton($auteur, $societe, function (AccesAuxCharges $acces) use ($uuid): void {
            $this->exigerLeDroit($acces);
            $type = TypeDeChargeDeLaSociete::query()->where('uuid', Str::isUuid($uuid) ? $uuid : null)->lockForUpdate()->first()
                ?? throw new LogicException(self::REFUS_INTROUVABLE);

            if (Charge::query()->where('type_de_la_societe_id', $type->getKey())->exists()) {
                throw new LogicException(self::REFUS_UTILISE);
            }

            $valeurs = ['type' => $type->nom, 'nature' => $type->nature->value];
            $type->delete();
            $this->journalAudit->inscrire(ActionAuditee::TypeDeChargeSupprime, $acces->compte, null, $valeurs, null);
        });
    }

    /**
     * Coche les types de la liste de PILIQI « habituels » de la société.
     *
     * @param  list<TypeDeCharge>  $habituels
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function choisirLesHabituels(User $auteur, Societe $societe, array $habituels): void
    {
        $valeurs = array_values(array_unique(array_map(fn (TypeDeCharge $type): string => $type->value, $habituels)));

        $this->gestes->executerSansJeton($auteur, $societe, function (AccesAuxCharges $acces) use ($valeurs, $societe): void {
            $this->exigerLeDroit($acces);
            Societe::query()->whereKey($societe->getKey())->update(['types_de_charge_habituels' => json_encode($valeurs, JSON_THROW_ON_ERROR)]);
            $this->journalAudit->inscrire(ActionAuditee::TypesDeChargeHabituelsChoisis, $acces->compte, null, null, ['types' => $valeurs]);
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function exigerLeDroit(AccesAuxCharges $acces): void
    {
        if (! $acces->gereLesTypes) {
            throw new AuthorizationException(self::REFUS_DROIT);
        }
    }
}
