#!/usr/bin/env python3
"""Extrait, de la transcription d'une session, les messages tapés par l'éditeur et les résumés
successifs de la session, en retirant clés, jetons, mots de passe, cookies, navigateurs et adresses
personnelles. Usage : extraire-historique.py TRANSCRIPTION SORTIE_MESSAGES SORTIE_RESUMES [RETRAITS_LOCAUX].
RETRAITS_LOCAUX (facultatif, jamais publié) : une chaîne à retirer par ligne (données personnelles)."""
import json, re, sys

src, sortie_messages, sortie_resumes = sys.argv[1:4]
locaux = []
if len(sys.argv) > 4:
    try:
        locaux = [l.strip() for l in open(sys.argv[4]) if l.strip()]
    except FileNotFoundError:
        pass

PEM = re.compile(r'-----BEGIN [A-Z0-9 ]+-----.*?(-----END [A-Z0-9 ]+-----|$)', re.S)
TOKEN = re.compile(r'(?<![A-Za-z0-9_/\-])(?=[A-Za-z0-9_\-+/=]*[0-9])(?=[A-Za-z0-9_\-+/=]*[A-Za-z])[A-Za-z0-9_\-+/=]{24,}(?![A-Za-z0-9_/\-])')
KV = re.compile(r'(?i)((?:client[_ ]?secret|client[_ ]?id|secret|password|mot de passe|passphrase|phrase secr[eè]te|token|jeton|api[_ ]?key|cl[eé] (?:priv[ée]e|d.api))\s*[:=]\s*)(\S+)')
EMAIL = re.compile(r'[A-Za-z0-9._%+-]+@(gmail|googlemail|outlook|hotmail|live|yahoo|icloud|orange|free|laposte|sfr|wanadoo)\.[a-z]{2,}')
UA = re.compile(r'(\*\*user-agent\*\*: ).*')
PG = re.compile(r'(PILIQI_PG_MOT_DE_PASSE=)(?!\[|…|\.\.\.|\$|"\$|%s)[^\s`"\']+')


def nettoie(t):
    t = PEM.sub('[clé ou certificat retiré]', t)
    t = KV.sub(lambda m: m.group(1) + '[valeur retirée]', t)

    def jeton(m):
        s = m.group(0)
        if re.fullmatch(r'[0-9a-f]{7,40}', s):
            return s
        if s.startswith(('session_', 'wf_', 'trig_', 'toolu_', 'env_')):
            return s
        if '/' in s and not re.search(r'[+=]', s):
            return s
        return '[jeton retiré]'

    t = TOKEN.sub(jeton, t)
    t = EMAIL.sub('[adresse retirée]', t)
    t = UA.sub(r'\1[navigateur retiré]', t)
    t = PG.sub(r'\1[valeur retirée]', t)
    for chaine in locaux:
        t = re.sub(re.escape(chaine), '[donnée personnelle retirée]', t, flags=re.I)
    return t


messages, resumes = [], []
for ligne in open(src):
    try:
        e = json.loads(ligne)
    except ValueError:
        continue
    if e.get('type') != 'user':
        continue
    c = e.get('message', {}).get('content')
    images = 0
    if isinstance(c, list):
        if any(b.get('type') == 'tool_result' for b in c):
            continue
        texte = '\n'.join(b.get('text', '') for b in c if b.get('type') == 'text')
        images = sum(1 for b in c if b.get('type') == 'image')
    else:
        texte = c or ''
    quand = (e.get('timestamp') or '')[:16].replace('T', ' ')
    if e.get('isCompactSummary') or 'continued from a previous conversation' in texte[:300]:
        resumes.append((quand, texte))
        continue
    if e.get('isMeta'):
        continue
    s = texte.strip()
    if s.startswith('Stop hook feedback') or s.startswith('[Request interrupted'):
        continue
    if re.match(r'<(system-reminder|task-notification|command-|local-command|bash-|user-memory)', s):
        continue
    s = re.sub(r'<system-reminder>.*?</system-reminder>', '', s, flags=re.S).strip()
    if not s and not images:
        continue
    messages.append((quand, nettoie(s), images))

with open(sortie_messages, 'w') as f:
    f.write("# Messages de l'éditeur, du premier au dernier\n\nTous les messages tapés par l'éditeur dans cette session (heure UTC), dans l'ordre, tels qu'il les a écrits, régénérés à chaque mise à jour de la branche de reprise. Les captures d'écran sont signalées sans leur image. Toute clé, tout jeton, tout mot de passe, tout navigateur et toute donnée personnelle ont été retirés. Les décisions qui font foi sont dans les fichiers de décision (README.md, § 7).\n\n")
    for i, (quand, s, images) in enumerate(messages, 1):
        f.write(f"## {i}. {quand} UTC\n\n")
        if images:
            f.write(f"[{images} capture(s) d'écran jointe(s)]\n\n")
        f.write(s.replace('\n', '\n\n') if len(s) < 4000 else s)
        f.write('\n\n')
with open(sortie_resumes, 'w') as f:
    f.write("# Résumés successifs de la session de développement\n\nÀ chaque fois que la conversation devenait trop longue, elle a été résumée : voici ces résumés, dans l'ordre (heure UTC). Toute clé, tout jeton et tout mot de passe ont été retirés.\n\n")
    for i, (quand, s) in enumerate(resumes, 1):
        f.write(f"## Résumé {i}, {quand} UTC\n\n{nettoie(s)}\n\n")
print(f"{len(messages)} messages, {len(resumes)} résumés")
