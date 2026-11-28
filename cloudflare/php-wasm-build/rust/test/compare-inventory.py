"""Compare the owned guest to the installed #228 interpreter of the same minor."""
import difflib
import json
from pathlib import Path
import sys

control, candidate = (json.loads(Path(path).read_text()) for path in sys.argv[1:])
major, minor, _ = control['behavior']['php'].split('.')
series = f'{major}.{minor}'

# These private extensions belonged to the old embed. Zend OPcache remains
# under its public name on every minor. PHP 8.5 already used only that name.
for name in ['dns_polyfill', 'post_message_to_js', 'wasm_memory_storage']:
    del control['extensions'][name]
if series in ('8.3', '8.4'):
    del control['extensions']['opcache']
control['extensions']['atoms_bridge'] = '0.1.0'

for name in ['checkdnsrr', 'dns_check_record', 'dns_get_mx', 'dns_get_record', 'getmxrr', 'post_message_to_js']:
    control['functions'].remove(name)
control['functions'].extend(['atoms_exchange_length', 'atoms_roundtrip_i64'])
for name in ['A', 'A6', 'AAAA', 'ALL', 'ANY', 'CAA', 'CNAME', 'HINFO', 'MX', 'NAPTR',
             'NS', 'PTR', 'SOA', 'SRV', 'TXT']:
    del control['constants']['DNS_' + name]

# The upstream stack guard exposes its two default INI values on these minors.
if series in ('8.4', '8.5'):
    control['ini'].update({'zend.max_allowed_stack_size': '0', 'zend.reserved_stack_size': '0'})

for report in [control, candidate]:
    report['functions'].sort()
    report['behavior']['pdo_drivers'].sort()

if control != candidate:
    def lines(report):
        return json.dumps(report, indent=2, sort_keys=True).splitlines(keepends=True)
    sys.stderr.writelines(difflib.unified_diff(lines(control), lines(candidate),
                                               fromfile='expected from #228', tofile='owned guest'))
    sys.exit(1)
