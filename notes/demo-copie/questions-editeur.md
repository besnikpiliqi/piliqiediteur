# DEMO-COPIE : les questions pour vous

Seulement ce qui vous revient : la loi qui touche votre décision, l'argent, le nom de domaine, ce que verront vos clients. Tout le reste (technique, sécurité, tests) est tranché dans le cahier. Le lot part de ma recommandation pour chaque question ; votre réponse prime et change le cahier.

## 1. La loi demande de garder la caisse d'une démonstration (touche votre choix Q3)

Vous avez dit : la copie est supprimée en entier au bout de 30 jours. Mais la démonstration fait tourner la vraie caisse sécurisée, avec de fausses ventes. Pour l'administration fiscale, c'est un « mode école ». Le BOFiP (BOI-TVA-DECLA-30-10-30, § 50, 130, 155 et 180) et le référentiel LNE (exigences 5, 13 et 16) demandent de garder ces données six ans, et pas seulement les tickets Z. C'est pour cette raison que, le 12/11, vous aviez renoncé à supprimer la démonstration (D70).

- **(a) Recommandé, appliqué par défaut :** avant de supprimer une copie, PILIQI fait une archive signée de sa caisse (registre, journal, tickets Z), la vérifie, la range six ans hors de toute base, puis supprime la base en entier. Vos clients voient toujours « supprimée le … ». L'archive ne contient rien de la vraie société. Coût : un peu de place disque (quelques Mo par copie, à mesurer), à sauvegarder six ans.
- **(b)** Suppression pure, sans archive. Plus simple, mais probablement contraire au BOFiP. Le risque serait écrit dans la notice et dans l'onglet « À faire vérifier par un professionnel ».

Votre choix : (a) ou (b) ?

## 2. Le nom de domaine du site de démonstration

Vous aviez donné l'exemple demo.piliqi.com. Pour la sécurité, le site de démonstration doit avoir **son propre nom de domaine**, jamais un sous-domaine de piliqi.com. Raison simple : n'importe qui avec un lien tape ce qu'il veut dans une démonstration ; si une faille s'y trouvait, un sous-domaine permettrait de toucher les témoins de connexion du vrai site.

- **Recommandé :** `piliqi-demo.fr` (ou `piliqi-demo.com`), environ 10 à 15 € par an. À acheter avant l'étape 15 ; sur votre PC, rien à acheter.

Votre choix de nom ?

## 3. Un seul bouton pour le lien : « Renouveler le lien »

Le site réel ne garde que l'empreinte du lien (votre choix Q1) : il ne peut pas renvoyer le même lien. Un bouton « M'envoyer le lien par e-mail » devrait donc en tirer un nouveau, et couperait toute l'équipe à qui vous l'aviez donné.

- **Recommandé, appliqué par défaut :** un seul bouton, « Renouveler le lien ». Il affiche le nouveau lien et l'envoie par e-mail ; sa fenêtre dit que l'ancien cesse de marcher pour tous.
- Autre voie : garder le lien chiffré sur le site réel pour pouvoir le renvoyer tel quel. Plus pratique, mais moins sûr, et contraire à « le site réel ne garde que son empreinte ».

D'accord avec un seul bouton ?

## 4. Combien de démonstrations en même temps, et pour qui

Chaque démonstration est une base à part : elle prend de la place et de la mémoire. Le lot part de :
- **200 démonstrations au plus en même temps** (à revoir après les mesures du lot) ; au-delà, la demande attend une place, elle n'est jamais refusée ;
- **la moitié des places réservée aux sociétés validées**, pour qu'une vague de fausses inscriptions ne bloque pas les vrais clients ;
- au plus 3 créations par heure et 10 en 30 jours depuis une même adresse internet.

Recommandé : ces chiffres, revus avec le coût d'hébergement à l'étape 15. D'accord ?

## 5. Une machine à part pour la démonstration (coût d'hébergement)

En production, la démonstration doit tourner sur **sa propre machine et son propre serveur PostgreSQL**, jamais sur ceux du site réel. Sinon, une démonstration qui grossit pourrait remplir le disque et arrêter le vrai PILIQI, et les sauvegardes du réel contiendraient les démonstrations.

- **Recommandé :** un serveur dédié à la démonstration, de taille moyenne (par exemple 4 processeurs, 8 à 16 Go de mémoire, 200 Go de disque rapide), à chiffrer chez votre hébergeur à l'étape 15 (ordre de grandeur : quelques dizaines d'euros par mois). Le nombre de démonstrations (question 4) se règle sur cette machine.

D'accord sur le principe d'une machine à part ?

## 6. La démonstration adaptée au régime de TVA de la vraie société ?

Une démonstration montre une société fictive au réel normal mensuel. Une société en franchise en base (ou au trimestriel, ou avec l'option pour les débits) peut changer le régime dans sa démonstration, comme dans le vrai logiciel, et voir aussitôt les mentions et les déclarations de son régime.

- **Recommandé, appliqué par défaut :** ne rien transmettre de la vraie société (votre choix Q4, au plus strict), et laisser le visiteur changer le régime dans sa démonstration ; une phrase le lui dit.
- Autre voie : transmettre seulement le régime de TVA de la vraie société, pour que sa démonstration démarre dans le même régime. Pratique, mais c'est une donnée de la vraie société qui passerait.

Votre choix ?

## 7. Ce que montre une démonstration (suite de votre précision de 1 h 30)

Puisque chaque société ne voit que les comptes de sa propre société fictive, une démonstration « Commerce ou atelier » montre **une seule société** : MAISON FERRAND, avec trois magasins (dont un à Fort-de-France), toute l'équipe, l'expert-comptable déclaré et treize mois de données. Les cas des autres sociétés du semis de votre PC (magasin cédé, fermé, suspendu par l'éditeur, Corse, La Réunion, assujetti unique, SIREN corrigé…) ne se voient pas dans les démonstrations ; ils restent sur votre PC.

- **Recommandé :** garder ainsi (une société claire, sans rien qui parle d'une autre). Si vous voulez un de ces cas dans les démonstrations, dites lequel : je l'ajoute à la société fictive.

D'accord ?

## 8. La durée du lot

L'estimation donnée hier soir (10 à 14 heures) était trop basse. En comptant tout ce que le lot doit faire (retrait du magasin de démonstration actuel, second site, copies, liens, bandeau, e-mails, archive, mises à jour, tests), il faut compter **45 à 65 heures**, en trois lots relus chacun à part :
- DEMO-COPIE-1 : retrait du magasin de démonstration actuel (10 à 14 heures) ;
- DEMO-COPIE-2 : site de démonstration, copies, demande depuis le compte (18 à 26 heures) ;
- DEMO-COPIE-3 : bandeau « Vous êtes : », e-mails, mises à jour, tests en navigateur (15 à 22 heures).

Recommandé : garder la place (après TVA27, avant RLS) et ces trois lots. Entre le premier et le deuxième, aucune société n'a de démonstration (rien n'est encore en production). D'accord ?
