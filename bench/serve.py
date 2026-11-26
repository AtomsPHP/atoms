"""Callback-only tunnel ingress and original measurement script's loopback /debug proxy."""
import base64,hashlib,hmac,json,re,sys,time,http.client
from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
from pathlib import Path
from urllib.parse import urlparse
root=Path(__file__).parent
mode=sys.argv[1]
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def reply(self,code,body=b'',content='application/json'):
        self.send_response(code);self.send_header('Content-Type',content);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def do_POST(self):
        if mode!='callback':return self.reply(405)
        arm=self.path.split('/')[1]
        if arm not in ('playground','candidate') or self.path!='/'+arm+'/callback':return self.reply(404)
        proof=self.headers.get('x-atoms-qualification-ready')
        if arm=='candidate' and proof:
            if not (root/'expected-gate.json').is_file():return self.reply(503)
            try: supplied=base64.b64decode(proof,validate=True)
            except ValueError:return self.reply(401)
            expected=json.loads((root/'expected-gate.json').read_text())
            if not hmac.compare_digest(hashlib.sha256(supplied).hexdigest(),expected['digest']):return self.reply(401)
            (root/'ready').write_text(expected['run']);return self.reply(204)
        if not all(self.headers.get('x-atoms-'+k) for k in ('signature','timestamp','nonce','kind')):return self.reply(401)
        try:length=int(self.headers.get('Content-Length','0'))
        except ValueError:return self.reply(400)
        if not 0<length<=262144:return self.reply(413)
        if arm!=(root/'active').read_text().strip():return self.reply(409)
        headers={k:v for k,v in self.headers.items() if k.lower().startswith('x-atoms-') or k.lower()=='content-type'}
        with self.connection_to('127.0.0.1',18000) as c:
            c.request('POST','/atoms/callback',self.rfile.read(length),headers);res=c.getresponse();self.reply(res.status,res.read())
    def do_GET(self):
        if mode!='debug':return self.reply(404)
        m=re.fullmatch(r'/debug/MancalaGame/([a-f0-9]{32})/info',self.path)
        if not m:return self.reply(404)
        config=json.loads((root/'config.json').read_text());arm=(root/'active').read_text().strip();name=urlparse(config[arm]).hostname
        start=time.perf_counter()
        with self.connection_to(name,443,True) as c:
            c.request('GET',self.path,headers={'Authorization':self.headers.get('Authorization',''),'User-Agent':'Atoms-Mancala-Qualification/1.0'});res=c.getresponse();body=res.read();status=res.status;ray=res.getheader('cf-ray');info=json.loads(body).get('info',{})
        with (root/'report'/((root/'label').read_text().strip()+'-debug.ndjson')).open('a') as f:
            print(json.dumps({'room':m[1],'do_id':info.get('do_id'),'constructions':info.get('constructions'),'resident_ms':info.get('resident_ms'),'booted':info.get('php_booted'),'boot_ms':info.get('php_boot_ms'),'ray':ray,'status':status,'ms':round((time.perf_counter()-start)*1000)}),file=f)
        self.reply(status,body)
    @staticmethod
    def connection_to(name,port,tls=False):
        import contextlib
        return contextlib.closing((http.client.HTTPSConnection if tls else http.client.HTTPConnection)(name,port,timeout=40))
ThreadingHTTPServer(('127.0.0.1',18001 if mode=='callback' else 18787),Handler).serve_forever()
