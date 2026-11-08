<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureRecuPaiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\CopieClient;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Le reçu de paiement (R2, décision de l'éditeur du 02/11/2026) d'un
 * encaissement fait après l'émission d'une facture ou d'un ticket (un
 * geste « Encaisser », toutes ses parts comprises, ou un paiement saisi
 * dans « Paiements »), ou d'une contre-passation : sa référence stable
 * (RP-LYON1-2026-000001), le jour de l'encaissement, le magasin et
 * l'identité de la société tels qu'ils étaient ce jour-là, ses paiements,
 * signés au registre fiscal, et chaque document réglé avec ce que ses
 * paiements lui imputent et ce qui en restait dû ensuite (voir
 * LigneRecuPaiement et RecusDePaiement). Il imprime le client tel que le
 * premier document réglé l'a figé à son émission. Il se produit depuis ces
 * données enregistrées, jamais recalculé (voir RecuPaiementPdfGenerator) :
 * deux téléchargements donnent le même contenu. Il n'est pas une facture
 * et ne modifie jamais le document qu'il règle. Jamais modifié ni supprimé
 * (voir ProtegeLesDonneesValidees et les déclencheurs de la table) ; il
 * part par e-mail comme un document (voir EnvoyerDocumentAction).
 */
class RecuPaiement extends Model implements DocumentEnvoyable
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, ProtegeLesDonneesValidees;

    public const MENTION = 'Ce reçu n\'est pas une facture.';

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    protected $table = 'recus_paiement';

    // Tout s'écrit à sa naissance, par RecusDePaiement, jamais en mass
    // assignment.
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => NatureRecuPaiement::class,
            'recu_le' => JourCivil::class,
            'emetteur' => 'array',
        ];
    }

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Un reçu de paiement ne se modifie ni ne se supprime.';
    }

    /**
     * Le client des paiements, même archivé depuis (voir les SoftDeletes
     * de Client), comme les documents qu'ils règlent.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * Ses paiements, dans l'ordre où ils ont été enregistrés : chaque part
     * de l'encaissement, ou la contre-passation.
     *
     * @return HasMany<Paiement, $this>
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class)->orderBy('id');
    }

    /**
     * Chaque document qu'il règle, dans l'ordre où l'encaissement les a
     * réglés.
     *
     * @return HasMany<LigneRecuPaiement, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneRecuPaiement::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Son historique d'envois par e-mail (voir EnvoyerDocumentAction).
     *
     * @return MorphMany<EnvoiDocument, $this>
     */
    public function envois(): MorphMany
    {
        return $this->morphMany(EnvoiDocument::class, 'document')->latest('id');
    }

    /**
     * « Reçu de paiement » ou « Contre-passation de paiement ».
     */
    public function libelleDocument(): string
    {
        return $this->nature->getLabel();
    }

    /**
     * Un reçu enregistré atteste un encaissement qui a eu lieu : il part
     * toujours.
     */
    public function peutEtreEnvoye(): bool
    {
        return $this->exists;
    }

    /**
     * Le magasin et la société tels qu'ils étaient le jour de
     * l'encaissement, figés à la naissance du reçu.
     */
    public function emetteur(): Emetteur
    {
        return Emetteur::duContenuSigne($this->emetteur);
    }

    /**
     * Le premier document qu'il règle : celui dont il imprime le client.
     *
     * @throws LogicException
     */
    public function premierDocument(): Facture
    {
        return $this->lignes->first()->facture
            ?? throw new LogicException("Le reçu de paiement {$this->numero} ne règle aucun document.");
    }

    /**
     * Le client tel que le premier document réglé l'a figé à son émission.
     *
     * @throws LogicException
     */
    public function copieClient(): CopieClient
    {
        return $this->premierDocument()->copieClient();
    }

    /**
     * Le premier document réglé était-il fait à un professionnel ? Ses
     * identifiants s'impriment alors sous son nom.
     *
     * @throws LogicException
     */
    public function pourUnProfessionnel(): bool
    {
        return $this->premierDocument()->estPourUnProfessionnel();
    }

    /**
     * Le montant de ses paiements, en centimes : ce que l'encaissement a
     * reçu, ou, en négatif, ce que la contre-passation annule. Le crédit
     * d'avoir n'en fait pas partie : il ne fait entrer aucun argent.
     */
    public function totalEnCentimes(): int
    {
        $calculateur = app(CalculateurLigne::class);

        return $this->paiements->sum(fn (Paiement $paiement): int => $calculateur->enCentiemes($paiement->montant));
    }
}
