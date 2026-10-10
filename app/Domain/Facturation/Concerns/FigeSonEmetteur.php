<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Facturation\Services\Emetteur;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Un ticket, une facture ou un avoir émis ne change plus jamais : il
 * garde l'émetteur qu'il a imprimé à son émission (identité légale du
 * magasin, adresse, identifiants, régime de TVA, option pour les débits,
 * mentions entre professionnels), même si le magasin change ensuite les
 * siens.
 *
 * Aucune colonne du document ne copie cet émetteur : il est signé au
 * registre fiscal avec le document (voir ContenuFiscal), dans son
 * enregistrement de la chaîne des ventes, et se relit là, jamais sur le
 * magasin. Le PDF d'un document émis, téléchargé ou joint à un e-mail,
 * l'imprime donc tel qu'il a été signé (voir FacturePdfGenerator et
 * AvoirPdfGenerator), et l'e-mail qui le porte le présente (voir
 * DocumentMail).
 */
trait FigeSonEmetteur
{
    /**
     * L'enregistrement du document dans la chaîne des ventes du registre
     * fiscal de son magasin, écrit à son émission (voir
     * EmettreFactureAction et EmettreAvoirAction). Un document n'en a
     * qu'un ; si la vérification de l'intégrité lui en trouve un second,
     * le premier de la chaîne, celui de son émission, fait foi.
     */
    public function enregistrementDeVente(): MorphOne
    {
        return $this->morphOne(EnregistrementFiscal::class, 'document')
            ->where('chaine', ChaineFiscale::Vente->value)
            ->orderBy('sequence');
    }

    /**
     * L'émetteur que le document imprime : celui que son contenu signé a
     * retenu une fois le document numéroté, le magasin tel qu'il est
     * aujourd'hui tant qu'il est un brouillon. Un document numéroté sans
     * enregistrement signé n'en a aucun : il est refusé, plutôt que de
     * prendre celui du magasin d'aujourd'hui.
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function emetteur(): Emetteur
    {
        if ($this->numero === null) {
            return Emetteur::duMagasin($this->magasin);
        }

        $enregistrement = $this->enregistrementDeVente()->first();

        if ($enregistrement === null) {
            throw DocumentSansEnregistrementSigneException::pour($this);
        }

        return Emetteur::duContenuSigne($enregistrement->contenuSigne()['donnees']['contexte']['emetteur']);
    }
}
