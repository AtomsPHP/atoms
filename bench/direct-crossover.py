"""Read snapshots from the frozen #113 rooms. Keep the same Actions runner across deployments."""
import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import time
from urllib.parse import urlparse

HERE = Path(__file__).resolve().parent
NAMESPACES = ('playground', 'candidate')
BASELINE = {'playground': 'f4b39387-f836-41a7-a4bb-9bdccda9a97c', 'candidate': '0015fe5c-7849-46b1-ad33-60f1efb5c5fb'}
ROOM_LIST_DIGESTS = {
    'playground': '3498a94c0ce1bec713fa54b574cfca80eb683e3562837ec351282c698c8ad310',
    'candidate': '776ff3acee321e0b1668f3a8cb4415148cd417dcc7746e6d9554c7c6a1912b0a',
}
WAIT_SECONDS = 65
PHASES = ('baseline', 'switched', 'restored')
GATES = 'bench/211-xover-gates'
RESULTS = HERE / 'xover-report'


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def read(path):
    return json.loads(path.read_text())


def save(path, data):
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + '\n')


def load_rooms(artifact):
    groups = {}
    for ns in NAMESPACES:
        rows = [json.loads(line) for line in (artifact / f'round2-{ns}-laravel.ndjson').read_text().splitlines()]
        indices = [i for i, row in enumerate(rows) if row['path'] == '/api/games'][-20:]
        rooms = [next(row['path'].split('/')[-1] for row in rows[i+1:] if re.fullmatch('/games/[a-f0-9]{32}', row['path'])) for i in indices]
        if len(set(rooms)) != 20 or hashlib.sha256(('\n'.join(rooms) + '\n').encode()).hexdigest() != ROOM_LIST_DIGESTS[ns]:
            raise ValueError(f'{ns} fixed room list differs from the recorded #113 round')
        groups[ns] = rooms
    return groups


def read_gate(phase):
    run_id = os.environ['GITHUB_RUN_ID']
    if phase == 'baseline':
        return {'run_id': run_id, 'phase': phase, 'versions': BASELINE, 'runtimes': dict(zip(NAMESPACES, NAMESPACES))}
    path = f'repos/AtomsPHP/atoms/contents/bench/gates/{run_id}/{phase}.json?ref={GATES}'
    end = time.monotonic() + 40 * 60
    while time.monotonic() < end:
        result = subprocess.run(['gh', 'api', '-H', 'Accept: application/vnd.github.raw+json', path], capture_output=True, timeout=30)
        if result.returncode == 0:
            gate = json.loads(result.stdout)
            if str(gate['run_id']) != run_id or gate['phase'] != phase or set(gate['versions']) != set(NAMESPACES):
                raise ValueError(f'Invalid {phase} receipt')
            for value in gate['versions'].values():
                if not re.fullmatch('[a-f0-9-]{36}', value):
                    raise ValueError(f'Invalid {phase} version')
            expected = dict(zip(NAMESPACES, reversed(NAMESPACES))) if phase == 'switched' else dict(zip(NAMESPACES, NAMESPACES))
            if gate['runtimes'] != expected:
                raise ValueError(f'Wrong runtime assignment for {phase}')
            return gate
        time.sleep(8)
    raise TimeoutError(f'Deployment operator gate timed out for {phase}')


class Client:
    def __init__(self, ns, endpoint, version, runtime, out):
        self.ns = ns
        self.version = version
        self.runtime = runtime
        self.out = out
        self.bearer = os.environ['XOVER_BEARER_' + ns.upper()]
        url = urlparse(endpoint)
        if url.scheme != 'https' or url.username or url.password or url.path not in ('', '/'):
            raise ValueError('Expected isolated HTTPS benchmark origin')
        self.conn = http.client.HTTPSConnection(url.hostname, timeout=35)

    def request(self, phase, number, attempt, room, role):
        method, path, body = 'GET', '/healthz', None
        if room is not None:
            if role.startswith('info-'):
                path = f'/debug/MancalaGame/{room}/info'
            else:
                method, path, body = 'POST', f'/invoke/MancalaGame/{room}/snapshot', b'{"args":[]}'
        fields = {'phase': phase, 'round': number, 'attempt': attempt, 'namespace': self.ns,
                  'runtime': self.runtime, 'expected_version': self.version, 'room': room, 'role': role,
                  'wall_start_ms': time.time_ns() / 1e6, 'open_socket_at_start': self.conn.sock is not None}
        start = time.perf_counter_ns()
        try:
            self.conn.request(method, path, body=body, headers={'Authorization': 'Bearer '+self.bearer, 'Content-Type': 'application/json', 'Accept': 'application/json', 'User-Agent': 'atoms-211-crossover/1'})
            response = self.conn.getresponse()
            raw = response.read()
            fields.update(status=response.status, bytes=len(raw), wall_end_ms=time.time_ns()/1e6,
                          duration_ms=(time.perf_counter_ns()-start)/1e6, cf_ray=response.getheader('cf-ray'),
                          server_date=response.getheader('date'), server_timing=response.getheader('server-timing'),
                          placement=response.getheader('cf-placement'), cache_status=response.getheader('cf-cache-status'),
                          response_version=response.getheader('cf-worker-version'))
            if response.status != 200:
                raise RuntimeError(f'{role} HTTP {response.status}')
            data = json.loads(raw)
            if role.startswith('info-'):
                info = data['info']
                fields['info'] = {key:info[key] for key in ('do_id','constructions','php_booted','php_boot_ms','resident_ms','activations_this_residency','turns_this_residency','user_version','stored')}
                fields['alarm_at_ms'] = info['storage']['alarm_at_ms']
                if not info['php_booted'] or not info['do_id'] or info['stored']['id'] != room:
                    raise ValueError('Room was not resident after an invoke')
            elif room is not None:
                state = data['result']
                if state.get('id') != room or state.get('status') != 'active' or state.get('players') != 2:
                    raise ValueError('Fixed room is not an active two-player game')
                fields['state_sha256'] = digest(state)
                fields['state'] = state
        except Exception as exc:
            fields['error_type'] = type(exc).__name__
            self.conn.close()
            raise
        finally:
            fields.setdefault('wall_end_ms', time.time_ns()/1e6)
            fields.setdefault('duration_ms', (time.perf_counter_ns()-start)/1e6)
            self.out.write(json.dumps(fields,separators=(',',':'))+'\n')
            self.out.flush()
        return fields


def ordered(rooms, phase, number):
    # Counterbalance which namespace and which room index is visited first.
    phase_index = PHASES.index(phase)
    indices = range(19,-1,-1) if (phase_index+number)%2 else range(20)
    for i in indices:
        lanes = NAMESPACES[::-1] if (i+phase_index+number)%2 else NAMESPACES
        for ns in lanes:
            yield ns,rooms[ns][i]


def identity(probe, state):
    info = probe['info']
    return {'do_id':info['do_id'], 'state_sha256':state['state_sha256'], 'user_version':info['user_version'],
            'room_id':info['stored']['id'],'abi_php':info['stored']['abi_php']}


def measure(phase, rooms):
    gate = read_gate(phase)
    phase_dir = RESULTS / phase
    phase_dir.mkdir(parents=True)
    save(phase_dir/'assignment.json', gate)
    save(phase_dir/'runner.json', {k:os.getenv(k) for k in ('GITHUB_RUN_ID','GITHUB_RUN_ATTEMPT','GITHUB_SHA','RUNNER_OS','RUNNER_ARCH','RUNNER_NAME','ImageOS','ImageVersion')} | {'hostname':socket.gethostname(),'utc_start':datetime.now(timezone.utc).isoformat(),'idle_seconds':WAIT_SECONDS})
    previous = {} if phase == 'baseline' else read(RESULTS/'baseline/identity.json')
    identities = {}
    counts = {}
    endpoints = read(HERE/'config.json')
    with (phase_dir/'raw.ndjson').open('x') as out, (phase_dir/'samples.ndjson').open('x') as samples:
        clients = {ns:Client(ns,endpoints[ns],gate['versions'][ns],gate['runtimes'][ns],out) for ns in NAMESPACES}
        for ns, room in ordered(rooms,phase,0):
            state = clients[ns].request(phase,0,0,room,'preflight')
            probe = clients[ns].request(phase,0,0,room,'info-preflight')
            key=ns+'/'+room
            identities[key]=identity(probe,state)
            if previous and previous[key] != identities[key]:
                raise ValueError('Room state, migration, or DO identity changed across assignment')
            counts[key]=probe['info']['constructions']
        save(phase_dir/'identity.json',identities)
        print(f'{phase}: preflight passed for all 40 rooms; starting two measurement rounds.',flush=True)
        for number in (1,2):
            pending=list(ordered(rooms,phase,number))
            for attempt in range(1,4):
                if not pending:break
                # Probe after a verified snapshot, then leave each room untouched. A
                # debug request after the idle would itself construct the DO.
                time.sleep(WAIT_SECONDS if attempt==1 else WAIT_SECONDS*2)
                for client in clients.values():
                    client.request(phase,number,attempt,None,'transport-health')
                retry=[]
                for ns,room in pending:
                    key=ns+'/'+room
                    client=clients[ns]
                    cold=client.request(phase,number,attempt,room,'cold')
                    cp=client.request(phase,number,attempt,room,'info-cold')
                    warm=client.request(phase,number,attempt,room,'warm')
                    wp=client.request(phase,number,attempt,room,'info-warm')
                    if identity(cp,cold)!=identities[key] or identity(wp,warm)!=identities[key]:
                        raise ValueError('Snapshot or DO identity changed during a sample')
                    before=counts[key]
                    cold_count=cp['info']['constructions']
                    warm_count=wp['info']['constructions']
                    valid=cold_count==before+1 and cold_count==warm_count
                    obs={'phase':phase,'round':number,'attempt':attempt,'namespace':ns,'runtime':client.runtime,'expected_version':client.version,
                         'room':room,**identities[key],'cold_ms':cold['duration_ms'],'warm_ms':warm['duration_ms'],'cold_wall_start_ms':cold['wall_start_ms'],'warm_wall_start_ms':warm['wall_start_ms'],
                         'cold_cf_ray':cold['cf_ray'],'warm_cf_ray':warm['cf_ray'],'counts':[before,cold_count,warm_count],'eviction_verified':valid}
                    samples.write(json.dumps(obs,separators=(',',':'))+'\n');samples.flush()
                    counts[key]=warm_count
                    if not valid:retry.append((ns,room))
                print(f'{phase} round {number} attempt {attempt}: {len(pending)-len(retry)} confirmed; {len(retry)} left.',flush=True)
                pending=retry
            if pending:raise RuntimeError(f'{len(pending)} rooms lacked a verified eviction in round {number}')
        for client in clients.values():client.conn.close()
    print(f'{phase}: complete.',flush=True)


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase',choices=PHASES)
    parser.add_argument('artifact',type=Path)
    args=parser.parse_args()
    measure(args.phase,load_rooms(args.artifact))
