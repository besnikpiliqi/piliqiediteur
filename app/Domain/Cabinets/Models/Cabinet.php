<?php

namespace App\Domain\Cabinets\Models;

use App\Domain\Cabinets\Enums\VoieDeVerificationOrdre;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Support\AvertissementsDuCodeDActivite;
use App\Integrations\Sirene\UniteSirene;
use Illuminate\Database\Eloquent\Model;

/**
 * La vérification propre à un cabinet d'expertise comptable (une société
 * de type « Cabinet d'expertise comptable », voir TypeDActivite) : son
 * code d'activité lu au répertoire Sirene à la confirmation de son
 * inscription, contrôlé 69.20Z en NAF rév. 2 ou, pour un code absent ou
 * d'une autre nomenclature, par l'éditeur ; et son inscription au tableau
 * de l'Ordre des experts-comptables, vérifiée par l'annuaire de l'Ordre
 * quand il offre un accès automatique (voir AnnuaireDeLOrdre), sinon par
 * l'éditeur (voir ConstaterLInscriptionALOrdreAction), refusée avec son
 * motif, ou retirée quand l'éditeur constate sa radiation.
 *
 * Une ligne par cabinet, née dans la transaction qui crée la société (voir
 * CreerSocieteAction) et seulement là ; la base refuse une ligne pour une
 * société d'un autre type (voir GardesDesCabinets) et sa suppression. Le
 * cabinet n'invite ni ne lie aucun client tant qu'il n'est pas vérifié
 * (voir Societe::estUnCabinetVerifie()). La correction de son SIREN par
 * l'éditeur relit son code d'activité sous le nouveau SIREN et remet à
 * faire la vérification de son inscription à l'Ordre (voir
 * CorrigerSirenAction).
 */
class Cabinet extends Model
{
    use BelongsToSociete;

    protected $table = 'cabinets';

    protected $fillable = [];

    protected $casts = [
        'activite_controlee_le' => Instant::class,
        'ordre_demande_le' => Instant::class,
        'ordre_verifie_le' => Instant::class,
        'ordre_voie' => VoieDeVerificationOrdre::class,
        'ordre_refuse_le' => Instant::class,
        'ordre_retire_le' => Instant::class,
    ];

    /**
     * Son code d'activité est-il contrôlé : 69.20Z en NAF rév. 2 lu au
     * répertoire Sirene, ou contrôlé par l'éditeur ?
     */
    public function aSonActiviteControlee(): bool
    {
        return $this->activite_controlee_le !== null;
    }

    /**
     * Le répertoire Sirene lui donne-t-il une autre activité que 69.20Z en
     * NAF rév. 2 ? Possible seulement après la correction de son SIREN par
     * l'éditeur (une telle activité refuse l'inscription d'un cabinet) :
     * elle ne se contrôle pas, pas même par l'éditeur, et le cabinet reste
     * non vérifié ; un code absent ou d'une autre nomenclature, lui, se
     * contrôle par l'éditeur (voir ConstaterLInscriptionALOrdreAction).
     */
    public function aUneAutreActivite(): bool
    {
        return $this->code_activite !== null
            && $this->nomenclature_activite === AvertissementsDuCodeDActivite::NOMENCLATURE
            && $this->code_activite !== ControleSirene::CODE_ACTIVITES_COMPTABLES;
    }

    /**
     * Note son activité telle que le répertoire Sirene la donne (sans
     * l'enregistrer) : le code et sa nomenclature, contrôlé à l'instant
     * quand il est 69.20Z en NAF rév. 2 (voir
     * ControleSirene::exerceLesActivitesComptables()), sinon à contrôler.
     * Rend le résultat de ce contrôle, tel que le journal d'audit le
     * retient : « concluant » ; « a_controler », pour un code absent ou
     * d'une autre nomenclature, que l'éditeur contrôle ensuite ;
     * « autre_activite », pour un autre code en NAF rév. 2 (voir
     * aUneAutreActivite()).
     */
    public function noterSonActivite(UniteSirene $unite): string
    {
        $exerce = ControleSirene::exerceLesActivitesComptables($unite);

        $this->forceFill([
            'code_activite' => $unite->activitePrincipale,
            'nomenclature_activite' => $unite->nomenclatureActivite,
            'activite_controlee_le' => $exerce === true ? now() : null,
        ]);

        return match ($exerce) {
            true => 'concluant',
            null => 'a_controler',
            false => 'autre_activite',
        };
    }

    /**
     * Son inscription au tableau de l'Ordre est-elle vérifiée, sans
     * radiation constatée depuis ?
     */
    public function estVerifieALOrdre(): bool
    {
        return $this->ordre_verifie_le !== null && $this->ordre_retire_le === null;
    }

    /**
     * Une vérification attend-elle l'éditeur ?
     */
    public function attendLEditeur(): bool
    {
        return $this->ordre_demande_le !== null && $this->ordre_verifie_le === null;
    }
}
