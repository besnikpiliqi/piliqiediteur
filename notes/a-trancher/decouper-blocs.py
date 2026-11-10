"""Découpe en morceaux de 30 000 octets au plus les blocs Markdown que le document refuse (plus de 32 768 octets).

Onglet « Vos décisions » (fichiers o2-…) : la semaine se coupe par jours entiers, chaque morceau garde son propre
sous-titre (« Du 8 au 11 octobre », « Le 4 octobre »…), les lignes restant de la plus récente à la plus ancienne.
Onglet « Mes questions et vos réponses » (fichiers o3-…) : le bloc se coupe entre deux séries ; une série trop longue
à elle seule se coupe entre deux lignes de son tableau, chaque partie sous un titre de niveau 4 « Questions X à Y ».

Usage : python3 -I decouper-blocs.py <dossier onglets23> <cle> [<cle> ...]
Écrit <cle>-p1.md, <cle>-p2.md… dans le même dossier et affiche leur taille.
"""
import os
import re
import sys

PLAFOND = 30000
MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre",
        "novembre", "décembre"]


def octets(texte):
    return len(texte.encode("utf-8"))


def intitule(jours):
    """jours : liste de (jour, mois) du plus récent au plus ancien."""
    fin, debut = jours[0], jours[-1]
    nom = lambda j: "1er" if j == 1 else str(j)
    if debut == fin:
        return f"Le {nom(debut[0])} {MOIS[debut[1] - 1]}"
    if debut[1] == fin[1]:
        return f"Du {nom(debut[0])} au {nom(fin[0])} {MOIS[fin[1] - 1]}"
    return f"Du {nom(debut[0])} {MOIS[debut[1] - 1]} au {nom(fin[0])} {MOIS[fin[1] - 1]}"


def decouper_decisions(texte):
    lignes = texte.rstrip("\n").split("\n")
    entete = [l for l in lignes if l.startswith("| Date |") or l.startswith("|---")]
    rangs = [l for l in lignes if l.startswith("| ") and not l.startswith("| Date |")]
    par_jour = []
    for rang in rangs:
        m = re.match(r"\| (\d{2})/(\d{2})", rang)
        cle = (int(m.group(1)), int(m.group(2)))
        if not par_jour or par_jour[-1][0] != cle:
            par_jour.append((cle, []))
        par_jour[-1][1].append(rang)
    morceaux, courant = [], []

    def rendu(groupe):
        return (f"### {intitule([j for j, _ in groupe])}\n\n" + "\n".join(entete) + "\n"
                + "\n".join(r for _, rs in groupe for r in rs))

    for jour in par_jour:
        if courant and octets(rendu(courant + [jour])) > PLAFOND:
            morceaux.append(rendu(courant))
            courant = []
        courant.append(jour)
    morceaux.append(rendu(courant))
    return morceaux


def sections(texte):
    blocs, courant = [], []
    for ligne in texte.rstrip("\n").split("\n"):
        if ligne.startswith("### ") and courant:
            blocs.append("\n".join(courant).rstrip("\n"))
            courant = []
        courant.append(ligne)
    blocs.append("\n".join(courant).rstrip("\n"))
    return blocs


def couper_serie(section):
    lignes = section.split("\n")
    entete_tableau = [l for l in lignes if l.startswith("| N° |") or l.startswith("|---")]
    tete = []
    for l in lignes:
        if l.startswith("| N° |"):
            break
        tete.append(l)
    rangs = [l for l in lignes if l.startswith("| ") and not l.startswith("| N° |")]
    parties, courant = [], []

    def rendu(groupe, premiere):
        premier_code = groupe[0].split("|")[1].strip()
        dernier_code = groupe[-1].split("|")[1].strip()
        titre = f"#### Questions {premier_code} à {dernier_code}"
        corps = titre + "\n\n" + "\n".join(entete_tableau) + "\n" + "\n".join(groupe)
        return ("\n".join(tete).rstrip("\n") + "\n\n" + corps) if premiere else corps

    for rang in rangs:
        if courant and octets(rendu(courant + [rang], not parties)) > PLAFOND:
            parties.append(rendu(courant, not parties))
            courant = []
        courant.append(rang)
    parties.append(rendu(courant, not parties))
    return parties


def decouper_questions(texte):
    morceaux, courant = [], []
    for section in sections(texte):
        if octets(section) > PLAFOND:
            if courant:
                morceaux.append("\n\n".join(courant))
                courant = []
            morceaux.extend(couper_serie(section))
            continue
        if courant and octets("\n\n".join(courant + [section])) > PLAFOND:
            morceaux.append("\n\n".join(courant))
            courant = []
        courant.append(section)
    if courant:
        morceaux.append("\n\n".join(courant))
    return morceaux


dossier = sys.argv[1]
for cle in sys.argv[2:]:
    texte = open(os.path.join(dossier, cle + ".md"), encoding="utf-8").read()
    morceaux = decouper_decisions(texte) if cle.startswith("o2-") else decouper_questions(texte)
    for n, m in enumerate(morceaux, 1):
        if octets(m) > 32768:
            raise SystemExit(f"{cle} partie {n} : {octets(m)} octets, encore trop gros")
        open(os.path.join(dossier, f"{cle}-p{n}.md"), "w", encoding="utf-8").write(m + "\n")
        premiere = m.split("\n", 1)[0]
        print(f"{cle}-p{n}\t{octets(m)}\t{premiere}")
