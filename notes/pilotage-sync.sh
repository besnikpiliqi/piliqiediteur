#!/usr/bin/env bash
# Copie les notes de pilotage dans la branche claude/pilotage-reprise, régénère ETAT.md,
# refuse tout secret, puis commite et pousse. Usage : pilotage-sync.sh "message".
set -uo pipefail
S=/tmp/claude-0/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/scratchpad
W="$S/pilotage-reprise"
DEPOT=/home/user/piliqiediteur
BRANCHE_TRAVAIL=claude/saas-invoice-editor-architecture-q6eizh
WF=/root/.claude/projects/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/subagents/workflows
SCRIPTS=/root/.claude/projects/-home-user-piliqiediteur/00d7e3b2-c4d1-5536-95c0-3e77e19552bb/workflows/scripts
MESSAGE="${1:-Mise à jour des notes de reprise}"
set +u
[ -f "$HOME/.profile" ] && . "$HOME/.profile" >/dev/null 2>&1
set -u
[ -d "$W" ] || { echo "ERREUR : dossier $W absent"; exit 1; }

# Messages de l'éditeur et résumés de cette session, régénérés à chaque fois (un fichier par session).
U_SESSION=$(basename "${WF%/subagents/workflows}")
SUFFIXE=""; [ "$U_SESSION" = "00d7e3b2-c4d1-5536-95c0-3e77e19552bb" ] || SUFFIXE="-${U_SESSION:0:8}"
TRANSCRIPTION="${WF%/subagents/workflows}.jsonl"
if [ -f "$TRANSCRIPTION" ] && [ -f "$S/historique/extraire-historique.py" ]; then
  mkdir -p "$W/notes/historique"
  python3 "$S/historique/extraire-historique.py" "$TRANSCRIPTION" "$W/notes/historique/messages-editeur$SUFFIXE.md" \
    "$W/notes/historique/resumes-de-session$SUFFIXE.md" "$S/historique/retraits-locaux.txt" >/dev/null \
    || echo "ATTENTION : extraction de l'historique impossible"
fi

while IFS= read -r f; do
  [ -z "$f" ] && continue
  if [ -f "$S/$f" ]; then mkdir -p "$W/notes/$(dirname "$f")"; cp "$S/$f" "$W/notes/$f"; fi
done < "$S/pilotage-fichiers.txt"
mkdir -p "$W/notes/reprises"
for f in "$SCRIPTS"/*-correction-reprise-puis-chaine-*.js; do
  [ -f "$f" ] && cp "$f" "$W/notes/reprises/$(basename "$f" | sed 's/-wf_[a-z0-9-]*\.js$/.js/')"
done
for f in "$S"/reprises/*.js; do
  [ -f "$f" ] && cp "$f" "$W/notes/reprises/"
done
cp "$S/pilotage-sync.sh" "$S/pilotage-fichiers.txt" "$W/notes/"

JOURNAL=""
JOURNAUX=$(find "$WF" -name journal.jsonl 2>/dev/null)
if [ -n "$JOURNAUX" ]; then
  JOURNAL=$(grep -lE '"label":"[A-Za-z0-9-]+:(implementation|relecture[12]|correction|correction-reprise|poussee|fiche|notes)"' $JOURNAUX </dev/null 2>/dev/null | xargs -r ls -t 2>/dev/null | head -1)
fi
{
  echo "# État du projet (généré automatiquement)"
  echo
  echo "Mis à jour le $(date -u '+%d/%m/%Y à %H:%M') UTC par pilotage-sync.sh : « $MESSAGE »."
  echo
  echo "## Branche de travail ($BRANCHE_TRAVAIL)"
  echo
  echo "Tête poussée : \`$(git -C "$DEPOT" rev-parse --short "origin/$BRANCHE_TRAVAIL" 2>/dev/null)\`. Commits locaux pas encore poussés :"
  echo
  echo '```'
  git -C "$DEPOT" log --oneline "origin/$BRANCHE_TRAVAIL..HEAD" 2>/dev/null
  echo '```'
  echo
  echo "Derniers commits poussés :"
  echo
  echo '```'
  git -C "$DEPOT" log --oneline -20 "origin/$BRANCHE_TRAVAIL" 2>/dev/null
  echo '```'
  echo
  echo "## Travail non commité dans l'arbre (agent en cours, ne jamais le committer à sa place)"
  echo
  echo '```'
  git -C "$DEPOT" status --short 2>/dev/null | head -60
  echo '```'
  echo
  echo "## Dernière course de la chaîne"
  echo
  if [ -n "$JOURNAL" ]; then
    echo "Course \`$(basename "$(dirname "$JOURNAL")")\`, étapes commencées (de la plus ancienne à la plus récente) :"
    echo
    echo '```'
    grep -o '"label":"[^"]*"' "$JOURNAL" | sed 's/"label":"//; s/"$//' | awk '!vu[$0]++' | tail -25
    echo '```'
  else
    echo "Aucune course trouvée."
  fi
} > "$W/ETAT.md"

TROUVE=0
if grep -rlE 'BEGIN [A-Z ]*PRIVATE KEY|ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|[A-Za-z0-9._%+-]+@(gmail|googlemail|outlook|hotmail|live|yahoo|icloud|orange|free|laposte|sfr|wanadoo)\.[a-z]{2,}' "$W" --exclude-dir=.git >/dev/null 2>&1; then TROUVE=1; fi
for v in RECOMMANDE_CLE RECOMMANDE_SECRET_RAPPEL JOURNAL_AUDIT_CLES_PRECEDENTES PILIQI_PG_MOT_DE_PASSE EDITEUR_OPERATEURS PILIQI_EDITEUR_EMAIL JOURNAL_AUDIT_CLE_ID SUPERPDP_CLIENT_SECRET SUPERPDP_CLIENT_ID SUPERPDP_TRICATEL_CLIENT_SECRET SUPERPDP_TRICATEL_CLIENT_ID INSEE_SIRENE_CLE INPI_RNE_IDENTIFIANT INPI_RNE_MOT_DE_PASSE JOURNAL_AUDIT_CLE CAISSE_SECURISEE_CLE_PRIVEE CAISSE_SECURISEE_PHRASE_SECRETE; do
  val="${!v:-}"
  # Une valeur publique (celle de la base de test de la CI, écrite dans le dépôt) n'est pas un secret.
  if [ ${#val} -ge 6 ] && ! grep -qF -- "$val" "$DEPOT/.github/workflows/tests.yml" 2>/dev/null \
     && grep -rlF --exclude-dir=.git -- "$val" "$W" >/dev/null 2>&1; then TROUVE=1; fi
done
if [ "$TROUVE" = 1 ]; then echo "ERREUR : un secret possible a été trouvé dans les notes ; rien n'est commité ni poussé."; exit 2; fi

cd "$W" || exit 1
git add -A
if git diff --cached --quiet; then echo "Aucun changement dans les notes."; exit 0; fi
git commit -q -m "$MESSAGE" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01K3593fBCSfG8EcbWrZryb6" || { echo "ERREUR : commit impossible"; exit 3; }
for attente in 0 2 4 8 16; do
  sleep "$attente"
  if git push -q -u origin claude/pilotage-reprise 2>/tmp/pilotage-push.err; then echo "Notes poussées : $(git rev-parse --short HEAD)."; exit 0; fi
done
echo "ERREUR : poussée impossible : $(tail -2 /tmp/pilotage-push.err)"; exit 4
