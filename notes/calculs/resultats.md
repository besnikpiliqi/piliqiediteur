# Écarts visibles à la calculatrice : calculs exhaustifs

Programme : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/scripts/calculs_exhaustifs.py`, Python, entiers seulement (centimes, millionièmes d'euro, centièmes de point), arrondi au plus proche, demi-centime au centime supérieur (copie de `CalculateurLigne::diviserArrondi()`). Avant l'énumération, il refait les cas des tests PHP (`tests/Unit/CalculateurLigneTest.php` : 50,00 € → 41,67 + 8,33 ; 2 × 25,00 € → 20,83 et 20,84 ; 4 × 65,00 € → 216,67 ; retours 33,33 + 6,67 puis 33,34 + 6,66 ; PU HT 0,833333, 8,325, 9,478673…) et le cas de l'éditeur : ses calculs sont donc ceux du code.

Relancer : `python3 -I /tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/scripts/calculs_exhaustifs.py` (durée de ce passage : 398 s). Résultats bruts : `/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad/nouvelle-session/calculs/resultats.json`.

## Domaine énuméré

- PU TTC : tous les centimes de 0,01 € à 20,00 € (2 000 prix), puis un pas fixe de 0,07 € de 20,07 € à 199,97 € (2 571 prix), plus 200,00 € : 4 572 prix. Le pas de 7 centimes est premier avec 6, 11, 217, 211 et 1 021 (les dénominateurs de 100/120, 100/110, 100/108,5, 100/105,5 et 100/102,1) : il parcourt tous les restes.
- Quantités : 1 à 100 (domaines à une ligne et à deux lignes identiques) ; 1, 2 ou 3 par ligne (domaines D5 et D6).
- Taux : 20 %, 10 %, 8,5 %, 5,5 %, 2,1 %.
- D5 : deux lignes de PU TTC pris de 0,01 € à 199,98 € par pas de 1,01 € (198 prix, chaque couple ordonné), même taux. D6 : trois lignes, PU TTC de 0,01 € à 198,02 € par pas de 2,11 € (94 prix), deux taux différents (20 couples ordonnés), la 3e ligne identique à la 1re.
- Remises : la remise de PILIQI est un pourcentage (`remise_pourcentage`, arrondie une fois sur le TTC de la ligne) : D2 la teste à 10 % et 15 %. D3 teste une remise en euros (0,01 € et 1,00 €, toujours inférieure au HT de la ligne) : en (iii) c'est une remise HT ; en (i) et (ii), une remise TTC, imprimée par hypothèse en HT (remise ÷ (1 + taux), arrondie).

| Domaine | Description | Documents |
|---|---|---:|
| D1 | une ligne, sans remise | 2 286 000 |
| D2 | une ligne, remise en % (10 % et 15 %, la remise de PILIQI) | 4 572 000 |
| D3 | une ligne, remise en euros (0,01 € et 1,00 €) | 4 569 408 |
| D4 | deux lignes identiques, sans remise | 2 286 000 |
| D5 | deux lignes différentes, même taux, sans remise | 1 782 045 |
| D6 | trois lignes, deux taux, la 3e identique à la 1re | 1 624 500 |

Un « écart visible » est une vérification qu'une personne refait avec une calculatrice sur le PDF et qui ne retombe pas sur le montant imprimé, le résultat de la calculatrice étant arrondi au centime le plus proche. Les PDF de PILIQI impriment, pour une facture à un professionnel : par ligne la quantité, le PU HT, la remise en %, le taux et le total HT, puis par taux la base HT, la TVA et le TTC, puis les totaux HT, TVA et TTC (`TableauDesLignes`, `documents/pdf/totaux.blade.php`) ; pour un ticket : par ligne le PU TTC et le total TTC, puis les mêmes récapitulatifs.

## 0. Exemples à prix courants

Chaque document tel que le PDF l'imprime, et ce que redonne la calculatrice. Ticket : règle actuelle, pour un particulier. (i) : facture actuelle à un professionnel (PU HT imprimé au centime). (iii) : méthode HT.

### Facture de l'éditeur (FAC-PARIS-2026-000001)

- **Ticket (particulier)** — TTC payé 50,00 € — aucun écart
  - ligne : 2 × 10,00 € = 20,00 € à la calculatrice, 20,00 € imprimé (juste)
  - ligne : 1 × 30,00 € = 30,00 € à la calculatrice, 30,00 € imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(i) Facture actuelle** — TTC payé 50,00 € — écarts : ligne
  - ligne : 2 × 8,33 € = 16,66 € à la calculatrice, 16,67 € HT imprimé (ÉCART)
  - ligne : 1 × 25,00 € = 25,00 € à la calculatrice, 25,00 € HT imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(ii) PU HT à 6 décimales** — TTC payé 50,00 € — aucun écart
  - ligne : 2 × 8,333333 € = 16,67 € à la calculatrice, 16,67 € HT imprimé (juste)
  - ligne : 1 × 25,000000 € = 25,00 € à la calculatrice, 25,00 € HT imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(iii) Méthode HT** — TTC payé 49,99 € — aucun écart
  - ligne : 2 × 8,33 € = 16,66 € à la calculatrice, 16,66 € HT imprimé (juste)
  - ligne : 1 × 25,00 € = 25,00 € à la calculatrice, 25,00 € HT imprimé (juste)
  - 20 % : base 41,66 €, TVA 8,33 € (base × taux = 8,332 € → 8,33 €, juste), TTC 49,99 €

### Un article à 9,99 € TTC

- **Ticket (particulier)** — TTC payé 9,99 € — écarts : tva
  - ligne : 1 × 9,99 € = 9,99 € à la calculatrice, 9,99 € imprimé (juste)
  - 20 % : base 8,33 €, TVA 1,66 € (base × taux = 1,666 € → 1,67 €, ÉCART), TTC 9,99 €
- **(i) Facture actuelle** — TTC payé 9,99 € — écarts : tva
  - ligne : 1 × 8,33 € = 8,33 € à la calculatrice, 8,33 € HT imprimé (juste)
  - 20 % : base 8,33 €, TVA 1,66 € (base × taux = 1,666 € → 1,67 €, ÉCART), TTC 9,99 €
- **(ii) PU HT à 6 décimales** — TTC payé 9,99 € — écarts : tva
  - ligne : 1 × 8,325000 € = 8,33 € à la calculatrice, 8,33 € HT imprimé (juste)
  - 20 % : base 8,33 €, TVA 1,66 € (base × taux = 1,666 € → 1,67 €, ÉCART), TTC 9,99 €
- **(iii) Méthode HT** — TTC payé 10,00 € — aucun écart
  - ligne : 1 × 8,33 € = 8,33 € à la calculatrice, 8,33 € HT imprimé (juste)
  - 20 % : base 8,33 €, TVA 1,67 € (base × taux = 1,666 € → 1,67 €, juste), TTC 10,00 €

### 4 articles à 65,00 € TTC

- **Ticket (particulier)** — TTC payé 260,00 € — aucun écart
  - ligne : 4 × 65,00 € = 260,00 € à la calculatrice, 260,00 € imprimé (juste)
  - 20 % : base 216,67 €, TVA 43,33 € (base × taux = 43,334 € → 43,33 €, juste), TTC 260,00 €
- **(i) Facture actuelle** — TTC payé 260,00 € — écarts : ligne
  - ligne : 4 × 54,17 € = 216,68 € à la calculatrice, 216,67 € HT imprimé (ÉCART)
  - 20 % : base 216,67 €, TVA 43,33 € (base × taux = 43,334 € → 43,33 €, juste), TTC 260,00 €
- **(ii) PU HT à 6 décimales** — TTC payé 260,00 € — aucun écart
  - ligne : 4 × 54,166667 € = 216,67 € à la calculatrice, 216,67 € HT imprimé (juste)
  - 20 % : base 216,67 €, TVA 43,33 € (base × taux = 43,334 € → 43,33 €, juste), TTC 260,00 €
- **(iii) Méthode HT** — TTC payé 260,02 € — aucun écart
  - ligne : 4 × 54,17 € = 216,68 € à la calculatrice, 216,68 € HT imprimé (juste)
  - 20 % : base 216,68 €, TVA 43,34 € (base × taux = 43,336 € → 43,34 €, juste), TTC 260,02 €

### 50,00 € TTC en une ligne

- **Ticket (particulier)** — TTC payé 50,00 € — aucun écart
  - ligne : 1 × 50,00 € = 50,00 € à la calculatrice, 50,00 € imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(i) Facture actuelle** — TTC payé 50,00 € — aucun écart
  - ligne : 1 × 41,67 € = 41,67 € à la calculatrice, 41,67 € HT imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(ii) PU HT à 6 décimales** — TTC payé 50,00 € — aucun écart
  - ligne : 1 × 41,666667 € = 41,67 € à la calculatrice, 41,67 € HT imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(iii) Méthode HT** — TTC payé 50,00 € — aucun écart
  - ligne : 1 × 41,67 € = 41,67 € à la calculatrice, 41,67 € HT imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €

### 50,00 € TTC en 2 × 25,00 €

- **Ticket (particulier)** — TTC payé 50,00 € — aucun écart
  - ligne : 2 × 25,00 € = 50,00 € à la calculatrice, 50,00 € imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(i) Facture actuelle** — TTC payé 50,00 € — écarts : ligne
  - ligne : 2 × 20,83 € = 41,66 € à la calculatrice, 41,67 € HT imprimé (ÉCART)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(ii) PU HT à 6 décimales** — TTC payé 50,00 € — aucun écart
  - ligne : 2 × 20,833333 € = 41,67 € à la calculatrice, 41,67 € HT imprimé (juste)
  - 20 % : base 41,67 €, TVA 8,33 € (base × taux = 8,334 € → 8,33 €, juste), TTC 50,00 €
- **(iii) Méthode HT** — TTC payé 49,99 € — aucun écart
  - ligne : 2 × 20,83 € = 41,66 € à la calculatrice, 41,66 € HT imprimé (juste)
  - 20 % : base 41,66 €, TVA 8,33 € (base × taux = 8,332 € → 8,33 €, juste), TTC 49,99 €

### Un article à 9,95 € TTC remisé de 10 %

- **Ticket (particulier)** — TTC payé 8,95 € — écarts : ligne
  - ligne : 1 × 9,95 € remisé de 10 % = 8,96 € à la calculatrice, 8,95 € imprimé (ÉCART)
  - 20 % : base 7,46 €, TVA 1,49 € (base × taux = 1,492 € → 1,49 €, juste), TTC 8,95 €
- **(i) Facture actuelle** — TTC payé 8,95 € — aucun écart
  - ligne : 1 × 8,29 € − 10 % = 7,46 € à la calculatrice, 7,46 € HT imprimé (juste)
  - 20 % : base 7,46 €, TVA 1,49 € (base × taux = 1,492 € → 1,49 €, juste), TTC 8,95 €
- **(ii) PU HT à 6 décimales** — TTC payé 8,95 € — aucun écart
  - ligne : 1 × 8,291667 € − 10 % = 7,46 € à la calculatrice, 7,46 € HT imprimé (juste)
  - 20 % : base 7,46 €, TVA 1,49 € (base × taux = 1,492 € → 1,49 €, juste), TTC 8,95 €
- **(iii) Méthode HT** — TTC payé 8,95 € — aucun écart
  - ligne : 1 × 8,29 € − 10 % = 7,46 € à la calculatrice, 7,46 € HT imprimé (juste)
  - 20 % : base 7,46 €, TVA 1,49 € (base × taux = 1,492 € → 1,49 €, juste), TTC 8,95 €

### Panier à trois taux (test « courses » de CalculateurLigneTest)

- **Ticket (particulier)** — TTC payé 66,67 € — aucun écart
  - ligne : 3 × 12,90 € = 38,70 € à la calculatrice, 38,70 € imprimé (juste)
  - ligne : 2 × 3,99 € = 7,98 € à la calculatrice, 7,98 € imprimé (juste)
  - ligne : 1 × 19,99 € = 19,99 € à la calculatrice, 19,99 € imprimé (juste)
  - 10 % : base 35,18 €, TVA 3,52 € (base × taux = 3,518 € → 3,52 €, juste), TTC 38,70 €
  - 5,5 % : base 7,56 €, TVA 0,42 € (base × taux = 0,4158 € → 0,42 €, juste), TTC 7,98 €
  - 20 % : base 16,66 €, TVA 3,33 € (base × taux = 3,332 € → 3,33 €, juste), TTC 19,99 €
- **(i) Facture actuelle** — TTC payé 66,67 € — écarts : ligne
  - ligne : 3 × 11,73 € = 35,19 € à la calculatrice, 35,18 € HT imprimé (ÉCART)
  - ligne : 2 × 3,78 € = 7,56 € à la calculatrice, 7,56 € HT imprimé (juste)
  - ligne : 1 × 16,66 € = 16,66 € à la calculatrice, 16,66 € HT imprimé (juste)
  - 10 % : base 35,18 €, TVA 3,52 € (base × taux = 3,518 € → 3,52 €, juste), TTC 38,70 €
  - 5,5 % : base 7,56 €, TVA 0,42 € (base × taux = 0,4158 € → 0,42 €, juste), TTC 7,98 €
  - 20 % : base 16,66 €, TVA 3,33 € (base × taux = 3,332 € → 3,33 €, juste), TTC 19,99 €
- **(ii) PU HT à 6 décimales** — TTC payé 66,67 € — aucun écart
  - ligne : 3 × 11,727273 € = 35,18 € à la calculatrice, 35,18 € HT imprimé (juste)
  - ligne : 2 × 3,781991 € = 7,56 € à la calculatrice, 7,56 € HT imprimé (juste)
  - ligne : 1 × 16,658333 € = 16,66 € à la calculatrice, 16,66 € HT imprimé (juste)
  - 10 % : base 35,18 €, TVA 3,52 € (base × taux = 3,518 € → 3,52 €, juste), TTC 38,70 €
  - 5,5 % : base 7,56 €, TVA 0,42 € (base × taux = 0,4158 € → 0,42 €, juste), TTC 7,98 €
  - 20 % : base 16,66 €, TVA 3,33 € (base × taux = 3,332 € → 3,33 €, juste), TTC 19,99 €
- **(iii) Méthode HT** — TTC payé 66,68 € — aucun écart
  - ligne : 3 × 11,73 € = 35,19 € à la calculatrice, 35,19 € HT imprimé (juste)
  - ligne : 2 × 3,78 € = 7,56 € à la calculatrice, 7,56 € HT imprimé (juste)
  - ligne : 1 × 16,66 € = 16,66 € à la calculatrice, 16,66 € HT imprimé (juste)
  - 10 % : base 35,19 €, TVA 3,52 € (base × taux = 3,519 € → 3,52 €, juste), TTC 38,71 €
  - 5,5 % : base 7,56 €, TVA 0,42 € (base × taux = 0,4158 € → 0,42 €, juste), TTC 7,98 €
  - 20 % : base 16,66 €, TVA 3,33 € (base × taux = 3,332 € → 3,33 €, juste), TTC 19,99 €

### 7 articles à 12,00 € TTC (HT juste : 10,00 €)

- **Ticket (particulier)** — TTC payé 84,00 € — aucun écart
  - ligne : 7 × 12,00 € = 84,00 € à la calculatrice, 84,00 € imprimé (juste)
  - 20 % : base 70,00 €, TVA 14,00 € (base × taux = 14,00 € → 14,00 €, juste), TTC 84,00 €
- **(i) Facture actuelle** — TTC payé 84,00 € — aucun écart
  - ligne : 7 × 10,00 € = 70,00 € à la calculatrice, 70,00 € HT imprimé (juste)
  - 20 % : base 70,00 €, TVA 14,00 € (base × taux = 14,00 € → 14,00 €, juste), TTC 84,00 €
- **(ii) PU HT à 6 décimales** — TTC payé 84,00 € — aucun écart
  - ligne : 7 × 10,000000 € = 70,00 € à la calculatrice, 70,00 € HT imprimé (juste)
  - 20 % : base 70,00 €, TVA 14,00 € (base × taux = 14,00 € → 14,00 €, juste), TTC 84,00 €
- **(iii) Méthode HT** — TTC payé 84,00 € — aucun écart
  - ligne : 7 × 10,00 € = 70,00 € à la calculatrice, 70,00 € HT imprimé (juste)
  - 20 % : base 70,00 €, TVA 14,00 € (base × taux = 14,00 € → 14,00 €, juste), TTC 84,00 €

### Retour des 2 coques, une par une

- Règle actuelle (facture 16,67 € HT + 3,33 €) : avoir 1 8,33 € HT + 1,67 € ; avoir 2 8,34 € HT + 1,66 €. Sur l'avoir 2 d'un professionnel : 1 × 8,33 € = 8,33 € ≠ 8,34 € HT imprimé, et 8,34 € × 20 % = 1,668 € → 1,67 € ≠ 1,66 €.
- Méthode HT, option A (facture 16,66 € HT + 3,33 €) : avoir 1 8,33 € HT + 1,67 € ; avoir 2 8,33 € HT + 1,66 € : 8,33 € × 20 % = 1,666 € → 1,67 € ≠ 1,66 € (écart visible sur l'avoir 2, somme exacte).
- Méthode HT, option B : chaque avoir 8,33 € HT + 1,67 € (juste à la calculatrice) ; ensemble ils rendent 3,34 € de TVA pour 3,33 € facturés.

## 1. Factures à un professionnel

### D1 — une ligne, sans remise (2 286 000 documents)

| Vérification | (i) règle actuelle | (ii) PU HT à 6 décimales | (iii) méthode HT |
|---|---:|---:|---:|
| PU HT imprimé × qté (− remise) ≠ total HT de la ligne | 2 079 683 (90,975 %) | 13 966 (0,611 %) | 0 (0,000 %) |
| Σ lignes ≠ base du taux ou Σ bases ≠ total HT | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| base du taux × taux, arrondi ≠ TVA du taux | 169 401 (7,410 %) | 169 401 (7,410 %) | 0 (0,000 %) |
| HT + TVA ≠ TTC (par taux ou au total) | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| documents avec au moins un écart visible | 2 085 523 (91,230 %) | 169 401 (7,410 %) | 0 (0,000 %) |
| *pour information, (iii) : TTC du taux ÷ (1 + taux), arrondi ≠ base* | | | 0 (0,000 %) |

Documents avec au moins un écart visible, par taux :

| Taux | Documents | (i) | (ii) | (iii) |
|---|---:|---:|---:|---:|
| 20 % | 457 200 | 376 527 (82,355 %) | 64 008 (14,000 %) | 0 (0,000 %) |
| 10 % | 457 200 | 408 220 (89,287 %) | 37 829 (8,274 %) | 0 (0,000 %) |
| 8,5 % | 457 200 | 432 623 (94,624 %) | 34 319 (7,506 %) | 0 (0,000 %) |
| 5,5 % | 457 200 | 434 322 (94,996 %) | 23 837 (5,214 %) | 0 (0,000 %) |
| 2,1 % | 457 200 | 433 831 (94,889 %) | 9 408 (2,058 %) | 0 (0,000 %) |

Plus petits exemples (ordre d'énumération : PU TTC, puis quantité) :

- (i) `ligne` : 4 × 0,01 € TTC à 20 % — ligne 1 : PU HT imprimé 0,01 €, 4 × 0,01 € = 0,04 € à la calculatrice, total HT imprimé 0,03 €
- (i) `tva` : 3 × 0,01 € TTC à 20 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- (ii) `ligne` : 3 × 0,01 € TTC à 20 % — ligne 1 : PU HT imprimé 0,008333 €, 3 × 0,008333 € = 0,02 € à la calculatrice, total HT imprimé 0,03 €
- (ii) `tva` : 3 × 0,01 € TTC à 20 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)

### D2 — une ligne, remise en % (10 % et 15 %, la remise de PILIQI) (4 572 000 documents)

| Vérification | (i) règle actuelle | (ii) PU HT à 6 décimales | (iii) méthode HT |
|---|---:|---:|---:|
| PU HT imprimé × qté (− remise) ≠ total HT de la ligne | 4 172 115 (91,254 %) | 940 577 (20,573 %) | 430 685 (9,420 %) |
| Σ lignes ≠ base du taux ou Σ bases ≠ total HT | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| base du taux × taux, arrondi ≠ TVA du taux | 353 776 (7,738 %) | 353 776 (7,738 %) | 0 (0,000 %) |
| HT + TVA ≠ TTC (par taux ou au total) | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| documents avec au moins un écart visible | 4 207 933 (92,037 %) | 1 163 511 (25,449 %) | 430 685 (9,420 %) |
| *pour information, (iii) : TTC du taux ÷ (1 + taux), arrondi ≠ base* | | | 0 (0,000 %) |

Documents avec au moins un écart visible, par taux :

| Taux | Documents | (i) | (ii) | (iii) |
|---|---:|---:|---:|---:|
| 20 % | 914 400 | 782 432 (85,568 %) | 246 957 (27,008 %) | 76 195 (8,333 %) |
| 10 % | 914 400 | 826 100 (90,343 %) | 235 377 (25,741 %) | 105 990 (11,591 %) |
| 8,5 % | 914 400 | 867 160 (94,834 %) | 238 026 (26,031 %) | 82 610 (9,034 %) |
| 5,5 % | 914 400 | 866 557 (94,768 %) | 227 355 (24,864 %) | 83 380 (9,119 %) |
| 2,1 % | 914 400 | 865 684 (94,672 %) | 215 796 (23,600 %) | 82 510 (9,023 %) |

Plus petits exemples (ordre d'énumération : PU TTC, puis quantité) :

- (i) `ligne` : 4 × 0,01 € TTC à 20 % remisé de 10 % — ligne 1 : PU HT imprimé 0,01 €, 4 × 0,01 € − 10 % = 0,04 € à la calculatrice, total HT imprimé 0,03 €
- (i) `tva` : 3 × 0,01 € TTC à 20 % remisé de 10 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- (ii) `ligne` : 2 × 0,01 € TTC à 20 % remisé de 10 % — ligne 1 : PU HT imprimé 0,008333 €, 2 × 0,008333 € − 10 % = 0,01 € à la calculatrice, total HT imprimé 0,02 €
- (ii) `tva` : 3 × 0,01 € TTC à 20 % remisé de 10 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- (iii) `ligne` : 5 × 0,01 € TTC à 20 % remisé de 10 % — ligne 1 : PU HT imprimé 0,01 €, 5 × 0,01 € − 10 % = 0,05 € à la calculatrice, total HT imprimé 0,04 €

### D3 — une ligne, remise en euros (0,01 € et 1,00 €) (4 569 408 documents)

| Vérification | (i) règle actuelle | (ii) PU HT à 6 décimales | (iii) méthode HT |
|---|---:|---:|---:|
| PU HT imprimé × qté (− remise) ≠ total HT de la ligne | 4 163 029 (91,107 %) | 561 724 (12,293 %) | 0 (0,000 %) |
| Σ lignes ≠ base du taux ou Σ bases ≠ total HT | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| base du taux × taux, arrondi ≠ TVA du taux | 314 598 (6,885 %) | 314 598 (6,885 %) | 0 (0,000 %) |
| HT + TVA ≠ TTC (par taux ou au total) | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| documents avec au moins un écart visible | 4 174 727 (91,363 %) | 652 787 (14,286 %) | 0 (0,000 %) |
| *pour information, (iii) : TTC du taux ÷ (1 + taux), arrondi ≠ base* | | | 0 (0,000 %) |

Documents avec au moins un écart visible, par taux :

| Taux | Documents | (i) | (ii) | (iii) |
|---|---:|---:|---:|---:|
| 20 % | 913 834 | 752 627 (82,359 %) | 242 932 (26,584 %) | 0 (0,000 %) |
| 10 % | 913 878 | 816 066 (89,297 %) | 75 609 (8,273 %) | 0 (0,000 %) |
| 8,5 % | 913 886 | 868 405 (95,023 %) | 147 254 (16,113 %) | 0 (0,000 %) |
| 5,5 % | 913 898 | 869 997 (95,196 %) | 142 853 (15,631 %) | 0 (0,000 %) |
| 2,1 % | 913 912 | 867 632 (94,936 %) | 44 139 (4,830 %) | 0 (0,000 %) |

Plus petits exemples (ordre d'énumération : PU TTC, puis quantité) :

- (i) `ligne` : 5 × 0,01 € TTC à 20 % avec une remise de 0,01 € — ligne 1 : PU HT imprimé 0,01 €, 5 × 0,01 € − 0,01 € de remise HT = 0,04 € à la calculatrice, total HT imprimé 0,03 €
- (i) `tva` : 4 × 0,01 € TTC à 20 % avec une remise de 0,01 € — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- (ii) `ligne` : 3 × 0,01 € TTC à 20 % avec une remise de 0,01 € — ligne 1 : PU HT imprimé 0,008333 €, 3 × 0,008333 € − 0,01 € de remise HT = 0,01 € à la calculatrice, total HT imprimé 0,02 €
- (ii) `tva` : 4 × 0,01 € TTC à 20 % avec une remise de 0,01 € — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)

### D4 — deux lignes identiques, sans remise (2 286 000 documents)

| Vérification | (i) règle actuelle | (ii) PU HT à 6 décimales | (iii) méthode HT |
|---|---:|---:|---:|
| PU HT imprimé × qté (− remise) ≠ total HT de la ligne | 2 128 473 (93,109 %) | 1 132 918 (49,559 %) | 0 (0,000 %) |
| Σ lignes ≠ base du taux ou Σ bases ≠ total HT | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| base du taux × taux, arrondi ≠ TVA du taux | 105 548 (4,617 %) | 105 548 (4,617 %) | 0 (0,000 %) |
| HT + TVA ≠ TTC (par taux ou au total) | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| documents avec au moins un écart visible | 2 130 493 (93,197 %) | 1 165 952 (51,004 %) | 0 (0,000 %) |
| *pour information, (iii) : TTC du taux ÷ (1 + taux), arrondi ≠ base* | | | 0 (0,000 %) |

Documents avec au moins un écart visible, par taux :

| Taux | Documents | (i) | (ii) | (iii) |
|---|---:|---:|---:|---:|
| 20 % | 457 200 | 379 576 (83,022 %) | 217 999 (47,681 %) | 0 (0,000 %) |
| 10 % | 457 200 | 413 207 (90,378 %) | 226 973 (49,644 %) | 0 (0,000 %) |
| 8,5 % | 457 200 | 445 931 (97,535 %) | 247 113 (54,049 %) | 0 (0,000 %) |
| 5,5 % | 457 200 | 446 217 (97,598 %) | 240 549 (52,614 %) | 0 (0,000 %) |
| 2,1 % | 457 200 | 445 562 (97,455 %) | 233 318 (51,032 %) | 0 (0,000 %) |

Plus petits exemples (ordre d'énumération : PU TTC, puis quantité) :

- (i) `ligne` : 2 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % — ligne 2 : PU HT imprimé 0,01 €, 2 × 0,01 € = 0,02 € à la calculatrice, total HT imprimé 0,01 €
- (i) `tva` : 3 × 0,01 € TTC à 8,5 % ; 3 × 0,01 € TTC à 8,5 % — base 0,06 € × 8,5 % = 0,0051 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,06 €)
- (ii) `ligne` : 2 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % — ligne 2 : PU HT imprimé 0,008333 €, 2 × 0,008333 € = 0,02 € à la calculatrice, total HT imprimé 0,01 €
- (ii) `tva` : 3 × 0,01 € TTC à 8,5 % ; 3 × 0,01 € TTC à 8,5 % — base 0,06 € × 8,5 % = 0,0051 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,06 €)

### D5 — deux lignes différentes, même taux, sans remise (1 782 045 documents)

| Vérification | (i) règle actuelle | (ii) PU HT à 6 décimales | (iii) méthode HT |
|---|---:|---:|---:|
| PU HT imprimé × qté (− remise) ≠ total HT de la ligne | 1 208 246 (67,801 %) | 447 936 (25,136 %) | 0 (0,000 %) |
| Σ lignes ≠ base du taux ou Σ bases ≠ total HT | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| base du taux × taux, arrondi ≠ TVA du taux | 152 096 (8,535 %) | 152 096 (8,535 %) | 0 (0,000 %) |
| HT + TVA ≠ TTC (par taux ou au total) | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| documents avec au moins un écart visible | 1 250 391 (70,166 %) | 539 479 (30,273 %) | 0 (0,000 %) |
| *pour information, (iii) : TTC du taux ÷ (1 + taux), arrondi ≠ base* | | | 0 (0,000 %) |

Documents avec au moins un écart visible, par taux :

| Taux | Documents | (i) | (ii) | (iii) |
|---|---:|---:|---:|---:|
| 20 % | 356 409 | 259 355 (72,769 %) | 140 190 (39,334 %) | 0 (0,000 %) |
| 10 % | 356 409 | 254 973 (71,539 %) | 105 951 (29,727 %) | 0 (0,000 %) |
| 8,5 % | 356 409 | 246 579 (69,184 %) | 102 810 (28,846 %) | 0 (0,000 %) |
| 5,5 % | 356 409 | 246 587 (69,187 %) | 98 268 (27,572 %) | 0 (0,000 %) |
| 2,1 % | 356 409 | 242 897 (68,151 %) | 92 260 (25,886 %) | 0 (0,000 %) |

Plus petits exemples (ordre d'énumération : PU TTC, puis quantité) :

- (i) `ligne` : 1 × 0,01 € TTC à 20 % ; 3 × 0,01 € TTC à 20 % — ligne 2 : PU HT imprimé 0,01 €, 3 × 0,01 € = 0,03 € à la calculatrice, total HT imprimé 0,02 €
- (i) `tva` : 1 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- (ii) `ligne` : 2 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % — ligne 2 : PU HT imprimé 0,008333 €, 2 × 0,008333 € = 0,02 € à la calculatrice, total HT imprimé 0,01 €
- (ii) `tva` : 1 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)

### D6 — trois lignes, deux taux, la 3e identique à la 1re (1 624 500 documents)

| Vérification | (i) règle actuelle | (ii) PU HT à 6 décimales | (iii) méthode HT |
|---|---:|---:|---:|
| PU HT imprimé × qté (− remise) ≠ total HT de la ligne | 1 161 484 (71,498 %) | 695 200 (42,795 %) | 0 (0,000 %) |
| Σ lignes ≠ base du taux ou Σ bases ≠ total HT | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| base du taux × taux, arrondi ≠ TVA du taux | 189 852 (11,687 %) | 189 852 (11,687 %) | 0 (0,000 %) |
| HT + TVA ≠ TTC (par taux ou au total) | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| documents avec au moins un écart visible | 1 202 162 (74,002 %) | 783 672 (48,241 %) | 0 (0,000 %) |
| *pour information, (iii) : TTC du taux ÷ (1 + taux), arrondi ≠ base* | | | 0 (0,000 %) |

Plus petits exemples (ordre d'énumération : PU TTC, puis quantité) :

- (i) `ligne` : 2 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 2,1 % ; 2 × 0,01 € TTC à 20 % — ligne 3 : PU HT imprimé 0,01 €, 2 × 0,01 € = 0,02 € à la calculatrice, total HT imprimé 0,01 €
- (i) `tva` : 1 × 0,01 € TTC à 2,1 % ; 3 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 2,1 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- (ii) `ligne` : 1 × 0,01 € TTC à 2,1 % ; 3 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 2,1 % — ligne 2 : PU HT imprimé 0,008333 €, 3 × 0,008333 € = 0,02 € à la calculatrice, total HT imprimé 0,03 €
- (ii) `tva` : 1 × 0,01 € TTC à 2,1 % ; 3 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 2,1 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)

## 2. Méthode HT (iii) : TTC payé par un professionnel moins TTC du même panier pour un particulier

| Domaine | Taux | Documents | Égal | Pro paie moins | Pro paie plus | Écart min | Écart max |
|---|---|---:|---:|---:|---:|---:|---:|
| D1 | 20 % | 457 200 | 80 673 (17,645 %) | 150 114 (32,833 %) | 226 413 (49,522 %) | -0,40 € | 0,60 € |
| D1 | 10 % | 457 200 | 48 980 (10,713 %) | 203 548 (44,521 %) | 204 672 (44,766 %) | -0,50 € | 0,50 € |
| D1 | 8,5 % | 457 200 | 24 577 (5,376 %) | 215 844 (47,210 %) | 216 779 (47,414 %) | -0,54 € | 0,54 € |
| D1 | 5,5 % | 457 200 | 22 878 (5,004 %) | 216 745 (47,407 %) | 217 577 (47,589 %) | -0,52 € | 0,53 € |
| D1 | 2,1 % | 457 200 | 23 369 (5,111 %) | 216 440 (47,340 %) | 217 391 (47,548 %) | -0,51 € | 0,51 € |
| D2 | 20 % | 914 400 | 131 589 (14,391 %) | 326 300 (35,685 %) | 456 511 (49,925 %) | -0,36 € | 0,54 € |
| D2 | 10 % | 914 400 | 88 781 (9,709 %) | 410 367 (44,878 %) | 415 252 (45,413 %) | -0,45 € | 0,45 € |
| D2 | 8,5 % | 914 400 | 48 537 (5,308 %) | 430 139 (47,041 %) | 435 724 (47,651 %) | -0,49 € | 0,49 € |
| D2 | 5,5 % | 914 400 | 47 964 (5,245 %) | 432 397 (47,288 %) | 434 039 (47,467 %) | -0,48 € | 0,47 € |
| D2 | 2,1 % | 914 400 | 48 952 (5,353 %) | 431 840 (47,227 %) | 433 608 (47,420 %) | -0,46 € | 0,46 € |
| D4 | 20 % | 457 200 | 77 624 (16,978 %) | 151 638 (33,167 %) | 227 938 (49,855 %) | -0,80 € | 1,20 € |
| D4 | 10 % | 457 200 | 43 993 (9,622 %) | 206 455 (45,156 %) | 206 752 (45,221 %) | -1,00 € | 1,00 € |
| D4 | 8,5 % | 457 200 | 11 269 (2,465 %) | 220 600 (48,250 %) | 225 331 (49,285 %) | -1,08 € | 1,08 € |
| D4 | 5,5 % | 457 200 | 10 983 (2,402 %) | 222 708 (48,711 %) | 223 509 (48,886 %) | -1,05 € | 1,05 € |
| D4 | 2,1 % | 457 200 | 11 638 (2,545 %) | 222 271 (48,616 %) | 223 291 (48,839 %) | -1,02 € | 1,02 € |
| D5 | 20 % | 356 409 | 124 444 (34,916 %) | 71 049 (19,935 %) | 160 916 (45,149 %) | -0,02 € | 0,04 € |
| D5 | 10 % | 356 409 | 138 822 (38,950 %) | 102 888 (28,868 %) | 114 699 (32,182 %) | -0,03 € | 0,03 € |
| D5 | 8,5 % | 356 409 | 142 366 (39,945 %) | 107 956 (30,290 %) | 106 087 (29,766 %) | -0,03 € | 0,03 € |
| D5 | 5,5 % | 356 409 | 144 193 (40,457 %) | 105 465 (29,591 %) | 106 751 (29,952 %) | -0,03 € | 0,03 € |
| D5 | 2,1 % | 356 409 | 149 620 (41,980 %) | 99 018 (27,782 %) | 107 771 (30,238 %) | -0,03 € | 0,03 € |
| D6 | deux taux | 1 624 500 | 512 083 (31,522 %) | 502 995 (30,963 %) | 609 422 (37,514 %) | -0,05 € | 0,06 € |

Écart le plus grand (en valeur absolue) selon la quantité, une ligne sans remise (D1) :

| Quantité | 20 % | 10 % | 8,5 % | 5,5 % | 2,1 % |
|---:|---:|---:|---:|---:|---:|
| 1 | 0,00 € / +0,01 € | 0,00 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € |
| 2 | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € |
| 3 | -0,01 € / +0,02 € | -0,01 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € |
| 4 | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € |
| 5 | -0,02 € / +0,03 € | -0,02 € / +0,03 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € |
| 6 | -0,02 € / +0,04 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € |
| 10 | -0,04 € / +0,06 € | -0,05 € / +0,05 € | -0,05 € / +0,05 € | -0,05 € / +0,05 € | -0,05 € / +0,05 € |
| 12 | -0,05 € / +0,07 € | -0,06 € / +0,06 € | -0,06 € / +0,06 € | -0,06 € / +0,06 € | -0,06 € / +0,06 € |
| 20 | -0,08 € / +0,12 € | -0,10 € / +0,10 € | -0,11 € / +0,11 € | -0,10 € / +0,11 € | -0,10 € / +0,10 € |
| 25 | -0,10 € / +0,15 € | -0,12 € / +0,13 € | -0,13 € / +0,14 € | -0,13 € / +0,13 € | -0,13 € / +0,13 € |
| 50 | -0,20 € / +0,30 € | -0,25 € / +0,25 € | -0,27 € / +0,27 € | -0,26 € / +0,26 € | -0,25 € / +0,26 € |
| 75 | -0,30 € / +0,45 € | -0,37 € / +0,38 € | -0,40 € / +0,41 € | -0,39 € / +0,39 € | -0,38 € / +0,38 € |
| 100 | -0,40 € / +0,60 € | -0,50 € / +0,50 € | -0,54 € / +0,54 € | -0,52 € / +0,53 € | -0,51 € / +0,51 € |

Histogramme des écarts, une ligne sans remise (D1), 20 % :

| Écart (centimes) | Documents |
|---:|---:|
| -40 | 1 524 |
| -39 | 1 524 |
| -38 | 2 286 |
| -37 | 1 524 |
| -36 | 2 286 |
| -35 | 1 524 |
| -34 | 2 286 |
| -33 | 1 524 |
| -32 | 2 286 |
| -31 | 1 524 |
| -30 | 2 286 |
| -29 | 1 524 |
| -28 | 2 286 |
| -27 | 1 524 |
| -26 | 2 286 |
| -25 | 1 524 |
| -24 | 2 286 |
| -23 | 1 524 |
| -22 | 2 286 |
| -21 | 1 524 |
| -20 | 4 572 |
| -19 | 5 334 |
| -18 | 6 096 |
| -17 | 5 334 |
| -16 | 6 096 |
| -15 | 5 334 |
| -14 | 6 096 |
| -13 | 5 334 |
| -12 | 6 096 |
| -11 | 5 334 |
| -10 | 6 096 |
| -9 | 5 334 |
| -8 | 6 096 |
| -7 | 5 334 |
| -6 | 6 096 |
| -5 | 5 334 |
| -4 | 6 096 |
| -3 | 5 334 |
| -2 | 6 096 |
| -1 | 5 334 |
| +0 | 80 673 |
| +1 | 6 860 |
| +2 | 7 623 |
| +3 | 6 098 |
| +4 | 7 623 |
| +5 | 6 860 |
| +6 | 6 861 |
| +7 | 6 860 |
| +8 | 7 623 |
| +9 | 6 098 |
| +10 | 7 623 |
| +11 | 6 860 |
| +12 | 6 861 |
| +13 | 6 860 |
| +14 | 7 623 |
| +15 | 6 098 |
| +16 | 7 623 |
| +17 | 6 860 |
| +18 | 6 861 |
| +19 | 6 860 |
| +20 | 6 099 |
| +21 | 2 288 |
| +22 | 3 813 |
| +23 | 3 050 |
| +24 | 3 051 |
| +25 | 3 050 |
| +26 | 3 813 |
| +27 | 2 288 |
| +28 | 3 813 |
| +29 | 3 050 |
| +30 | 3 051 |
| +31 | 3 050 |
| +32 | 3 813 |
| +33 | 2 288 |
| +34 | 3 813 |
| +35 | 3 050 |
| +36 | 3 051 |
| +37 | 3 050 |
| +38 | 3 813 |
| +39 | 2 288 |
| +40 | 3 050 |
| +41 | 1 524 |
| +42 | 762 |
| +43 | 1 524 |
| +44 | 1 524 |
| +45 | 762 |
| +46 | 1 524 |
| +47 | 1 524 |
| +48 | 762 |
| +49 | 1 524 |
| +50 | 1 524 |
| +51 | 762 |
| +52 | 1 524 |
| +53 | 1 524 |
| +54 | 762 |
| +55 | 1 524 |
| +56 | 1 524 |
| +57 | 762 |
| +58 | 1 524 |
| +59 | 1 524 |
| +60 | 762 |

Plus petit exemple : 3 × 0,01 € TTC à 20 % — particulier 0,03 € TTC, professionnel 0,03 € HT + 0,01 € TVA = 0,04 € TTC.

Exemple de l'éditeur, 2 coques à 10,00 € TTC à 20 % : 20,00 € TTC pour un particulier ; en (iii), PU HT 8,33 €, HT 16,66 €, TVA 3,33 €, TTC 19,99 €. Sa facture entière (2 coques + 1 remplacement d'écran à 30,00 € TTC) : particulier 50,00 € ; (iii) 41,66 € HT + 8,33 € TVA = 49,99 € TTC.

## 3. Prix TTC dont le HT tombe juste

| Taux | PU TTC étudiés | HT exact au centime (aucun écart, quelle que soit la méthode) | PU HT arrondi qui redonne le PU TTC pour 1 article |
|---|---:|---:|---:|
| 20 % | 20 000 (0,01 € à 200,00 €) | 3 333 (16,665 %) | 16 667 (83,335 %) |
| 10 % | 20 000 (0,01 € à 200,00 €) | 1 818 (9,090 %) | 18 182 (90,910 %) |
| 8,5 % | 20 000 (0,01 € à 200,00 €) | 92 (0,460 %) | 18 433 (92,165 %) |
| 5,5 % | 20 000 (0,01 € à 200,00 €) | 94 (0,470 %) | 18 957 (94,785 %) |
| 2,1 % | 20 000 (0,01 € à 200,00 €) | 19 (0,095 %) | 19 589 (97,945 %) |

- 20 %, premiers PU TTC qu'aucun PU HT au centime ne redonne : PU TTC 0,03 € → PU HT 0,03 € → 0,03 € + 0,01 € de TVA = 0,04 € ; PU TTC 0,09 € → PU HT 0,08 € → 0,08 € + 0,02 € de TVA = 0,10 € ; PU TTC 0,15 € → PU HT 0,13 € → 0,13 € + 0,03 € de TVA = 0,16 € ; PU TTC 0,21 € → PU HT 0,18 € → 0,18 € + 0,04 € de TVA = 0,22 € ; PU TTC 0,27 € → PU HT 0,23 € → 0,23 € + 0,05 € de TVA = 0,28 €
- 10 %, premiers PU TTC qu'aucun PU HT au centime ne redonne : PU TTC 0,05 € → PU HT 0,05 € → 0,05 € + 0,01 € de TVA = 0,06 € ; PU TTC 0,16 € → PU HT 0,15 € → 0,15 € + 0,02 € de TVA = 0,17 € ; PU TTC 0,27 € → PU HT 0,25 € → 0,25 € + 0,03 € de TVA = 0,28 € ; PU TTC 0,38 € → PU HT 0,35 € → 0,35 € + 0,04 € de TVA = 0,39 € ; PU TTC 0,49 € → PU HT 0,45 € → 0,45 € + 0,05 € de TVA = 0,50 €
- 8,5 %, premiers PU TTC qu'aucun PU HT au centime ne redonne : PU TTC 0,06 € → PU HT 0,06 € → 0,06 € + 0,01 € de TVA = 0,07 € ; PU TTC 0,19 € → PU HT 0,18 € → 0,18 € + 0,02 € de TVA = 0,20 € ; PU TTC 0,32 € → PU HT 0,29 € → 0,29 € + 0,02 € de TVA = 0,31 € ; PU TTC 0,45 € → PU HT 0,41 € → 0,41 € + 0,03 € de TVA = 0,44 € ; PU TTC 0,57 € → PU HT 0,53 € → 0,53 € + 0,05 € de TVA = 0,58 €
- 5,5 %, premiers PU TTC qu'aucun PU HT au centime ne redonne : PU TTC 0,10 € → PU HT 0,09 € → 0,09 € + 0,00 € de TVA = 0,09 € ; PU TTC 0,29 € → PU HT 0,27 € → 0,27 € + 0,01 € de TVA = 0,28 € ; PU TTC 0,48 € → PU HT 0,45 € → 0,45 € + 0,02 € de TVA = 0,47 € ; PU TTC 0,67 € → PU HT 0,64 € → 0,64 € + 0,04 € de TVA = 0,68 € ; PU TTC 0,86 € → PU HT 0,82 € → 0,82 € + 0,05 € de TVA = 0,87 €
- 2,1 %, premiers PU TTC qu'aucun PU HT au centime ne redonne : PU TTC 0,24 € → PU HT 0,24 € → 0,24 € + 0,01 € de TVA = 0,25 € ; PU TTC 0,73 € → PU HT 0,71 € → 0,71 € + 0,01 € de TVA = 0,72 € ; PU TTC 1,22 € → PU HT 1,19 € → 1,19 € + 0,02 € de TVA = 1,21 € ; PU TTC 1,70 € → PU HT 1,67 € → 1,67 € + 0,04 € de TVA = 1,71 € ; PU TTC 2,19 € → PU HT 2,14 € → 2,14 € + 0,04 € de TVA = 2,18 €

Un PU TTC dont le HT tombe juste (à 20 % : un multiple de 0,06 €, comme 12,00 € = 10,00 € HT ; à 10 % : un multiple de 0,11 €) donne les mêmes montants dans les trois méthodes, sans aucun écart, pour toute quantité, et le même TTC qu'au particulier.

## 4. Tickets et factures à un particulier (règle actuelle)

| Domaine | Documents | qté × PU TTC (− remise) ≠ total TTC de la ligne | Σ lignes ≠ récapitulatif ou totaux | TTC du taux ÷ (1 + taux), arrondi ≠ base | base × taux, arrondi ≠ TVA | base + TVA ≠ TTC | documents avec au moins un écart visible |
|---|---:|---:|---:|---:|---:|---:|---:|
| D1 | 2 286 000 | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) | 169 401 (7,410 %) | 0 (0,000 %) | 169 401 (7,410 %) |
| D2 | 4 572 000 | 411 500 (9,000 %) | 0 (0,000 %) | 0 (0,000 %) | 353 776 (7,738 %) | 0 (0,000 %) | 734 495 (16,065 %) |
| D3 | 4 569 408 | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) | 314 598 (6,885 %) | 0 (0,000 %) | 314 598 (6,885 %) |
| D4 | 2 286 000 | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) | 105 548 (4,617 %) | 0 (0,000 %) | 105 548 (4,617 %) |
| D5 | 1 782 045 | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) | 152 096 (8,535 %) | 0 (0,000 %) | 152 096 (8,535 %) |
| D6 | 1 624 500 | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) | 189 852 (11,687 %) | 0 (0,000 %) | 189 852 (11,687 %) |

Plus petits exemples :

- D1 `tva` : 3 × 0,01 € TTC à 20 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- D2 `ligne` : 5 × 0,01 € TTC à 20 % remisé de 10 % — ligne 1 : 5 × 0,01 € remisé de 10 % = 0,05 € à la calculatrice, total TTC imprimé 0,04 €
- D2 `tva` : 3 × 0,01 € TTC à 20 % remisé de 10 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- D3 `tva` : 4 × 0,01 € TTC à 20 % avec une remise de 0,01 € — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- D4 `tva` : 3 × 0,01 € TTC à 8,5 % ; 3 × 0,01 € TTC à 8,5 % — base 0,06 € × 8,5 % = 0,0051 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,06 €)
- D5 `tva` : 1 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)
- D6 `tva` : 1 × 0,01 € TTC à 2,1 % ; 3 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 2,1 % — base 0,03 € × 20 % = 0,006 € → 0,01 €, TVA imprimée 0,00 € (TTC 0,03 €)

Récapitulatif d'un taux, étudié pour chaque TTC de 0,01 € à 2 000,00 € :

| Taux | Ticket : base × taux arrondi ≠ TVA (= TTC − base) | Méthode HT : TTC ÷ (1 + taux) arrondi ≠ HT |
|---|---:|---:|
| 20 % | 33 333 (16,666 %) | 0 (0,000 %) |
| 10 % | 18 182 (9,091 %) | 0 (0,000 %) |
| 8,5 % | 15 668 (7,834 %) | 0 (0,000 %) |
| 5,5 % | 10 427 (5,213 %) | 0 (0,000 %) |
| 2,1 % | 4 114 (2,057 %) | 0 (0,000 %) |

- 20 %, ticket, plus petits cas : TTC 0,03 € : base 0,03 €, TVA imprimée 0,00 €, base × 20 % = 0,006 € → 0,01 € ; TTC 0,09 € : base 0,08 €, TVA imprimée 0,01 €, base × 20 % = 0,016 € → 0,02 € ; TTC 0,15 € : base 0,13 €, TVA imprimée 0,02 €, base × 20 % = 0,026 € → 0,03 € ; TTC 0,21 € : base 0,18 €, TVA imprimée 0,03 €, base × 20 % = 0,036 € → 0,04 € ; TTC 0,27 € : base 0,23 €, TVA imprimée 0,04 €, base × 20 % = 0,046 € → 0,05 €
- 10 %, ticket, plus petits cas : TTC 0,05 € : base 0,05 €, TVA imprimée 0,00 €, base × 10 % = 0,005 € → 0,01 € ; TTC 0,16 € : base 0,15 €, TVA imprimée 0,01 €, base × 10 % = 0,015 € → 0,02 € ; TTC 0,27 € : base 0,25 €, TVA imprimée 0,02 €, base × 10 % = 0,025 € → 0,03 € ; TTC 0,38 € : base 0,35 €, TVA imprimée 0,03 €, base × 10 % = 0,035 € → 0,04 € ; TTC 0,49 € : base 0,45 €, TVA imprimée 0,04 €, base × 10 % = 0,045 € → 0,05 €
- 8,5 %, ticket, plus petits cas : TTC 0,06 € : base 0,06 €, TVA imprimée 0,00 €, base × 8,5 % = 0,0051 € → 0,01 € ; TTC 0,19 € : base 0,18 €, TVA imprimée 0,01 €, base × 8,5 % = 0,0153 € → 0,02 € ; TTC 0,32 € : base 0,29 €, TVA imprimée 0,03 €, base × 8,5 % = 0,02465 € → 0,02 € ; TTC 0,45 € : base 0,41 €, TVA imprimée 0,04 €, base × 8,5 % = 0,03485 € → 0,03 € ; TTC 0,57 € : base 0,53 €, TVA imprimée 0,04 €, base × 8,5 % = 0,04505 € → 0,05 €
- 5,5 %, ticket, plus petits cas : TTC 0,10 € : base 0,09 €, TVA imprimée 0,01 €, base × 5,5 % = 0,00495 € → 0,00 € ; TTC 0,29 € : base 0,27 €, TVA imprimée 0,02 €, base × 5,5 % = 0,01485 € → 0,01 € ; TTC 0,48 € : base 0,45 €, TVA imprimée 0,03 €, base × 5,5 % = 0,02475 € → 0,02 € ; TTC 0,67 € : base 0,64 €, TVA imprimée 0,03 €, base × 5,5 % = 0,0352 € → 0,04 € ; TTC 0,86 € : base 0,82 €, TVA imprimée 0,04 €, base × 5,5 % = 0,0451 € → 0,05 €
- 2,1 %, ticket, plus petits cas : TTC 0,24 € : base 0,24 €, TVA imprimée 0,00 €, base × 2,1 % = 0,00504 € → 0,01 € ; TTC 0,73 € : base 0,71 €, TVA imprimée 0,02 €, base × 2,1 % = 0,01491 € → 0,01 € ; TTC 1,22 € : base 1,19 €, TVA imprimée 0,03 €, base × 2,1 % = 0,02499 € → 0,02 € ; TTC 1,70 € : base 1,67 €, TVA imprimée 0,03 €, base × 2,1 % = 0,03507 € → 0,04 € ; TTC 2,19 € : base 2,14 €, TVA imprimée 0,05 €, base × 2,1 % = 0,04494 € → 0,04 €

- Méthode HT : aucun cas. Avec TVA = HT × t arrondie, TTC = HT × (1 + t) + d, |d| ≤ 0,5 centime, donc TTC ÷ (1 + t) = HT + d ÷ (1 + t), à moins d'un demi-centime du HT : l'arrondi redonne toujours la base. Une facture calculée en HT se vérifie donc dans les deux sens.

Explication : la base d'un ticket est H = TTC ÷ (1 + t) + e, avec |e| ≤ 0,5 centime, et la TVA imprimée TTC − H ; base × t vaut alors (TTC − H) + e × (1 + t). La calculatrice retombe sur la TVA imprimée tant que |e| × (1 + t) < 0,5 centime : l'écart apparaît dès que |e| ≥ 0,5 ÷ (1 + t), soit pour une part t ÷ (1 + t) des TTC (1/6 à 20 %, 1/11 à 10 %).

## 5. Avoirs sur une facture de deux lignes (D5)

Scénarios : A, retour complet de la ligne 2 seule ; B1, un article de la ligne 1 (si sa quantité est au moins 2), puis B2, le reste de la ligne 1 ; C, un article de chaque ligne dans un même avoir (si les deux quantités sont au moins 2). En (iii), un retour rend, pour les articles rendus jusqu'ici, quantité × PU HT et la TVA de ce HT sans dépasser celle de la ligne, moins les retours précédents, comme `CalculateurLigne::retourDAchat()`, et le retour de toute la ligne rend exactement ses montants ; l'avoir additionne ses lignes.

| Scénario | Méthode | Avoirs | Ligne : qté × PU ≠ HT rendu | base × taux ≠ TVA rendue | Au moins un écart |
|---|---|---:|---:|---:|---:|
| A | (i) pro | 1 782 045 | 835 224 (46,869 %) | 523 151 (29,357 %) | 1 006 690 (56,491 %) |
| A | (ii) pro | 1 782 045 | 435 560 (24,442 %) | 523 151 (29,357 %) | 523 151 (29,357 %) |
| A | (iii) pro | 1 782 045 | 0 (0,000 %) | 454 063 (25,480 %) | 454 063 (25,480 %) |
| A | ticket (règle actuelle) | 1 782 045 | 0 (0,000 %) | 523 151 (29,357 %) | 523 151 (29,357 %) |
| B1 | (i) pro | 1 188 030 | 0 (0,000 %) | 96 714 (8,141 %) | 96 714 (8,141 %) |
| B1 | (ii) pro | 1 188 030 | 0 (0,000 %) | 96 714 (8,141 %) | 96 714 (8,141 %) |
| B1 | (iii) pro | 1 188 030 | 0 (0,000 %) | 0 (0,000 %) | 0 (0,000 %) |
| B1 | ticket (règle actuelle) | 1 188 030 | 0 (0,000 %) | 96 714 (8,141 %) | 96 714 (8,141 %) |
| B2 | (i) pro | 1 188 030 | 702 072 (59,095 %) | 420 885 (35,427 %) | 702 072 (59,095 %) |
| B2 | (ii) pro | 1 188 030 | 401 184 (33,769 %) | 420 885 (35,427 %) | 420 885 (35,427 %) |
| B2 | (iii) pro | 1 188 030 | 0 (0,000 %) | 427 452 (35,980 %) | 427 452 (35,980 %) |
| B2 | ticket (règle actuelle) | 1 188 030 | 0 (0,000 %) | 420 885 (35,427 %) | 420 885 (35,427 %) |
| C | (i) pro | 792 020 | 0 (0,000 %) | 233 696 (29,506 %) | 233 696 (29,506 %) |
| C | (ii) pro | 792 020 | 0 (0,000 %) | 233 696 (29,506 %) | 233 696 (29,506 %) |
| C | (iii) pro | 792 020 | 0 (0,000 %) | 223 088 (28,167 %) | 223 088 (28,167 %) |
| C | ticket (règle actuelle) | 792 020 | 0 (0,000 %) | 233 696 (29,506 %) | 233 696 (29,506 %) |

Retours article par article d'une ligne (méthode HT), domaine D1 :

| Taux | Avoirs simulés (q de 2 à 10, un avoir par article) | Option A : base × taux ≠ TVA rendue | dont sur le dernier avoir, qui solde la ligne |
|---|---:|---:|---:|
| 20 % | 246 888 | 67 078 (27,169 %) | 12 196 (4,940 %) |
| 10 % | 246 888 | 66 519 (26,943 %) | 12 472 (5,052 %) |
| 8,5 % | 246 888 | 65 136 (26,383 %) | 12 282 (4,975 %) |
| 5,5 % | 246 888 | 63 582 (25,753 %) | 12 022 (4,869 %) |
| 2,1 % | 246 888 | 61 865 (25,058 %) | 11 653 (4,720 %) |

Plus petit exemple (option A) : 3 × 0,01 € TTC à 20 % (PU HT 0,01 €, ligne 0,03 € HT + 0,01 € TVA), rendus un par un : l'avoir n° 3 rend 0,01 € HT et 0,01 € de TVA, quand 0,01 € × 20 % = 0,002 € → 0,00 €.

Option B (chaque avoir calculé comme une facture indépendante : aucun écart visible sur aucun avoir) : TVA rendue par les q avoirs moins TVA de la ligne, pour q de 1 à la quantité maximale du domaine :

| Taux | Lignes | TVA rendue = TVA de la ligne | en plus | en moins | min | max |
|---|---:|---:|---:|---:|---:|---:|
| 20 % | 457 200 | 81 435 (17,812 %) | 225 552 | 150 213 | -0,40 € | 0,40 € |
| 10 % | 457 200 | 49 396 (10,804 %) | 244 732 | 163 072 | -0,40 € | 0,50 € |
| 8,5 % | 457 200 | 24 900 (5,446 %) | 216 578 | 215 722 | -0,50 € | 0,50 € |
| 5,5 % | 457 200 | 23 116 (5,056 %) | 218 815 | 215 269 | -0,50 € | 0,50 € |
| 2,1 % | 457 200 | 23 462 (5,132 %) | 216 891 | 216 847 | -0,50 € | 0,50 € |

Écart extrême selon la quantité (option B, un avoir par article) :

| Quantité | 20 % | 10 % | 8,5 % | 5,5 % | 2,1 % |
|---:|---:|---:|---:|---:|---:|
| 1 | 0,00 € / +0,00 € | 0,00 € / +0,00 € | 0,00 € / +0,00 € | 0,00 € / +0,00 € | 0,00 € / +0,00 € |
| 2 | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € |
| 3 | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € | -0,01 € / +0,01 € |
| 4 | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € |
| 5 | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € | -0,02 € / +0,02 € |
| 6 | -0,02 € / +0,02 € | -0,02 € / +0,03 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € | -0,03 € / +0,03 € |
| 10 | -0,04 € / +0,04 € | -0,04 € / +0,05 € | -0,05 € / +0,05 € | -0,05 € / +0,05 € | -0,05 € / +0,05 € |
| 12 | -0,05 € / +0,05 € | -0,05 € / +0,06 € | -0,06 € / +0,06 € | -0,06 € / +0,06 € | -0,06 € / +0,06 € |
| 20 | -0,08 € / +0,08 € | -0,08 € / +0,10 € | -0,10 € / +0,10 € | -0,10 € / +0,10 € | -0,10 € / +0,10 € |
| 25 | -0,10 € / +0,10 € | -0,10 € / +0,12 € | -0,12 € / +0,12 € | -0,12 € / +0,12 € | -0,12 € / +0,12 € |
| 50 | -0,20 € / +0,20 € | -0,20 € / +0,25 € | -0,25 € / +0,25 € | -0,25 € / +0,25 € | -0,25 € / +0,25 € |
| 75 | -0,30 € / +0,30 € | -0,30 € / +0,37 € | -0,37 € / +0,37 € | -0,37 € / +0,37 € | -0,37 € / +0,37 € |
| 100 | -0,40 € / +0,40 € | -0,40 € / +0,50 € | -0,50 € / +0,50 € | -0,50 € / +0,50 € | -0,50 € / +0,50 € |

Option B sur les factures de deux lignes (D5) : S1 = un article de la ligne 1, puis le reste de la ligne 1, puis toute la ligne 2 (trois avoirs) ; S2 = un article de chaque ligne, puis le reste des deux lignes (deux avoirs). TVA rendue moins TVA de la facture :

| Suite | Taux | Factures | Égal | en plus | en moins | min | max |
|---|---|---:|---:|---:|---:|---:|---:|
| S1 | 20 % | 237 606 | 137 447 (57,847 %) | 62 337 | 37 822 | -0,01 € | 0,01 € |
| S1 | 10 % | 237 606 | 135 795 (57,151 %) | 68 508 | 33 303 | -0,01 € | 0,01 € |
| S1 | 8,5 % | 237 606 | 138 447 (58,267 %) | 51 235 | 47 924 | -0,01 € | 0,01 € |
| S1 | 5,5 % | 237 606 | 139 386 (58,663 %) | 51 607 | 46 613 | -0,01 € | 0,01 € |
| S1 | 2,1 % | 237 606 | 142 632 (60,029 %) | 47 451 | 47 523 | -0,01 € | 0,01 € |
| S2 | 20 % | 158 404 | 108 936 (68,771 %) | 24 222 | 25 246 | -0,01 € | 0,01 € |
| S2 | 10 % | 158 404 | 112 465 (70,999 %) | 26 928 | 19 011 | -0,01 € | 0,01 € |
| S2 | 8,5 % | 158 404 | 113 156 (71,435 %) | 22 803 | 22 445 | -0,01 € | 0,01 € |
| S2 | 5,5 % | 158 404 | 113 025 (71,352 %) | 22 913 | 22 466 | -0,01 € | 0,01 € |
| S2 | 2,1 % | 158 404 | 112 438 (70,982 %) | 23 074 | 22 892 | -0,01 € | 0,01 € |

- Plus petit exemple S1 : facture 2 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 20 % (0,03 € HT, 0,01 € TVA) ; avoirs 1 × 0,01 € puis 1 × 0,01 € puis 1 × 0,01 € : TVA rendue 0,00 €.
- Plus petit exemple S2 : facture 2 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % (0,04 € HT, 0,01 € TVA) ; avoirs 1 × 0,01 €+1 × 0,01 € puis 1 × 0,01 €+1 × 0,01 € : TVA rendue 0,00 €.

Plus petits exemples :

- A i `ligne` : facture 1 × 0,01 € TTC à 20 % ; 3 × 0,01 € TTC à 20 % (lignes HT 0,01 € et 0,02 €, TVA 0,00 € et 0,01 €) ; avoir A (3 × ligne de 0,01 € : 0,02 € HT, 0,01 € TVA) — 3 × 0,01 € = 0,03 € à la calculatrice, HT imprimé 0,02 €
- A i `tva` : facture 1 × 0,01 € TTC à 20 % ; 3 × 0,01 € TTC à 20 % (lignes HT 0,01 € et 0,02 €, TVA 0,00 € et 0,01 €) ; avoir A (3 × ligne de 0,01 € : 0,02 € HT, 0,01 € TVA) — base 0,02 € × 20 % = 0,004 € → 0,00 €, TVA imprimée 0,01 €
- A iii `tva` : facture 1 × 0,01 € TTC à 20 % ; 2 × 0,01 € TTC à 20 % (lignes HT 0,01 € et 0,02 €, TVA 0,00 € et 0,01 €) ; avoir A (2 × ligne de 0,01 € : 0,02 € HT, 0,01 € TVA) — base 0,02 € × 20 % = 0,004 € → 0,00 €, TVA imprimée 0,01 €
- A ticket `tva` : ticket 1 × 0,01 € TTC à 20 % ; 3 × 0,01 € TTC à 20 % ; avoir A (3 × 0,01 € : 0,03 € TTC) — base 0,02 € × 20 % = 0,004 € → 0,00 €, TVA imprimée 0,01 €
- A ticket `base` : ticket 1 × 0,01 € TTC à 20 % ; 3 × 0,01 € TTC à 20 % ; avoir A (3 × 0,01 € : 0,03 € TTC) — TTC 0,03 € ÷ (1 + 20 %) → 0,03 €, base imprimée 0,02 €
- B1 i `tva` : facture 2 × 2,03 € TTC à 10 % ; 1 × 0,01 € TTC à 10 % (lignes HT 3,69 € et 0,01 €, TVA 0,37 € et 0,00 €) ; avoir B1 (1 × ligne de 2,03 € : 1,85 € HT, 0,18 € TVA) — base 1,85 € × 10 % = 0,185 € → 0,19 €, TVA imprimée 0,18 €
- B1 ticket `tva` : ticket 2 × 2,03 € TTC à 10 % ; 1 × 0,01 € TTC à 10 % ; avoir B1 (1 × 2,03 € : 2,03 € TTC) — base 1,85 € × 10 % = 0,185 € → 0,19 €, TVA imprimée 0,18 €
- B2 i `ligne` : facture 2 × 1,02 € TTC à 10 % ; 1 × 0,01 € TTC à 10 % (lignes HT 1,85 € et 0,01 €, TVA 0,19 € et 0,00 €) ; avoir B2 (1 × ligne de 1,02 € : 0,92 € HT, 0,10 € TVA) — 1 × 0,93 € = 0,93 € à la calculatrice, HT imprimé 0,92 €
- B2 i `tva` : facture 2 × 1,02 € TTC à 10 % ; 1 × 0,01 € TTC à 10 % (lignes HT 1,85 € et 0,01 €, TVA 0,19 € et 0,00 €) ; avoir B2 (1 × ligne de 1,02 € : 0,92 € HT, 0,10 € TVA) — base 0,92 € × 10 % = 0,092 € → 0,09 €, TVA imprimée 0,10 €
- B2 iii `tva` : facture 3 × 0,01 € TTC à 20 % ; 1 × 0,01 € TTC à 20 % (lignes HT 0,03 € et 0,01 €, TVA 0,01 € et 0,00 €) ; avoir B2 (2 × ligne de 0,01 € : 0,02 € HT, 0,01 € TVA) — base 0,02 € × 20 % = 0,004 € → 0,00 €, TVA imprimée 0,01 €
- B2 ticket `tva` : ticket 2 × 1,02 € TTC à 10 % ; 1 × 0,01 € TTC à 10 % ; avoir B2 (1 × 1,02 € : 1,02 € TTC) — base 0,92 € × 10 % = 0,092 € → 0,09 €, TVA imprimée 0,10 €
- B2 ticket `base` : ticket 2 × 1,02 € TTC à 10 % ; 1 × 0,01 € TTC à 10 % ; avoir B2 (1 × 1,02 € : 1,02 € TTC) — TTC 1,02 € ÷ (1 + 10 %) → 0,93 €, base imprimée 0,92 €
- C i `tva` : facture 2 × 0,01 € TTC à 10 % ; 2 × 2,03 € TTC à 10 % (lignes HT 0,02 € et 3,69 €, TVA 0,00 € et 0,37 €) ; avoir C (1 × ligne de 0,01 € : 0,01 € HT, 0,00 € TVA, 1 × ligne de 2,03 € : 1,85 € HT, 0,18 € TVA) — base 1,86 € × 10 % = 0,186 € → 0,19 €, TVA imprimée 0,18 €
- C iii `tva` : facture 2 × 0,01 € TTC à 20 % ; 2 × 5,06 € TTC à 20 % (lignes HT 0,02 € et 8,44 €, TVA 0,00 € et 1,69 €) ; avoir C (1 × ligne de 0,01 € : 0,01 € HT, 0,00 € TVA, 1 × ligne de 5,06 € : 4,22 € HT, 0,84 € TVA) — base 4,23 € × 20 % = 0,846 € → 0,85 €, TVA imprimée 0,84 €
- C ticket `tva` : ticket 2 × 0,01 € TTC à 10 % ; 2 × 2,03 € TTC à 10 % ; avoir C (1 × 0,01 € : 0,01 € TTC, 1 × 2,03 € : 2,03 € TTC) — base 1,86 € × 10 % = 0,186 € → 0,19 €, TVA imprimée 0,18 €
- C ticket `base` : ticket 2 × 0,01 € TTC à 10 % ; 2 × 2,03 € TTC à 10 % ; avoir C (1 × 0,01 € : 0,01 € TTC, 1 × 2,03 € : 2,03 € TTC) — TTC 2,04 € ÷ (1 + 10 %) → 1,85 €, base imprimée 1,86 €

