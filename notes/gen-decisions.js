const fs = require('fs');
const html = fs.readFileSync(process.argv[2], 'utf8');
const js = html.match(/<script>([\s\S]*)<\/script>/)[1];
const data = js.split('const COLLECTION')[0];
const { GROUPES, DECISIONS } = new Function(data + '; return { GROUPES, DECISIONS };')();
const dir = process.argv[3];
let out = '# Décisions de l\'éditeur (page d\'arbitrages, réponses du 9 octobre 2026)\n\n';
for (const g of GROUPES) {
  out += `## ${g.id}. ${g.titre}\n\n`;
  for (const d of DECISIONS.filter(x => x.g === g.id)) {
    const r = JSON.parse(fs.readFileSync(`${dir}/${d.id}.json`, 'utf8'));
    const rep = r.data || r;
    const opt = d.options.find(o => o.k === rep.choix);
    const choix = rep.choix === 'autre' ? 'Autre choix' : (opt ? opt.t : rep.choix);
    out += `- **${d.id} – ${d.titre}** (${d.source})\n  - Choix : ${choix}${rep.choix === d.reco ? ' (recommandation)' : ''}\n`;
    if (rep.note) out += `  - Note de l'éditeur : « ${rep.note} »\n`;
  }
  out += '\n';
}
fs.writeFileSync(process.argv[4], out);
