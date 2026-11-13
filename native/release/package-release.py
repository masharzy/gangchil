"""Package both completed binaries and a hosted update manifest; no signing keys included."""
import argparse, hashlib, json, pathlib, re, zipfile
p=argparse.ArgumentParser();p.add_argument('--version',required=True);p.add_argument('--apk',required=True);p.add_argument('--exe',required=True);p.add_argument('--out',required=True);a=p.parse_args()
if not re.fullmatch(r'\d+\.\d+\.\d+',a.version):p.error('Use a semantic version')
manifest={}
with zipfile.ZipFile(a.out,'w',zipfile.ZIP_DEFLATED) as z:
 for platform,source,name,extension in [('android',a.apk,'Gangchil-Android','apk'),('windows',a.exe,'Gangchil-Setup','exe')]:
  source=pathlib.Path(source);data=source.read_bytes()
  if len(data)<100000:raise ValueError('Incomplete release binary')
  filename=f'{name}-{a.version}.{extension}';z.write(source,'downloads/'+filename)
  manifest[platform]={'version':a.version,'url':'https://gangchil.xyz/downloads/'+filename,'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()}
 # Publish the manifest after the download files.
 z.writestr('native-update.json',json.dumps(manifest,indent=2)+'\n')
print('Release package:',a.out)
