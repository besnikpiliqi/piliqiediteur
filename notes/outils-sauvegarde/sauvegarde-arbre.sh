#!/usr/bin/env bash
# Usage : sauvegarde-arbre.sh <dossier> <nom> : photographie l'arbre (non commité compris) sans toucher à la copie de travail, la pousse sur claude/sauvegarde-<nom> avec [skip ci].
set -uo pipefail
D="$1"; N="$2"
cd "$D" || exit 1
IDX=$(mktemp -u /tmp/idx-sauvegarde.XXXXXX)
cp "$(git rev-parse --git-path index)" "$IDX"
export GIT_INDEX_FILE="$IDX"
git add -A >/dev/null 2>&1
TREE=$(git write-tree) || { echo "ERREUR write-tree $N"; exit 2; }
PARENTS="-p $(git rev-parse HEAD)"
if git rev-parse -q --verify MERGE_HEAD >/dev/null; then PARENTS="$PARENTS -p $(git rev-parse MERGE_HEAD)"; fi
MSG="Sauvegarde du chantier « $N » au 23/11/2026 (arrêt demandé par l'éditeur) [skip ci]

Photographie de l'arbre de travail (commits, fichiers modifiés et nouveaux), sans
rien changer à la copie de travail. Branche d'origine : $(git branch --show-current), tête $(git rev-parse --short HEAD).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01K3593fBCSfG8EcbWrZryb6"
COMMIT=$(git commit-tree "$TREE" $PARENTS -m "$MSG")
unset GIT_INDEX_FILE; rm -f "$IDX"
# refus des secrets et des gros fichiers dans ce qui part
AJOUT=$(git diff "$(git rev-parse HEAD)" "$COMMIT" 2>/dev/null)
if printf '%s' "$AJOUT" | grep -qE 'BEGIN [A-Z ]*PRIVATE KEY|ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}'; then echo "ERREUR $N : un secret possible dans l'arbre, rien n'est poussé"; exit 3; fi
GROS=$(git diff --stat=200 "$(git rev-parse HEAD)" "$COMMIT" 2>/dev/null | tail -1)
echo "$N : $(git rev-parse --short "$COMMIT") ($(git diff --name-only "$(git rev-parse HEAD)" "$COMMIT" | wc -l) fichiers en plus de la tête) — $GROS"
for a in 0 3 6 12; do sleep $a; if git push -q origin "$COMMIT:refs/heads/claude/sauvegarde-$N" 2>/tmp/push-sauv.err; then echo "  poussé sur claude/sauvegarde-$N"; exit 0; fi; done
echo "ERREUR poussée $N : $(tail -2 /tmp/push-sauv.err)"; exit 4
