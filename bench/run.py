"""Run the two original Board programs, alternating both arms on one runner."""
from pathlib import Path
from contextlib import contextmanager
import json,os,re,signal,socket,subprocess,time,sys,statistics,urllib.request,urllib.error,datetime
r=Path(__file__).resolve().parent;report=r/'report';report.mkdir(exist_ok=True);demo=Path(os.environ['GITHUB_WORKSPACE'])/'demo';cfg=json.loads((r/'config.json').read_text());secrets={a:os.environ.pop('BENCH_'+a.upper()) for a in cfg};assert len(set(secrets.values()))==2
@contextmanager
def server(args,env):
    p=subprocess.Popen(args,cwd=demo,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    try: yield p
    finally:
        if p.poll() is None:os.killpg(p.pid,signal.SIGTERM);p.wait(timeout=10)
def get(url,auth=None):
    h={'User-Agent':'Atoms-Mancala-Qualification/1.0'}
    if auth:h['Authorization']='Bearer '+auth
    try:
        with urllib.request.urlopen(urllib.request.Request(url,headers=h),timeout=10) as res:return res.status,res.read(),res.headers.get('cf-ray')
    except urllib.error.HTTPError as e:return e.code,b'',e.headers.get('cf-ray')

def run(cmd,env):subprocess.run(cmd,cwd=demo,env=env,stdout=subprocess.DEVNULL,check=True)
context='atoms-211/benchmark-'+os.environ['GITHUB_RUN_ID']
status_url='https://api.github.com/repos/AtomsPHP/atoms/commits/'+os.environ['GITHUB_SHA']+'/status'
for attempt in range(80):
    try:
        status,body,_=get(status_url);states=json.loads(body).get('statuses',[])
        if any(x['context']==context and x['state']=='success' for x in states):break
    except (OSError,ValueError):pass
    time.sleep(15)
else:raise RuntimeError('Local operator did not acknowledge both matching callback deployments')
for arm in cfg:
    deadline=time.monotonic()+1200
    while time.monotonic()<deadline:
        try:status,body,ray=get(cfg[arm]+'/healthz'); ready=status==200 and json.loads(body).get('ok')==True
        except (OSError,ValueError):ready=False
        if ready:break
        time.sleep(5)
    else:raise RuntimeError('Waiting for benchmark deployment timed out: '+arm)
    status,_,_=get(cfg[arm]+'/debug/MancalaGame/readiness/info'); assert status in (401,403),(arm,status)
    (report/(arm+'-runner-ingress.json')).write_text(json.dumps({'healthz_ray':ray,'unauthorized_status':status})+'\n')
print('Both isolated Workers are up and require authorization.',flush=True)
for round_num,arms in enumerate([('playground','candidate'),('candidate','playground')],1):
    for arm in arms:
        label=f'round{round_num}-{arm}'; (r/'active').write_text(arm); (r/'label').write_text(label)
        request_log=report/f'{label}-laravel.ndjson';db=r/(label+'.sqlite');db.touch(mode=0o600)
        env=dict(os.environ,ATOMS_ENDPOINT=cfg[arm],ATOMS_SHARED_SECRET=secrets[arm],APP_URL='http://127.0.0.1:18000',DB_CONNECTION='sqlite',DB_DATABASE=str(db),BENCH_REQUESTS=str(request_log),MANCALA_TICKET_MINTS_PER_MINUTE='1000',QUEUE_CONNECTION='sync',SESSION_DRIVER='database',CACHE_STORE='database',ATOMS_CALLBACK_NONCE_DRIVER='database',ATOMS_CALLBACK_NONCE_NAMESPACE=label,APP_DEBUG='false')
        run(['php','artisan','migrate','--force'],env)
        measurements={}; started=datetime.datetime.now(datetime.timezone.utc).isoformat()
        with server(['php','-S','127.0.0.1:18000','-t','public',str(r/'proxy-router.php')],env) as php:
            for _ in range(30):
                try:
                    if get('http://127.0.0.1:18000/')[0]==200:break
                except OSError:pass
                time.sleep(.5)
            else:raise RuntimeError('Laravel failed to start')
            for name in ['verify','cold','fresh']:
                print(label,name,flush=True)
                p=subprocess.Popen(['node',str(r/(name+'.cjs'))],cwd=demo,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
                with (report/f'{label}-{name}.log').open('w') as safe:
                    for line in p.stdout:
                        if re.match(r'^(prepared [0-9]+|Idling|sample [0-9]+|fresh [0-9]+|RESULTS |PASS:)',line):
                            safe.write(line);safe.flush()
                            if line.startswith('RESULTS '):measurements[name]=json.loads(line[8:])
                        elif line.startswith('FAILED'):safe.write(re.sub(r'https?://\S+','[URL]',line.splitlines()[0])+'\n');safe.flush()
                if p.wait():raise RuntimeError('Browser test failed: '+label+' '+name)
        rooms=sorted(set(m[1] for m in (re.fullmatch(r'/games/([a-f0-9]{32})',json.loads(line)['path']) for line in request_log.read_text().splitlines()) if m));assert len(rooms)>=41,(label,len(rooms));(report/f'{label}-rooms.json').write_text(json.dumps(rooms)+'\n')
        assert len(measurements['cold'])==20 and len(measurements['fresh']['creation'])==20 and len(measurements['fresh']['revisit'])==20
        measurements['room_count']=len(rooms);measurements['start']=started;measurements['end']=datetime.datetime.now(datetime.timezone.utc).isoformat();(report/f'{label}-results.json').write_text(json.dumps(measurements,indent=2)+'\n')
        if round_num==1:run(['php',str(r/'expire-listings.php'),str(report/f'{label}-rooms.json')],env)
        print(label,'sleeping confirmed', sum(s['after']>s['before'] for s in measurements['cold']),'of 20',flush=True)
