"""Bundle existing UI, fonts and Pusher into each app. No remote HTML/JS dependency."""
from pathlib import Path
import re,hashlib,urllib.request,shutil
root=Path(__file__).resolve().parents[2];public=root/'public';out=root/'native/shared/ui';out.mkdir(parents=True,exist_ok=True)
for p in public.rglob('*'):
 if p.is_file() and p.suffix.lower() in ['.html','.css','.js','.json','.svg','.png','.ico'] and 'uploads' not in p.parts:
  dest=out/p.relative_to(public);dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
index=(out/'index.html').read_text();css=[]
for link,url in re.findall(r'(<link[^>]*href="(https://fonts.googleapis.com/[^\"]+)"[^>]*>)',index):
 text=urllib.request.urlopen(url,timeout=40).read().decode()
 for remote in set(re.findall(r'url\((https://[^)]+)\)',text)):
  name='font-'+hashlib.sha256(remote.encode()).hexdigest()[:16]+'.ttf';dest=out/'fonts'/name;dest.parent.mkdir(exist_ok=True)
  if not dest.exists():dest.write_bytes(urllib.request.urlopen(remote,timeout=40).read())
  text=text.replace(remote,'/fonts/'+name)
 css.append(text);index=index.replace(link,'')
(out/'fonts.css').write_text('\n'.join(css));index=index.replace('</head>','<link rel="stylesheet" href="/fonts.css"></head>')
pusher=out/'vendor/pusher.min.js';pusher.parent.mkdir(exist_ok=True)
if not pusher.exists():pusher.write_bytes(urllib.request.urlopen('https://cdn.jsdelivr.net/npm/pusher-js@8.6.0/dist/web/pusher.min.js',timeout=40).read())
index=index.replace('https://cdn.jsdelivr.net/npm/pusher-js@8.6.0/dist/web/pusher.min.js','/vendor/pusher.min.js')
index=index.replace('<head>','<head><script src="/native-start.js"></script>').replace('</head>','<style>#pwaInstallButton{display:none!important}</style></head>')
(out/'index.html').write_text(index)
print('Bundled local UI assets:',len(list(out.rglob('*'))))
