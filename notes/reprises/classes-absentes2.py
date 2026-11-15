import re,glob,collections
sel=set()
for f in glob.glob('public/css/**/*.css',recursive=True):
    for m in re.finditer(r'\.((?:\\.|[\w-])+)',open(f,errors='ignore').read()):
        sel.add(re.sub(r'\\(.)',r'\1',m.group(1)))
pref=r'(?:(?:sm|md|lg|xl|dark|hover|focus|group-hover):)*(?:-?(?:m|p)[trblxyse]?|gap|space-[xy]|w|h|min-w|max-w|min-h|max-h|bg|text|border|ring|rounded|grid-cols|col-span|font|leading|tracking|top|left|right|bottom|inset|z|opacity|shadow|divide-[xy]?|divide|fill|stroke|outline)-[\w./\[\]%-]+'
tok=re.compile(r'^'+pref+r'$')
miss=collections.defaultdict(set)
for f in glob.glob('resources/views/**/*.blade.php',recursive=True)+glob.glob('app/**/*.php',recursive=True):
    if '/pdf' in f or 'mail/' in f or 'courriers/' in f: continue
    s=open(f,errors='ignore').read()
    for m in re.finditer(r"""(["'])([^"'\n]{3,300})\1""",s):
        toks=m.group(2).split()
        good=[t for t in toks if tok.match(t)]
        if len(good)<2 and not (len(toks)==1 and good): continue
        for t in good:
            if t not in sel: miss[t].add(f)
for t in sorted(miss): print(t,'|',', '.join(sorted(miss[t])[:5]))
