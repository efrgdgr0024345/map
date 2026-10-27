#!/usr/bin/env python3
"""Build the reviewed 100-place catalogue from local source records; never runs on cPanel.
Input records are produced from the trusted Geofabrik WA extract (node/way tags
and coordinates) and the exact Wikidata points recorded in the selection file.
This build has no remote fetches and does not invent opening hours or events.
"""
import argparse,datetime as dt,hashlib,json,re,urllib.parse
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def http_url(value):
 if not value:return None
 p=urllib.parse.urlparse(value)
 return value if p.scheme in ('http','https') and p.hostname and not p.username else None

def build(records,selection,stamp):
 source=json.loads((ROOT/'assets/place-access.json').read_text())['source']
 if isinstance(records,dict):source=records['source'];records=records['records']
 points={p['id']:p for p in records};places=[]
 access=json.loads((ROOT/'assets/place-access.json').read_text())['places']
 for rank,item in enumerate(selection,1):
  osm=item.get('osm');tags={};nav=None
  if osm:
   row=points[osm];tags=row['tags'];lat,lon=row['lat'],row['lon'];source_url='https://www.openstreetmap.org/'+osm;as_of=source['updatedAt']
   kind='mapped-point' if osm.startswith('node/') else 'area-centre'
   # A named open path's location remains a map point, not a building entrance.
   if tags.get('highway') in ('path','footway','pedestrian','cycleway'):kind='mapped-path-point'
   if tags.get('natural') in ('bay','water'):kind='area-centre'
   entrances=row.get('entrances',[])
   if entrances:
    entrance=entrances[0];nav={'lat':round(entrance['lat'],7),'lon':round(entrance['lon'],7),'kind':'mapped-entrance','label':'Mapped entrance','checkedAt':None,'sourceUrl':source_url}
   for reviewed in access:
    if osm==f"way/{reviewed['osmId']}" and reviewed['osmType']=='W':
     nav={'lat':reviewed['access']['lat'],'lon':reviewed['access']['lon'],'kind':'reviewed-access','label':reviewed['access']['name'],'checkedAt':reviewed['reviewedAt'],'sourceUrl':reviewed['access']['sourceUrl']}
   sources=[{'name':'OpenStreetMap contributors / Geofabrik','url':source_url,'asOf':as_of,'license':'ODbL-1.0'}]
   source_name=row['name']
  else:
   lon,lat=map(float,re.fullmatch(r'Point\(([-.0-9]+) ([-.0-9]+)\)',item['coordinate']).groups());lat,lon=round(lat,7),round(lon,7);kind='area-centre'
   source_url='https://www.wikidata.org/wiki/'+item['wikidata'];as_of=None
   sources=[{'name':'Wikidata','url':source_url,'retrievedAt':item.get('retrievedAt'),'license':'CC0-1.0'}];source_name=item['name']
  address={k:tags.get(v) for k,v in [('houseNumber','addr:housenumber'),('street','addr:street'),('suburb','addr:suburb'),('postcode','addr:postcode')]}
  address['display']=', '.join(filter(None,[' '.join(filter(None,[address['houseNumber'],address['street']])),address['suburb'],address['postcode']])) or None
  aliases=list(dict.fromkeys(item['aliases']+([source_name] if source_name!=item['name'] else[])))
  ident='osm-'+osm.replace('/','-') if osm else 'wikidata-'+item['wikidata'].lower()
  places.append({'id':ident,'name':item['name'],'aliases':aliases,'category':item['category'],'lat':lat,'lon':lon,
   'location':{'kind':kind,'sourceUrl':source_url,'entranceConfirmed':False},'navigation':nav,'address':address,
   'openingHours':{'text':tags.get('opening_hours'),'timezone':'Australia/Perth','sourceUrl':source_url if tags.get('opening_hours') else None,'asOf':as_of if tags.get('opening_hours') else None,'verifiedAt':None},
   'contact':{'website':http_url(tags.get('website') or tags.get('contact:website')),'phone':tags.get('phone') or tags.get('contact:phone'),'email':tags.get('email') or tags.get('contact:email'),'bookingUrl':None,'social':{}},
   'whatsOn':{'events':[],'sourceUrl':None,'lastCheckedAt':None},
   'accessibility':{'wheelchair':tags.get('wheelchair'),'stepFree':None,'notes':None},
   'defaultStayMinutes':90 if item['category'] in ('park','museum','shopping','attraction') else 60,
   'display':{'enabled':True,'priority':rank},'editorial':{'summary':None,'lastReviewedAt':None},
   'pricing':{'description':None,'currency':'AUD','bookingRequired':None},'sources':sources})
 return {'schemaVersion':1,'revision':'perth-curated-100-v1','updatedAt':stamp,
  'description':'100 curated, well-known Perth-area places. Editorial selection, not measured visitor rankings.',
  'ranking':{'method':'editorial','visitorCounts':None},'updatePolicy':{'mode':'manual','automaticRefresh':False,'nextReviewAt':None},
  'source':{'name':'OpenStreetMap contributors / Geofabrik and Wikidata','osmSnapshotAt':source['updatedAt'],'osmExtractUrl':source['url'],'osmSha256':source['sha256'],'license':'ODbL-1.0 (OSM-derived database); Wikidata source points CC0-1.0','attribution':'© OpenStreetMap contributors · Wikidata','attributionUrl':'https://www.openstreetmap.org/copyright'},
  'places':places}
if __name__=='__main__':
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--records',default=ROOT/'scripts/data/perth-poi-osm-records.json',type=Path);parser.add_argument('--selection',type=Path,default=ROOT/'scripts/data/perth-poi-selection.json');parser.add_argument('--output',type=Path,default=ROOT/'assets/perth-pois.json');args=parser.parse_args()
 catalog=build(json.loads(args.records.read_text()),json.loads(args.selection.read_text()),dt.datetime.now(dt.timezone.utc).isoformat().replace('+00:00','Z'))
 assert len(catalog['places'])==100
 args.output.write_text(json.dumps(catalog,ensure_ascii=False,indent=2,allow_nan=False)+'\n');print(f"Wrote {len(catalog['places'])} POIs; {args.output.stat().st_size} bytes")
