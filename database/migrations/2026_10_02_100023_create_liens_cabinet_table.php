<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les liens entre un cabinet d'expertise comptable et ses clients (voir
 * LienCabinet), chacun dans la société du client, qui accorde l'accès :
 * les demandes, du cabinet ou du client, et les liens actifs, puis
 * terminés. Elle porte aussi la déclaration de l'expert-comptable d'une
 * société validée, un lien en attente sans cabinet, qui devient le lien
 * actif à son rattachement à un cabinet vérifié de son SIREN (voir
 * DeclarerSonExpertComptableAction et RattacherUneDeclarationAction). Un
 * client n'a qu'un lien en cours à la fois, actif ou déclaré, et une
 * seule demande en cours par cabinet ; le jeton du lien de l'e-mail d'une
 * demande de rattachement n'est gardé, haché, que tant qu'elle est en
 * cours. La base refuse un lien vers une société qui n'est pas un
 * cabinet, d'un client qui en est un ou vers soi, une transition qui n'est
 * pas permise et un jeton hors d'une demande du cabinet (voir
 * GardesDesCabinets) ; l'historique des liens ne se supprime jamais. Le
 * client ouvre au cabinet tous ses magasins réels, présents et futurs, ou
 * une sélection (voir magasins_cabinet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liens_cabinet', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Le client, qui accorde l'accès.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Le cabinet ; nul pour une déclaration du client tant qu'aucun
            // cabinet de son SIREN ne s'y est rattaché, et pour toujours si
            // elle prend fin sans rattachement.
            $table->foreignId('cabinet_id')->nullable()->constrained('societes')->restrictOnDelete();
            // Le SIREN que le client déclare (origine declaration_du_client
            // seulement), contrôlé au répertoire Sirene ; il ne change
            // jamais.
            $table->char('siren_cabinet', 9)->nullable();
            // La dénomination de ce SIREN lue au répertoire Sirene le jour
            // de la déclaration, nulle quand il la masque : pour les écrans
            // et le nom figé des comptes de l'expert-comptable de la
            // société.
            $table->string('denomination_cabinet')->nullable();
            // OrigineDuLien : invitation, demande_du_cabinet,
            // designation_du_client ou declaration_du_client.
            $table->string('origine', 30);
            // EtatDuLien : une demande, actif, declare (une déclaration du
            // client en attente de son cabinet) ou un état final.
            $table->string('etat', 30);
            // Qui a fait la demande (nul pour un lien né d'une invitation),
            // ou, pour une déclaration, le propriétaire qui l'a faite, et
            // son nom au moment de l'opération (voir FigeLeNomDeSesAuteurs) ;
            // de même pour la réponse et la fin.
            $table->foreignId('demande_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('demande_par_nom')->nullable();
            $table->timestamp('demande_le');
            $table->timestamp('expire_le')->nullable();
            // SHA-256 du jeton du lien de l'e-mail d'une demande de
            // rattachement, effacé dès qu'elle n'est plus en cours.
            $table->char('jeton_hache', 64)->nullable()->unique();
            $table->foreignId('repondu_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('repondu_par_nom')->nullable();
            $table->timestamp('repondu_le')->nullable();
            // Pour une déclaration, la date de son rattachement.
            $table->timestamp('actif_depuis')->nullable();
            // Le client ouvre-t-il à son cabinet tous ses magasins réels,
            // présents et futurs, ou seulement ceux qu'il choisit (voir
            // magasins_cabinet) ? Tous par défaut, à la naissance du lien.
            $table->boolean('tous_les_magasins')->default(true);
            // Nul pour le système ou l'éditeur.
            $table->foreignId('termine_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('termine_par_nom')->nullable();
            $table->timestamp('termine_le')->nullable();
            // MotifDeFinDuLien : par_le_client, par_le_cabinet, remplace,
            // cabinet_plus_verifie, proprietaire_remplace, inscription_close,
            // societe_revoquee.
            $table->string('motif_fin', 30)->nullable();
            $table->timestamps();

            $table->unique(['societe_id', 'id']);
            $table->unique(['cabinet_id', 'id']);
            $table->index(['cabinet_id', 'etat']);
            $table->index(['etat', 'expire_le']);
        });

        // Un client n'a qu'un cabinet lié ou déclaré à la fois, et une
        // seule demande en cours avec un même cabinet ; les déclarations en
        // attente d'un SIREN se retrouvent quand son cabinet est vérifié.
        DB::statement("CREATE UNIQUE INDEX liens_cabinet_un_cabinet_par_client ON liens_cabinet (societe_id) WHERE etat IN ('actif', 'declare')");
        DB::statement("CREATE INDEX liens_cabinet_declarations_par_siren ON liens_cabinet (siren_cabinet) WHERE etat = 'declare'");
        DB::statement("CREATE UNIQUE INDEX liens_cabinet_une_demande_par_paire ON liens_cabinet (societe_id, cabinet_id) WHERE etat IN ('demande_par_le_cabinet', 'demande_par_le_client')");

        DeclencheursDeProtection::sansSuppression('liens_cabinet');
        GardesDesCabinets::poser(GardesDesCabinets::LIENS);
    }

    public function down(): void
    {
        GardesDesCabinets::retirer(GardesDesCabinets::LIENS);
        Schema::dropIfExists('liens_cabinet');
        DeclencheursDeProtection::retirer('liens_cabinet');
    }
};
