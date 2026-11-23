import subprocess, sys, tempfile, os
chemin, base_rev, theirs_rev = sys.argv[1], sys.argv[2], sys.argv[3]
def lire(rev):
    return subprocess.run(['git','show',f'{rev}:{chemin}'],capture_output=True,text=True,check=True).stdout
def jetons(t):
    return t.replace('\n','\n\x00\n').replace(' ','\n') if False else '\n'.join(('\x01' if x=='\n' else x) for x in split(t))
def split(t):
    out=[];cur=''
    for c in t:
        if c==' ':
            out.append(cur);cur=''
        elif c=='\n':
            out.append(cur);out.append('\n');cur=''
        else: cur+=c
    out.append(cur)
    return out
def ecrire(nom,t):
    with open(nom,'w') as f: f.write('\n'.join(('\x01' if x=='\n' else x) for x in split(t))+'\n')
def rejoindre(fichier):
    t=open(fichier).read()
    morceaux=t.split('\n')
    if morceaux and morceaux[-1]=='': morceaux.pop()
    res='';premier=True
    for m in morceaux:
        if m=='\x01': res+='\n'; premier=True
        else:
            res+=('' if premier else ' ')+m; premier=False
    return res
d=tempfile.mkdtemp()
ours=lire('HEAD'); base=lire(base_rev); theirs=lire(theirs_rev)
for n,t in (('o',ours),('b',base),('t',theirs)): ecrire(f'{d}/{n}',t)
r=subprocess.run(['git','merge-file','-p','--diff3','-L','HEAD','-L','base','-L','lot',f'{d}/o',f'{d}/b',f'{d}/t'],capture_output=True,text=True)
open(f'{d}/m','w').write(r.stdout)
print('conflits:',r.returncode, file=sys.stderr)
if r.returncode==0:
    open(chemin,'w').write(rejoindre(f'{d}/m'))
