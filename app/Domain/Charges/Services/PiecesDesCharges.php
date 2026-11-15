<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Fichiers\FichierControle;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Fichiers\RevueAntivirus;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * La pièce de chaque charge de la société (décision de l'éditeur du
 * 14/11/2026, lot CHARGES), écrite une seule fois ici, par la même chaîne
 * que les pièces du fournisseur (voir PiecesDuFournisseur) :
 * - chaque fichier déposé est contrôlé avant toute transaction (voir
 *   ControleDesFichiers) : type lu dans son contenu (PDF, JPEG ou PNG),
 *   10 Mo au plus, nom réécrit avec l'extension de ce type, PDF sans code
 *   actif, photo réenregistrée sans ses métadonnées, antivirus ; un refus
 *   pour une raison de sécurité s'inscrit au journal d'audit de la
 *   société, et une menace prévient le propriétaire ;
 * - une charge porte une seule pièce, que son dépôt suivant remplace tant
 *   qu'elle n'est pas validée ; ensuite elle se conserve comme la charge
 *   (la base le garantit, voir GardesDesCharges). Le fichier d'une pièce
 *   remplacée s'efface une fois la transaction validée ; celui d'une
 *   transaction annulée, aussitôt ;
 * - le fichier se range sur le disque privé « pieces_de_charge », dans le
 *   dossier de sa société, sous un nom tiré au hasard ;
 * - une pièce se sert, en pièce jointe, après une nouvelle analyse quand
 *   la dernière date de plus de 24 heures (voir contenuServable()) :
 *   reconnue alors comme une menace, elle est bloquée, gardée mais plus
 *   jamais servie.
 * Chaque dépôt, remplacement, refus et blocage s'inscrit au journal
 * d'audit de la société, avec la charge, le nom du fichier et son
 * empreinte (et, au dépôt et au refus, le nom tel que reçu, l'empreinte
 * et la taille du fichier reçu).
 */
final class PiecesDesCharges
{
    public const DISQUE = 'pieces_de_charge';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly ControleDesFichiers $controle,
        private readonly RevueAntivirus $revue,
    ) {}

    /**
     * Contrôle ce fichier déposé pour une charge de cette société, avant
     * toute transaction ; un refus pour une raison de sécurité s'inscrit au
     * journal d'audit, au nom de l'auteur, puis remonte.
     *
     * @throws FichierRefuseException
     */
    public function controler(User $auteur, Societe $societe, ?Charge $charge, PieceDeposee $piece): FichierControle
    {
        try {
            return $this->controle->controler($piece);
        } catch (FichierRefuseException $refus) {
            if ($refus->raison->estDeSecurite()) {
                $this->inscrireLeRefus($auteur, $societe, $charge, $refus);
            }

            throw $refus;
        }
    }

    /**
     * Range ce fichier contrôlé comme la pièce de cette charge, dans la
     * transaction de l'action, sous le verrou de la charge : la pièce
     * d'avant, quand il y en a une, s'efface et son fichier aussi, une
     * fois la transaction validée.
     */
    public function ranger(User $compte, Charge $charge, FichierControle $fichier): PieceDeCharge
    {
        $avant = PieceDeCharge::query()->where('charge_id', $charge->getKey())->first();

        if ($avant !== null) {
            $chemin = $avant->chemin;
            $avant->delete();
            DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));
        }

        $chemin = 'societe-'.$charge->societe_id.'/'.bin2hex(random_bytes(16)).'.'.NomDeFichier::EXTENSIONS[$fichier->type];
        Storage::disk(self::DISQUE)->put($chemin, $fichier->contenu);
        DB::afterRollBack(fn () => Storage::disk(self::DISQUE)->delete($chemin));

        $piece = (new PieceDeCharge)->forceFill([
            'charge_id' => $charge->getKey(),
            'nom' => $fichier->nom,
            'type_mime' => $fichier->type,
            'taille' => $fichier->taille(),
            'empreinte' => $fichier->empreinte(),
            'chemin' => $chemin,
            'analysee_le' => $fichier->analyseLe,
            'ajoutee_par' => $compte->getKey(),
        ]);
        $piece->save();

        $this->journalAudit->inscrire(ActionAuditee::PieceDeChargeDeposee, $compte, $charge->magasin, $avant === null ? null : ['piece' => self::designation($avant)], [
            'charge' => ChargesDeLaSociete::designation($charge),
            'piece' => self::designation($piece),
            ...$fichier->valeursRecues(),
        ], $charge);

        return $piece;
    }

    /**
     * Efface la pièce d'une charge qui se supprime, avant sa validation ;
     * son fichier s'efface une fois la transaction validée.
     */
    public function effacer(Charge $charge): void
    {
        $piece = PieceDeCharge::query()->where('charge_id', $charge->getKey())->first();

        if ($piece === null) {
            return;
        }

        $chemin = $piece->chemin;
        $piece->delete();
        DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));
    }

    /**
     * Le contenu d'une pièce, prêt à être servi à ce compte : jamais celui
     * d'une pièce bloquée ; analysé de nouveau quand sa dernière analyse
     * date de plus de 24 heures (voir RevueAntivirus). Reconnue alors comme
     * une menace, la pièce est bloquée, au nom de ce compte, et le
     * propriétaire de la société en est prévenu.
     *
     * @throws PieceBloqueeException quand la pièce est bloquée
     * @throws AntivirusInjoignableException quand l'antivirus ne répond pas pour une analyse due
     */
    public function contenuServable(User $compte, PieceDeCharge $piece): string
    {
        if ($piece->bloquee_le !== null) {
            throw new PieceBloqueeException;
        }

        $contenu = (string) Storage::disk(self::DISQUE)->get($piece->chemin);

        if (! $this->revue->aRevoir(ValeurRequise::de($piece->analysee_le, 'l\'instant de la dernière analyse de la pièce'))) {
            return $contenu;
        }

        $resultat = $this->revue->analyser($contenu);
        $maintenant = CarbonImmutable::now();

        if ($resultat->estSain()) {
            PieceDeCharge::query()->whereKey($piece->getKey())->where('analysee_le', '<', Instant::enBase($maintenant))->update(['analysee_le' => Instant::enBase($maintenant)]);

            return $contenu;
        }

        $this->bloquer($compte, $piece, (string) $resultat->menace, $maintenant);

        throw new PieceBloqueeException;
    }

    /**
     * Ce que le journal d'audit retient d'une pièce : son nom et son
     * empreinte.
     *
     * @return array{nom: string, empreinte: string}
     */
    public static function designation(PieceDeCharge $piece): array
    {
        return ['nom' => $piece->nom, 'empreinte' => $piece->empreinte];
    }

    /**
     * Bloque une pièce que l'antivirus vient de reconnaître comme une
     * menace, une seule fois même si deux téléchargements la trouvent en
     * même temps ; le blocage s'inscrit au journal d'audit et le
     * propriétaire en est prévenu.
     */
    private function bloquer(User $compte, PieceDeCharge $piece, string $menace, CarbonImmutable $maintenant): void
    {
        $charge = Charge::query()->whereKey($piece->charge_id)->firstOrFail();

        $bloquee = DB::transaction(function () use ($compte, $piece, $menace, $maintenant, $charge): bool {
            Societe::verrouillerAvantSonJournalDAudit($charge->societe_id);

            if (PieceDeCharge::query()->whereKey($piece->getKey())->whereNull('bloquee_le')->update(['bloquee_le' => Instant::enBase($maintenant), 'analysee_le' => Instant::enBase($maintenant), 'menace' => $menace]) === 0) {
                return false;
            }

            $this->journalAudit->inscrire(ActionAuditee::PieceDeChargeBloquee, $compte, $charge->magasin, null, ['charge' => ChargesDeLaSociete::designation($charge), 'piece' => self::designation($piece), 'menace' => $menace], $charge);

            return true;
        });

        if ($bloquee) {
            $this->prevenirLeProprietaire(Societe::query()->findOrFail($charge->societe_id), 'Pièce d\'une charge bloquée par l\'antivirus', "La pièce « {$piece->nom} » de la charge {$charge->libelleDuType()} de {$charge->mois_tva} est bloquée : l'antivirus y a trouvé la menace « {$menace} ». Elle reste conservée comme pièce justificative, mais ne se télécharge plus.");
        }
    }

    /**
     * Inscrit au journal d'audit de la société de la charge, dans sa propre
     * transaction, le refus d'un fichier pour une raison de sécurité, quel
     * que soit l'espace d'où il vient (celui d'un cabinet lié n'est pas la
     * société de la charge) ; une menace prévient aussi le propriétaire de
     * la société.
     */
    private function inscrireLeRefus(User $auteur, Societe $societe, ?Charge $charge, FichierRefuseException $refus): void
    {
        app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(function () use ($auteur, $societe, $charge, $refus): void {
            Societe::verrouillerAvantSonJournalDAudit($societe->getKey());
            $this->journalAudit->inscrire(ActionAuditee::PieceDeChargeRefusee, $auteur, $charge?->magasin, null, [
                'charge' => $charge === null ? null : ChargesDeLaSociete::designation($charge),
                ...$refus->valeursAuditees(),
            ], $charge);
        }));

        if ($refus->raison === RaisonDuRefus::Menace) {
            $this->prevenirLeProprietaire($societe, 'Fichier refusé par l\'antivirus', 'Le fichier « '.NomDeFichier::pourUnMessage($refus->nomRecu)." » déposé par {$auteur->nomPourLHistorique()} pour une charge a été refusé : l'antivirus y a trouvé la menace « {$refus->menace} ». Rien n'a été enregistré.");
        }
    }

    private function prevenirLeProprietaire(Societe $societe, string $titre, string $texte): void
    {
        $societe->proprietaire()->first()?->notify(new AvisDeLAntivirus($societe, $titre, $texte));
    }
}
