### C0 [bloquant] [isolation] [DECISION] Gestion d'équipe : le propriétaire (ou l'administrateur) d'un magasin B prend le contrôle du compte du propriétaire d'un magasin A
app/Domain/Tenancy/Actions/CreerMembreMagasinAction.php:59
Scénario: Bob, propriétaire du magasin B, ouvre Équipe > Nouveau membre et saisit l'email d'Alice (propriétaire du magasin A), un nom quelconque et le mot de passe 'choisi-par-bob'. L'action trouve le compte existant (User::firstWhere('email')) et, comme Alice n'appartient pas à B, écrase son nom ET son mot de passe (forceFill(['name','password'])). L'ancien mot de passe d'Alice ne marche plus ; Bob se connecte avec 'choisi-par-bob' et ouvre le magasin A en tant que propriétaire : lecture et écriture de toutes les données de A, et Alice ne peut plus se connecter. Un administrateur de B (qui a equipe.creer) peut faire la même chose. Les emails étant globalement uniques, il suffit de connaître l'email d'un compte pour le prendre.
Correction: Ne jamais modifier le nom ou le mot de passe d'un compte qui existe déjà. Le magasin doit choisir entre deux options (décision métier). (1) Refuser une adresse déjà utilisée, avec une règle de validation sur le champ email et un message neutre. Il n'y a alors plus de comptes partagés entre magasins. (2) Garder les comptes partagés : rattacher le compte existant sans toucher à ses identifiants, par une invitation que la personne accepte elle-même. Dans les deux cas, réécrire le docblock de CreerMembreMagasinAction et le test qui affirme l'écrasement, puis ajouter un test qui vérifie que le mot de passe d'un compte existant ne change pas. Attention : l'option (2) seule ne suffit pas. Combinée au constat suivant (« Nouveau mot de passe »), elle laisse toujours B rattacher Alice puis changer son mot de passe.

### C1 [bloquant] [isolation] [DECISION] « Nouveau mot de passe » sur un membre partagé : le propriétaire de B ouvre le magasin A avec ce compte
app/Filament/App/Resources/MembreEquipeResource.php:102
Scénario: Un comptable est membre des magasins A et B. Le propriétaire (ou l'administrateur) de B clique sur « Nouveau mot de passe » sur la ligne du comptable dans SA liste d'équipe. ReinitialiserMotDePasseMembreAction (ligne 16) change le mot de passe du compte User global, pas seulement l'accès à B. Le propriétaire de B se connecte alors avec ce compte et ouvre le magasin A ; le vrai comptable perd aussi son accès à A. Seul le rôle Propriétaire du magasin courant est protégé, pas un compte qui est propriétaire ou membre ailleurs.
Correction: Refuser la réinitialisation, et cacher l'action, dès que le compte appartient à un autre magasin que le magasin courant (magasins()->count() > 1). Faire cette vérification dans l'action elle-même, qui doit recevoir le MagasinUser et non un User nu, pas seulement dans visible(). Si le magasin choisit « aucun compte partagé » au constat précédent, ce cas ne peut plus se produire, mais le garde-fou dans l'action doit rester. Sinon, un compte partagé ne se réinitialise que par la personne elle-même (lien envoyé par email). Ajouter un test pour le compte partagé.

### C2 [important] [isolation] Facture et devis brouillons : un client_id d'un autre magasin forgé dans le formulaire est enregistré, puis l'écran de la facture et son émission plantent
app/Domain/Facturation/Actions/ModifierFactureAction.php:41
Scénario: Un vendeur du magasin A ouvre la modification d'un brouillon de facture et envoie via Livewire data.client_id = l'id d'un client du magasin B (les ids se suivent, ils sont faciles à deviner). EditFacture (ligne 54) passe la valeur brute à ModifierFactureAction, qui fait $facture->update(['client_id' => ...]) sans relire le client par une requête limitée au magasin. Résultat : la facture de A pointe vers un client de B ; $facture->client renvoie null (filtré par le scope), la page ViewFacture renvoie une erreur 500 et l'émission plante (« Call to a member function estProfessionnel() on null », Facture.php:232). Même défaut sur EditDevis (ligne 51) avec ModifierDevisAction (ligne 37). Cause : la règle que Filament ajoute à un Select relationship() est Rule::exists(Client::class, 'clients.id'), vérifiée par le query builder sans MagasinScope, donc tout id de la table clients passe. Cette règle laisse aussi deviner quels ids existent dans les autres magasins (id inexistant : erreur de validation ; id d'un autre magasin : accepté).
Correction: Dans EditFacture et EditDevis, faire $client = Client::query()->findOrFail($data['client_id']) (requête limitée au magasin), puis passer $client->getKey(). Mieux : faire en sorte que ModifierFactureAction et ModifierDevisAction reçoivent un Client, comme CreerFactureAction et CreerDevisAction. Ajouter sur le Select client_id une règle exists limitée au magasin : ->rules([Rule::exists('clients', 'id')->where('magasin_id', app(TenantContext::class)->id())->whereNull('deleted_at')]). La même règle vaut pour le Select client_id d'AvoirResource et de PaiementResource. Ajouter un test Edit qui vérifie le refus d'un client d'un autre magasin.

### C3 [important] [isolation] Facture d'achat : un fournisseur d'un autre magasin forgé dans le formulaire est enregistré, puis copié sur les produits du magasin à la validation
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:64
Scénario: Sur la modification d'une facture d'achat du magasin A, data.fournisseur_id est forcé à l'id d'un fournisseur du magasin B. EditFactureAchat (ligne 45) le transmet tel quel et ModifierFactureAchatAction fait $factureAchat->update($attributs). À la validation, IncrementerStockApresValidationFactureAchat (et ModifierFactureAchatAction, ligne 139) appellent $produit->rattacherAuFournisseur($factureAchat->fournisseur_id) : les produits du magasin A pointent alors vers un fournisseur de B. ConstructeurLigneAchat:176 l'utilise aussi pour les nouveaux produits. La règle exists de Filament n'est pas limitée au magasin (même cause que le constat précédent).
Correction: Dans EditFactureAchat, faire Fournisseur::query()->findOrFail($data['fournisseur_id']) avant d'appeler l'action. Mieux : faire en sorte que ModifierFactureAchatAction reçoive un Fournisseur, comme CreerFactureAchatAction. Ajouter sur le Select fournisseur_id la règle exists limitée au magasin et aux fournisseurs non supprimés.

### C4 [important] [isolation] Fiche produit : fournisseur_id d'un autre magasin accepté à la création et à la modification
app/Filament/App/Resources/ProduitResource.php:184
Scénario: Sur Catalogue > Produits > Créer (ou Modifier), data.fournisseur_id est forcé à l'id d'un fournisseur du magasin B. Le Select relationship('fournisseur') ne valide qu'avec Rule::exists non limité au magasin, et fournisseur_id est fillable sur Produit : le produit de A est enregistré avec le fournisseur de B (le filtre « Fournisseur » et la fiche le montrent vide).
Correction: Ajouter sur le Select fournisseur_id ->rules([Rule::exists('fournisseurs', 'id')->where('magasin_id', app(TenantContext::class)->id())->whereNull('deleted_at')]). Pour ne pas oublier un champ, créer une seule méthode partagée qui produit cette règle pour tous les Select relationship() du panneau qui pointent vers un modèle de magasin (client, fournisseur).

### C5 [important] [isolation] Paiements > Créer : tout paiement choisi depuis le navigateur est refusé (« La facture … n'appartient pas au client de ce paiement »)
app/Domain/Facturation/Actions/EnregistrerPaiementAction.php:49
Scénario: Le Select client (Choices.js) envoie la valeur sous forme de chaîne ("5") : Filament transforme les options avec strval(). CreatePaiement (ligne 37) passe $data['client_id'] sans conversion, Paiement::create garde la chaîne "5" (pas de cast), et la comparaison stricte $facture->client_id (int 5) !== $paiement->client_id ("5") est toujours vraie. Le paiement est annulé par le rollback et une notification d'erreur s'affiche pour le bon client. Les tests existants passent parce qu'ils envoient des entiers. Ce défaut sort un peu du domaine isolation : c'est la vérification d'appartenance au client qui casse.
Correction: Dans EnregistrerPaiementAction, résoudre le client par Client::query()->findOrFail($donneesPaiement['client_id']), une requête limitée au magasin. Enregistrer client_id = $client->getKey() et comparer (int) $facture->client_id !== $client->getKey(). Mieux : faire en sorte que l'action reçoive un Client. Ajouter un test du panneau qui envoie client_id et facture_id sous forme de chaînes.

### C6 [important] [isolation] Retirer un membre laisse ses permissions supplémentaires : s'il est ré-ajouté comme simple vendeur, il les retrouve
app/Domain/Tenancy/Actions/RetirerMembreMagasinAction.php:27
Scénario: Le propriétaire accorde à un vendeur la permission supplémentaire facture.view_toutes, puis le retire de l'équipe. RetirerMembreMagasinAction ne retire que le rôle (removeRole) et supprime le rattachement ; la permission directe reste dans model_has_permissions pour ce magasin. Si le propriétaire ré-ajoute plus tard cette personne comme simple vendeur, elle voit de nouveau toutes les factures et tous les paiements du magasin, alors que la page Équipe n'affiche aucune permission supplémentaire accordée par cette nouvelle création.
Correction: Dans RetirerMembreMagasinAction, avec le TenantContext déjà positionné sur le magasin, appeler $membre->utilisateur->syncPermissions([]) avant $membre->delete(). Avec les teams Spatie, la relation permissions() filtre sur magasin_id, donc seules les permissions directes de ce magasin sont retirées. Ajouter un test qui retire un membre puis le ré-ajoute.

### C7 [mineur] [isolation] Fournisseur : aucune règle unique pour le SIRET (limitée au magasin), donc un doublon provoque une erreur SQL brute
app/Filament/App/Resources/FournisseurResource.php:33
Scénario: La table fournisseurs a un index unique (magasin_id, siret), corbeille comprise (SoftDeletes). Le champ SIRET du formulaire n'a pas de règle unique. Créer (ou modifier) un fournisseur avec le SIRET d'un fournisseur du même magasin, même supprimé, déclenche une UniqueConstraintViolationException au lieu d'un message de validation : page d'erreur 500.
Correction: ->unique(table: Fournisseur::class, ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('magasin_id', app(TenantContext::class)->id())), sans withoutTrashed() pour suivre l'index qui inclut la corbeille, avec un message clair qui signale aussi le cas d'un fournisseur supprimé (sur le modèle de ProduitResource::champsIdentification). Ajouter un test pour la création et la modification.

### C8 [mineur] [isolation] [DECISION] Tableau de bord : le widget de chiffre d'affaires montre le CA de tout le magasin à un vendeur sans facture.view_toutes
app/Filament/App/Widgets/FacturesApercu.php:20
Scénario: Un vendeur sans facture.view_toutes ouvre le tableau de bord. FacturesApercu n'a pas de canView() et additionne toutes les factures émises du magasin, y compris celles des autres vendeurs et du propriétaire. Cela contredit la « confidentialité par vendeur (décision produit explicite) » de FacturePolicy et le commentaire de FactureResource::getEloquentQuery (« un montant visible dans un tableau est déjà une fuite »). Défaut interne au magasin, signalé en passant.
Correction: Le magasin doit choisir : (a) réserver le widget à facture.view_toutes via canView(), ou (b) limiter la somme aux factures cree_par = auth()->id() pour un utilisateur sans cette permission (et adapter le libellé, par exemple « Mes ventes »). Ajouter un test vendeur.

### C9 [mineur] [isolation] [DECISION] Code mort dans le domaine Tenancy et autour : table journaux_audit, users.current_magasin_id, magasin_user.invited_by, actions de mandat PDP, Fournisseur::upsertDepuisFactureEntrante
database/migrations/2026_10_02_100005_create_journaux_audit_table.php:11
Scénario: (1) La table journaux_audit (avec magasin_id) n'a ni modèle ni écriture ni lecture dans app/ ni dans les tests. (2) users.current_magasin_id n'est écrit qu'une fois, dans CreerMagasinAction, et n'est jamais lu ni mis à jour au changement de magasin (User n'implémente pas HasDefaultTenant ; magasinActuel() n'est utilisé que par les tests) ; le commentaire de la migration magasins (« un utilisateur retient le magasin sur lequel il travaille ») est donc faux. (3) magasin_user.invited_by n'est jamais rempli. (4) CreerMandatPdpAction et RevokerMandatPdpAction ne sont appelées que par les tests : aucun écran ne crée de mandat, donc DeclencherTransmissionPdpApres* ne transmet jamais rien en production. (5) Fournisseur::upsertDepuisFactureEntrante n'est appelée nulle part, et l'import TenantContext de Fournisseur.php ne sert pas.
Correction: Supprimer la migration journaux_audit (l'étape 3 la recréera avec son modèle). Supprimer la colonne current_magasin_id, la relation magasinActuel(), l'écriture dans CreerMagasinAction, les assertions de test qui en dépendent et le commentaire de la migration magasins. Supprimer la colonne invited_by et la retirer des deux withPivot. Supprimer upsertDepuisFactureEntrante, l'import TenantContext de Fournisseur.php et la mention dans la migration fournisseurs. Pour le mandat PDP, au choix : ajouter l'écran de consentement dans Paramètres fiscaux, ou retirer ces actions jusqu'à l'étape 11 (décision de planning de l'éditeur).

### C10 [mineur] [isolation] Commentaires faux sur l'isolation : TenantContext se dit « singleton », TenantIsolationTest prétend couvrir chaque modèle de magasin
app/Domain/Tenancy/Services/TenantContext.php:14
Scénario: Le docblock de TenantContext dit « Ce service est bindé en singleton (voir TenancyServiceProvider) », alors que TenancyServiceProvider utilise scoped() et explique justement qu'un singleton ferait fuiter le magasin d'un job à l'autre. Un développeur qui suit ce commentaire peut réintroduire la fuite. Par ailleurs, le docblock de tests/Feature/Tenancy/TenantIsolationTest affirme qu'« ajouter un nouveau modèle BelongsToMagasin doit simplement ajouter une entrée à modelesTenant() », mais modelesTenant() ne liste que Client, Fournisseur et Produit, sur les 21 modèles qui utilisent BelongsToMagasin (Facture, Devis, Avoir, Paiement, FactureAchat, Service, MouvementStock, lignes, allocations, clôtures, TransmissionPdp…). Aucun test ne couvre non plus un id forgé d'un autre magasin dans un formulaire.
Correction: Corriger le docblock de TenantContext : « bindé en scoped() : une instance par requête ou par job, réinitialisée entre deux jobs d'un même worker ». Compléter modelesTenant() avec tous les modèles BelongsToMagasin qui ont une factory, ou corriger la promesse du docblock. Ajouter par formulaire Edit ou Create un test qui vérifie le refus d'un client ou fournisseur d'un autre magasin.

### C11 [bloquant] [ventes] Modifier une facture ou un devis brouillon accepte le client d'un AUTRE magasin (fuite entre magasins)
app/Domain/Facturation/Actions/ModifierFactureAction.php:41
Scénario: Magasin B a un client id=X. Le propriétaire du magasin A ouvre l'édition d'une facture brouillon A, envoie data.client_id = X (requête Livewire modifiée), enregistre -> aucune erreur, factures.client_id = X (client du magasin B). Ensuite, l'émission plante ($facture->client vaut null à cause du MagasinScope). Même chose sur EditDevis -> devis.client_id = X.
Correction: Dans ModifierFactureAction et ModifierDevisAction, recevoir un Client, ou relire client_id par Client::query()->findOrFail() avant update, comme le font les actions Creer*. Ajouter sur chaque Select client_id (FactureResource, DevisResource, AvoirResource, PaiementResource) ->exists(modifyRuleUsing: fn (Exists $r) => $r->where('magasin_id', app(TenantContext::class)->id())). Le plus simple est de le factoriser dans un helper partagé. Ajouter un test de régression d'isolation sur EditFacture et EditDevis.

### C12 [bloquant] [ventes] L'identité légale du magasin (adresse, SIREN/SIRET, n° TVA, raison sociale) ne peut être saisie nulle part : elle manque sur tous les PDF de facture et d'avoir
resources/views/factures/pdf.blade.php:42
Scénario: Un magasin s'inscrit (RegisterMagasin ne demande que le nom), règle ses Paramètres fiscaux, émet une facture à un professionnel -> le PDF n'affiche que le nom commercial : pas d'adresse, pas de SIRET, pas de n° TVA intracommunautaire, pas de raison sociale. Ces mentions sont obligatoires (art. 242 nonies A de l'annexe II au CGI, L441-9 du Code de commerce).
Correction: Ajouter une page Paramètres > Identité du magasin, réservée au propriétaire (MANDAT_PDP_GERER), ou un tenantProfile Filament : raison sociale, forme juridique, capital, SIREN/SIRET, n° TVA, adresse complète avec pays. Valider le format SIREN/SIRET et rendre unique le SIRET déjà indexé. Imprimer raison sociale, SIREN, adresse complète et n° TVA sur les PDF de facture et d'avoir. Le magasin doit décider s'il faut bloquer l'émission tant que l'identité est incomplète (au moins pour les factures à des professionnels).

### C13 [important] [ventes] Le type et les coordonnées du client sont lus au moment de l'affichage : un ticket déjà émis devient une « Facture » et sort de la clôture et de l'e-reporting
app/Domain/Facturation/Models/Facture.php:230
Scénario: Ticket TK-2026-000001 (120 € TTC) émis à 10 h pour un client particulier. À 15 h, la fiche client passe en « Professionnel ». Le PDF du ticket affiche alors le titre « Facture » et « Facturé à », et le fichier s'appelle facture-TK-2026-000001.pdf. La clôture de caisse du soir ne compte plus cette vente (total 0,00 €, cloture_caisse_id NULL), et elle n'a jamais été transmise au PDP : la vente disparaît des déclarations.
Correction: À l'émission, enregistrer sur la facture, et de même sur l'avoir, le circuit réglementaire (ticket ou facture, cohérent avec la série) et une copie de l'identité et de l'adresse du client (nom, SIRET, n° TVA, adresse, pays) et du vendeur. Ajouter ces colonnes dans les migrations de création de factures et d'avoirs. Faire lire ces valeurs enregistrées à relevantDeLeInvoicing(), aux scopes enAttenteDeCloture, aux PDF et à nomFichier(). Corriger le docblock de FacturePdfGenerator.

### C14 [important] [ventes] [DECISION] Un utilisateur en lecture seule (Observateur) peut supprimer en masse des devis, y compris un devis déjà converti en facture
app/Filament/App/Resources/DevisResource.php:227
Scénario: Un membre « Observateur » (lecture seule : devis.view, ni devis.create ni devis.update) ouvre la liste des devis, coche un devis accepté et déjà converti en facture, puis lance « Supprimer » -> le devis est supprimé (suppression douce) et la facture perd son devis d'origine (devisOrigine devient null).
Correction: Ajouter à DevisPolicy : delete(), qui exige l'appartenance au magasin, devis.update et estModifiable() ; deleteAny(), qui exige devis.update ; forceDelete() et restore() qui renvoient false. Dans DevisResource, filtrer le DeleteBulkAction ligne par ligne avec can('delete', $record), comme dans FactureResource. Ajouter un test pour l'Observateur et pour un devis converti. Le magasin doit dire s'il veut qu'on puisse supprimer un devis déjà numéroté (envoyé, accepté ou refusé).

### C15 [important] [ventes] [DECISION] Le taux de TVA d'une ligne de produit se modifie librement à la vente et remplace celui du produit
app/Filament/Concerns/SaisitLesPrixDeLigne.php:63
Scénario: Produit à 20 % vendu 120 € TTC. Sur Facture, Devis ou Caisse, le vendeur tape 0 dans « TVA % » -> la ligne est enregistrée à 0 % : 120,00 € HT, 0,00 € de TVA, au lieu de 100,00 € HT + 20,00 € de TVA. La TVA collectée est fausse sur la facture, la clôture et l'e-reporting.
Correction: Sur une ligne de produit, afficher taux_tva en lecture seule (disabled + dehydrated(false)). Dans ConstructeurLigneDocument, prendre toujours $produit->taux_tva quand produit_id est fourni. Garder le taux saisissable seulement pour une ligne de service, ou l'ajouter à la fiche Service. Le magasin doit décider s'il faut un cas d'exonération explicite (export, autoliquidation intra-UE) avec la mention légale correspondante, plutôt qu'un taux libre.

### C16 [important] [ventes] Confidentialité par vendeur contournée : factures, restants dus et chiffre d'affaires des autres vendeurs visibles et modifiables
app/Filament/App/Resources/PaiementResource.php:104
Scénario: Vendeur B (rôle Vendeur par défaut, sans facture.view_toutes) n'a pas le droit de voir la facture du vendeur A. Pourtant, dans Paiements > Créer, en choisissant le client, il voit « FAC-… — restant : 120,00 € » de la facture de A, peut l'encaisser (elle passe « Payée »), et le tableau de bord lui affiche le CA de tout le magasin (widget FacturesApercu).
Correction: Sans facture.view_toutes : limiter à cree_par = auth()->id() les factures proposées dans PaiementResource et les lignes proposées dans AvoirResource. Faire vérifier can('view', $facture) ou une policy dédiée « encaisser » par la page ou par EnregistrerPaiementAction. Réserver FacturesApercu à facture.view_toutes (canView), ou le calculer sur les seules factures de l'utilisateur. Le magasin doit dire si un vendeur peut encaisser au comptoir la facture d'un collègue. Si oui, il faut une permission dédiée qui n'affiche que le montant restant.

### C17 [important] [ventes] [DECISION] Un paiement supérieur à ses allocations est accepté : le trop-perçu disparaît, et les commentaires annoncent une imputation ultérieure qui n'existe pas
app/Domain/Facturation/Actions/EnregistrerPaiementAction.php:19
Scénario: Paiements > Créer : montant reçu 200 €, une seule allocation de 120 € sur une facture de 120 € -> enregistré sans avertissement, la facture passe « Payée », et 80 € restent non alloués. Ils ne sont affichés nulle part, ne peuvent jamais être imputés, et ne deviennent pas non plus un crédit.
Correction: Option la plus simple, cohérente avec « un trop-perçu ne devient pas un crédit » : exiger montant = somme des allocations. Valider dans le formulaire (le montant est calculé ou contrôlé à partir des allocations) et lever une LogicException dans l'action. Supprimer ensuite Paiement::montantAlloue() et montantNonAlloue(), et réécrire les commentaires de l'action et de $fillable. L'autre option, une imputation ultérieure réelle avec affichage du non-alloué, demande une décision du magasin.

### C18 [important] [ventes] [DECISION] Mentions obligatoires d'une facture entre professionnels absentes du PDF (pénalités de retard, indemnité de 40 €, escompte)
resources/views/factures/pdf.blade.php:208
Scénario: Facture émise à un client professionnel -> le PDF ne mentionne ni le taux des pénalités de retard, ni l'indemnité forfaitaire de 40 € pour frais de recouvrement, ni les conditions d'escompte (ou « pas d'escompte »), toutes exigées par l'art. L441-9 du Code de commerce. Le n° de TVA du client, enregistré sur sa fiche, n'est pas non plus imprimé (obligatoire pour une livraison intracommunautaire), ni son complément d'adresse ni son pays.
Correction: Ajouter aux paramètres du magasin un taux de pénalités de retard et une mention d'escompte (par défaut « Pas d'escompte pour paiement anticipé »). Pour un client professionnel, imprimer ces mentions et l'indemnité forfaitaire de 40 € sous les totaux. Imprimer aussi le n° TVA, le complément d'adresse et le pays du client. Les valeurs (taux de pénalité, escompte) relèvent d'une décision du magasin.

### C19 [important] [ventes] [DECISION] Magasin en franchise en base de TVA : TVA quand même facturée et mention « TVA non applicable, art. 293 B du CGI » absente
resources/views/factures/pdf.blade.php:216
Scénario: Un magasin choisit « Franchise en base de TVA » dans Paramètres fiscaux. Ses ventes restent calculées à 20 % (taux du produit, ou 20 par défaut sur une ligne) : la facture fait apparaître de la TVA qu'il n'a pas le droit de facturer, et ne porte pas la mention obligatoire.
Correction: Quand regime_tva = FranchiseEnBase : imprimer « TVA non applicable, art. 293 B du CGI » sur les factures et les avoirs, et forcer le taux à 0 sur les lignes de vente (dans ConstructeurLigneDocument, ou en refusant un autre taux). Le magasin doit trancher l'effet sur le prix TTC des produits déjà réglés à 20 % : garder le TTC ou garder le HT.

### C20 [important] [ventes] [DECISION] Facture issue d'un devis créée sans date d'échéance ; délai de paiement du client jamais utilisé
app/Domain/Facturation/Actions/CreerFactureDepuisDevisAction.php:34
Scénario: Devis accepté puis converti, pour un client pro avec delai_paiement_jours = 30 -> la facture est émise avec date_echeance NULL : le PDF n'affiche aucune échéance (mention obligatoire entre professionnels), et MarquerFacturesEnRetard ne la passera jamais « en retard ».
Correction: Calculer l'échéance à la conversion, et la valeur par défaut d'une facture créée, à partir du délai du client, sinon d'un délai par défaut du magasin. Exiger une échéance à l'émission d'une facture à un professionnel. Le délai par défaut et sa règle de calcul relèvent d'une décision du magasin.

### C21 [mineur] [ventes] [DECISION] Une facture brouillon émise après son échéance passe « en retard » dès son émission
app/Domain/Facturation/Actions/EmettreFactureAction.php:50
Scénario: Brouillon créé le jour J avec l'échéance par défaut (aujourd'hui), émis à J+5 -> la facture porte une échéance antérieure à sa date d'émission, et la commande planifiée la passe « En retard » le jour même.
Correction: Dans EmettreFactureAction, refuser l'émission si date_echeance < aujourd'hui (LogicException affichée en notification), ou la recalculer (date d'émission + délai). Le magasin doit choisir entre refus et recalcul.

### C22 [mineur] [ventes] Le retour (avoir) d'un article vendu sans suivi de stock gonfle le stock
app/Domain/Facturation/Listeners/RestaurerStockApresEmissionAvoir.php:26
Scénario: 3 articles vendus alors que suivi_stock = false (aucun mouvement de stock) ; le suivi est ensuite activé (stock 0) ; un avoir est émis sur ces 3 articles -> le stock passe à 3 alors qu'aucun n'en était sorti. Dans l'autre sens (suivi désactivé après la vente), le retour ne remet rien en stock.
Correction: Dans RestaurerStockApresEmissionAvoir, ne remettre en stock que min(quantité de l'avoir, quantité sortie par le mouvement Vente de cette facture pour ce produit, moins les retours déjà enregistrés pour cette ligne). Ne rien faire s'il n'y a pas eu de mouvement de sortie. Réécrire le docblock pour décrire l'avoir partiel.

### C23 [mineur] [ventes] [DECISION] Le crédit d'un avoir ne s'applique jamais à une facture impayée déjà émise
app/Domain/Facturation/Actions/AppliquerAvoirsDisponiblesAction.php:30
Scénario: Client pro : facture F1 émise et impayée ; plus tard, un avoir sur une facture F0 déjà payée laisse un crédit. F1 reste due en entier (et peut passer « en retard ») alors que le client a un crédit, et aucun écran ne permet d'imputer ce crédit sur F1 : il attend la PROCHAINE facture.
Correction: À l'émission de l'avoir, après ImputerAvoirSurSesFacturesAction, appliquer l'excédent aux factures du client qui attendent un règlement, de la plus ancienne à la plus récente (sauf pour le client comptoir). Autre solution : une action « Imputer le crédit » sur la facture. Le magasin doit choisir entre application automatique et action manuelle.

### C24 [mineur] [ventes] [DECISION] Le widget « chiffre d'affaires » ne déduit pas les avoirs
app/Filament/App/Widgets/FacturesApercu.php:34
Scénario: Facture de 120 € émise puis entièrement créditée par un avoir le même mois -> le tableau de bord affiche toujours 120 € de CA pour la semaine, le mois et l'année.
Correction: Déduire les total_ttc des avoirs émis sur la période (avec le même CalculateurLigne::deduire que la clôture), ou renommer explicitement la tuile « montant facturé brut, avant avoirs ». Le magasin doit aussi dire s'il veut un chiffre d'affaires HT ou TTC.

### C25 [mineur] [ventes] [DECISION] Devis : le statut « Expiré » n'est jamais atteint, la date de validité n'est jamais vérifiée, et un devis envoyé ou accepté se modifie sans laisser de trace
app/Domain/Facturation/Enums/StatutDevis.php:14
Scénario: Devis valide jusqu'au J-10, envoyé puis accepté aujourd'hui, et converti -> rien ne l'empêche. Un devis numéroté, envoyé et même accepté peut aussi voir ses lignes et ses prix modifiés tout en restant « Accepté », puis être converti aux nouveaux prix.
Correction: Ajouter une commande planifiée, filtrée par magasin via TenantContext::runAs, qui passe en Expire les devis Envoye dont date_validite < aujourd'hui. Faire refuser par AccepterDevisAction un devis dont la validité est dépassée. Sinon, supprimer le statut Expire. Le magasin doit dire si modifier un devis envoyé ou accepté doit le repasser en brouillon ou créer une nouvelle version.

### C26 [mineur] [ventes] Code mort : statuts et méthodes jamais utilisés
app/Domain/Facturation/Enums/StatutFacture.php:16
Scénario: StatutFacture::Envoyee n'est atteint par aucune transition (seulement listé dans des filtres). StatutAvoir::Annulee n'est jamais affecté. StatutFacture::estDefinitive() n'est appelé que par un test unitaire. Facture::devisOrigine() n'est utilisée nulle part.
Correction: Supprimer StatutAvoir::Annulee (et sa transition), StatutFacture::estDefinitive() avec son test, et Facture::devisOrigine(). Une autre solution pour devisOrigine est de l'utiliser pour afficher un lien vers le devis d'origine sur la fiche facture. Pour StatutFacture::Envoyee : le supprimer maintenant et le réintroduire avec l'envoi par e-mail (étape 4), ou le brancher à ce moment-là.

### C27 [mineur] [ventes] Commentaires devenus faux ou racontant l'historique du code (interdit par les règles du projet)
app/Filament/App/Resources/FactureResource.php:112
Scénario: Un développeur lit les commentaires pour comprendre les règles et en tire des conclusions fausses.
Correction: Réécrire ces commentaires pour ne décrire que le comportement actuel. Dans FactureResource et DevisResource : « lignes modifiables tant que le document n'est pas émis ou converti ». Dans CreerFactureDepuisDevisAction : « copie les montants enregistrés sur les lignes du devis au moment de la conversion ». Supprimer les récits historiques. Dans la migration, lister les vraies valeurs de TypeDocument (devis, facture, ticket_caisse, avoir...).

### C28 [bloquant] [caisse] Les clôtures d'avant le réglage du régime de TVA ne sont jamais soumises à l'e-reporting
app/Console/Commands/SoumettreEReportingPeriodique.php:45
Scénario: Un magasin s'inscrit : regime_tva vaut NULL, l'inscription ne le demande pas. Il vend le 05/09 et la tâche de minuit clôture ce jour (Z, 1 vente). Le 05/10, le propriétaire règle le régime « réel normal mensuel ». La commande tourne les 06/10, 12/10, 22/10 et 02/11. Résultat : la clôture du 05/09 garde soumission_ereporting_id = NULL pour toujours. Ses ventes ne sont jamais déclarées.
Correction: Dans GenererSoumissionEReportingAction, retirer la borne basse : prendre toutes les clôtures du magasin avec soumission_ereporting_id NULL et date_cloture <= periode->fin. La soumission couvre ainsi les clôtures en retard. Autre solution : générer une soumission pour chaque période manquante, depuis la plus ancienne clôture orpheline. Dans les deux cas, ajouter un test de non-régression. Rendre aussi regime_tva obligatoire à l'inscription (RegisterMagasin), ou bloquer la Caisse tant que le régime n'est pas renseigné.

### C29 [important] [caisse] Un jour manqué par la clôture automatique n'est plus jamais clôturé
app/Console/Commands/ClotureAutomatiqueCaisse.php:29
Scénario: Vente à un particulier le 05/10. La tâche de minuit du 06/10 ne tourne pas : serveur arrêté, ou exception non LogicException sur un magasin précédent, qui interrompt la boucle each() pour tous les magasins suivants. Les 07/10 et 08/10 à 00:00, la tâche clôture seulement le 06 puis le 07. La vente du 05 garde cloture_caisse_id NULL et n'apparaît dans aucune clôture ni aucun e-reporting.
Correction: Pour chaque magasin, clôturer chaque jour non clôturé jusqu'à la veille. Partir du plus ancien date_emission d'une facture ou d'un avoir en attente (cloture_caisse_id NULL, client particulier), ou de la dernière clôture. Isoler chaque magasin dans un try/catch Throwable journalisé avec report(), pour qu'un magasin en panne ne bloque pas les autres. Lever une exception dédiée, par exemple JourneeDejaClotureeException, dans CloturerCaisseAction et n'attraper qu'elle.

### C30 [important] [caisse] Le circuit fiscal dépend du type de client au moment de la clôture : une vente peut disparaître de toute déclaration
app/Domain/Facturation/Models/Facture.php:113
Scénario: Le 07/10, vente à un client particulier, numérotée TK-2026-... (série ticket, donc pas d'e-invoicing). Le même jour, le vendeur passe ce client en « Professionnel ». À 00:00, la clôture du 07 donne nombre_ventes = 0. La vente n'est ni dans l'e-reporting ni transmise au PDP. Dans le sens inverse (pro devenu particulier), la facture est e-invoicée à l'émission puis comptée aussi dans la clôture : double déclaration. Même problème pour les avoirs (Avoir.php ligne 77).
Correction: Figer le circuit à l'émission, par une colonne dans les migrations de création de factures et d'avoirs, par exemple type_client ou circuit_fiscal, posée par EmettreFactureAction et EmettreAvoirAction. Filtrer ensuite les scopes enAttenteDeCloture, le listener PDP et le choix de la série sur cette valeur, et non sur le client.

### C31 [important] [caisse] [DECISION] Clôture manuelle de la journée inatteignable : seule la veille est proposée, et la tâche de minuit l'a déjà clôturée
app/Filament/App/Resources/ClotureCaisseResource/Pages/ListClotureCaisses.php:36
Scénario: Le 07/10 à 19:00, le magasin ferme. Le bouton propose « Clôturer la journée du 06/10 », jamais celle du 07. À 00:00, la tâche automatique clôture le 07. Le 08 au matin, le bouton est masqué parce que la veille est déjà clôturée. En fonctionnement normal, le propriétaire ne peut donc jamais clôturer lui-même. Les widgets VentesEnAttenteApercu et CaissesPersonnellesOuvertesApercu portent toujours sur une veille déjà clôturée, et le blocage « caisses personnelles non fermées » ne sert à rien.
Correction: Décision métier à faire trancher par le magasin. Option 1 : permettre de clôturer la journée en cours. Il faut alors définir le sort des ventes émises ensuite le même jour, par exemple les compter sur le jour suivant ou interdire la vente après la clôture. Option 2 : clôturer tout jour non clôturé antérieur. À coordonner avec le chantier Reco 8.

### C32 [important] [caisse] Transmission PDP : un échec transitoire marque la facture « Rejetée » et les nouvelles tentatives ne renvoient jamais rien
app/Domain/Facturation/Jobs/SubmitFactureToPdpJob.php:54
Scénario: Le PDP renvoie un timeout à la première tentative. Le job crée la TransmissionPdp, la passe à Rejetee (libellé « format invalide ») et relance l'exception. À la tentative suivante ($tries = 4, $backoff), la garde « une transmission outbound existe déjà » fait return : le gateway n'est jamais rappelé et la facture reste rejetée sans être transmise. SubmitSoumissionEReportingToPdpJob (ligne 46) a exactement le même code, donc le même défaut pour l'e-reporting.
Correction: La garde d'idempotence ne doit sortir que si une transmission non rejetée existe déjà. Sur une erreur réseau, ne pas passer la transmission à Rejetee : la laisser Soumise, ou la supprimer ou la réutiliser à la tentative suivante, et rappeler la passerelle. Réserver Rejetee au rejet renvoyé par le PDP (ResultatSoumissionPdp) ou à une méthode failed() après la dernière tentative. Appliquer la même correction aux deux jobs.

### C33 [important] [caisse] Mandat PDP : aucun écran pour le créer, et la preuve de consentement est annulée si le PDP échoue
app/Domain/Tenancy/Actions/CreerMandatPdpAction.php:26
Scénario: 1) CreerMandatPdpAction et RevokerMandatPdpAction ne sont appelées nulle part dans app/, resources/ ni routes/, uniquement dans un test. La permission mandat_pdp.gerer n'ouvre que la page « Paramètres fiscaux ». Aucun magasin ne peut donc donner son mandat, et aucune facture ni soumission n'est jamais transmise. 2) Si gateway->creerMandat() lève une exception, le DB::transaction englobant annule l'enregistrement du consentement. Le docblock affirme pourtant qu'il « doit survivre même si l'appel réseau échoue ». 3) updateOrCreate sur magasin_id (unique) écrase la preuve du mandat précédent lorsqu'on en redonne un.
Correction: Enregistrer le consentement dans sa propre transaction, committée avant l'appel à la passerelle, puis mettre à jour le statut dans une seconde écriture. Garder l'historique des consentements : une ligne par consentement, en retirant l'unique sur magasin_id dans la migration de création, ou une table d'historique. Ajouter l'écran de consentement et de révocation réservé à mandat_pdp.gerer, en le concevant directement pour le flux OAuth SUPER PDP de l'étape 11.

### C34 [important] [caisse] Les avoirs émis à un client professionnel ne sont jamais transmis au PDP
app/Providers/EventServiceProvider.php:38
Scénario: Mandat actif, facture à un professionnel émise : SubmitFactureToPdpJob est bien poussé. Un avoir est ensuite émis sur cette facture : aucun job n'est poussé et aucune TransmissionPdp n'est créée. La facture rectificative n'entre jamais dans le circuit e-invoicing. Elle n'est pas non plus dans la clôture B2C, qui exclut les professionnels.
Correction: Ajouter soumettreAvoir(Avoir) à PdpGatewayInterface et à FakePdpGateway. Ajouter un listener DeclencherTransmissionPdpApresEmissionAvoir (client pro, figé à l'émission, et mandat actif) et un job SubmitAvoirToPdpJob calqué sur celui de la facture, en y intégrant déjà la correction des nouvelles tentatives.

### C35 [important] [caisse] La Caisse émet et encaisse sans les permissions facture.emettre ni paiement.creer
app/Filament/App/Pages/Caisse.php:73
Scénario: Un membre Observateur reçoit la seule permission supplémentaire facture.create. Il n'a ni facture.emettre ni paiement.creer, ce que le test vérifie. Il ouvre la Caisse et encaisse : la facture est numérotée, émise et payée. Il obtient ainsi par la Caisse ce que FacturePolicy::emettre et PaiementPolicy lui refusent ailleurs. Le docblock dit pourtant que la caisse n'est « qu'une façon plus rapide de faire ce qu'un vendeur peut déjà faire ».
Correction: Faire exiger facture.create, facture.emettre et paiement.creer par Caisse::canAccess(). Revérifier ces permissions dans encaisser(), car une méthode Livewire peut être appelée directement.

### C36 [important] [caisse] Le widget de chiffre d'affaires du tableau de bord montre à chaque vendeur le CA de toute l'équipe
app/Filament/App/Widgets/FacturesApercu.php:34
Scénario: Le propriétaire émet une facture de 1 200,00 € TTC. Un vendeur sans facture.view_toutes ouvre le tableau de bord et voit « 1 200,00 € TTC, 1 facture émise ». Cela contredit la confidentialité par vendeur (FacturePolicy, « décision produit explicite »). Le widget n'a pas de canView() : tout membre le voit, même sans facture.view.
Correction: Sans facture.view_toutes, filtrer la requête sur cree_par = auth()->id() et l'indiquer dans le libellé (« Mes ventes »). Masquer le widget via canView() quand l'utilisateur n'a pas facture.view.

### C37 [important] [caisse] Client comptoir reconnu par son nom, que l'on peut librement modifier : le crédit d'un avoir anonyme passe sur la vente suivante
app/Domain/Contacts/Models/Client.php:58
Scénario: Une vente comptoir de 30 € fait l'objet d'un avoir de 30 €. Un vendeur (client.update) renomme ensuite le client comptoir en « Vente comptoir ». La vente comptoir suivante, à une autre personne, est réglée intégralement par ce crédit de 30 € (montantCrediteParAvoir 30, montantPaye 0). C'est exactement ce que AppliquerAvoirsDisponiblesAction dit interdire. Autres effets : la Caisse ne présélectionne plus aucun client, on peut créer un deuxième « Client comptoir » depuis la Caisse (le crédit d'un vrai client disparaît alors), et l'on peut passer le comptoir en Professionnel.
Correction: Ajouter une colonne booléenne est_comptoir dans la migration de création des clients, posée par CreerMagasinAction, avec une unicité partielle par magasin. estComptoir() et la présélection de la Caisse doivent s'appuyer sur cette colonne. Dans ClientPolicy et ClientResource, interdire de renommer la fiche comptoir, de changer son type ou de la supprimer.

### C38 [important] [caisse] La Caisse accepte un PU TTC négatif et émet un ticket de montant négatif
app/Filament/Concerns/SaisitLesPrixDeLigne.php:40
Scénario: Sur la Caisse, une ligne produit à PU TTC -50, puis « Encaisser » : aucune erreur de formulaire. Un ticket TK-... de -50,00 € TTC est émis au statut « Émise ». Le « Total à payer » affiche 0,00 € : creditApplique fait min(0, -50). Aucun paiement n'est créé. Une vente négative non soldée entre ainsi dans la clôture et l'e-reporting comme une vente.
Correction: Ajouter ->minValue(0) sur prix_unitaire_ttc. Côté domaine, lever une InvalidArgumentException dans ConstructeurLigneDocument ou ResolveurPrixLigne si le PU est négatif, pour que l'API et les actions soient protégées aussi.

### C39 [important] [caisse] [DECISION] Le relevé « Fermer ma caisse » additionne le TTC facturé, pas ce qui a été encaissé
app/Domain/Facturation/Actions/FermerSaCaisseAction.php:60
Scénario: Un client a 30 € de crédit d'avoir. Vente caisse de 60 € : 30 € de crédit et 30 € en espèces. Le relevé du vendeur affiche total_ttc 60,00 €, alors que le tiroir ne contient que 30 €. Une facture B2B impayée du vendeur est aussi comptée comme encaissée. Le relevé ne ventile pas non plus par mode de paiement. Caisse::resumeEncaissement affirme pourtant que l'encaissé réel doit être distingué « sous peine de fausser la caisse du vendeur en fin de journée ».
Correction: Décision métier sur le contenu du relevé. Proposition : garder le TTC vendu et y ajouter les encaissements réels du vendeur, c'est-à-dire ses paiements par mode de paiement, puis le crédit d'avoir utilisé et le reste dû, à afficher séparément.

### C40 [mineur] [caisse] [DECISION] Taux de TVA modifiable sur une ligne produit de la Caisse, qui remplace celui du produit
app/Domain/Facturation/Services/ConstructeurLigneDocument.php:60
Scénario: Produit à 20 % de TVA. Sur la Caisse, le vendeur tape 5.5 dans « TVA % » de la ligne produit puis encaisse. La ligne est enregistrée à 5,50 %. Les écrans de vente devraient ne saisir que le PU TTC.
Correction: Désactiver taux_tva quand produit_id est renseigné et le laisser saisissable pour un service. Dans ConstructeurLigneDocument, utiliser $produit->taux_tva pour une ligne produit et ignorer la valeur postée. Ne prévoir de taux modifiable que si le magasin en exprime le besoin.

### C41 [mineur] [caisse] Une vente caisse à 0 € reste « Émise », non soldée
app/Domain/Facturation/Actions/EnregistrerVenteCaisseAction.php:46
Scénario: Vente caisse au client comptoir avec une remise de 100 % : total 0,00 €. Aucun paiement n'est créé et constaterUnReglement() n'est pas appelé, donc la facture reste au statut « Émise ». Cela contredit « une vente comptoir est toujours payée intégralement et immédiatement ». Elle apparaît parmi les factures attendant un règlement (attendUnReglement).
Correction: Dans EnregistrerVenteCaisseAction, quand montantRestant <= 0 et que la facture est encore Emise, appeler $facture->constaterUnReglement(), qui la passe à Payee. Vérifier que la transition Emise vers Payee est autorisée.

### C42 [mineur] [caisse] Relevé personnel : « Depuis » (periode_debut) est faux quand il compte des ventes des jours précédents
app/Domain/Facturation/Actions/FermerSaCaisseAction.php:37
Scénario: Un vendeur vend le 07/10 à 18h sans fermer sa caisse. Le 08/10 à 10h, il la ferme. Le relevé compte bien la vente du 07 (nombre_ventes 1), mais periode_debut = 08/10 00:00 : la colonne « Depuis » exclut la vente qu'il contient.
Correction: periode_debut = min(date de la dernière fermeture, plus ancien date_emission des factures comptées). Si aucune facture n'est comptée : la dernière fermeture, à défaut le début du jour.

### C43 [mineur] [caisse] [DECISION] La clôture du 31 décembre est numérotée dans la série de l'année suivante
app/Domain/Facturation/Actions/CloturerCaisseAction.php:59
Scénario: La tâche du 01/01/2027 à 00:00 clôture la journée du 31/12/2026 et lui attribue le numéro Z-2027-000001. La série Z-2026 se termine donc sans la dernière journée de 2026. La soumission e-reporting, elle, est numérotée sur l'année de sa période (GenererSoumissionEReportingAction).
Correction: Utiliser Carbon::parse($dateCloture)->format('Y') pour la clé de numérotation. À faire valider par le magasin et à coordonner avec le chantier Reco 8 (clôtures et totaux perpétuels).

### C44 [mineur] [caisse] [DECISION] Le widget de CA ne déduit pas les avoirs
app/Filament/App/Widgets/FacturesApercu.php:34
Scénario: Facture de 120 € intégralement créditée par un avoir : le widget « Chiffre d'affaires facturé » affiche toujours 120,00 € TTC.
Correction: Décision métier : soit déduire les avoirs émis sur la période (CalculateurLigne::deduire) et afficher le net, de préférence en HT ; soit renommer clairement le libellé en « Montant facturé TTC (brut, avant avoirs) ».

### C45 [mineur] [caisse] Commentaires devenus faux sur le blocage de la clôture et sur l'horaire de l'e-reporting
app/Domain/Facturation/Services/VerificateurCaissesPersonnelles.php:15
Scénario: Le docblock affirme qu'« un vendeur qui oublie de fermer sa caisse ne bloque donc jamais la clôture ». Or ListClotureCaisses désactive le bouton dans ce cas (->disabled), et ClotureCaisseResourceTest l'impose comme « exigence explicite ». bootstrap/app.php (ligne 32) affirme aussi que « l'horaire tardif laisse le temps à une clôture manuelle de la veille d'être faite avant l'agrégation ». Or la veille est déjà clôturée par la tâche de 00:00 avant que la moindre clôture manuelle soit possible (voir le constat sur la clôture manuelle).
Correction: Réécrire ces commentaires une fois le comportement de la clôture manuelle tranché. Ils doivent dire que seule la clôture manuelle est bloquée par les caisses ouvertes et que la clôture automatique ne l'est jamais. Supprimer la justification de l'horaire de 01:00 ou la remplacer par la bonne : passer après la clôture de 00:00.

### C46 [mineur] [caisse] Code mort : PeriodeEReporting::contient()
app/Integrations/Pdp/Contracts/PeriodeEReporting.php:19
Scénario: La méthode contient() n'est appelée ni dans app/ ni dans tests/.
Correction: Supprimer la méthode contient().

### C47 [important] [caisse] (connu b) coutMoyenPondere renvoie null pour des lots de coût nul
app/Domain/Facturation/Services/CalculateurLigne.php:236
Scénario: Lots de quantité > 0 et de coût 0 : la valeur totale vaut 0, la fonction renvoie null et le CMUP garde l'ancien coût au lieu de 0.
Correction: Dans coutMoyenPondere, ne renvoyer null que si la quantité totale est <= 0 ou si la valeur totale est < 0. Une valeur nulle doit donner 0.0. Mettre à jour le docblock en conséquence.

### C48 [important] [achats] Correction d'une facture d'achat sur un stock déjà mélangé : CMUP faux
app/Domain/Stock/Services/StockLedgerService.php:87
Scénario: Facture 1 : 10 @ 2 €, facture 2 : 10 @ 10 € (CMUP 6), 10 vendus. Facture 2 corrigée à 5 € : CMUP 1,0000 au lieu de 3,5000. Corrigée ensuite à 1 € : CMUP toujours 1,0000 au lieu de 1,5000 (la valeur devient négative, coutMoyenPondere renvoie null et l'ancien CMUP est gardé).
Correction: Revaloriser au prorata : la part de la réception corrigée encore en stock vaut stock x (quantité reçue / quantité totale présente après cette réception), et seule cette part passe de l'ancien au nouveau coût. Autre solution : recalculer le CMUP en rejouant les entrées et sorties depuis la réception corrigée. Ajouter un test de régression avec les deux corrections successives.

### C49 [important] [achats] Correction d'une réception à coût nul : le CMUP garde l'ancien coût au lieu de 0
app/Domain/Facturation/Services/CalculateurLigne.php:236
Scénario: 10 reçus à 5 € (CMUP 5), puis la facture validée est corrigée à 0 € (marchandise offerte) : le CMUP reste 5,0000 au lieu de 0,0000.
Correction: Ne renvoyer null que si la quantité totale est <= 0. Pour une quantité positive, renvoyer 0 quand la valeur vaut 0. Traiter explicitement une valeur négative, qui ne peut venir que du défaut (a), au lieu de la masquer derrière null. Mettre à jour le docblock de coutMoyenPondere et le commentaire de la l. 93 de StockLedgerService.

### C50 [important] [achats] Produit non suivi : le « dernier achat » est choisi par facture_achat_id et non par date de validation
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:85
Scénario: Produit non suivi. Brouillon F1 à 8 € créé avant le brouillon F2 à 5 €. F2 est validée, puis F1 (CMUP 8). On corrige F1 de 8 à 9 € : le CMUP passe à 5,0000 (F2, dont l'id est plus grand) au lieu de 9,0000.
Correction: Dans retenirLeCoutDuDernierAchat, trier par factures_achat.date_validation décroissante (jointure ou orderByDesc d'une sous-requête), puis par facture_achat_id et ordre. Réutiliser cette requête pour le rattachement au fournisseur (constat « Corriger une ancienne facture d'achat... »).

### C51 [important] [achats] Suivi du stock activé après une réception : la correction rend le stock négatif
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:132
Scénario: Produit non suivi : 10 reçus, aucune entrée en stock. Le suivi est ensuite activé, puis la facture est corrigée de 10 à 5 : le stock tombe à -5. Le suivi peut aussi être activé depuis la ligne elle-même pendant la modification, car ConstructeurLigneAchat enregistre suivi_stock avant corrigerLaReception.
Correction: Calculer la quantité réellement entrée pour cette facture à partir de mouvements_stock : ReceptionAchat avec source = cette facture, plus les ajustements de correction, qui ont besoin d'une source et d'un sens (voir le constat sur le ledger). Si rien n'est entré, traiter la correction comme une première réception, ou ne rien ajuster. Lire suivi_stock avant d'enregistrer les nouvelles lignes.

### C52 [mineur] [achats] Barème : rouvrir une ligne d'achat avec « Modifier » perd prix_vente_propose
app/Filament/Concerns/AjouteDesLignesDAchat.php:439
Scénario: Palier < 10 € (+5 €), sans bénéfice au-delà. Une ligne est ajoutée à 2 € (prix proposé 8,40), puis rouverte avec « Modifier » et passée à 20 € d'achat : 8,40 reste affiché. À la première ouverture, ce prix était bien retiré.
Correction: Conserver prix_vente_propose dans l'état de la ligne (Hidden non envoyé au domaine, ignoré par ConstructeurLigneAchat) et le reprendre dans formulaireDepuisLaLigne. Autre solution : le recalculer avec le barème à la réouverture, à partir du prix d'achat net et de la TVA de la ligne.

### C53 [mineur] [achats] Paramètres > Prix de vente : désactiver ne réinitialise pas les erreurs de validation
app/Filament/App/Pages/ParametresPrixDeVente.php:75
Scénario: Calcul activé, bénéfice au-delà vidé et paliers supprimés, puis Enregistrer : erreur. Le calcul est désactivé ensuite : l'erreur reste affichée alors que le barème enregistré a été remis.
Correction: Injecter $livewire dans le afterStateUpdated et appeler $livewire->resetErrorBag() (ou resetValidation()) lors de la désactivation.

### C54 [mineur] [achats] Essai du barème : une TVA vidée vaut 0 au lieu de 20
app/Filament/App/Pages/ParametresPrixDeVente.php:165
Scénario: Prix d'achat d'essai 0,47 € et champ TVA vidé : 10,47 € TTC s'affiche au lieu de 12,56 €.
Correction: $tauxTva = filled($get('essai_taux_tva')) ? (float) $get('essai_taux_tva') : 20.0; ou rendre le champ obligatoire avec minValue(0)/maxValue(100).

### C55 [bloquant] [achats] Fuite entre magasins : le fournisseur d'un autre magasin est accepté à la modification d'une facture d'achat et sur la fiche produit
app/Filament/App/Resources/FactureAchatResource/Pages/EditFactureAchat.php:45
Scénario: Une requête Livewire forgée envoie data.fournisseur_id = un fournisseur du magasin 1, sur la modification d'une facture validée du magasin 2. L'enregistrement réussit : factures_achat.fournisseur_id pointe vers le fournisseur étranger, et rattacherAuFournisseur donne aussi ce fournisseur au produit du magasin 2. Même chose sur EditProduit (ProduitResource l. 184, Select fournisseur_id).
Correction: EditFactureAchat::handleRecordUpdate : résoudre Fournisseur::query()->findOrFail($data['fournisseur_id']) (scopé) avant l'action, comme CreateFactureAchat. ModifierFactureAchatAction : vérifier que le fournisseur appartient au magasin. Sur les deux Select (FactureAchatResource et ProduitResource) : ->exists(modifyRuleUsing: fn (Exists $r) => $r->where('magasin_id', app(TenantContext::class)->id())). Ajouter un test d'isolation pour chaque écran.

### C56 [important] [achats] Avoir fournisseur : deux lignes du même produit peuvent retourner plus que le stock (articles déjà vendus)
app/Domain/Achats/Models/LigneAchat.php:117
Scénario: Facture validée avec 2 lignes du même produit (5 + 5), 4 vendus, il reste 6 en stock. L'avoir propose 5 sur chaque ligne et les accepte : 10 articles retournés, le stock tombe à -4. Des articles vendus sont donc « retournés », à l'inverse de la règle annoncée.
Correction: Dans CreerAvoirFournisseurAction, cumuler les quantités demandées par produit_id (produits suivis) et refuser (LogicException) si le cumul dépasse le stock du produit lu sous lockForUpdate. Refaire ce contrôle dans EmettreAvoirFournisseurAction, puisque le stock peut changer entre la création et l'émission. Dans le formulaire, ajouter une règle qui vérifie le total par produit.

### C57 [important] [achats] Lignes d'achat : aucune vérification serveur des droits produit (fiche modifiée ou produit créé sans permission)
app/Domain/Achats/Services/ConstructeurLigneAchat.php:154
Scénario: Un membre a les droits facture_achat.voir et facture_achat.créer, sans produit.modifier ni produit.créer. Il envoie un état Livewire forgé pour data.lignes : produit.prix_vente_ttc = 1.00 sur un produit existant à 150 €, plus une ligne sans produit_id avec produit.nom. Le prix du produit passe à 1,00 € et un nouveau produit est créé.
Correction: Avant d'appeler l'action, dans CreateFactureAchat et EditFactureAchat, ou dans ConstructeurLigneAchat avec l'utilisateur courant quand il y en a un : refuser (LogicException avec un message pour l'utilisateur) si le produit existant devient dirty sans Gate::allows('update', $produit), ou si une ligne demande un nouveau produit sans PRODUIT_CREATE. Garder un chemin sans utilisateur pour la future intégration API.

### C58 [important] [achats] Barème dans la ligne d'achat : une TVA vidée calcule le prix de vente à 0 %, enregistré ensuite à 20 %
app/Filament/Concerns/AjouteDesLignesDAchat.php:312
Scénario: Barème par défaut actif, nouveau produit, champ TVA vidé, prix d'achat 2 € : le prix proposé est 17,00 € (2 + 15 sans TVA) au lieu de 20,40 €. À l'enregistrement, la TVA retombe à 20 %, et le produit est vendu 17,00 € TTC = 14,17 € HT, soit 12,17 € de bénéfice au lieu des 15 € visés.
Correction: Rendre taux_tva obligatoire dans prixVenteTtcSchema (->required()), ce qui couvre la fiche produit et la ligne d'achat. Ajouter un helper commun (filled($get('taux_tva')) ? (float) $get('taux_tva') : 20.0) pour appliquerLeBareme, venteEnMagasin, detailDuPrixDeVente et ProduitResource::detailPrixDeVente.

### C59 [important] [achats] Corriger une ancienne facture d'achat rattache le produit à l'ancien fournisseur
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:139
Scénario: Produit acheté chez A (F1 validée), puis chez B (F2 validée) : son fournisseur est B. On corrige seulement la note de F1 : son fournisseur redevient A, alors que le dernier achat est chez B.
Correction: Après correction, pour chaque produit présent avant ou après, reprendre le fournisseur du dernier achat validé, trié par date_validation (même requête que pour (c)). Ne rien changer si aucun achat validé ne reste.

### C60 [important] [achats] [DECISION] Retour fournisseur sorti au CMUP : la valeur du stock est surévaluée
app/Domain/Achats/Listeners/DecrementerStockApresEmissionAvoirFournisseur.php:23
Scénario: 10 @ 2 € puis 10 @ 10 € (CMUP 6). Les 10 articles à 10 € sont retournés (avoir de 100 € HT) : il reste 10 articles au CMUP de 6 €, soit 60 € HT, alors qu'ils ont coûté 20 €. Le stock est surévalué de 40 € et le CMUP ne baisse jamais.
Correction: Soumettre au magasin. S'il choisit la déduction au coût d'achat : ajouter StockLedgerService::retournerAuFournisseur(produit, quantité, coût de la ligne d'achat retournée) pour recalculer le CMUP avec un lot négatif (au repli près si la valeur devient négative), l'appeler depuis le listener, et mettre à jour le docblock de StockLedgerService.

### C61 [important] [achats] Produit archivé : sa réception disparaît en silence à la validation, et la facture n'est plus modifiable
app/Domain/Achats/Listeners/IncrementerStockApresValidationFactureAchat.php:30
Scénario: 1) Un produit est archivé (DeleteAction, SoftDeletes) alors qu'un brouillon le contient. La validation donne un stock de 0, sans mouvement ni CMUP, et sans aucun message. 2) Le produit d'une facture validée est archivé : toute modification de la facture lève ModelNotFoundException (ConstructeurLigneAchat l. 154, findOrFail). Ce n'est pas une LogicException, donc GereLesExceptionsMetier ne l'attrape pas et une page d'erreur s'affiche.
Correction: Refuser explicitement (LogicException avec un message pour l'utilisateur) la validation et la modification d'une facture qui référence un produit archivé, ou charger le produit avec withTrashed() dans le listener, dans corrigerLaReception et dans produitDeLaLigne. Le refus est plus sûr. Envisager aussi d'interdire l'archivage d'un produit présent dans un brouillon d'achat, ou de proposer sa restauration.

### C62 [important] [achats] Ledger de stock non reconstructible : le sens d'un ajustement n'est enregistré nulle part
app/Domain/Stock/Services/StockLedgerService.php:154
Scénario: Réception de 10 corrigée à 5, et réception de 10 corrigée à 15 : le ledger contient dans les deux cas [reception_achat:10, ajustement:5], pour des stocks de 5 et de 15. Le sens n'apparaît que dans le texte libre de la note « (+5) » ou « (-5) ».
Correction: Remplacer Ajustement par AjustementEntree et AjustementSortie dans TypeMouvementStock et dans le commentaire de la migration de création, avec estEntree() défini pour chacun. ajuster() choisit le type selon le signe. Passer la facture d'achat corrigée comme source du mouvement de correction. Ajouter un test qui recalcule stock_quantite depuis le ledger.

### C63 [important] [achats] Fournisseur : un SIRET déjà pris (même archivé) provoque une erreur 500
app/Filament/App/Resources/FournisseurResource.php:33
Scénario: Un fournisseur au SIRET 12345678900011 est archivé (DeleteAction, SoftDeletes), puis recréé avec le même SIRET : UniqueConstraintViolationException, page d'erreur. Comme aucune restauration n'existe, ce fournisseur ne peut plus jamais être recréé. Même plantage avec un doublon actif.
Correction: Sur le champ siret : ->unique(table: Fournisseur::class, ignoreRecord: true, modifyRuleUsing: fn (Unique $r) => $r->where('magasin_id', app(TenantContext::class)->id())), sans filtre deleted_at (archivés compris, comme l'index), avec un message clair. Ajouter aussi ->length(14)/->regex('/^\d{14}$/'). Prévoir la restauration d'un fournisseur archivé, ou un message qui l'indique.

### C64 [mineur] [achats] Prix de vente TTC et prix d'achat négatifs acceptés (ligne d'achat et fiche produit)
app/Filament/App/Resources/ProduitResource.php:124
Scénario: Ligne d'achat d'un nouveau produit avec prix de vente TTC -12 : acceptée, le produit est créé à -12,00 € TTC / -10,00 € HT et reste vendable. Le champ cmup_ht « Prix d'achat HT » de la fiche (l. 197) n'a pas non plus de minimum.
Correction: Ajouter ->minValue(0) sur prix_vente_ttc et cmup_ht. Dans Produit::saving, lever InvalidArgumentException si prix_vente_ttc, prix_vente_ht ou cmup_ht sont négatifs.

### C65 [mineur] [achats] [DECISION] Un membre sans droit de valider peut modifier une réception validée (stock et CMUP)
app/Policies/FactureAchatPolicy.php:40
Scénario: Un membre a facture_achat.créer sans facture_achat.valider. Il ouvre une facture validée (2 reçus) et passe la quantité à 50 : le stock passe à 50 et le CMUP est recalculé, ce qui revient à valider une réception sans en avoir le droit.
Correction: Soumettre au magasin. Si retenu : dans FactureAchatPolicy::update, exiger aussi FACTURE_ACHAT_VALIDER quand statut = Validee, et ajouter un test de policy et un test Livewire.

### C66 [mineur] [achats] [DECISION] Un achat manuel change le fournisseur d'un produit verrouillé par une facture API
app/Domain/Achats/Listeners/IncrementerStockApresValidationFactureAchat.php:35
Scénario: Produit reçu par facture API du fournisseur A (fiche verrouillée, Select fournisseur grisé, texte : « son fournisseur [est] celui du fournisseur »). Une facture manuelle de B est validée : le fournisseur du produit devient B.
Correction: Soumettre au magasin. Soit rattacherAuFournisseur ignore un produit verrouillé par API quand la facture n'est pas elle-même une facture API, soit fournisseur_id sort de ATTRIBUTS_DU_FOURNISSEUR et les textes de ProduitResource sont corrigés (description de la section, Select non grisé).

### C67 [mineur] [achats] [DECISION] Brouillon supprimé : les nouveaux produits qu'il a créés restent au catalogue (fournisseur, CMUP jamais payé, prix 0 sans barème)
app/Domain/Achats/Services/ConstructeurLigneAchat.php:176
Scénario: Un brouillon crée un nouveau produit, puis il est supprimé : le produit reste vendable, rattaché au fournisseur du brouillon (alors que le fournisseur d'un produit doit venir d'une facture validée), avec un CMUP égal au prix d'un achat jamais réalisé. Créé sans prix de vente et sans barème (chemin domaine ou futur chemin API), son prix de vente est 0,00 €.
Correction: Soumettre au magasin. Options : (1) ne rattacher le fournisseur qu'à la validation (pas à la création du produit dans le brouillon) ; (2) supprimer avec le brouillon les produits qu'il a créés et qui ne sont référencés nulle part ailleurs ; (3) ne créer les produits qu'à la validation. Dans ConstructeurLigneAchat, refuser un nouveau produit sans prix de vente et sans barème applicable au lieu de l'enregistrer à 0 €.

### C68 [mineur] [achats] Code mort : Fournisseur::upsertDepuisFactureEntrante n'est jamais appelé (et planterait sur un SIRET archivé)
app/Domain/Contacts/Models/Fournisseur.php:61
Scénario: Aucun appelant. S'il était branché, updateOrCreate ignorerait un fournisseur archivé au même SIRET et l'index unique (magasin_id, siret) lèverait une exception.
Correction: Supprimer la méthode et la mention dans le commentaire de la migration fournisseurs (garder ou retirer AutoPdp selon l'usage prévu). La réécrire à l'étape 11 (SUPER PDP) avec withTrashed() et restore().

### C69 [mineur] [achats] Commentaire devenu faux dans StatutFactureAchat
app/Domain/Achats/Enums/StatutFactureAchat.php:17
Scénario: Le docblock dit « une fois validée, une correction passe par un avoir fournisseur (retour) ». Or une facture manuelle validée reste directement modifiable (FactureAchat::estModifiable, ModifierFactureAchatAction).
Correction: Réécrire : une facture manuelle validée sans avoir émis se corrige directement (voir ModifierFactureAchatAction), sinon (facture API ou avoir déjà émis) par un avoir fournisseur ; jamais par une annulation.

### C70 [bloquant] [catalogue] [DECISION] Un magasin peut prendre le compte (et le magasin) de n'importe quel utilisateur d'un autre magasin en saisissant son email dans « Équipe > Nouveau membre »
app/Domain/Tenancy/Actions/CreerMembreMagasinAction.php:59
Scénario: Le propriétaire du magasin B crée un membre (rôle Observateur) avec l'email du propriétaire du magasin A et le mot de passe « choisi-par-b ». Résultat : le mot de passe et le nom du propriétaire de A sont remplacés (nom = « Pirate ») ; B se connecte avec patron-a@x.test / choisi-par-b et ouvre /app/{uuid A}/clients (200). Même chose par l'action « Nouveau mot de passe » de MembreEquipeResource (ligne 102) : le propriétaire ou l'admin de A remet le mot de passe d'un membre de A qui est aussi propriétaire d'un magasin B (comptable multi-magasins, etc.) et prend B. Un User étant global, aucun magasin ne devrait pouvoir changer ses identifiants.
Correction: Ne jamais écrire name ni password sur un User qui existe déjà. Deux options à trancher par l'éditeur : refuser l'email déjà utilisé, ou bien un rattachement que la personne accepte elle-même. Ne proposer « Nouveau mot de passe » (action ET ReinitialiserMotDePasseMembreAction) que pour un compte qui n'appartient à aucun autre magasin. Réécrire le test existant selon la règle choisie.

### C71 [bloquant] [catalogue] Suppression en masse ouverte à tous ceux qui voient la liste : produits, clients et fournisseurs (deleteAny absent des policies)
app/Policies/ProduitPolicy.php:31
Scénario: Un vendeur (sans produit.delete) coche 2 produits dans Catalogue > Produits et lance « Supprimer » : il reste 0 produit. Un observateur (lecture seule) supprime de la même façon des clients et des fournisseurs, y compris le « Client comptoir ».
Correction: Ajouter deleteAny() à ProduitPolicy (PRODUIT_DELETE), ClientPolicy (CLIENT_DELETE) et FournisseurPolicy (FOURNISSEUR_DELETE). Dans les DeleteBulkAction de ProduitResource, ClientResource et FournisseurResource, filtrer avec can('delete', $record) comme FactureResource. Ajouter aussi delete() et deleteAny() à DevisPolicy, en définissant quels devis sont supprimables, et filtrer la DeleteBulkAction de DevisResource.

### C72 [bloquant] [catalogue] Supprimer un client (même le « Client comptoir ») retire ses ventes du jour de la clôture Z et casse sa facture (page et PDF en erreur 500)
app/Policies/ClientPolicy.php:31
Scénario: Vente comptoir TK-2026-000001 de 12,00 € TTC encaissée, puis le client comptoir est supprimé (bulk delete, possible même pour un observateur, voir constat précédent). Résultat : la clôture du jour donne nombre_ventes=0 et total_ttc=0.00. La fiche facture et le PDF lèvent « Call to a member function estProfessionnel() on null ». Même chose pour tout client particulier supprimé avant minuit.
Correction: Refuser dans ClientPolicy::delete la suppression d'un client qui a des documents (devis, factures, avoirs, paiements), et toujours celle du client comptoir : il s'archive via « actif ». En défense, ajouter withTrashed() aux relations client() de Facture, Avoir, Devis et Paiement, et faire passer les whereHas des scopes de clôture par withTrashed().

### C73 [bloquant] [catalogue] [DECISION] Modifier une fiche client réécrit les factures déjà émises et peut sortir une vente de toute déclaration
app/Filament/App/Resources/ClientResource.php:104
Scénario: Ticket comptoir émis et payé pour « Jean Ancien » (particulier). La fiche est ensuite modifiée en « Marc Nouveau », professionnel, SIRET 11122233344455. Résultat : le PDF de la facture déjà émise affiche « Marc Nouveau » (plus « Jean Ancien »), devient une « facture » (nomFichier facture-TK-...) et relevantDeLeInvoicing() vaut true. La clôture du jour la retire (nombre_ventes=0) alors qu'elle n'a jamais été transmise au PDP : elle n'est plus déclarée nulle part.
Correction: Décision de l'éditeur ou du magasin. Option 1 : figer sur la facture et l'avoir, à l'émission, le nom, l'adresse, le SIRET/TVA et le type du client, puis s'en servir pour le PDF, le circuit e-invoicing/e-reporting et la clôture, dans les migrations de création. Option 2 : interdire de changer le type et l'identité légale d'un client qui a des documents émis.

### C74 [important] [catalogue] « Nouveau mot de passe » et « Retirer » (Équipe) ne vérifient pas equipe.modifier : un membre qui a seulement « Équipe : voir » prend le compte d'un administrateur
app/Filament/App/Resources/MembreEquipeResource.php:90
Scénario: Un vendeur à qui le propriétaire a donné la seule permission supplémentaire « Équipe : voir » ouvre la liste Équipe. Il change le mot de passe de l'administrateur puis le retire du magasin.
Correction: Ajouter ->authorize(fn (MagasinUser $record): bool => auth()->user()->can('update', $record)) à « Nouveau mot de passe » et ->authorize(... can('delete', $record)) à « Retirer », et mettre le même contrôle dans visible(). Pour le mot de passe, combiner avec la correction du premier constat (compte présent dans un autre magasin).

### C75 [important] [catalogue] Le client comptoir n'est reconnu que par son nom « Client comptoir » : renommé, la caisse perd son client par défaut et ses avoirs deviennent un crédit appliqué à d'autres clients anonymes
app/Domain/Contacts/Models/Client.php:58
Scénario: Un vendeur (client.update) renomme « Client comptoir » en « Comptoir ». Résultat : la Caisse s'ouvre sans client (client_id null), estComptoir() vaut false, et un avoir émis pour ce client devient un solde créditeur que AppliquerAvoirsDisponiblesAction applique à la vente anonyme suivante (exactement le cas que soldeCrediteur() veut empêcher). À l'inverse, n'importe quelle fiche nommée « Client comptoir » est traitée comme le comptoir.
Correction: Repérer le comptoir par un marqueur fixe : booléen est_comptoir dans la migration de création de clients, unique par magasin, posé par CreerMagasinAction. Utiliser ce marqueur dans estComptoir() et dans Caisse. Interdire de le renommer, de changer son type et de le supprimer.

### C76 [important] [catalogue] Un produit peut être rattaché au fournisseur d'un autre magasin (règle exists implicite du Select non limitée au magasin)
app/Filament/App/Resources/ProduitResource.php:184
Scénario: Dans le magasin A, création d'un produit avec data.fournisseur_id = id d'un fournisseur du magasin B (requête Livewire modifiée). Le produit est enregistré avec ce fournisseur_id.
Correction: Limiter au magasin la règle exists de chaque Select relationship (fournisseur_id de ProduitResource et FactureAchatResource, client_id de DevisResource et FactureResource), par exemple ->rule(Rule::exists('fournisseurs','id')->where('magasin_id', app(TenantContext::class)->id())->whereNull('deleted_at')). En défense, vérifier dans les Actions de modification (ModifierFactureAction, ModifierDevisAction, ModifierFactureAchatAction), ou dans un saving du modèle, que la clé étrangère appartient au magasin courant.

### C77 [important] [catalogue] Fiche produit : vider le taux de TVA plante l'enregistrement (erreur SQL 500) et le détail HT/TVA affiché est faux
app/Filament/App/Resources/ProduitResource.php:131
Scénario: Créer un produit à 12 € TTC en vidant le champ TVA, ou effacer le taux sur un produit existant puis « Sauvegarder ». Résultat : QueryException « NOT NULL constraint failed: produits.taux_tva ». Pendant la saisie, le détail affiche « Soit 12,00 € HT + 0,00 € de TVA » (chaîne vide lue comme 0) ou « Soit 10,00 € HT + 2,00 € » (null lu comme 20).
Correction: Rendre taux_tva required() dans prixVenteTtcSchema(). Dans detailPrixDeVente, renvoyer null tant que blank($get('taux_tva')). Le même schéma sert aussi aux lignes d'achat (AjouteDesLignesDAchat::appliquerLeBareme fait aussi ?? 20).

### C78 [important] [catalogue] Une référence saisie au format automatique (PRD-000001) fait planter le produit suivant créé sans référence
app/Domain/Catalogue/Models/Produit.php:69
Scénario: Créer un produit avec la référence « PRD-000001 », puis un produit en laissant la référence vide (« générée automatiquement »). Résultat : UniqueConstraintViolationException sur produits.magasin_id/reference (page d'erreur).
Correction: Après la génération, reprendre le numéro suivant tant que la référence existe déjà (withTrashed). Autre option : refuser à la saisie une référence manuelle au format réservé PRD-NNNNNN.

### C79 [important] [catalogue] Fournisseur : un SIRET en double, ou celui d'un fournisseur supprimé, provoque une erreur 500 ; un SIRET supprimé reste bloqué pour toujours
app/Filament/App/Resources/FournisseurResource.php:120
Scénario: Créer un fournisseur avec un SIRET déjà utilisé : UniqueConstraintViolationException. Supprimer le fournisseur F1 puis recréer un fournisseur avec son SIRET : même erreur, et aucun écran ne permet de restaurer F1 (pas de TrashedFilter ni de RestoreAction).
Correction: Ajouter ->unique(ignoreRecord: true, modifyRuleUsing: where magasin_id), archivés compris, avec un message clair, et une règle de format (14 chiffres). Ajouter un moyen de restaurer un fournisseur supprimé, ou bien interdire sa suppression et l'archiver via « actif ».

### C80 [important] [catalogue] Recréer un membre suspendu (ou « invité ») provoque une erreur 500
app/Domain/Tenancy/Actions/CreerMembreMagasinAction.php:48
Scénario: Un vendeur est passé au statut « Suspendu ». Le propriétaire le recrée ensuite depuis Équipe > Nouveau membre avec le même email. Résultat : UniqueConstraintViolationException sur magasin_user.magasin_id/user_id au lieu d'un message.
Correction: Dans CreerMembreMagasinAction, tester l'existence du rattachement quel que soit son statut (magasins()->wherePivot('magasin_id', ...)->exists()). Lever alors une LogicException du type « Cette personne fait déjà partie de votre équipe : modifiez son statut. »

### C81 [important] [catalogue] Les permissions supplémentaires d'un membre retiré restent en base et reviennent s'il est ajouté de nouveau
app/Domain/Tenancy/Actions/RetirerMembreMagasinAction.php:27
Scénario: Un vendeur reçoit « Avoirs : voir » et « Équipe : voir », puis il est retiré. Plus tard, il est ajouté de nouveau comme Observateur : il récupère aussitôt ces deux permissions sans que personne les ait accordées.
Correction: Dans la transaction de RetirerMembreMagasinAction, TenantContext étant positionné sur le magasin, appeler aussi $membre->utilisateur->syncPermissions([]) : seules les permissions de cette équipe sont touchées.

### C82 [important] [catalogue] Aucun écran ne permet de saisir l'identité légale du magasin (raison sociale, adresse, SIREN/SIRET, n° de TVA) : les factures sortent sans ces mentions
app/Filament/App/Pages/Auth/RegisterMagasin.php:55
Scénario: Un magasin s'inscrit (seul son nom est demandé) puis émet ses factures. Le PDF (pdf.blade.php:41-53) n'imprime l'adresse, le SIRET et la TVA du vendeur que s'ils sont remplis. Or aucun écran ne les remplit : ParametresFiscaux ne touche que regime_tva et option_debits_tva.
Correction: Ajouter une page « Identité du magasin », réservée au propriétaire : raison sociale, adresse, SIREN/SIRET (format contrôlé), n° de TVA. Imprimer raison sociale et SIREN sur les PDF de facture et d'avoir. Bloquer ou avertir à la première émission tant que ces mentions manquent.

### C83 [important] [catalogue] Ajuster le stock est impossible : la permission « Produits : ajuster le stock » et ProduitPolicy::ajusterStock ne servent à rien, et le texte d'aide renvoie à un mouvement de stock qu'aucun écran ne crée
app/Filament/App/Resources/ProduitResource.php:215
Scénario: Après un inventaire, de la casse ou un vol, le magasin veut corriger le stock d'un produit. La fiche indique « Modifiable uniquement via un mouvement de stock », mais aucun écran ni action n'appelle StockLedgerService::ajuster(). Le propriétaire peut accorder PRODUIT_AJUSTER_STOCK à un membre : cela n'a aucun effet. Le stock initial d'un produit existant ne peut entrer que par une facture d'achat.
Correction: Implémenter l'action « Ajuster le stock » (quantité comptée + motif, autorisée par ProduitPolicy::ajusterStock, appel à StockLedgerService::ajuster()) avec l'étape 7. Sinon, retirer d'ici là la permission et la méthode de policy, conformément à la règle « code devenu inutile supprimé ».

### C84 [important] [catalogue] [DECISION] Client professionnel enregistrable sans SIRET/SIREN ; SIRET et n° de TVA jamais contrôlés (client et fournisseur)
app/Filament/App/Resources/ClientResource.php:105
Scénario: Créer un client « Professionnel » sans SIRET, ou avec le SIRET « abc » : la fiche est acceptée. Ses factures relèvent alors de l'e-invoicing (relevantDeLeInvoicing) sans identifiant de l'acheteur, alors que le SIREN du client est une mention obligatoire des factures B2B depuis la réforme.
Correction: À trancher par le magasin. Rendre le SIREN/SIRET obligatoire pour un professionnel français (pays FR) et accepter un n° de TVA ou un autre identifiant pour l'étranger. Contrôler le format côté client et fournisseur : 9 ou 14 chiffres avec clé de Luhn ; TVA FR = « FR » + 2 caractères de clé + SIREN.

### C85 [mineur] [catalogue] Corriger une facture d'achat ancienne remet le produit sur l'ancien fournisseur
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:139
Scénario: Le produit Q est acheté chez F1 (facture 1 validée) puis chez F2 (facture 2 validée) : son fournisseur est F2. On corrige ensuite le prix de la facture 1 de F1. Résultat : Q repasse sur F1, alors que la fiche dit « Celui de la dernière facture d'achat validée pour ce produit ».
Correction: Après la correction, rattacher le produit au fournisseur de sa dernière facture d'achat validée, triée par date_validation puis id (même critère que le correctif (c)), et ne rien changer si ce n'est pas la facture corrigée.

### C86 [mineur] [catalogue] Prix de vente TTC et CMUP négatifs ou démesurés acceptés sur la fiche produit
app/Filament/App/Resources/ProduitResource.php:124
Scénario: Créer un produit avec un prix d'achat de -5 € et un prix de vente de -12 € TTC : il est enregistré (cmup_ht -5.0000, prix_vente_ttc -12.00) et ce prix négatif est repris par défaut dans les lignes de vente. 99 999 999 999 € est aussi accepté : sous MySQL, le decimal(12,2) déborde et l'enregistrement finit en erreur 500 (la Caisse prévoit MONTANT_MAXIMUM pour cette raison).
Correction: Ajouter minValue(0) et maxValue(Caisse::MONTANT_MAXIMUM) à prix_vente_ttc (prixVenteTtcSchema) et à cmup_ht.

### C87 [mineur] [catalogue] [DECISION] Client : « Délai de paiement » et « Mode de paiement préféré » ne servent nulle part et ne sont pas validés
app/Filament/App/Resources/ClientResource.php:126
Scénario: La fiche client accepte un délai de -5, 1,5 ou 70 000 jours (colonne unsignedSmallInteger : erreur SQL hors limites sous MySQL). Ces deux champs ne sont jamais lus : la date d'échéance d'une facture ne tient pas compte du délai du client.
Correction: Décision du magasin. Soit utiliser le délai pour proposer date_echeance (integer, minValue(0), maxValue(365)) et le mode préféré comme valeur par défaut en caisse et dans les paiements. Soit supprimer les deux champs, migration de création comprise.

### C88 [mineur] [catalogue] Un client repassé de professionnel à particulier garde un SIRET et une TVA invisibles, toujours imprimés sur ses factures
app/Filament/App/Resources/ClientResource.php:105
Scénario: Un client professionnel (SIRET 12345678901234, TVA FR123) est modifié en particulier. Les champs disparaissent du formulaire, donc ne sont plus ni modifiables ni enregistrés, mais restent en base. « SIRET : 12345678901234 » reste imprimé sur ses factures et avoirs (pdf.blade.php:79-81).
Correction: Dans ClientResource, vider siret et numero_tva quand type_client devient particulier (afterStateUpdated sur le type, ou mutateFormDataBeforeSave dans EditClient, CreateClient et la création rapide). Le PDF peut en plus ne les imprimer que pour un professionnel.

### C89 [mineur] [catalogue] Le bouton « Actif » des clients et des fournisseurs ne change rien
app/Filament/App/Resources/ClientResource.php:129
Scénario: Un client ou un fournisseur désactivé reste proposé dans la Caisse, les devis et factures (Select relationship('client')) et les factures d'achat (relationship('fournisseur')).
Correction: Exclure les fiches inactives des recherches et des listes de client et de fournisseur pour un nouveau document : modifyQueryUsing sur les Select, where('actif', true) dans Caisse. Garder lisible la fiche déjà liée à un document existant.

### C90 [mineur] [catalogue] Le filtre « Épuisé » de la liste des produits inclut les produits sans suivi de stock
app/Filament/App/Resources/ProduitResource.php:256
Scénario: Un produit avec « Suivre le stock » désactivé (stock affiché « — ») apparaît sous « Statut du stock : Épuisé », alors que le widget « Références épuisées » ne compte que les produits suivis.
Correction: Ajouter where('suivi_stock', true) aux deux branches du filtre (en_stock et epuise).

### C91 [mineur] [catalogue] Le mot de passe d'un membre n'a aucune règle (un seul caractère accepté)
app/Filament/App/Resources/MembreEquipeResource.php:57
Scénario: Le propriétaire crée un vendeur avec le mot de passe « 1 » : le compte est créé. Même chose dans l'action « Nouveau mot de passe » (ligne 95), alors que l'inscription impose Password::default().
Correction: Ajouter ->rule(Password::default()) aux deux champs.

### C92 [mineur] [catalogue] [DECISION] Supprimer un produit qui figure dans un brouillon : la vente émise ensuite ne décrémente pas le stock
app/Filament/App/Resources/ProduitResource/Pages/EditProduit.php:19
Scénario: Produit suivi avec 5 en stock, ajouté (quantité 2) à un brouillon de facture, puis supprimé. Le brouillon est émis : la facture sort normalement, mais le stock reste à 5 au lieu de 3, sans aucun mouvement. Un avoir sur cette ligne ne remettrait rien en stock non plus.
Correction: À trancher par le magasin. Soit refuser la suppression d'un produit en stock ou présent dans un document ouvert, et l'archiver via « actif ». Soit charger le produit avec withTrashed() dans DecrementerStockApresEmissionFacture et RestaurerStockApresEmissionAvoir. Refuser aussi l'émission d'un brouillon dont un produit a été supprimé.

### C93 [mineur] [catalogue] Code mort dans les domaines Contacts, Catalogue et Tenancy
app/Domain/Contacts/Models/Fournisseur.php:61
Scénario: Ces méthodes et colonnes ne sont jamais appelées ni alimentées. Fournisseur::upsertDepuisFactureEntrante() n'a aucun appel, donc SourceFournisseur::AutoPdp n'est jamais attribué (le filtre et le badge « Source » de la liste ne montrent que « Saisi manuellement »). Sont aussi inutilisés : StatutMagasin::permetConnexion() et label(), StatutMembreMagasin::permetAcces(), le statut « invited » (défaut de migration, jamais produit, mais proposé dans EditMembreEquipe) et magasin_user.invited_by (jamais écrit). users.current_magasin_id est écrit par CreerMagasinAction mais jamais lu (User n'implémente pas HasDefaultTenant). Enfin, Produit::parent(), declinaisons() et la colonne produit_parent_id (les déclinaisons n'existent nulle part), Service::lignesFacture(), Fournisseur::produits() et facturesAchat() ne servent pas.
Correction: Supprimer ces méthodes et ces colonnes dans leurs migrations de création, ainsi que les assertions des deux tests sur current_magasin_id. Retirer « Invité » des options de statut, ou le brancher. Réécrire upsertDepuisFactureEntrante quand l'étape 11 en aura besoin (attention, updateOrCreate par SIRET ignore les fournisseurs supprimés et heurterait l'index unique).

### C94 [mineur] [catalogue] Commentaires devenus faux sur Filament Shield et sur le panneau SuperAdmin
app/Domain/Tenancy/Actions/SeedMagasinRolesAction.php:15
Scénario: Le docblock affirme qu'« un propriétaire pourra ensuite affiner via Filament Shield ». Shield n'est branché sur aucun panneau : les permissions s'affinent par « Permissions supplémentaires » dans EditMembreEquipe. SuperAdminPanelProvider.php:24-25 annonce « gestion des magasins, abonnements, plans », mais le panneau est vide et inaccessible.
Correction: Corriger le docblock de SeedMagasinRolesAction (affinage par les permissions supplémentaires du membre) et la mention « Phase 7 » de User::canAccessPanel (étape 13). Retirer bezhansalleh/filament-shield s'il reste inutilisé : cela demande l'accord de l'utilisateur (l'éditeur), pas du magasin, car CLAUDE.md interdit de changer les dépendances sans approbation.

### C95 [important] [catalogue] (a) corrigerUneReception faux quand le stock était déjà un mélange avant la réception corrigée
app/Domain/Stock/Services/StockLedgerService.php:85
Scénario: 10 @ 2 € + 10 @ 10 € (CMUP 6), 10 vendus, puis la réception à 10 € est corrigée à 5 € : CMUP 1,0000 au lieu de 3,50. Corrigée ensuite à 1 € : CMUP toujours 1,0000 au lieu de 1,50 (le calcul tombe en négatif, renvoie null, et l'ancien CMUP est gardé).
Correction: En CMUP, ne revaloriser que la part de la réception encore en stock : stock actuel x (quantité de la réception / stock juste après cette réception, lu dans les mouvements). Ajouter l'écart de valeur correspondant sans jamais descendre sous 0.

### C96 [important] [catalogue] (b) coutMoyenPondere renvoie null pour des lots de coût nul : le CMUP garde l'ancien coût
app/Domain/Facturation/Services/CalculateurLigne.php:236
Scénario: 10 articles reçus à 5 €, réception corrigée à 0 € : CMUP 5,0000 au lieu de 0.
Correction: Ne renvoyer null que si la quantité totale est <= 0. Une valeur nulle donne 0, et une valeur négative, qui ne peut venir que d'un retrait de lot mal calculé, est bornée à 0.

### C97 [mineur] [catalogue] (c) Dernier achat d'un produit non suivi choisi par facture_achat_id et non par date de validation
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:85
Scénario: La facture d'achat 1 est créée, la 2 est créée et validée à 9 €, puis la 1 est validée à 4 € (le vrai dernier achat). Une correction qui retire le produit d'une facture recalcule le coût sur la facture d'id le plus grand, et non sur la dernière validée.
Correction: Trier sur la date de validation de la facture d'achat (join ou sous-requête sur factures_achat.date_validation), puis par facture_achat_id et id.

### C98 [important] [catalogue] (d) suivi_stock activé après une réception : la correction de la facture rend le stock négatif
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:133
Scénario: Produit sans suivi de stock reçu à 10 unités, suivi activé ensuite, puis facture corrigée à 5 unités : stock -5.
Correction: Calculer lotsAvant à partir des mouvements de réception réellement enregistrés pour cette facture (MouvementStock de type ReceptionAchat dont la source est la facture), à zéro s'il n'y en a pas.

### C99 [mineur] [catalogue] (e) Barème : rouvrir une ligne d'achat avec « Modifier » perd prix_vente_propose
app/Filament/Concerns/AjouteDesLignesDAchat.php:251
Scénario: Une ligne dont le prix de vente vient du barème est rouverte par « Modifier ». La proposition mémorisée est perdue : un changement de prix d'achat ne recalcule plus le prix de vente, comme si l'utilisateur l'avait saisi lui-même.
Correction: Garder prix_vente_propose dans la ligne (déshydraté, ou recopié par ligneDepuisLeFormulaire) et le restaurer dans formulaireDepuisLaLigne().

### C100 [mineur] [catalogue] (f) Paramètres > Prix de vente : désactiver ne réinitialise pas les erreurs de validation
app/Filament/App/Pages/ParametresPrixDeVente.php:75
Scénario: Une saisie invalide affiche ses erreurs, puis le calcul automatique est désactivé : les valeurs reviennent à celles enregistrées, mais les messages d'erreur restent affichés sous des champs grisés.
Correction: Injecter $livewire dans afterStateUpdated et appeler $livewire->resetErrorBag() quand le calcul est désactivé.

### C101 [mineur] [catalogue] (g) Essai du barème : TVA laissée vide calculée à 0 au lieu de 20
app/Filament/App/Pages/ParametresPrixDeVente.php:165
Scénario: Dans « Essayer le barème », vider le champ TVA : le prix d'essai est calculé à 0 % de TVA. ProduitResource::detailPrixDeVente (ligne 155) a le même défaut (voir le constat sur le taux de TVA vidé).
Correction: Utiliser blank($get('essai_taux_tva')) ? 20 : (float) $get('essai_taux_tva'), ou ne rien calculer tant que le champ est vide. Appliquer la même chose dans detailPrixDeVente et appliquerLeBareme.

### C102 [important] [incomplet] Identité légale du magasin impossible à saisir : les PDF n'impriment jamais le SIRET, l'adresse ni le n° de TVA du vendeur
app/Domain/Tenancy/Models/Magasin.php:40
Scénario: Un commerçant s'inscrit : RegisterMagasin ne demande que le nom du magasin et l'e-mail. Il émet ensuite une facture ou un avoir. Le PDF affiche seulement le nom commercial, sans SIRET, adresse ni n° de TVA intracommunautaire, car aucun écran ne permet de remplir raison_sociale, siren, siret, numero_tva, adresse, code_postal, ville. Même si ces colonnes étaient remplies, raison_sociale et siren ne sont jamais imprimés.
Correction: Ajouter une page « Mon magasin » (->tenantProfile() Filament, ou page Paramètres réservée au propriétaire avec sa permission) qui édite raison sociale, SIREN (9 chiffres), SIRET (14 chiffres commençant par le SIREN), n° TVA et adresse. magasins.siret a un index unique global et Magasin n'est pas sous MagasinScope : la règle unique porte donc sur toute la table, en ignorant l'enregistrement courant. Imprimer aussi raison sociale et SIREN sur les PDF de facture et d'avoir. Supprimer les colonnes qui restent inutilisées (complement_adresse, telephone, email, pays) ou les imprimer. Soumettre au magasin la question de bloquer l'émission tant que l'identité est incomplète : c'est une décision métier.

### C103 [important] [incomplet] Un SIRET fournisseur en double déclenche une exception SQL au lieu d'une erreur de validation
app/Filament/App/Resources/FournisseurResource.php:33
Scénario: Créer le fournisseur « Grossiste A » avec le SIRET 12345678900012, l'archiver (ou pas), puis créer un autre fournisseur avec le même SIRET dans le même magasin. On obtient une UniqueConstraintViolationException (page 500) au lieu d'un message sur le champ. Aucune Resource n'a de TrashedFilter ni de RestoreAction, donc la fiche archivée n'est ni visible ni restaurable : ce SIRET reste bloqué pour toujours.
Correction: Sur siret : ->unique(table: Fournisseur::class, ignoreRecord: true, modifyRuleUsing: fn (Unique $r) => $r->where('magasin_id', app(TenantContext::class)->id())) avec un validationMessages clair. Comme pour la référence produit, la règle Unique par défaut ne filtre pas deleted_at et couvre donc les archivés, comme l'index. Ajouter TrashedFilter + RestoreAction sur fournisseurs (et produits, dont la référence est bloquée de la même façon). Ajouter un test de régression (doublon actif et doublon archivé).

### C104 [important] [incomplet] [DECISION] Aucun mandat PDP ne peut être créé : la transmission PDP des factures et de l'e-reporting ne part jamais en dehors des tests
app/Domain/Tenancy/Actions/CreerMandatPdpAction.php:20
Scénario: Facture émise à un client professionnel, ou soumission d'e-reporting générée : DeclencherTransmissionPdpApresEmissionFacture (l.30) et DeclencherTransmissionPdpApresSoumissionEReporting (l.20) cherchent un MandatPdp du magasin. Ils n'en trouvent jamais et sortent sans rien faire, donc SubmitFactureToPdpJob et SubmitSoumissionEReportingToPdpJob ne sont jamais dispatchés. La permission « Mandat PDP : gérer » (MANDAT_PDP_GERER) ne sert qu'à ouvrir Paramètres fiscaux. Le docblock promet un clic « Accepter » qui n'existe nulle part.
Correction: Brancher la création du mandat sur le flux OAuth SUPER PDP de l'étape 11 : bouton « Connecter SUPER PDP » / « Révoquer » dans Paramètres fiscaux (permission MANDAT_PDP_GERER). Le callback /superpdp/retour vérifie le state, enregistre la preuve de consentement, échange le code contre des jetons stockés chiffrés par magasin, puis crée le MandatPdp via CreerMandatPdpAction. La révocation passe par RevokerMandatPdpAction. Tester avec Http::fake et les entreprises fictives du bac à sable (Tricatel, Burger Queen). D'ici là, corriger les deux docblocks qui décrivent un écran inexistant.

### C105 [important] [incomplet] Défaut connu (a) toujours présent : CMUP faux à la correction d'une réception quand le stock était déjà un mélange
app/Domain/Stock/Services/StockLedgerService.php:87
Scénario: Stock reçu 10 @ 2 € puis 10 @ 10 € (CMUP 6), 10 articles vendus. La réception à 10 € est corrigée à 5 € : le CMUP passe à 1 €. Corrigée ensuite de 5 € à 1 € : la somme pondérée devient négative, coutMoyenPondere renvoie null et le CMUP reste à 1 € au lieu de baisser.
Correction: Recalculer le CMUP en rejouant le grand livre du produit : parcourir mouvements_stock dans l'ordre, chaque reception_achat entrant au coût actuel de sa ligne d'achat (le stocker sur le mouvement ou le relire depuis lignes_achat), les sorties et autres entrées ne changeant pas le CMUP, et un stock vide ou négatif ne pesant rien comme dans receptionner(). Sur l'exemple, on obtient 3,5 puis 1,5. L'écart de quantité continue de passer par ajuster(). Aucune décision métier : le CMUP des ventes passées n'est pas modifié.

### C106 [important] [incomplet] Défaut connu (b) toujours présent : coutMoyenPondere renvoie null pour des lots de coût nul, le CMUP garde l'ancien coût
app/Domain/Facturation/Services/CalculateurLigne.php:236
Scénario: Une réception de 10 articles à 5 € est corrigée à 0 € (article offert). Les lots valent 0 au total, coutMoyenPondere renvoie null, et corrigerUneReception garde le CMUP à 5 € au lieu de 0 €.
Correction: Ne renvoyer null que si la quantité totale est <= 0 ou si la valeur est < 0 ; une valeur nulle pour une quantité positive donne 0.0. Mettre le docblock à jour. Ajouter un test (correction d'une réception à 0 € : CMUP 0). Tant que (a) n'est pas corrigé, des valeurs négatives restent possibles.

### C107 [important] [incomplet] Défaut connu (d) toujours présent : basculer suivi_stock après une réception donne un stock négatif à la correction
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:132
Scénario: Produit reçu à 10 unités avec suivi_stock désactivé : aucun mouvement n'est créé. Le suivi est activé ensuite (stock 0), puis la facture d'achat est corrigée à 5 unités. corrigerUneReception prend les 10 de la ligne comme s'ils étaient entrés : écart -5, et le stock est ajusté à -5.
Correction: Prendre lotsAvant dans les mouvements reception_achat réellement enregistrés pour cette facture et ce produit. Si cette réception n'est jamais entrée en stock, ne corriger que le coût (retenirLeCoutDuDernierAchat ou CMUP) et ne créer aucun ajustement de quantité. Ajouter un test de régression avec le scénario ci-dessus.

### C108 [mineur] [incomplet] Défaut connu (c) toujours présent : le « dernier achat » d'un produit non suivi est choisi par facture_achat_id
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:85
Scénario: Une facture A est créée en brouillon, puis une facture B est créée et validée. A est validée ensuite. En corrigeant B, le coût retenu est celui de B (id le plus grand) alors que A est le dernier achat validé.
Correction: Dans retenirLeCoutDuDernierAchat, joindre factures_achat et trier par factures_achat.date_validation décroissante, puis par lignes_achat.id décroissant.

### C109 [mineur] [incomplet] Défaut connu (e) toujours présent : rouvrir une ligne d'achat avec « Modifier » perd prix_vente_propose
app/Filament/Concerns/AjouteDesLignesDAchat.php:251
Scénario: Barème actif. Une ligne d'achat reçoit un prix de vente proposé par le barème, puis on la rouvre avec « Modifier » : le repère du prix proposé est perdu et le prix ne suit plus les changements de prix d'achat.
Correction: Garder prix_vente_propose dans l'état de la ligne : champ déshydraté, enregistré par ligneDepuisLeFormulaire, ignoré par ConstructeurLigneAchat. Le restituer dans formulaireDepuisLaLigne. Ajouter un test Livewire : ligne proposée par le barème, « Modifier », prix d'achat au-delà du dernier palier, le prix proposé est retiré.

### C110 [mineur] [incomplet] Défaut connu (f) toujours présent : désactiver le barème ne réinitialise pas les erreurs de validation
app/Filament/App/Pages/ParametresPrixDeVente.php:75
Scénario: Barème activé, palier invalide, on enregistre : erreurs affichées. On désactive ensuite le toggle : les valeurs reviennent au barème enregistré, mais les messages d'erreur restent sous des champs désormais grisés.
Correction: Quand l'état passe à false dans afterStateUpdated, appeler aussi $this->resetValidation() (la closure est définie dans form(), $this est la page ; on peut aussi injecter $livewire). Ajouter un test : save invalide, désactivation, assertHasNoFormErrors().

### C111 [mineur] [incomplet] Défaut connu (g) toujours présent : essai du barème avec TVA vide calculé à 0 % au lieu de 20 %
app/Filament/App/Pages/ParametresPrixDeVente.php:165
Scénario: Dans « Essayer le barème », on vide le champ TVA : Livewire envoie la chaîne vide, l'essai est calculé avec une TVA à 0 %.
Correction: $tauxTva = filled($get('essai_taux_tva')) ? (float) $get('essai_taux_tva') : 20.0 ; appliquer le même traitement dans AjouteDesLignesDAchat (appliquerLeBareme, detailDuPrixDeVente).

### C112 [mineur] [incomplet] La preuve de consentement au mandat PDP est perdue si l'appel au PDP échoue, contrairement à ce qu'affirme le docblock
app/Domain/Tenancy/Actions/CreerMandatPdpAction.php:26
Scénario: Le propriétaire donne son consentement et la passerelle PDP lève une exception (réseau). Le MandatPdp en attente avec IP, user-agent et date de consentement est annulé avec la transaction : il ne reste aucune trace. Le docblock dit au contraire que cette preuve « doit survivre même si l'appel réseau échoue ». Le défaut est latent tant que l'action n'est pas branchée.
Correction: Enregistrer la preuve (MandatPdp EnAttente, IP, user-agent, date) dans sa propre écriture validée avant l'appel réseau. Appeler ensuite la passerelle, puis mettre à jour le statut. En cas d'échec, laisser le mandat EnAttente et relancer l'exception. Avec le flux OAuth SUPER PDP, enregistrer la preuve au retour du callback, avant l'échange du code contre les jetons. Ajouter un test avec une passerelle qui échoue.

### C113 [mineur] [incomplet] Le seeder plante : php artisan migrate:fresh --seed lève une exception NOT NULL sur users.uuid
database/seeders/DatabaseSeeder.php:11
Scénario: php artisan migrate:fresh --seed (ou db:seed) sur une base vide lève une QueryException « NOT NULL constraint failed: users.uuid ». Le seul utilisateur semé n'aurait de toute façon aucun magasin, donc aucun accès au panneau.
Correction: Retirer WithoutModelEvents et le reste du squelette. Vider le seeder jusqu'à l'étape 15, ou semer des données de démonstration factices via CreerMagasinAction (propriétaire + magasin + clients, produits et documents fictifs). Ajouter un test qui exécute le seeder.

### C114 [mineur] [incomplet] Table journaux_audit créée mais jamais utilisée (aucun modèle, aucune écriture)
database/migrations/2026_10_02_100005_create_journaux_audit_table.php:11
Scénario: migrate:fresh crée la table journaux_audit, mais aucune action n'y écrit : aucune trace d'audit n'est enregistrée, contrairement à ce que suggère le schéma.
Correction: Supprimer la migration 2026_10_02_100005_create_journaux_audit_table.php, puis migrate:fresh. La recréer avec son modèle, son écriture et ses tests à l'étape 3.

### C115 [mineur] [incomplet] Colonnes de schéma jamais lues ou jamais écrites
database/migrations/2026_10_02_200003_create_produits_table.php:14
Scénario: produits.produit_parent_id (avec les relations Produit::parent() et declinaisons()) n'est ni saisi ni lu. magasins.heure_cloture_automatique (migration magasins l.42) laisse croire à une heure de clôture propre à chaque magasin, alors que ClotureAutomatiqueCaisse tourne pour tous à 00:00. magasins.devise et locale (l.30-31) ne sont lues nulle part. magasin_user.invited_by (l.17) n'est jamais écrite, et le statut par défaut 'invited' (l.16) n'est jamais produit : tous les rattachements sont créés à 'active'.
Correction: Dans les migrations de création, supprimer produit_parent_id, heure_cloture_automatique, magasins.devise, magasins.locale et invited_by. Supprimer aussi les relations, $fillable, $casts, withPivot (Magasin et User), les valeurs par défaut de Magasin::booted() et les entrées de MagasinFactory. Retirer le cas StatutMembreMagasin::Invite et la valeur par défaut 'invited' jusqu'à ce que les invitations existent. Lancer migrate:fresh et toute la suite de tests.

### C116 [mineur] [incomplet] [DECISION] Champs client « Délai de paiement » et « Mode de paiement préféré » saisis mais sans effet
database/migrations/2026_10_02_200001_create_clients_table.php:25
Scénario: Le magasin saisit un délai de 30 jours sur un client professionnel, puis crée une facture : l'échéance reste vide ou saisie à la main (DatePicker), le délai n'est jamais utilisé. Le mode préféré est un texte libre de 30 caractères, sans rapport avec l'enum ModePaiement, et n'est jamais lu.
Correction: Soumettre au magasin. Option 1 (liée à l'étape 4, échéances et relances) : date_echeance par défaut = date d'émission + delai_paiement_jours, mode_paiement_prefere converti en enum ModePaiement et présélectionné à l'encaissement. Option 2 : supprimer les deux colonnes et les champs. Si les champs restent, valider delai_paiement_jours (->integer()->minValue(0)->maxValue(65535)), la colonne étant unsignedSmallInteger.

### C117 [mineur] [incomplet] Méthodes et relations jamais appelées en production
app/Domain/Tenancy/Models/Magasin.php:104
Scénario: Code mort qui laisse croire à des comportements inexistants. Exemple : Magasin::estActif() et StatutMagasin::permetConnexion() ne sont jamais appelés, et aucun accès au panneau ne vérifie le statut du magasin. Liste complète dans la preuve.
Correction: Supprimer ces méthodes et relations, ainsi que les tests qui ne testent qu'elles (estDefinitive, contient...). Ne garder le statut du magasin que là où il agit (ClotureAutomatiqueCaisse filtre déjà sur StatutMagasin::Active). Brancher le statut dans canAccessPanel et canAccessTenant à l'étape 13, quand l'éditeur pourra suspendre un magasin.

### C118 [mineur] [incomplet] Commentaire faux sur les relations de Magasin « pour la convention de tenancy de Filament » : leurs noms ne correspondent pas à cette convention
app/Domain/Tenancy/Models/Magasin.php:109
Scénario: Le commentaire dit que ces relations servent à la convention de tenancy de Filament (Resource::$tenantOwnershipRelationshipName). En réalité, Filament utilise getTenantRelationshipName() (pluriel camelCase du modèle), et seulement dans CreateRecord sans handleRecordCreation surchargé. Pour ClotureCaisse, FactureAchat, AvoirFournisseur et SoumissionEReporting, Filament attend clotureCaisses, factureAchats, avoirFournisseurs et soumissionEReportings : les relations cloturesCaisse, facturesAchat, avoirsFournisseur et soumissionsEReporting ne servent donc à rien.
Correction: Supprimer cloturesCaisse, facturesAchat, avoirsFournisseur, soumissionsEReporting, devis, factures, paiements et avoirs. Réécrire le commentaire : Filament associe la création au magasin via getTenantRelationshipName() (Magasin::clients() pour ClientResource), seulement quand la création n'est pas surchargée. Corriger le docblock de membres() : relation utilisée par CreateMembreEquipe.

### C119 [mineur] [incomplet] Permission « Produits : ajuster le stock » sans effet (aucun écran d'ajustement)
app/Policies/ProduitPolicy.php:36
Scénario: Le propriétaire accorde « Produits : ajuster le stock » à un membre dans l'éditeur de permissions. Le membre n'obtient rien : aucune action ne vérifie cette capacité et aucun écran n'appelle StockLedgerService::ajuster().
Correction: Retirer la permission, son libellé et ProduitPolicy::ajusterStock jusqu'à l'étape 7 (inventaire, ajustements), et corriger le helperText. Autre option : créer dès maintenant une action « Ajuster le stock » sur EditProduit, autorisée par ajusterStock, qui appelle StockLedgerService::ajuster avec un motif obligatoire, avec ses tests.

### C120 [mineur] [incomplet] [DECISION] Réception automatique (PDP/API) des fournisseurs et factures d'achat inexistante : code et valeurs d'enum jamais produits
app/Domain/Contacts/Models/Fournisseur.php:61
Scénario: Fournisseur::upsertDepuisFactureEntrante() se présente comme le « point d'entrée unique » d'une synchronisation depuis un job WithTenantContext qui n'existe pas. SourceFournisseur::AutoPdp et SourceFactureAchat::Api ne sont jamais écrits par le code applicatif. Le filtre « source » de FournisseurResource et le verrouillage « produit venu d'une facture API » ne sont donc atteignables qu'en test.
Correction: Soit écrire la réception à l'étape 11 : job tenant-aware qui reçoit les factures fournisseurs de SUPER PDP, rapproche par SIRET et crée la FactureAchat source Api, avec tests sur des données factices du bac à sable. Soit, d'ici là, supprimer upsertDepuisFactureEntrante, SourceFournisseur::AutoPdp, le filtre « source » et les commentaires qui y renvoient. Garder SourceFactureAchat::Api et la règle de verrouillage établie avec le magasin.

### C121 [mineur] [incomplet] Docblock faux : la clôture automatique ne tourne pas « juste après » le marquage des factures en retard mais 15 minutes avant
app/Console/Commands/ClotureAutomatiqueCaisse.php:18
Scénario: Le docblock dit que la commande est planifiée « juste après app:marquer-factures-en-retard ». Le planificateur fait l'inverse.
Correction: Corriger le docblock : la clôture tourne à minuit (heure de Paris), avant le marquage des factures en retard à 00:15.

### C122 [mineur] [incomplet] Docblock de Facture et commentaire de migration faux : « une facture ne se supprime jamais » alors qu'un brouillon se supprime
app/Domain/Facturation/Models/Facture.php:26
Scénario: Le docblock du modèle (« une facture ne se supprime jamais, même doucement (voir FacturePolicy::delete()) ») et la migration factures l.41 l'affirment. Pourtant FacturePolicy::delete() autorise la suppression définitive d'un brouillon, et FactureResource et EditFacture proposent un DeleteAction.
Correction: Réécrire les deux textes : « une facture numérotée (émise ou annulée) ne se supprime jamais, même doucement ; un brouillon, sans numéro ni existence légale, peut être supprimé définitivement (voir FacturePolicy::delete()) ».

### C123 [mineur] [incomplet] Autres commentaires devenus faux par rapport au code
app/Domain/Stock/Services/StockLedgerService.php:17
Scénario: 1) StockLedgerService l.17-19 : les achats ne déclencheraient ce service que via des listeners, alors que ModifierFactureAchatAction l'appelle directement (corrigerUneReception, retenirLeCoutDuDernierAchat). 2) Migration lignes_achat l.17 : « CreerFactureAchatAction exige un produit_id », alors que c'est ConstructeurLigneAchat, et qu'une ligne peut créer un nouveau produit sans produit_id. 3) ServicePolicy l.12 renvoie à « Caisse::actionAjouterUnService() », qui n'existe pas : la méthode est privée dans AjouteDesLignesDeVente. 4) Migration transmissions_pdp l.21 : formats « facturx, ubl, cii », alors que FakePdpGateway écrit 'ereporting'. 5) Migration fournisseurs : la source « pdp_auto » serait « utile pour signaler en UI fournisseur à vérifier », mais rien ne la produit.
Correction: Réécrire ces commentaires selon le code actuel. 1) Les achats appellent le service via le listener de validation et directement à la correction. 2) Chaque ligne a un produit, existant ou créé avec la facture (voir ConstructeurLigneAchat). 3) Renvoyer à AjouteDesLignesDeVente::actionAjouterUnService(). 4) Lister les formats réellement produits (facturx, ereporting). 5) Supprimer le commentaire avec le code mort.

### C124 [mineur] [incomplet] Commentaires qui décrivent un état antérieur ou renvoient à des documents absents
app/Domain/Facturation/Actions/AccepterDevisAction.php:11
Scénario: AccepterDevisAction l.11 et MarquerFacturesEnRetard l.15 commencent par « … existait depuis la Phase 3 mais n'était atteint par aucun code ». User.php l.115 renvoie à une « Phase 7 » (l'administration est l'étape 13 de la feuille de route). PdpGatewayInterface l.13 renvoie au « §18 du cahier des charges », absent du dépôt. Cela contredit la règle « on ne garde aucune trace d'un état antérieur ».
Correction: Ne décrire que ce que fait chaque classe aujourd'hui. Remplacer « Phase 7 » par l'étape 13 de la feuille de route. Supprimer le renvoi au §18 en gardant la règle (aucun code propre à un prestataire dans les actions métier). Indiquer que FakePdpGateway sera remplacée par l'implémentation SUPER PDP de l'étape 11.

### C125 [mineur] [incomplet] [DECISION] Statuts jamais atteints : StatutDevis::Expire, StatutAvoir::Annulee, StatutAvoirFournisseur::Annulee
app/Domain/Facturation/Enums/StatutDevis.php:14
Scénario: Un devis dont la date de validité est dépassée reste « Envoyé » : il peut encore être accepté puis converti en facture, car aucun code ne passe un devis à Expire. Les avoirs, clients comme fournisseurs, sont créés puis émis dans la même opération : le statut Annulee n'est jamais atteint.
Correction: Soumettre au magasin le sort des devis échus : commande planifiée qui passe à Expire les devis Envoyés dont la date de validité est dépassée (sur le modèle de MarquerFacturesEnRetard, par magasin), refus d'accepter un devis échu, ou suppression du cas Expire. Supprimer dès maintenant StatutAvoir::Annulee et StatutAvoirFournisseur::Annulee.

### C126 [mineur] [incomplet] Factories jamais utilisées
database/factories/DevisFactory.php:1
Scénario: Six factories ne sont appelées ni dans app/ ni dans tests/ : AvoirAllocationFactory, AvoirFournisseurFactory, ClotureCaisseFactory, ClotureCaissePersonnelleFactory, DevisFactory, PaiementAllocationFactory.
Correction: Supprimer ces six factories et le trait HasFactory (avec son @use) des modèles concernés. Les recréer le jour où un test en a réellement besoin.

### C127 [mineur] [incomplet] Squelette Laravel/Filament mort laissé en place
routes/console.php:6
Scénario: La commande « inspire » (routes/console.php), la page d'accueil Laravel par défaut sur « / » (routes/web.php et welcome.blade.php), le contrôleur de base app/Http/Controllers/Controller.php jamais étendu, AppServiceProvider::register() vide (« // »), withMiddleware vide dans bootstrap/app.php et l'import commenté « // use ...MustVerifyEmail » dans User.php. Le panneau super-admin est enregistré avec une page de connexion, mais User::canAccessPanel renvoie false pour tout le monde et ses dossiers ne contiennent que des .gitkeep.
Correction: Faire rediriger « / » vers le panneau /app et supprimer welcome.blade.php, la commande inspire, Controller.php, register() vide, withMiddleware vide et l'import commenté. Retirer SuperAdminPanelProvider (de bootstrap/providers.php) et app/Filament/SuperAdmin jusqu'à l'étape 13. Lancer toute la suite de tests.

### C128 [mineur] [incomplet] Référence de paiement sans longueur maximale alors que la colonne fait 100 caractères (erreur SQL sous MySQL)
app/Filament/App/Resources/PaiementResource.php:80
Scénario: Sous MySQL en mode strict (cible de production, étape 15), saisir une référence de paiement de plus de 100 caractères dans Paiements > Créer, ou dans l'action « Enregistrer un paiement » de la facture, déclenche « Data too long » (page 500) au lieu d'une erreur de validation. SQLite ne vérifie pas cette longueur.
Correction: Ajouter ->maxLength(100) aux deux TextInput::make('reference'). Ajouter un test qui vérifie l'erreur de validation pour une référence de 101 caractères.

### C129 [bloquant] [tests] [DECISION] Le propriétaire d'un magasin prend le contrôle du compte d'un utilisateur d'un autre magasin, et un test exige ce comportement
app/Domain/Tenancy/Actions/CreerMembreMagasinAction.php:59
Scénario: Le propriétaire du magasin B ouvre Équipe > Nouveau membre et saisit l'email du propriétaire du magasin A avec un mot de passe qu'il choisit. Le mot de passe de A est remplacé : B se connecte avec le compte de A et voit tout le magasin A (/app/{A}/clients renvoie 200 et affiche les clients de A). ReinitialiserMotDePasseMembreAction ouvre la même faille pour un membre commun à deux magasins (par exemple un comptable) : le propriétaire de B change le mot de passe qui donne aussi accès à A.
Correction: Décision à prendre par le magasin ou l'éditeur : refuser avec un message neutre, ou passer par une invitation que le titulaire accepte. Dans les deux cas, CreerMembreMagasinAction ne modifie jamais le nom ni le mot de passe d'un compte existant. ReinitialiserMotDePasseMembreAction refuse tout compte rattaché à un autre magasin (ou exige que le compte n'appartienne qu'à ce magasin). Réécrire le test de la ligne 68 pour qu'il affirme : mot de passe inchangé, et aucun accès au magasin A avec le mot de passe saisi par B.

### C130 [important] [tests] Équipe : avec la seule permission « Équipe : voir », un membre réinitialise le mot de passe d'un administrateur et le retire de l'équipe
app/Filament/App/Resources/MembreEquipeResource.php:90
Scénario: Le propriétaire accorde à un vendeur la permission supplémentaire « Équipe : voir » (equipe.view). Le vendeur ouvre la liste Équipe et clique sur « Nouveau mot de passe » sur la ligne de l'administrateur : il choisit le mot de passe de l'administrateur et peut ensuite se connecter avec son compte. L'action « Retirer » supprime aussi l'administrateur de l'équipe. Pourtant MembreEquipePolicy::update() renvoie false pour ce vendeur.
Correction: Ajouter ->authorize(fn (MagasinUser $record): bool => auth()->user()->can('update', $record)) à reinitialiser_mot_de_passe et ->authorize(fn (MagasinUser $record): bool => auth()->user()->can('delete', $record)) à retirer, ou remplacer le ->visible() par ces mêmes conditions. Ajouter un test : un membre avec equipe.view sans equipe.modifier voit les deux actions masquées ou refusées, et le mot de passe et le rattachement de l'administrateur ne changent pas.

### C131 [important] [tests] Transmission PDP : après un échec passager, la facture n'est jamais renvoyée ($tries=4 et backoff ne servent à rien)
app/Domain/Facturation/Jobs/SubmitFactureToPdpJob.php:54
Scénario: La PDP répond 503 à la première tentative. Le job enregistre une transmission « rejetee » puis relance l'exception. Au retry suivant, la PDP répond de nouveau, mais le contrôle d'idempotence trouve une transmission sortante et sort sans rien envoyer. La facture reste rejetée pour toujours. SubmitSoumissionEReportingToPdpJob.php:46 a le même défaut pour l'e-reporting. C'est ce qui arrivera avec SUPER PDP à la première coupure réseau.
Correction: Au retry, réutiliser une transmission sortante existante dans un statut non définitif au lieu de sortir. Sur une exception passagère, laisser la transmission à « soumise » (avec l'erreur dans son historique) et relancer. Ne passer à « rejetee » que dans failed(), après le dernier essai, ou quand la PDP répond réellement par un rejet. Même correction dans SubmitSoumissionEReportingToPdpJob. Test : échec puis succès donne une seule transmission, acceptée, et un second appel à la passerelle.

### C132 [important] [tests] [DECISION] Un magasin suspendu ou fermé garde tout l'accès au panneau (caisse comprise), mais les tâches planifiées l'ignorent ; permetConnexion() et estActif() ne sont jamais appelés
app/Models/User.php:70
Scénario: Le magasin passe au statut suspended ou closed. Son propriétaire ouvre toujours /app/{uuid}/caisse (200) et /factures (200) et peut encaisser. ClotureAutomatiqueCaisse, MarquerFacturesEnRetard et SoumettreEReportingPeriodique filtrent where('statut', Active) : ces ventes ne sont jamais clôturées automatiquement ni déclarées en e-reporting.
Correction: Le magasin ou l'éditeur décide de ce que veut dire la suspension : blocage complet, lecture seule sans caisse, ou clôtures et e-reporting qui continuent. Ensuite, soit canAccessTenant() et getTenants() utilisent $magasin->statut->permetConnexion(), soit les commandes planifiées traitent aussi les magasins suspendus qui ont encore des ventes à clôturer ou à déclarer. Supprimer celle des deux méthodes (estActif ou permetConnexion) qui restera inutile. Peut être rattaché à l'étape 13.

### C133 [important] [tests] Les tests HTTP réutilisent le TenantContext posé pendant la préparation : le middleware qui lie le magasin à la requête n'est jamais vraiment testé
app/Http/Middleware/BindTenantContextFromFilamentTenant.php:29
Scénario: On supprime la ligne app(TenantContext::class)->set($tenant) du middleware. En production, toute requête du panneau échouerait alors avec TenantContextMissingException, mais la suite entière reste verte. La cause : CreerMagasinAction ou TenantContext::set() dans le test laissent le contexte positionné dans le conteneur, et ->get('/app/...') en hérite. Un test HTTP ne vérifie donc jamais quel magasin la requête résout réellement.
Correction: Dans tests/TestCase.php, surcharger call() pour appeler $this->app->forgetScopedInstances() (ou app(TenantContext::class)->clear()) avant chaque requête HTTP de test, comme le fait un vrai cycle de requête. Ajouter un test qui vide le contexte, positionne volontairement le contexte sur le magasin B, puis appelle /app/{A}/clients en tant que membre de A : il doit voir uniquement les clients de A.

### C134 [important] [tests] Isolation des magasins : TenantIsolationTest couvre 3 modèles sur 22 et aucune ressource n'est testée sur l'accès par identifiant à l'enregistrement d'un autre magasin
tests/Feature/Tenancy/TenantIsolationTest.php:47
Scénario: Le docblock (ligne 25) affirme qu'ajouter un modèle BelongsToMagasin suffit à le couvrir, mais modelesTenant() ne liste que Client, Fournisseur et Produit. Les 19 autres modèles (Facture, Devis, Avoir, Paiement, FactureAchat, AvoirFournisseur, ClotureCaisse, ClotureCaissePersonnelle, SoumissionEReporting, MouvementStock, TransmissionPdp, Service, toutes les lignes et allocations) n'ont aucun test de lecture croisée ni de magasin_id forcé. Aucune ressource n'est testée sur l'URL /app/{B}/<ressource>/{id d'un enregistrement de A}. ClientResourceTest ne teste que la liste d'un magasin auquel l'utilisateur n'appartient pas. Le docblock renvoie aussi à « le prompt maître du projet, §29 », un document absent du dépôt. test_un_super_admin_peut_explicitement_contourner_le_scope ne vérifie que withoutGlobalScope d'Eloquent, pour un super admin qui n'existe pas.
Correction: Étendre modelesTenant() à tous les modèles BelongsToMagasin qui ont une fabrique. Ajouter un test qui énumère les classes utilisant le trait et échoue si l'une manque à la liste. Ajouter un test paramétré par ressource Filament : membre de B, URL d'un enregistrement de A, réponse 404 attendue. Réécrire le docblock sans renvoi au « prompt maître ». Supprimer le test « super admin » ou le renommer pour dire ce qu'il vérifie vraiment.

### C135 [important] [tests] Contrôles d'accès qu'aucun test ne vérifie : la suite reste verte quand on les supprime
app/Models/User.php:117
Scénario: Chacune des mutations suivantes laisse les 547 tests verts. Panneau super-admin ouvert à tout le monde (default => true). Membre suspendu toujours accepté par appartientA(), getTenants() et canAccessPanel('app'). Jobs PDP qui ne restaurent plus le magasin (demarrerContexteTenant() supprimé : un worker réel lèverait TenantContextMissingException, mais en test le contexte est déjà posé). Filtre cree_par retiré de AvoirResource. Confidentialité par créateur retirée de AvoirPolicy, PaiementPolicy, ClotureCaissePolicy et ClotureCaissePersonnellePolicy::view. Statut Brouillon retiré de FactureAchatPolicy::delete(), qui est la seule garde du bouton Supprimer d'EditFactureAchat (Actions\DeleteAction sans visible()) : une facture d'achat validée, dont le stock est déjà entré, deviendrait supprimable.
Correction: Ajouter un test par contrôle : GET /super-admin renvoie 403 pour un propriétaire ; un membre suspendu ou invité reçoit 404 ou 403 sur /app/{uuid} et le magasin n'apparaît pas dans getTenants() ; un vendeur sans facture.view_toutes ne voit ni les avoirs ni les paiements d'un autre vendeur, ni dans la liste ni via la policy ; même chose pour les clôtures sans cloture_caisse.view_toutes ; le job PDP lancé après TenantContext::clear() fonctionne ; EditFactureAchat d'une facture validée a assertActionHidden('delete') et la policy delete renvoie false.

### C136 [mineur] [tests] MarquerFacturesEnRetardTest : le cas « partiellement payée » n'en crée jamais, et la borne de l'échéance n'est pas testée
tests/Feature/Facturation/MarquerFacturesEnRetardTest.php:46
Scénario: Le test crée Paiement et PaiementAllocation directement, sans EnregistrerPaiementAction : la facture reste au statut « emise ». On peut retirer StatutFacture::PartiellementPayee du whereIn de la commande, ou remplacer '<' par '<=' (une facture échue aujourd'hui passerait alors en retard), et la suite reste verte.
Correction: Enregistrer le paiement partiel avec EnregistrerPaiementAction, puis vérifier que le statut vaut PartiellementPayee avant de lancer la commande. Ajouter un cas où l'échéance est aujourd'hui (date du jour à l'heure de Paris) et où la facture reste « emise ».

### C137 [mineur] [tests] Les tests de téléchargement PDF passent même si l'action ne renvoie rien
tests/Feature/Filament/FactureResourceTest.php:727
Scénario: Les actions de table telecharger_pdf (FactureResource:426, AvoirResource:186) peuvent ne rien renvoyer : les tests test_telecharger_le_pdf_dune_facture_emise et AvoirResourceTest:161 test_telecharger_le_pdf_dun_avoir_emis restent verts. Ils n'appellent que ->assertSuccessful().
Correction: Remplacer assertSuccessful() par ->assertFileDownloaded($generateur->nomFichier($facture)) (Livewire 3), et faire de même pour l'avoir avec AvoirPdfGenerator::nomFichier(). On peut aussi vérifier que le contenu commence par %PDF.

### C138 [mineur] [tests] Commentaires devenus faux
app/Domain/Tenancy/Services/TenantContext.php:14
Scénario: TenantContext.php:14 dit « bindé en singleton (voir TenancyServiceProvider) » alors que TenancyServiceProvider utilise scoped() et justifie ce choix contre singleton(). SeedMagasinRolesAction.php:15 dit qu'un propriétaire pourra « affiner via Filament Shield », mais aucun plugin Shield n'est enregistré dans les panneaux. Le docblock de ParametresFiscaux annonce la « même permission que la gestion du mandat PDP », alors qu'aucun écran ne crée ni ne révoque de mandat. SuperAdminPanelProvider annonce « gestion des magasins, abonnements, plans » alors que ses dossiers Resources, Pages et Widgets sont vides.
Correction: TenantContext : écrire « lié en scoped() » et retirer la mention de l'API Sanctum tant que l'API v1 n'existe pas. SeedMagasinRolesAction : renvoyer aux permissions supplémentaires de MembreEquipeResource. ParametresFiscaux : écrire « réservé à qui a la permission mandat_pdp.gerer (le propriétaire par défaut) ». SuperAdminPanelProvider : dire que le panneau est vide et fermé à tous (canAccessPanel renvoie false) jusqu'à l'étape 13.

### C139 [mineur] [tests] Code et schéma jamais utilisés
app/Domain/Authorization/PermissionRegistry.php:44
Scénario: La permission PRODUIT_AJUSTER_STOCK (« Produits : ajuster le stock ») et ProduitPolicy::ajusterStock() se cochent dans les permissions supplémentaires d'un membre, mais aucun écran ni aucune action ne les vérifie. CreerMandatPdpAction et RevokerMandatPdpAction ne sont appelées que par les tests : sans mandat, DeclencherTransmissionPdp* ne transmet jamais rien. La table journaux_audit n'a ni modèle ni écriture. Les paquets filament-shield et sanctum sont installés sans être utilisés (aucun HasApiTokens, aucune table personal_access_tokens), et la route sanctum/csrf-cookie est exposée.
Correction: Supprimer PRODUIT_AJUSTER_STOCK, son libellé et ProduitPolicy::ajusterStock() jusqu'à l'étape 7 (inventaire). Supprimer la migration journaux_audit et la recréer à l'étape 3. Brancher la création et la révocation du mandat dans Paramètres fiscaux, ou les laisser explicitement à l'intégration SUPER PDP (étape 11). Retirer sanctum et filament-shield, avec l'accord préalable de l'utilisateur, car le protocole interdit de changer les dépendances sans approbation.

### C140 [mineur] [tests] Outillage resté celui du squelette Laravel : CI qui ne se déclenche pas sur main, sans Pint, et seeder inutilisable
.github/workflows/tests.yml:5
Scénario: tests.yml ne se déclenche au push que sur master et '*.x', alors que la branche par défaut du dépôt est main (git ls-remote --symref : refs/heads/main). Après une fusion, seul le cron nocturne teste main. La CI ne lance pas Pint, pourtant exigé par le protocole. issues.yml, pull-requests.yml, update-changelog.yml et dependabot-auto-merge.yml appellent les workflows de l'organisation laravel. DatabaseSeeder crée un 'Test User' sans magasin, qui ne peut se connecter à aucun panneau (canAccessPanel renvoie false), et garde une ligne commentée. La route '/' sert la page d'accueil Laravel. La commande « inspire » (routes/console.php:6), README.md, CHANGELOG.md et le name/description de composer.json viennent aussi du squelette.
Correction: tests.yml : push sur main (et sur les branches de travail claude/*), plus une étape vendor/bin/pint --test. Supprimer les quatre workflows de l'organisation laravel. Remplacer DatabaseSeeder par un seeder de démonstration qui passe par CreerMagasinAction (étape 15). Retirer la route welcome et sa vue, la commande inspire, le README et le CHANGELOG du squelette, et renseigner name et description dans composer.json.

### C141 [important] [tests] [DECISION] (a) StockLedgerService::corrigerUneReception faux quand le stock était déjà un mélange
app/Domain/Stock/Services/StockLedgerService.php:85
Scénario: 10 articles reçus à 2 €, puis 10 à 10 € (CMUP 6), puis 10 vendus. La réception à 10 € est corrigée à 5 € : le CMUP passe à 1 au lieu de 3,5. Elle est ensuite corrigée de 5 € à 1 € : le CMUP reste à 1 au lieu de 1,5.
Correction: Ne revaloriser que la part de la réception encore en stock, au prorata (stock restant x quantité de la réception / quantité totale entrée au CMUP). Ajouter le scénario comme test de non-régression. Le magasin doit valider la règle de répartition (prorata CMUP).

### C142 [important] [tests] (b) coutMoyenPondere renvoie null pour des lots de coût nul : le CMUP garde l'ancien coût au lieu de 0
app/Domain/Facturation/Services/CalculateurLigne.php:236
Scénario: 10 articles reçus à 5 €, puis la réception est corrigée à 0 € (marchandise gratuite) : le CMUP reste à 5 au lieu de 0.
Correction: Ne renvoyer null que si la quantité totale est nulle ou négative. Une valeur nulle donne un CMUP de 0, et une valeur négative (retrait incohérent) se borne à 0. Mettre à jour le docblock de coutMoyenPondere et ajouter le cas au test.

### C143 [mineur] [tests] (c) ModifierFactureAchatAction : le dernier achat d'un produit non suivi est choisi par facture_achat_id, pas par date de validation
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:84
Scénario: F1 est créée avant F2. F2 est validée la veille à 6 €, F1 aujourd'hui à 5 € : le coût est 5. Si on corrige F1 à 5,5 €, le coût devient 6 (celui de F2) au lieu de 5,5.
Correction: Faire une jointure sur factures_achat et trier par factures_achat.date_validation desc, puis par facture_achat_id et id desc. Ajouter le scénario en test.

### C144 [important] [tests] (d) suivi_stock activé après une réception : la correction rend le stock négatif
app/Domain/Achats/Actions/ModifierFactureAchatAction.php:131
Scénario: Un produit sans suivi de stock est reçu en 10 exemplaires, puis on active suivi_stock. Si on corrige la facture de 10 à 2, le stock passe à -8 alors que rien n'était entré.
Correction: Construire les lotsAvant d'un produit suivi à partir des mouvements ReceptionAchat de cette facture (source_type/source_id). Un produit sans mouvement est traité comme une première réception de la quantité corrigée, ou comme une simple mise à jour du coût, selon la règle validée. Ajouter le scénario en test.

### C145 [mineur] [tests] (e) Barème : rouvrir une ligne d'achat avec « Modifier » perd prix_vente_propose
app/Filament/Concerns/AjouteDesLignesDAchat.php:251
Scénario: Une ligne d'achat dont le prix de vente vient du barème est rouverte avec « Modifier », puis son prix d'achat est changé : le prix de vente ne suit plus le barème.
Correction: Garder prix_vente_propose dans l'état de la ligne du repeater (le recopier dans ligneDepuisLeFormulaire, que ConstructeurLigneAchat ignore) et le remettre dans formulaireDepuisLaLigne(). Test : ligne au prix proposé, rouverte, prix d'achat passé au-delà du dernier palier, et prix de vente revenu à celui de la fiche.

### C146 [mineur] [tests] (f) Paramètres > Prix de vente : désactiver ne réinitialise pas les erreurs de validation
app/Filament/App/Pages/ParametresPrixDeVente.php:75
Scénario: On active le calcul, on vide le barème et on enregistre : une erreur s'affiche sur « Bénéfice au-delà ». Si on désactive ensuite le calcul, l'erreur reste affichée.
Correction: Dans afterStateUpdated, quand le calcul est désactivé, appeler $livewire->resetErrorBag() (injecter $livewire dans la closure), ou resetValidation() sur les champs du barème. Ajouter une assertion assertHasNoFormErrors() après la désactivation.

### C147 [mineur] [tests] (g) Essai du barème : une TVA laissée vide donne 0 au lieu de 20
app/Filament/App/Pages/ParametresPrixDeVente.php:165
Scénario: Prix d'achat d'essai 10 € : avec une TVA de 20, l'essai affiche 54,00 € TTC. Avec le champ TVA vidé, il affiche 45,00 € TTC.
Correction: Utiliser filled($get('essai_taux_tva')) ? (float) $get('essai_taux_tva') : 20.0, et faire de même aux lignes 137, 312 et 354 d'AjouteDesLignesDAchat. Dans appliquerLeBareme, ne rien proposer tant que la TVA est vide, plutôt que de calculer à 0 %.

### X0 [important] [None] Paiement : « Reçu le » refuse toute date passée mais accepte une date future
app/Filament/App/Resources/PaiementResource.php:85
Scénario: Facture pro émise de 120 €. Le vendeur veut enregistrer un virement vu sur le relevé d'hier, avec « Encaisser » ou « Paiements > Créer » et Reçu le = hier. Le formulaire refuse (« The reçu le field must be a date after or equal to 2026-10-09 00:00:00. ») et le calendrier ne propose même pas les jours passés. Avec Reçu le = 09/10/2027 (dans un an), le paiement est accepté et enregistré avec recu_le = 2027-10-09.
Correction: Sur les deux formulaires, remplacer ->minDate(today()) par ->maxDate(today()) (un paiement ne peut pas être reçu dans le futur) et ajouter le même contrôle dans EnregistrerPaiementAction. Ajouter un test pour une date passée (acceptée) et une date future (refusée).

### X1 [important] [None] [DECISION] Un paiement saisi par erreur ne peut jamais être corrigé : la correction annoncée (« enregistrer un nouveau paiement ») est impossible
app/Domain/Facturation/Actions/EnregistrerPaiementAction.php:43
Scénario: Facture de 100 €. Le vendeur tape 100 € en espèces au lieu de 10 € (ou se trompe de mode : espèces au lieu de CB, ou de facture). La facture passe « Payée ». PaiementResource dit qu'« une erreur se corrige en enregistrant un nouveau paiement », mais un paiement négatif est refusé (« Le montant alloué à une facture doit être strictement positif. »). Aucune page de modification ou de suppression n'existe, et « Encaisser » disparaît d'une facture payée. Le faux encaissement reste donc à vie, et le commentaire de Paiement::$fillable (« se corrige en ajustant ses allocations ») décrit une possibilité qui n'existe pas.
Correction: Prévoir un mécanisme explicite et tracé de contre-passation : un paiement d'annulation lié au paiement d'origine, qui retire ses allocations et recalcule le statut des factures. Puis aligner les deux commentaires sur ce mécanisme. Les règles (qui peut contre-passer, et si c'est permis après une clôture) sont à fixer par le magasin.

### X2 [important] [None] Devis : aucun PDF ni aucun document à remettre au client ; « Envoyer » ne change qu'un statut
app/Filament/App/Resources/DevisResource.php:193
Scénario: Un magasin crée un devis et clique « Envoyer » : le devis reçoit un numéro DEV-2026-… et passe « Envoyé », mais rien n'est produit. Il n'y a pas d'action PDF dans la liste, ViewDevis n'a aucune action d'en-tête, aucune vue resources/views/devis/ ni aucun générateur n'existe. Le client ne peut donc jamais recevoir le devis qu'il est censé accepter, alors que la feuille de route coche « Devis, factures, avoirs (...), PDF ».
Correction: Ajouter un DevisPdfGenerator et une vue devis/pdf.blade.php sur le modèle de la facture : mêmes mentions vendeur et client, lignes, ventilation de TVA, date de validité, conditions. Ajouter une action « PDF » dans la liste et sur ViewDevis, avec un test qui vérifie le contenu téléchargé.

### X3 [important] [None] Aucun moyen de changer ou de récupérer son mot de passe : un propriétaire qui l'oublie perd son magasin
app/Providers/Filament/AppPanelProvider.php:46
Scénario: Le propriétaire d'un magasin oublie son mot de passe. La page de connexion n'a pas de lien « Mot de passe oublié » et le panneau n'a pas de page de profil. Dans Équipe, les actions « Nouveau mot de passe », « Modifier » et « Retirer » sont masquées pour la ligne Propriétaire, et le panneau de l'éditeur est vide et inaccessible. Personne ne peut donc rétablir l'accès. De même, aucun membre ne peut changer lui-même un mot de passe compromis.
Correction: Activer ->passwordReset() (avec un mailer configuré) et ->profile() sur le panneau app, pour que chacun puisse changer son mot de passe (session ré-authentifiée par AuthenticateSession). Ajouter des tests HTTP pour la demande et la réinitialisation.

### X4 [important] [None] Tous les messages de validation s'affichent en anglais (avec la clé brute du champ ou une date technique)
config/app.php:83
Scénario: Interface en français (APP_LOCALE=fr) : vider le nom d'un client affiche « The nom field is required. ». Une date de paiement passée affiche « The reçu le field must be a date after or equal to 2026-10-09 00:00:00. ». Même chose pour chaque règle (max, min, numeric, unique...) de chaque formulaire, sauf les 5 champs qui ont un validationMessages() personnalisé.
Correction: Publier les fichiers de langue (php artisan lang:publish) et ajouter lang/fr/validation.php, traduit, avec les noms d'attributs lisibles, ou ajouter une dépendance de traduction après accord. Ajouter un test qui vérifie un message en français.

### X5 [important] [None] [DECISION] Changer de régime de TVA (ou une panne du planificateur) produit des déclarations e-reporting vides ou qui se chevauchent, et laisse des clôtures jamais déclarées
app/Console/Commands/SoumettreEReportingPeriodique.php:45
Scénario: Magasin en réel normal mensuel : les décades 01-10/09, 11-20/09, 21-30/09 et 01-10/10 sont déclarées. Le 12/10 il passe au réel simplifié : la commande génère une soumission « 01/09-30/09 » avec 0 clôture, transmise au PDP comme une déclaration vide d'un mois déjà déclaré, puis le 01/11 une soumission « 01/10-31/10 » qui chevauche la décade 01-10/10 déjà transmise. Autre cas : un magasin en franchise passe au réel normal mensuel le 31/12 à 23h30, avant le calcul du bimestre novembre-décembre (le 01/01 à 01h). Ses clôtures du 05/11 et du 15/12 ne sont jamais déclarées : la commande ne calcule que la dernière période du régime actuel, ici 21-31/12. Pour la même raison, si le planificateur ne tourne pas pendant plus d'une période, les clôtures des périodes sautées restent orphelines.
Correction: Faire générer par la commande toutes les périodes terminées qui contiennent encore des clôtures sans soumission (rattrapage), au lieu de la seule dernière période. Ne pas générer de période qui chevauche une soumission existante. Appliquer un changement de régime à partir du début de la période suivante. La conduite exacte (date d'effet du changement de régime, déclaration à zéro ou non) est à fixer avec le magasin.

### X6 [mineur] [None] [DECISION] Retours partiels d'une ligne remisée : les avoirs intermédiaires créditent trop de TVA et le dernier avoir a une TVA négative
app/Domain/Facturation/Models/LigneFacture.php:125
Scénario: Ticket : 20 articles à 0,51 € TTC (TVA 5,5 %, PU HT 0,48 €) avec 40 % de remise ; la ligne vaut 5,76 € HT + 0,36 € TVA = 6,12 € TTC. Le client les rend un par un, avec 20 avoirs successifs. Après 19 retours, 0,38 € de TVA ont été crédités alors que la facture n'en contenait que 0,36 €. Le 20e avoir porte 0,25 € HT, -0,02 € de TVA et 0,23 € TTC : HT supérieur au TTC, et TVA négative transmise dans la clôture et l'e-reporting.
Correction: Calculer chaque retour partiel par différence de cumul : montants(quantité déjà créditée + q) moins montants(quantité déjà créditée), chacun calculé comme la ligne. Le total crédité ne peut alors jamais dépasser la ligne, ni rendre un montant négatif. Règle de calcul à valider par le magasin.

### X7 [mineur] [None] Un acompte sur une facture « En retard » efface le retard jusqu'au lendemain 00h15
app/Domain/Facturation/Models/Facture.php:207
Scénario: Facture pro échue le 01/10, marquée « En retard » par la tâche du 05/10 à 00h15. Le 05/10 à 10h, le client verse un acompte de 10 € sur 100 € : la facture repasse « Partiellement payée » et sort du filtre « En retard » toute la journée, alors que l'échéance est dépassée et qu'il reste 90 € dus. Elle redevient « En retard » à la tâche suivante, et l'imputation d'un avoir partiel produit le même effet.
Correction: Dans constaterUnReglement(), garder EnRetard tant que montantRestant() > 0 et que date_echeance < aujourd'hui ; passer à Payee seulement quand le solde est nul. Ajouter le test correspondant.

### X8 [mineur] [None] [DECISION] Taux de TVA libre entre 0 et 100 % : 19,6 %, 7 % ou une faute de frappe (2 au lieu de 20) sont acceptés partout
app/Filament/Concerns/SaisitLesPrixDeLigne.php:63
Scénario: Sur la fiche produit, ou sur une ligne de caisse, de facture ou de devis, le vendeur saisit 2 au lieu de 20, ou un ancien taux (19,6 %). C'est accepté, enregistré, imprimé sur la facture et cumulé dans la clôture et l'e-reporting avec un taux qui n'existe pas en France.
Correction: Remplacer la saisie libre par un choix parmi les taux en vigueur (enum), appliqué aux formulaires et dans ConstructeurLigneDocument et ConstructeurLigneAchat. La liste exacte (métropole, Corse, DOM) est à fixer par le magasin.

### X9 [mineur] [None] Code mort : dépendances filament-shield et sanctum installées mais inutilisées (route /sanctum/csrf-cookie et commandes shield:* exposées)
composer.json:11
Scénario: Aucun code n'utilise Filament Shield (plugin non enregistré, aucune config) ni Sanctum (pas de HasApiTokens, pas de config, pas de table personal_access_tokens). Leurs fournisseurs de services se chargent pourtant : la route GET /sanctum/csrf-cookie existe, et les commandes shield:generate, shield:install et shield:super-admin sont proposées. Un `shield:generate --all` réécrirait les policies du magasin. Le docblock de TenantContext mentionne une « API (token Sanctum scopé) » qui n'existe pas.
Correction: Avec l'accord de l'utilisateur (changement de dépendances), retirer bezhansalleh/filament-shield et laravel/sanctum de composer.json, puis corriger les deux commentaires. Sanctum reviendra avec l'étape 10 (API v1) si elle l'utilise.
