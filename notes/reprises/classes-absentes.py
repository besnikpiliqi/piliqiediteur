import re,glob,collections
sel=set()
for f in glob.glob('public/css/**/*.css',recursive=True):
    css=open(f,errors='ignore').read()
    for m in re.finditer(r'\.((?:\\.|[\w-])+)',css):
        sel.add(re.sub(r'\\(.)',r'\1',m.group(1)))
miss=collections.defaultdict(set)
files=glob.glob('resources/views/**/*.blade.php',recursive=True)+glob.glob('app/**/*.php',recursive=True)
for f in files:
    s=open(f,errors='ignore').read()
    for m in re.finditer(r'class="([^"]*)"',s):
        for t in re.sub(r'\{\{.*?\}\}|@\w+\([^)]*\)','',m.group(1)).split():
            if not re.match(r'^[a-z!-][\w:/.\[\]%-]*$',t) or t.startswith(('fi-','piliqi')): continue
            if t not in sel: miss[t].add(f)
    for m in re.finditer(r"=> '([a-z][^']*)'",s):
        toks=m.group(1).split()
        if len(toks)>=2 and all(re.match(r'^[a-z][\w:/.-]*-\d',x) or x in('flex','hidden','block') for x in toks[:2]):
            for t in toks:
                if t not in sel: miss[t].add(f+' (tableau)')
for t in sorted(miss): print(t,'|',len(miss[t]),'|',', '.join(sorted(miss[t])[:4]))
