# Devis accepté en ligne : cadre « Bon pour accord » rempli, PDF signé (demandes de l'éditeur du 14/11/2026 vers 00 h UTC)

1. **Cadre « Date et signature du client, précédées de la mention « Bon pour accord » » du PDF du devis.**
   - Une fois le devis accepté en ligne, le cadre porte la date et l'heure de l'acceptation (heure de Paris) et le nom et le prénom tels que le client les a tapés.
   - Présentation : le modèle choisi par l'éditeur parmi A–D (https://claude.ai/artifact/Y8hGptGyzZqH76miqD7N9q).
   - **MODÈLE CHOISI : D1 (14/11/2026, vers 00 h 20 UTC), dans l'ordre du cadre :**
     - le titre du cadre, inchangé ;
     - un cachet sobre « Accepté en ligne » : petit cadre vert fin, droit, en minuscules ;
     - « Bon pour accord » en gras ;
     - « Le 14/11/2026 à 00:05 (heure de Paris) » ;
     - le nom et le prénom en texte simple, en gras, sans écriture manuscrite ;
     - sous un trait fin : « Signature électronique : nom tapé et case « Bon pour accord » cochée par le client, sur le lien personnel reçu à son adresse e-mail » ;
     - « Référence de l'acceptation : … · Empreinte du devis accepté : … ». L'empreinte est celle du contenu du devis accepté, et l'acceptation est déjà enregistrée par le lot DV.
     - Modèle de référence : `../modeles/signature-devis.html`, section D1.
   - Un devis pas encore accepté, refusé, expiré ou repassé en brouillon garde le cadre vide.
   - L'adresse IP et le navigateur ne s'impriment jamais.
2. **Avant la réponse, le client ne reçoit ni ne voit aucun PDF du devis** (précision de l'éditeur du 14/11 vers 00 h 25 UTC : « le client voit le devis là où il signe et ne voit le devis en PDF que quand il accepte ») :
   - l'e-mail d'envoi d'un devis n'a plus le PDF en pièce jointe. Son texte devient : « Votre devis DEV-… d'un montant de … TTC vous attend en ligne : vous pouvez le lire, puis l'accepter ou le refuser. » Il ne dit plus « ci-joint ». Le bouton « Répondre au devis » reste, avec les mêmes phrases sur le lien personnel ;
   - la page publique montre le devis en entier (magasin, client, lignes, totaux, validité, mentions), mais pas le lien « Télécharger le devis en PDF » ;
   - une relance ou un nouvel envoi du même devis suit la même règle ;
   - dans le magasin, la fiche du devis garde son PDF (aperçu, impression, remise en main propre), qui ne change pas.
3. **Après l'acceptation :**
   - la page publique montre « Devis accepté le … par … » et le lien vers le PDF signé, valable tant que le lien de la page l'est ;
   - un e-mail de confirmation part à l'adresse de l'envoi, avec le PDF signé en pièce jointe. Il suit les règles des e-mails aux clients : magasin qui peut écrire aux clients, plafonds, nouvel essai et cause de l'échec dite en clair.
   - Après un refus : rien de plus que le message actuel.
4. **Tests :** domaine, HTTP de la page publique (lien caché avant et montré après), PDF (contenu du cadre), e-mail de confirmation, navigateur et inventaire des actions. Le lien de la page reste caduc dès la réponse, comme aujourd'hui, sauf pour lire la confirmation et télécharger le PDF signé.
