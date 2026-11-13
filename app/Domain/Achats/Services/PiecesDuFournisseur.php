<?php

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Fichiers\FichierControle;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Fichiers\RevueAntivirus;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
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
use InvalidArgumentException;
use LogicException;

/**
 * Les pièces du fournisseur jointes aux factures d'achat et aux avoirs
 * fournisseur (décision de l'éditeur du 03/11/2026, lot EX), écrites une
 * seule fois ici, quel que soit l'écran ou l'action qui les dépose :
 *
 * - chaque fichier déposé, à l'écran ou par l'API, est contrôlé avant
 *   toute transaction (voir controler() et ControleDesFichiers, lot FI) :
 *   type lu dans son contenu (PDF, JPEG ou PNG), 10 Mo au plus, nom
 *   réécrit avec l'extension de ce type, PDF sans code actif, photo
 *   réenregistrée sans ses métadonnées, antivirus ; un refus pour une
 *   raison de sécurité s'inscrit au journal d'audit de la société, dans la
 *   chaîne du magasin, et une menace prévient le propriétaire ;
 * - un document porte au plus dix pièces, comptées sous le verrou de sa
 *   ligne : deux dépôts simultanés ne dépassent jamais ce nombre ; un même
 *   fichier ne se joint qu'une fois au même document ;
 * - le fichier conservé se range sur le disque privé « pieces_fournisseur »,
 *   dans le dossier de son magasin, sous un nom tiré au hasard ; la ligne
 *   garde son nom réécrit, son type, sa taille, son empreinte SHA-256 et
 *   l'instant de sa dernière analyse par l'antivirus. Une transaction
 *   annulée efface le fichier déjà rangé ;
 * - avant la validation d'une facture d'achat, une pièce se retire ; jointe
 *   à une facture validée ou à un avoir émis, elle ne se retire plus (la
 *   base le garantit aussi, voir GardesDesPiecesDuFournisseur), on ne peut
 *   qu'en ajouter une. Le fichier d'une pièce retirée s'efface une fois la
 *   transaction validée ;
 * - une pièce se sert (téléchargement seul ou dans un ZIP) après une
 *   nouvelle analyse quand la dernière date de plus de 24 heures (voir
 *   contenuServable()) : reconnue alors comme une menace, elle est bloquée,
 *   gardée comme pièce justificative mais plus jamais servie ;
 * - un document saisi à la main sans aucune pièce porte la marque « pièce
 *   du fournisseur manquante » (F1 = b) : il s'enregistre et se valide
 *   quand même, et la marque se lève à l'ajout de la première pièce (voir
 *   marquer()) ; un document reçu par l'API n'en exige pas ;
 * - chaque ajout, chaque retrait, chaque refus, chaque blocage, chaque pose
 *   et chaque levée de la marque s'inscrit au journal d'audit de la
 *   société, dans la chaîne du magasin (document, nom du fichier,
 *   empreinte, auteur ; à l'ajout et au refus, le nom tel que reçu,
 *   l'empreinte et la taille du fichier reçu).
 *
 * Les actions qui joignent ont déjà pris, dans l'ordre, la ligne de la
 * société, celle du magasin et celle de l'auteur relu ; la ligne du
 * document se verrouille ici.
 */
final class PiecesDuFournisseur
{
    public const DISQUE = 'pieces_fournisseur';

    public const NOMBRE_MAXIMAL_PAR_DOCUMENT = 10;

    public const REFUS_TROP_DE_PIECES = 'Un document porte au plus dix pièces du fournisseur.';

    public const REFUS_DEJA_JOINTE = 'Le fichier « %s » est déjà joint à ce document.';

    public const REFUS_RETRAIT = 'Une pièce ne se retire que d\'une facture d\'achat en brouillon : jointe à un document validé, elle se conserve comme lui ; vous pouvez en ajouter une autre.';

    public function __construct(
        private readonly JournalAudit $journalAudit,
        private readonly ControleDesFichiers $controle,
        private readonly RevueAntivirus $revue,
    ) {}

    /**
     * Contrôle ces fichiers déposés pour ce document du magasin courant
     * (aucun encore à sa saisie), avant toute transaction ; un refus pour
     * une raison de sécurité s'inscrit au journal d'audit, au nom de
     * l'auteur, puis remonte.
     *
     * @param  list<PieceDeposee>  $pieces
     * @return list<FichierControle>
     *
     * @throws FichierRefuseException
     */
    public function controler(User $auteur, FactureAchat|AvoirFournisseur|null $document, array $pieces): array
    {
        return array_map(function (PieceDeposee $piece) use ($auteur, $document): FichierControle {
            try {
                return $this->controle->controler($piece);
            } catch (FichierRefuseException $refus) {
                if ($refus->raison->estDeSecurite()) {
                    $this->inscrireLeRefus($auteur, $document, $refus);
                }

                throw $refus;
            }
        }, $pieces);
    }

    /**
     * Joint ces fichiers contrôlés au document, puis met sa marque à jour.
     *
     * @param  list<FichierControle>  $pieces
     * @return list<PieceDuFournisseur>
     *
     * @throws InvalidArgumentException quand le document en porterait trop, ou porte déjà l'un d'eux
     */
    public function joindre(User $compte, FactureAchat|AvoirFournisseur $document, array $pieces): array
    {
        if ($pieces === []) {
            return [];
        }

        return DB::transaction(function () use ($compte, $document, $pieces): array {
            $document = $this->verrouiller($document);
            $empreintes = PieceDuFournisseur::query()->where($this->colonneDu($document), $document->getKey())->pluck('empreinte')->all();

            if (count($empreintes) + count($pieces) > self::NOMBRE_MAXIMAL_PAR_DOCUMENT) {
                throw new InvalidArgumentException(self::REFUS_TROP_DE_PIECES);
            }

            foreach ($pieces as $piece) {
                if (in_array($piece->empreinte(), $empreintes, true)) {
                    throw new InvalidArgumentException(sprintf(self::REFUS_DEJA_JOINTE, $piece->nom));
                }

                $empreintes[] = $piece->empreinte();
            }

            $jointes = array_map(fn (FichierControle $piece): PieceDuFournisseur => $this->ranger($compte, $document, $piece), $pieces);
            $this->marquer($compte, $document);

            return $jointes;
        });
    }

    /**
     * Retire cette pièce de sa facture d'achat en brouillon, puis, sauf
     * quand une autre la remplace aussitôt (voir RetirerUnePieceAction), met
     * la marque du document à jour ; son fichier s'efface une fois la
     * transaction validée.
     *
     * @throws LogicException quand le document n'est pas un brouillon
     */
    public function retirer(User $compte, PieceDuFournisseur $piece, bool $mettreAJourLaMarque = true): void
    {
        DB::transaction(function () use ($compte, $piece, $mettreAJourLaMarque): void {
            $document = $this->verrouiller($piece->document());

            if (! $document instanceof FactureAchat || $document->statut !== StatutFactureAchat::Brouillon) {
                throw new LogicException(self::REFUS_RETRAIT);
            }

            $piece = PieceDuFournisseur::query()->whereKey($piece->getKey())->firstOrFail();
            $chemin = $piece->chemin;
            $piece->delete();
            DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));

            $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurRetiree, $compte, $document->magasin, null, self::valeursAuditees($document, $piece), $document);

            if ($mettreAJourLaMarque) {
                $this->marquer($compte, $document);
            }
        });
    }

    /**
     * Retire toutes les pièces d'une facture d'achat en brouillon qui se
     * supprime (voir SupprimerFactureAchatAction), chacune inscrite au
     * journal d'audit ; aucune marque à mettre à jour.
     */
    public function retirerToutes(User $compte, FactureAchat $factureAchat): void
    {
        foreach (PieceDuFournisseur::query()->where('facture_achat_id', $factureAchat->getKey())->orderBy('id')->get() as $piece) {
            $chemin = $piece->chemin;
            $piece->delete();
            DB::afterCommit(fn () => Storage::disk(self::DISQUE)->delete($chemin));
            $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurRetiree, $compte, $factureAchat->magasin, null, self::valeursAuditees($factureAchat, $piece), $factureAchat);
        }
    }

    /**
     * Pose ou lève la marque « pièce du fournisseur manquante » : posée sur
     * un document saisi à la main qui n'a aucune pièce, levée sinon ;
     * jamais sur un document reçu par l'API. Chaque changement s'inscrit au
     * journal d'audit.
     */
    public function marquer(User $compte, FactureAchat|AvoirFournisseur $document): void
    {
        $saisieALaMain = $document instanceof FactureAchat
            ? ($document->source ?? SourceFactureAchat::Manuelle) === SourceFactureAchat::Manuelle
            : ($document->source ?? SourceAvoirFournisseur::Manuelle) === SourceAvoirFournisseur::Manuelle;
        $manquante = $saisieALaMain && PieceDuFournisseur::query()->where($this->colonneDu($document), $document->getKey())->doesntExist();

        if ((bool) $document->piece_manquante === $manquante) {
            return;
        }

        $document->forceFill(['piece_manquante' => $manquante])->save();
        $this->journalAudit->inscrire(
            $manquante ? ActionAuditee::PieceDuFournisseurManquantePosee : ActionAuditee::PieceDuFournisseurManquanteLevee,
            $compte,
            $document->magasin,
            null,
            ['document' => TvaDuFournisseur::designation($document)],
            $document,
        );
    }

    /**
     * Le contenu d'une pièce, prêt à être servi à ce compte (téléchargement
     * seul, ou dans un ZIP) : jamais celui d'une pièce bloquée ; analysé de
     * nouveau quand sa dernière analyse date de plus de 24 heures (voir
     * RevueAntivirus). Reconnue alors comme une menace, la pièce est
     * bloquée, au nom de ce compte, et le propriétaire de la société en est
     * prévenu.
     *
     * @throws PieceBloqueeException quand la pièce est bloquée
     * @throws AntivirusInjoignableException quand l'antivirus ne répond pas pour une analyse due
     */
    public function contenuServable(User $compte, PieceDuFournisseur $piece): string
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
            PieceDuFournisseur::query()->whereKey($piece->getKey())->where('analysee_le', '<', Instant::enBase($maintenant))->update(['analysee_le' => Instant::enBase($maintenant)]);

            return $contenu;
        }

        $this->bloquer($compte, $piece, (string) $resultat->menace, $maintenant);

        throw new PieceBloqueeException;
    }

    /**
     * Bloque une pièce que l'antivirus vient de reconnaître comme une
     * menace : gardée, plus jamais servie ; le blocage s'inscrit au journal
     * d'audit et le propriétaire en est prévenu, une seule fois même si
     * deux téléchargements la trouvent en même temps.
     */
    private function bloquer(User $compte, PieceDuFournisseur $piece, string $menace, CarbonImmutable $maintenant): void
    {
        $document = $piece->document();
        $magasin = $document->magasin;

        $bloquee = DB::transaction(function () use ($compte, $piece, $menace, $maintenant, $document, $magasin): bool {
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);

            if (PieceDuFournisseur::query()->whereKey($piece->getKey())->whereNull('bloquee_le')->update(['bloquee_le' => Instant::enBase($maintenant), 'analysee_le' => Instant::enBase($maintenant), 'menace' => $menace]) === 0) {
                return false;
            }

            $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurBloquee, $compte, $magasin, null, [...self::valeursAuditees($document, $piece), 'menace' => $menace], $document);

            return true;
        });

        if ($bloquee) {
            $this->prevenirLeProprietaire($magasin, 'Pièce du fournisseur bloquée par l\'antivirus', "La pièce « {$piece->nom} » de ".self::libelle($document)." est bloquée : l'antivirus y a trouvé la menace « {$menace} ». Elle reste conservée comme pièce justificative, mais ne se télécharge plus.");
        }
    }

    /**
     * Inscrit au journal d'audit, dans sa propre transaction, le refus d'un
     * fichier pour une raison de sécurité ; une menace prévient aussi le
     * propriétaire de la société.
     */
    private function inscrireLeRefus(User $auteur, FactureAchat|AvoirFournisseur|null $document, FichierRefuseException $refus): void
    {
        $magasin = app(TenantContext::class)->magasin();

        DB::transaction(function () use ($auteur, $document, $refus, $magasin): void {
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);
            $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurRefusee, $auteur, $magasin, null, [
                'document' => $document === null ? null : TvaDuFournisseur::designation($document),
                ...$refus->valeursAuditees(),
            ], $document);
        });

        if ($refus->raison === RaisonDuRefus::Menace) {
            $vise = $document === null ? 'une saisie d\'achat' : self::libelle($document);
            $this->prevenirLeProprietaire($magasin, 'Fichier refusé par l\'antivirus', 'Le fichier « '.NomDeFichier::pourUnMessage($refus->nomRecu)." » déposé par {$auteur->nomPourLHistorique()} pour {$vise} a été refusé : l'antivirus y a trouvé la menace « {$refus->menace} ». Rien n'a été enregistré.");
        }
    }

    private function prevenirLeProprietaire(Magasin $magasin, string $titre, string $texte): void
    {
        $societe = Societe::query()->findOrFail($magasin->societe_id);
        $societe->proprietaire()->first()?->notify(new AvisDeLAntivirus($societe, "{$magasin->nom} : {$titre}", $texte));
    }

    /**
     * « la facture d'achat FA-PORT-2026-000001 (F-812 chez le fournisseur) ».
     */
    private static function libelle(FactureAchat|AvoirFournisseur $document): string
    {
        $nature = $document instanceof FactureAchat ? 'la facture d\'achat' : 'l\'avoir fournisseur';
        $numero = $document->numero ?? 'en brouillon';
        $chezLeFournisseur = $document->numero_fournisseur === null ? '' : " ({$document->numero_fournisseur} chez le fournisseur)";

        return "{$nature} {$numero}{$chezLeFournisseur}";
    }

    /**
     * Les valeurs qu'un retrait ou un blocage inscrit au journal d'audit.
     *
     * @return array{document: array{nature: string, numero: string|null, numero_fournisseur: string|null}, nom: string, empreinte: string}
     */
    private static function valeursAuditees(FactureAchat|AvoirFournisseur $document, PieceDuFournisseur $piece): array
    {
        return ['document' => TvaDuFournisseur::designation($document), 'nom' => $piece->nom, 'empreinte' => $piece->empreinte];
    }

    /**
     * Range le fichier contrôlé, puis enregistre sa ligne et l'inscrit au
     * journal d'audit ; le fichier s'efface si la transaction est annulée.
     */
    private function ranger(User $compte, FactureAchat|AvoirFournisseur $document, FichierControle $fichier): PieceDuFournisseur
    {
        $chemin = 'magasin-'.$document->magasin_id.'/'.bin2hex(random_bytes(16)).'.'.NomDeFichier::EXTENSIONS[$fichier->type];
        Storage::disk(self::DISQUE)->put($chemin, $fichier->contenu);
        DB::afterRollBack(fn () => Storage::disk(self::DISQUE)->delete($chemin));

        $piece = (new PieceDuFournisseur)->forceFill([
            $this->colonneDu($document) => $document->getKey(),
            'nom' => $fichier->nom,
            'type_mime' => $fichier->type,
            'taille' => $fichier->taille(),
            'empreinte' => $fichier->empreinte(),
            'chemin' => $chemin,
            'analysee_le' => $fichier->analyseLe,
            'ajoutee_par' => $compte->getKey(),
        ]);
        $piece->save();

        $this->journalAudit->inscrire(ActionAuditee::PieceDuFournisseurJointe, $compte, $document->magasin, null, [...self::valeursAuditees($document, $piece), ...$fichier->valeursRecues()], $document);

        return $piece;
    }

    /**
     * Le document relu sous le verrou de sa ligne.
     */
    private function verrouiller(FactureAchat|AvoirFournisseur $document): FactureAchat|AvoirFournisseur
    {
        return $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }

    private function colonneDu(FactureAchat|AvoirFournisseur $document): string
    {
        return $document instanceof FactureAchat ? 'facture_achat_id' : 'avoir_fournisseur_id';
    }
}
