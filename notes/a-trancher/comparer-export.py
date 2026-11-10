"""Compare l'export Markdown d'un onglet du document avec les fichiers Markdown envoyés.

Usage : python3 -I comparer-export.py <export> <fichier.md> [<fichier.md> ...]

<export> est soit le résultat JSON de l'outil d'export (avec data.bytes_b64), soit le Markdown déjà décodé.
Compare, dans l'ordre, les titres de niveau 3, les lignes de texte hors tableau qui suivent un titre de
niveau 3, et chaque cellule de chaque ligne de tableau, après avoir retiré les échappements Markdown et
resserré les espaces. Code de sortie 0 si tout est identique, 1 sinon (chaque écart est affiché).
"""
import base64
import json
import re
import sys

ECHAPPEMENT = re.compile(r"\\([!-/:-@\[-`{-~])")


def lire_export(chemin):
    brut = open(chemin, encoding="utf-8").read()
    try:
        donnees = json.loads(brut)
    except json.JSONDecodeError:
        return brut
    if isinstance(donnees, list):
        donnees = next(x for x in donnees if isinstance(x, dict))
        if "text" in donnees:
            donnees = json.loads(donnees["text"])
    noeud = donnees.get("data", donnees)
    return base64.b64decode(noeud["bytes_b64"]).decode("utf-8")


def cellules(ligne):
    morceaux, courant, i = [], [], 0
    interieur = ligne.strip()
    if interieur.startswith("|"):
        interieur = interieur[1:]
    if interieur.endswith("|") and not interieur.endswith("\\|"):
        interieur = interieur[:-1]
    while i < len(interieur):
        c = interieur[i]
        if c == "\\" and i + 1 < len(interieur):
            courant.append(interieur[i:i + 2])
            i += 2
            continue
        if c == "|":
            morceaux.append("".join(courant))
            courant = []
        else:
            courant.append(c)
        i += 1
    morceaux.append("".join(courant))
    return [normaliser(m) for m in morceaux]


def normaliser(texte):
    t = ECHAPPEMENT.sub(r"\1", texte)
    t = t.replace("\u00a0", " ").replace("\u202f", " ")
    return " ".join(t.split())


def elements(markdown):
    """Suite des éléments comparables : ('titre', texte), ('texte', texte), ('ligne', [cellules])."""
    sortie = []
    dans_section = False
    for ligne in markdown.splitlines():
        brute = ligne.strip()
        if not brute:
            continue
        if brute.startswith("### ") or brute.startswith("#### "):
            sortie.append(("titre", normaliser(brute.lstrip("#"))))
            dans_section = True
        elif brute.startswith("#"):
            dans_section = False
        elif brute.startswith("|"):
            if re.fullmatch(r"\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?", brute):
                continue
            sortie.append(("ligne", cellules(brute)))
        elif dans_section:
            sortie.append(("texte", normaliser(brute)))
    return sortie


def premier_ecart(a, b):
    n = 0
    while n < min(len(a), len(b)) and a[n] == b[n]:
        n += 1
    return n


export = elements(lire_export(sys.argv[1]))
attendu = []
for chemin in sys.argv[2:]:
    attendu.extend(elements(open(chemin, encoding="utf-8").read()))

ecarts = 0
if len(export) != len(attendu):
    print(f"nombre d'éléments différent : export {len(export)}, attendu {len(attendu)}")
for rang, (e, a) in enumerate(zip(export, attendu), 1):
    if e == a:
        continue
    ecarts += 1
    if e[0] != a[0]:
        print(f"[{rang}] nature différente : export {e[0]}, attendu {a[0]}")
        continue
    if e[0] == "ligne":
        if len(e[1]) != len(a[1]):
            print(f"[{rang}] nombre de cellules : export {len(e[1])}, attendu {len(a[1])} ; début : {a[1][0][:60]!r}")
            continue
        for k, (ce, ca) in enumerate(zip(e[1], a[1]), 1):
            if ce != ca:
                n = premier_ecart(ce, ca)
                print(f"[{rang}] cellule {k} (ligne qui commence par {a[1][0][:40]!r}) : à partir du caractère {n}\n"
                      f"    export : {ce[max(0, n - 30):n + 50]!r}\n    attendu : {ca[max(0, n - 30):n + 50]!r}")
    else:
        n = premier_ecart(e[1], a[1])
        print(f"[{rang}] {e[0]} : export {e[1][max(0, n - 30):n + 60]!r} ; attendu {a[1][max(0, n - 30):n + 60]!r}")
lignes = sum(1 for x in attendu if x[0] == "ligne")
print(f"éléments attendus {len(attendu)} (dont {lignes} lignes de tableau), éléments exportés {len(export)}, écarts {ecarts}")
sys.exit(0 if ecarts == 0 and len(export) == len(attendu) else 1)
