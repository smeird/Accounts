#!/usr/bin/env python3
"""Check maintained Markdown paths/anchors and Bash snippet syntax without executing examples."""
from pathlib import Path
import re,subprocess,unicodedata
from urllib.parse import unquote
root=Path(__file__).resolve().parents[1];errors=[];links=0;snippets=0
files=[root/'README.md',root/'REQUIREMENTS.md',*sorted((root/'wiki').glob('*.md'))]
def anchors(path):
 text=path.read_text();text=re.sub(r'```.*?```','',text,flags=re.S);out=set();seen={}
 for h in re.findall(r'^#{1,6}\s+(.+?)\s*#*$',text,re.M):
  h=re.sub(r'\[([^]]+)\]\([^)]*\)',r'\1',h).replace('`','').replace('*','').lower()
  h=''.join(c for c in h if c in '-_ ' or unicodedata.category(c)[0] in 'LN').replace(' ','-')
  n=seen.get(h,0);seen[h]=n+1;out.add(h+('-'+str(n) if n else ''))
 return out
for p in files:
 text=p.read_text();clean=re.sub(r'```.*?```','',text,flags=re.S)
 for target in re.findall(r'!?\[[^]\n]*\]\(([^\s)]+)\)',clean):
  if target.startswith(('https://','http://','mailto:')):continue
  name,sep,anchor=target.partition('#');q=(p.parent/unquote(name)).resolve() if name else p
  links+=1
  if not q.exists():errors.append(f'{p.relative_to(root)}: missing {target}')
  elif sep and q.suffix=='.md' and unquote(anchor) not in anchors(q):errors.append(f'{p.relative_to(root)}: missing anchor {target}')
 for snippet in re.findall(r'```bash\n(.*?)```',text,re.S):
  snippets+=1;r=subprocess.run(['bash','-n'],input=snippet,text=True,capture_output=True)
  if r.returncode: errors.append(f'{p.relative_to(root)}: shell syntax: {r.stderr}')
print(f'Checked {len(files)} documents, {links} local links/anchors, {snippets} shell snippets.')
for e in errors:print(e)
raise SystemExit(bool(errors))
