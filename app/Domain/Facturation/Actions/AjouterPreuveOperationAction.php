<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Enregistre la référence d'une preuve qui justifie l'exonération d'une
 * facture émise (I4) : une déclaration d'export, un document de transport
 * ou l'attestation de réception de l'acquéreur, d'un type que sa nature
 * admet (voir PreuvesRequises::typesAdmis()). Une preuve enregistrée ne se
 * modifie ni ne se supprime (voir PreuveOperation) : une référence n'est
 * donc enregistrée qu'une fois par facture, quel que soit son type et
 * sans tenir compte de la casse (« cmr-2026-0042 » est « CMR-2026-0042 »),
 * faute de quoi un même document compterait deux fois parmi ceux
 * qu'exige une livraison intracommunautaire, ou vaudrait à la fois la
 * déclaration et le document de transport d'une livraison vers un DOM.
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, n'enregistre plus rien, voir
 * Magasin::verifierQuIlEstOuvert() ; une fermeture du magasin, qui la
 * verrouille, voir FermerMagasinAction, passe tout entière avant ou
 * après), puis son auteur sous le verrou de sa ligne (voir
 * LecteurDesDroits), puis la facture sous le sien, dans le magasin actif
 * (MagasinScope) : celle d'un autre magasin est introuvable, et deux
 * enregistrements de la même preuve ne passent pas l'un à côté de
 * l'autre. La facture elle-même ne change pas : la preuve s'écrit à côté
 * de son contenu signé. L'auteur relu doit pouvoir justifier la facture
 * relue (voir FacturePolicy::justifier()) ; la preuve note son nom figé.
 */
class AjouterPreuveOperationAction
{
    public function __construct(private readonly LecteurDesDroits $lecteur) {}

    /**
     * @param  string  $reference  la référence du document, telle qu'il la porte (n° MRN, de CN23, de lettre de voiture...)
     * @param  string|null  $dateDocument  AAAA-MM-JJ
     * @param  string|null  $emetteur  qui a établi le document
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Facture $facture, TypePreuve $type, string $reference, ?string $dateDocument = null, ?string $emetteur = null): PreuveOperation
    {
        $reference = trim($reference);
        $emetteur = blank($emetteur) ? null : trim($emetteur);

        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('Indiquez la référence de la preuve, en 100 caractères au plus.');
        }

        if ($emetteur !== null && mb_strlen($emetteur) > 150) {
            throw new InvalidArgumentException('Le nom de qui a établi la preuve compte 150 caractères au plus.');
        }

        $dateDocument = blank($dateDocument) ? null : $this->dateDuDocument($dateDocument);

        return DB::transaction(function () use ($auteur, $facture, $type, $reference, $dateDocument, $emetteur): PreuveOperation {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, PermissionRegistry::FACTURE_JUSTIFIER, $magasin);
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Facture::REFUS_INTROUVABLE);
            $this->lecteur->exiger($compte, 'justifier', $magasin, $facture);

            if (($refus = self::refusPour($facture)) !== null) {
                throw new LogicException($refus);
            }

            if (! in_array($type, PreuvesRequises::typesAdmis($facture->nature_operation), true)) {
                throw new LogicException("Ce type de preuve ne concerne pas une vente de nature « {$facture->nature_operation->getLabel()} ».");
            }

            $dejaEnregistree = $facture->preuvesOperation()->pluck('reference')
                ->first(fn (string $enregistree): bool => self::sansCasse($enregistree) === self::sansCasse($reference));

            if ($dejaEnregistree !== null) {
                throw new LogicException("La preuve « {$dejaEnregistree} » est déjà enregistrée sur cette facture.");
            }

            return PreuveOperation::forceCreate([
                'facture_id' => $facture->getKey(),
                'type' => $type,
                'reference' => $reference,
                'date_document' => $dateDocument,
                'emetteur' => $emetteur,
                'note_par' => $compte->getKey(),
            ]);
        });
    }

    /**
     * Pourquoi cette facture ne reçoit aucune preuve, null quand elle en
     * reçoit : ce que l'action refuse, et ce qui retire son bouton de la
     * fiche. Une facture entièrement corrigée par des avoirs en reçoit
     * encore, même si elle n'en demande plus (voir PreuvesRequises).
     */
    public static function refusPour(Facture $facture): ?string
    {
        return match (true) {
            $facture->statut === StatutFacture::Brouillon => 'Une preuve s\'enregistre sur une facture émise : émettez d\'abord ce brouillon.',
            PreuvesRequises::typesAdmis($facture->nature_operation) === [] => "Une vente de nature « {$facture->nature_operation->getLabel()} » ne demande aucune preuve d'exonération.",
            default => null,
        };
    }

    /**
     * La référence telle que la comparent les doublons : sa casse pliée
     * (pliage simple d'Unicode), en PHP, pour que SQLite et PostgreSQL
     * comparent de la même façon.
     */
    private static function sansCasse(string $reference): string
    {
        return mb_convert_case($reference, MB_CASE_FOLD_SIMPLE, 'UTF-8');
    }

    /**
     * La date que porte le document, jamais après aujourd'hui à Paris.
     *
     * @throws InvalidArgumentException
     */
    private function dateDuDocument(string $saisie): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $saisie, config('app.timezone'));
        } catch (InvalidFormatException) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $saisie) {
            throw new InvalidArgumentException('La date du document n\'est pas une date valide.');
        }

        if ($date->isAfter(today())) {
            throw new InvalidArgumentException('La date du document ne peut pas être dans le futur.');
        }

        return $saisie;
    }
}
