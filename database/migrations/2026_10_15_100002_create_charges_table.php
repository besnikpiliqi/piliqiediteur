<?php

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\OrigineDeLaCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Support\GardesDesCharges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les charges de la société (décision de l'éditeur du 14/11/2026, lot
 * CHARGES ; voir Charge) : loyer, électricité, honoraires… déposées et
 * saisies par le client ou par son comptable, validées par le comptable,
 * pour une TVA à payer réelle et estimée (voir TvaDeLaPeriode). Elles ne
 * deviennent jamais un achat de marchandises, ne créent jamais de produit
 * et ne touchent jamais le stock. Une charge est de « Toute la société »
 * ou d'un magasin réel de la société (clé étrangère composite) ; son type
 * est de la liste de PILIQI ou de la société, jamais les deux ; ses
 * montants, en centimes, sont la somme de ses lignes par taux (voir
 * lignes_de_charge), et sa pièce est rangée à part (voir
 * pieces_de_charge). Une charge validée ne s'efface jamais et ne change
 * que par une correction, comptée (voir GardesDesCharges).
 * Deux charges d'une société n'ont jamais le même fournisseur avec le même
 * numéro de pièce, ni, pour une facture qu'apportera la plateforme agréée
 * (étape 2), le même identifiant de fournisseur avec le même numéro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Un magasin réel de la société, ou aucun : « Toute la société ».
            $table->unsignedBigInteger('magasin_id')->nullable();
            // Un type de la liste de PILIQI (voir TypeDeCharge), ou un type
            // de la société.
            $table->string('type', 40)->nullable();
            $table->unsignedBigInteger('type_de_la_societe_id')->nullable();
            // Le fournisseur, en texte libre ou repris d'un fournisseur de
            // la société, sans créer de fiche ; sa clé, en minuscules, sans
            // accents ni espaces répétés, sert à refuser un doublon.
            $table->string('fournisseur', 120)->nullable();
            $table->string('fournisseur_cle', 120)->nullable();
            // L'identifiant du fournisseur (SIREN, SIRET ou n° de TVA) d'une
            // facture reçue par la plateforme agréée, à l'étape 2.
            $table->string('fournisseur_identifiant', 30)->nullable();
            $table->string('numero_piece', 60)->nullable();
            $table->string('numero_piece_cle', 60)->nullable();
            $table->date('date_piece');
            $table->date('date_paiement')->nullable();
            // Le mois de TVA, « AAAA-MM » : proposé par PILIQI (voir
            // PropositionsDeTva), fixé par le comptable.
            $table->char('mois_tva', 7);
            $table->boolean('mois_tva_fixe')->default(false);
            // Un fournisseur établi hors de France : autoliquidation.
            $table->boolean('fournisseur_hors_france')->default(false);
            // Pour le carburant seulement : un véhicule utilitaire (100 %
            // de la TVA proposés) ou de tourisme (80 %).
            $table->boolean('vehicule_utilitaire')->nullable();
            $table->text('note')->nullable();
            $table->string('etat', 30);
            $table->string('origine', 20);
            // La somme de ses lignes, en centimes, réécrite avec elles.
            $table->bigInteger('total_ht')->default(0);
            $table->bigInteger('total_tva')->default(0);
            $table->bigInteger('total_ttc')->default(0);
            $table->bigInteger('total_tva_deductible')->default(0);
            // Une ligne au moins dont la TVA ou le TTC s'écarte de plus d'un
            // centime du calcul de PILIQI, confirmée à la saisie.
            $table->boolean('tva_a_verifier')->default(false);
            $table->foreignId('deposee_par')->constrained('users')->restrictOnDelete();
            $table->string('deposee_par_nom');
            $table->string('validee_par', 20)->nullable();
            $table->foreignId('validee_par_compte')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('validee_par_nom')->nullable();
            $table->timestamp('validee_le')->nullable();
            // Le mot du comptable, tant que la charge est renvoyée.
            $table->text('motif_renvoi')->nullable();
            $table->foreignId('renvoyee_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('renvoyee_par_nom')->nullable();
            $table->timestamp('renvoyee_le')->nullable();
            // Le nombre de corrections d'une charge validée (voir
            // GardesDesCharges), et l'instant de la dernière.
            $table->unsignedInteger('corrections')->default(0);
            $table->timestamp('corrigee_le')->nullable();
            $table->timestamps();

            $table->unique(['societe_id', 'id']);
            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
            $table->foreign(['societe_id', 'type_de_la_societe_id'])->references(['societe_id', 'id'])->on('types_de_charge_de_la_societe')->restrictOnDelete();
            $table->index(['societe_id', 'mois_tva']);
            $table->index(['societe_id', 'etat']);
            $table->index(['societe_id', 'magasin_id', 'mois_tva']);
        });

        $types = self::liste(array_map(static fn (TypeDeCharge $type): string => $type->value, TypeDeCharge::cases()));
        $etats = self::liste(array_map(static fn (EtatDeLaCharge $etat): string => $etat->value, EtatDeLaCharge::cases()));
        $origines = self::liste(array_map(static fn (OrigineDeLaCharge $origine): string => $origine->value, OrigineDeLaCharge::cases()));
        $validations = self::liste(array_map(static fn (ValideePar $par): string => $par->value, ValideePar::cases()));
        $validee = "'".EtatDeLaCharge::Validee->value."'";
        $renvoyee = "'".EtatDeLaCharge::Renvoyee->value."'";
        $carburant = "'".TypeDeCharge::Carburant->value."'";

        foreach ([
            'type_connu' => "type IS NULL OR type IN ({$types})",
            'un_seul_type' => '(type IS NULL) <> (type_de_la_societe_id IS NULL)',
            'etat_connu' => "etat IN ({$etats})",
            'origine_connue' => "origine IN ({$origines})",
            'validation_connue' => "validee_par IS NULL OR validee_par IN ({$validations})",
            'validee_avec_sa_validation' => "(etat = {$validee}) = (validee_par IS NOT NULL) AND (validee_par IS NULL) = (validee_le IS NULL) AND (validee_par IS NULL) = (validee_par_compte IS NULL)",
            'renvoyee_avec_son_mot' => "(etat = {$renvoyee}) = (motif_renvoi IS NOT NULL) AND (motif_renvoi IS NULL) = (renvoyee_le IS NULL)",
            'vehicule_du_carburant' => "(type IS NOT DISTINCT FROM {$carburant}) = (vehicule_utilitaire IS NOT NULL)",
            'mois_tva_lisible' => "mois_tva ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'",
            'montants_positifs' => 'total_ht >= 0 AND total_tva >= 0 AND total_ttc >= 0 AND total_tva_deductible >= 0 AND total_tva_deductible <= total_tva',
            'fournisseur_et_sa_cle' => '(fournisseur IS NULL) = (fournisseur_cle IS NULL) AND (numero_piece IS NULL) = (numero_piece_cle IS NULL)',
        ] as $nom => $condition) {
            DB::statement("ALTER TABLE charges ADD CONSTRAINT charges_{$nom} CHECK ({$condition})");
        }

        DB::statement('CREATE UNIQUE INDEX charges_fournisseur_et_numero_uniques ON charges (societe_id, fournisseur_cle, numero_piece_cle) WHERE fournisseur_cle IS NOT NULL AND numero_piece_cle IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX charges_identifiant_et_numero_uniques ON charges (societe_id, fournisseur_identifiant, numero_piece_cle) WHERE fournisseur_identifiant IS NOT NULL AND numero_piece_cle IS NOT NULL');

        GardesDesCharges::poserSurLesCharges();
    }

    public function down(): void
    {
        GardesDesCharges::retirerDesCharges();
        Schema::dropIfExists('charges');
    }

    /**
     * @param  list<string>  $valeurs
     */
    private static function liste(array $valeurs): string
    {
        return implode(', ', array_map(static fn (string $valeur): string => "'{$valeur}'", $valeurs));
    }
};
