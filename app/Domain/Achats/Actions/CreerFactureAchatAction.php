<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Exceptions\ConfirmationDesEcartsRequiseException;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Achats\Services\MontantsDuFournisseur;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Achats\Services\TvaDuFournisseur;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Enregistre une facture d'achat en brouillon, sans aucun impact sur le
 * stock (voir ValiderFactureAchatAction). Ses lignes, et les produits
 * existants qu'elles corrigent, sont enregistrés par
 * ConstructeurLigneAchat ; ses nouveaux produits ne naissent qu'à sa
 * validation. Quand une ligne change le taux de TVA d'un
 * produit, le registre fiscal du magasin est le premier verrou de la
 * transaction (voir ConstructeurLigneAchat::verrouillerLeRegistreSiUnTauxChange()).
 *
 * Un magasin suspendu ou fermé n'enregistre plus de facture d'achat (voir
 * Magasin::verifierQuIlEstOuvert()) : sa ligne se relit en base sous un
 * verrou partagé, après le registre et la ligne de sa société (voir
 * Societe::verrouillerAvantSonJournalDAudit(), la facture pouvant
 * s'inscrire au journal d'audit) et avant l'auteur, comme à la validation
 * (voir ValiderFactureAchatAction). Sa fermeture, qui la
 * verrouille (voir FermerMagasinAction), passe donc tout entière avant
 * la création, qui est refusée, ou après elle, le brouillon déjà
 * enregistré restant consultable dans le magasin fermé.
 *
 * Son auteur se relit ensuite en base sous le verrou de sa ligne (voir
 * LecteurDesDroits::exigerSousVerrou()), comme dans tous les gestes sur
 * les brouillons : sa suspension au même instant, qui verrouille cette
 * ligne (voir JournalDesDroits), passe tout entière avant la création, qui
 * est alors refusée, ou après elle. Il doit pouvoir enregistrer une facture
 * d'achat, et, pour chaque ligne qui crée ou corrige un produit, le faire
 * au catalogue ; la facture l'enregistre comme auteur, avec son nom figé.
 *
 * Elle garde les montants qu'écrit la facture du fournisseur (décision de
 * l'éditeur du 02/11/2026, T1) : le PU HT et le HT de chaque ligne, la TVA
 * de chaque taux, saisis à l'écran ou reçus par l'API (source « api »,
 * qui peut aussi écrire la base de chaque taux), et le total TTC saisi
 * pour contrôle (voir MontantsDuFournisseur). Un écart au-delà de la
 * tolérance se confirme, sinon rien n'est enregistré (voir
 * TvaDuFournisseur) ; sans montant écrit, les propositions de PILIQI
 * s'appliquent. La TVA de chaque taux se retient en dernier, et son journal
 * d'audit se verrouille après tout le reste.
 *
 * Une facture saisie à la main porte la date de la facture du fournisseur,
 * sans laquelle elle ne s'enregistre pas (lot EX : le téléchargement des
 * documents par période classe un achat par elle), et la pièce du
 * fournisseur, jointe à la saisie (voir PiecesDuFournisseur) ; sans pièce,
 * elle s'enregistre quand même, marquée « pièce du fournisseur manquante »
 * (décision de l'éditeur du 03/11/2026, F1 = b). Une facture reçue par
 * l'API n'exige ni l'une ni l'autre.
 */
class CreerFactureAchatAction
{
    public function __construct(
        private readonly ConstructeurLigneAchat $constructeurLignes,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly TvaDuFournisseur $tva,
        private readonly PiecesDuFournisseur $pieces,
    ) {}

    /**
     * @param  array<array-key, array<string, mixed>>  $lignes  voir ConstructeurLigneAchat::enregistrer()
     * @param  array<string, mixed>  $attributs
     * @param  MontantsDuFournisseur|null  $montants  ce qu'écrit la facture du fournisseur au niveau du document ; aucun, les propositions de PILIQI
     * @param  list<PieceDeposee>  $pieces  les pièces du fournisseur jointes à la saisie
     *
     * @throws AuthorizationException
     * @throws LogicException quand le magasin est en lecture seule ou qu'une saisie à la main n'a pas la date de la facture du fournisseur
     * @throws ConfirmationDesEcartsRequiseException
     */
    public function handle(User $auteur, Fournisseur $fournisseur, array $lignes, array $attributs = [], ?MontantsDuFournisseur $montants = null, array $pieces = []): FactureAchat
    {
        $montants ??= MontantsDuFournisseur::aucun();

        return DB::transaction(function () use ($auteur, $fournisseur, $lignes, $attributs, $montants, $pieces) {
            $this->constructeurLignes->verrouillerLeRegistreSiUnTauxChange($lignes);
            $magasin = app(TenantContext::class)->magasin();
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, FactureAchat::class);

            return $this->operateur->pendant($compte, function () use ($compte, $fournisseur, $lignes, $attributs, $montants, $pieces): FactureAchat {
                $factureAchat = (new FactureAchat([...$attributs, 'fournisseur_id' => $fournisseur->getKey()]))->forceFill(['cree_par' => $compte->getKey()]);
                $factureAchat->verifierLaDateDeLaSaisie();
                $factureAchat->save();

                $document = $this->constructeurLignes->enregistrer($compte, $factureAchat, $lignes, $montants);
                $this->tva->retenir($compte, $factureAchat, $document, $montants);
                $this->pieces->joindre($compte, $factureAchat, $pieces);
                $this->pieces->marquer($compte, $factureAchat->refresh());

                return ModeleRelu::de($factureAchat, 'lignes');
            });
        });
    }
}
