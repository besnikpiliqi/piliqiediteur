"""Surveille les colonnes « Réponse préparée et décidée par Claude » et « Réponse du professionnel » de l'onglet professionnel (D69, D72).

Usage : python3 -I surveiller.py <export-markdown-de-l-onglet> [--enregistrer]
Compare les deux dernières colonnes de chaque ligne (Claude, puis professionnel) à l'instantané (instantane.json, à côté
de ce script) et affiche chaque ligne ajoutée, retirée ou changée ; --enregistrer
remplace l'instantané par l'état lu. Clé d'une ligne : section + N° (sans la date).
"""
import json
import pathlib
import re
import sys

ICI = pathlib.Path(__file__).resolve().parent
INSTANTANE = ICI / 'instantane.json'


def lire(chemin):
    lignes = {}
    section = sujet = None
    for brut in pathlib.Path(chemin).read_text(encoding='utf-8').split('\n'):
        if brut.startswith('## '):
            section = brut[3:].strip()
        elif brut.startswith('### '):
            sujet = brut[4:].strip()
        elif brut.startswith('|') and not re.match(r'^\|\s*-', brut) and not brut.startswith('| N°'):
            cellules = [c.strip() for c in brut.strip().strip('|').split('|')]
            if len(cellules) < 6:
                continue
            numero = re.sub(r'<[^>]+>|\*', '', cellules[0]).split('(')[0].strip()
            cle = f'{section} | {numero}'
            n = 2
            while cle in lignes:
                cle = f'{section} | {numero} #{n}'
                n += 1
            lignes[cle] = {'sujet': sujet, 'reponse': cellules[-1], 'claude': cellules[-2] if len(cellules) >= 7 else None}
    return lignes


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(2)
    actuel = lire(sys.argv[1])
    ancien = json.loads(INSTANTANE.read_text(encoding='utf-8')) if INSTANTANE.exists() else {}
    changes = 0
    for cle in sorted(set(actuel) | set(ancien)):
        for colonne, nom in (('reponse', 'Réponse du professionnel'), ('claude', 'Réponse préparée et décidée par Claude')):
            avant = ancien.get(cle, {}).get(colonne)
            apres = actuel.get(cle, {}).get(colonne)
            if avant != apres:
                changes += 1
                etat = 'AJOUTÉE' if avant is None else 'RETIRÉE' if apres is None else 'CHANGÉE'
                print(f'{etat} [{nom}] : {cle}')
                if avant is not None:
                    print(f'  avant : {avant[:400]}')
                if apres is not None:
                    print(f'  après : {apres[:400]}')
    print(f'{changes} ligne(s) changée(s) sur {len(actuel)}.')
    if '--enregistrer' in sys.argv:
        INSTANTANE.write_text(json.dumps(actuel, ensure_ascii=False, indent=1), encoding='utf-8')
        print('Instantané enregistré.')


if __name__ == '__main__':
    main()
