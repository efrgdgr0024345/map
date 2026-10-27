#!/usr/bin/env python3
import json,tempfile,math,argparse,hashlib
from pathlib import Path
parser=argparse.ArgumentParser(description='Development-only extraction from a trusted local Geofabrik PBF. Requires pyosmium 4.x.')
parser.add_argument('--pbf',required=True,type=Path);parser.add_argument('--source-date',required=True);parser.add_argument('--output',required=True,type=Path);args=parser.parse_args()
import osmium
B=(115.45,-32.6,116.25,-31.45)
class Handler(osmium.SimpleHandler):
 def __init__(self):super().__init__();self.items=[];self.entrances={}
 def node(self,n):
  if not n.location.valid():return
  lon,lat=n.location.lon,n.location.lat
  if not(B[0]<=lon<=B[2] and B[1]<=lat<=B[3]):return
  t=dict(n.tags)
  if t.get('entrance') in ('main','yes') and t.get('access') not in ('private','no') and t.get('foot') not in ('private','no'):self.entrances[n.id]={'lat':lat,'lon':lon,'tags':t}
  if t.get('name') and any(k in t for k in ['tourism','leisure','shop','amenity','historic','natural','aeroway','railway','man_made','building','highway']):self.items.append({'id':f'node/{n.id}','name':t['name'],'lat':round(lat,7),'lon':round(lon,7),'tags':t})
 def way(self,w):
  t=dict(w.tags)
  if not t.get('name') or not any(k in t for k in ['tourism','leisure','shop','amenity','historic','natural','aeroway','railway','man_made','building','highway']):return
  try:pts=[(n.lon,n.lat) for n in w.nodes]
  except osmium.InvalidLocationError:return
  if not pts:return
  lon=sum(p[0] for p in pts)/len(pts);lat=sum(p[1] for p in pts)/len(pts)
  if B[0]<=lon<=B[2] and B[1]<=lat<=B[3]:self.items.append({'id':f'way/{w.id}','name':t['name'],'lat':round(lat,7),'lon':round(lon,7),'tags':t,'nodes':[n.ref for n in w.nodes]})
h=Handler()
with tempfile.TemporaryDirectory(prefix='nextstop-poi-nodes-') as folder:
 index=osmium.index.create_map(f'sparse_file_array,{folder}/nodes.idx');locations=osmium.NodeLocationsForWays(index);locations.ignore_errors()
 with osmium.io.Reader(str(args.pbf)) as r:osmium.apply(r,locations,osmium.filter.KeyFilter('name','entrance'),h)
for item in h.items:
 hits=[h.entrances[n] for n in item.pop('nodes',[]) if n in h.entrances]
 if hits:item['entrances']=sorted(hits,key=lambda e:e['tags'].get('entrance')!='main')
payload={'source':{'url':'https://download.geofabrik.de/australia-oceania/australia/western-australia-latest.osm.pbf','updatedAt':args.source_date,'sha256':hashlib.file_digest(args.pbf.open('rb'),'sha256').hexdigest()},'records':h.items}
args.output.write_text(json.dumps(payload,ensure_ascii=False)+'\n')
print('Candidates:',len(h.items),'entrances:',len(h.entrances))
