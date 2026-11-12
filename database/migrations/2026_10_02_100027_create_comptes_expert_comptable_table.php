<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les comptes de l'expert-comptable d'une société, chacun attaché pour
 * toujours à la déclaration qui l'a fait naître (voir CompteExpertComptable
 * et DeclarerSonExpertComptableAction), dans la société qui déclare : une
 * clé étrangère de users vers liens_cabinet obligerait à modifier users
 * dans une autre migration que la sienne. Chaque ligne garde aussi le lien
 * du mot de passe en cours (l'empreinte SHA-256 de son jeton, jamais le
 * jeton, et son échéance, 7 jours plus tard à la même heure de Paris) et
 * la date du premier mot de passe choisi par la personne, qui ferme la
 * correction de son adresse par le propriétaire. L'empreinte d'un jeton se
 * lit avant qu'aucune société ne soit ouverte, par la page du lien (voir
 * CompteExpertComptable::parSonJeton()).
 *
 * Les clés étrangères composites gardent chaque ligne dans la société de
 * sa déclaration et de son compte ; aucune ligne ne se supprime. La base
 * refuse (voir GardesDesCabinets) : un compte attaché qui n'est pas un
 * expert-comptable de la société, ou à une déclaration qui n'est plus en
 * attente ; un compte qui change de déclaration ; un lien sans échéance ou
 * hors d'une déclaration en attente ; la date du premier mot de passe
 * choisi qui change ; un expert-comptable ouvert dont la déclaration n'est
 * plus en attente, ou sans adresse ; un compte qui prend ou quitte ce
 * rôle ; une déclaration qui prend fin ou se rattache avec un compte
 * encore ouvert. Ces gardes lisent cette table, la dernière créée : elles
 * se posent ici, et se retirent avant elle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes_expert_comptable', function (Blueprint $table) {
            $table->id();
            // La société qui déclare.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Sa déclaration, pour toujours.
            $table->foreignId('lien_id');
            // Le compte d'expert-comptable de la société, attaché pour
            // toujours.
            $table->foreignId('user_id')->unique();
            // SHA-256 du jeton du lien du mot de passe en cours, jamais le
            // jeton ; effacé quand il sert, quand un autre le remplace,
            // quand l'e-mail ne peut pas partir et quand le compte est
            // suspendu ou retiré.
            $table->char('jeton_hache', 64)->nullable()->unique();
            $table->timestamp('jeton_expire_le')->nullable();
            // Le premier mot de passe choisi par la personne, par un lien ou
            // en présence du propriétaire : écrite une fois, jamais
            // effacée.
            $table->timestamp('mot_de_passe_choisi_le')->nullable();
            $table->timestamps();

            $table->index(['societe_id', 'lien_id']);
            $table->foreign(['societe_id', 'lien_id'])->references(['societe_id', 'id'])->on('liens_cabinet')->restrictOnDelete();
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
        });

        DeclencheursDeProtection::sansSuppression('comptes_expert_comptable');
        GardesDesCabinets::poser(GardesDesCabinets::COMPTES_EXPERT_COMPTABLE);
        GardesDesCabinets::poser(GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION);
        GardesDesCabinets::poser(GardesDesCabinets::DECLARATION_SANS_COMPTE_OUVERT);
        GardesDesCabinets::poserLaFonctionDesMagasinsOuverts();
    }

    public function down(): void
    {
        GardesDesCabinets::retirerLaFonctionDesMagasinsOuverts();
        GardesDesCabinets::retirer(GardesDesCabinets::DECLARATION_SANS_COMPTE_OUVERT);
        GardesDesCabinets::retirer(GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION);
        GardesDesCabinets::retirer(GardesDesCabinets::COMPTES_EXPERT_COMPTABLE);
        Schema::dropIfExists('comptes_expert_comptable');
        DeclencheursDeProtection::retirer('comptes_expert_comptable');
    }
};
