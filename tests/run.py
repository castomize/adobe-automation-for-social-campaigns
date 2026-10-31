#!/usr/bin/env python3
"""No packages required. Isolated copy, dummy credentials, loopback HTTP only."""
import base64
import copy
from contextlib import ExitStack
import http.cookiejar
import hashlib
import io
import zipfile
import json
import os
from pathlib import Path
import re
import shutil
import socket
import struct
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
passed = 0


def check(condition, description):
    global passed
    if not condition:
        raise AssertionError(description)
    passed += 1
    print('PASS ' + description, flush=True)


def png(width=256, height=256):
    """A synthetic solid-color test image; never a product asset."""
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
    pixels = (b'\0' + b'\x80\xc0\x90' * width) * height
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(pixels)) + chunk(b'IEND', b''))


GOOD_PNG = png()
fixture_state = {'active': 0, 'maximum': 0, 'prompts': [], 'overlap': {}}
lock = threading.Lock()


class ProviderFixture(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        prompt = data['prompt']
        with lock:
            fixture_state['active'] += 1
            fixture_state['maximum'] = max(fixture_state['maximum'], fixture_state['active'])
            fixture_state['prompts'].append(prompt)
            if prompt.startswith('overlap-'):
                fixture_state['overlap'][prompt] = [time.monotonic(), None]
        try:
            time.sleep(1.5 if prompt == 'timeout' else 0.15)
            code = 200
            payload = {'success': True, 'errors': [], 'result': {'image': base64.b64encode(GOOD_PNG).decode()}}
            if prompt == 'overlap-failure' or 'CONTROLLED_FAILURE' in prompt:
                code, payload = 429, {'success': False, 'errors': [{'message': 'test-token must not leak'}]}
            elif prompt == 'provider-error':
                payload = {'success': False, 'errors': [{'message': 'test-token must not leak'}]}
            elif prompt == 'missing-image':
                payload['result'] = {}
            elif prompt == 'bad-base64':
                payload['result']['image'] = 'not!base64'
            elif prompt in ['non-image', 'corrupt-image', 'tiny-image', 'oversized-dimensions', 'pixel-limit']:
                images = {'non-image': b'not an image', 'corrupt-image': GOOD_PNG[:33], 'tiny-image': png(1, 1),
                          'oversized-dimensions': png(4097, 256), 'pixel-limit': png(4096, 2048)}
                payload['result']['image'] = base64.b64encode(images[prompt]).decode()
            elif prompt == 'redirect':
                code = 302
            body = json.dumps(payload).encode()
            if prompt == 'invalid-json':
                body = b'not-json test-token'
            elif prompt == 'huge-response':
                body = b'x' * (16 * 1024 * 1024 + 1)
            self.send_response(code)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            if code == 302:
                self.send_header('Location', '/must-not-follow')
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass  # Expected for timeout / response-size rejection.
        finally:
            with lock:
                fixture_state['active'] -= 1
                if prompt.startswith('overlap-'):
                    fixture_state['overlap'][prompt][1] = time.monotonic()


def fields(brief, csrf):
    output = {'csrf': csrf}
    for key, value in brief.items():
        if key != 'products':
            output[key] = value
    for i, product in enumerate(brief['products']):
        for key in ['id', 'name', 'description']:
            output[f'products[{i}][{key}]'] = product[key]
        for ratio, crop in product.get('crops', {}).items():
            for key, value in crop.items():
                output[f'products[{i}][crops][{ratio}][{key}]'] = value
    return output


def multipart(data, uploads):
    boundary = 'melt-muse-test-' + os.urandom(8).hex()
    body = bytearray()
    for key, value in data.items():
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    for field, filename, content, mime in uploads:
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode())
        body.extend(content + b'\r\n')
    body.extend(f'--{boundary}--\r\n'.encode())
    return bytes(body), 'multipart/form-data; boundary=' + boundary


def startup_checks():
    # Package-manager fixtures exercise setup without changing the test machine.
    cases = [
        ('existing PHP needs no installer', {'PHP_AVAILABLE': '1'}, 0, ['check', 'server'], 'Load example'),
        ('missing PHP installs and starts', {}, 0, ['install-php', 'check', 'server'], 'Installing PHP'),
        ('installed Homebrew PHP is reused outside PATH', {'PHP_INSTALLED': '1', 'BREW_LOCATION': 'fallback'}, 0, ['check', 'server'], 'Load example'),
        ('missing Homebrew installs first on Intel', {'BREW_LOCATION': 'missing'}, 0, ['install-brew', 'install-php', 'check', 'server'], 'official Homebrew installer'),
        ('missing Homebrew installs first on Apple Silicon', {'BREW_LOCATION': 'missing', 'MACHINE': 'arm64'}, 0, ['install-brew', 'install-php', 'check', 'server'], 'Load example'),
        ('Homebrew download failure stops startup', {'BREW_LOCATION': 'missing', 'DOWNLOAD_FAIL': '1'}, 1, [], 'Could not download Homebrew'),
        ('Homebrew installer failure stops startup', {'BREW_LOCATION': 'missing', 'BREW_SETUP_FAIL': '1'}, 1, ['install-brew'], 'Homebrew setup did not complete'),
        ('PHP install failure stops startup', {'PHP_INSTALL_FAIL': '1'}, 1, ['install-php'], 'PHP installation did not complete'),
        ('old PHP reports the required version', {'PHP_AVAILABLE': '1', 'VERSION_FAIL': '1'}, 1, [], 'PHP 8.2 or later'),
        ('missing extensions prevent serving', {'PHP_AVAILABLE': '1', 'CHECK_FAIL': '1'}, 1, ['check'], 'Startup checks failed'),
        ('Linux without PHP gets setup instructions', {'SYSTEM': 'Linux'}, 1, [], 'Linux/WSL commands'),
        ('invalid port is rejected before installing', {'STUDIO_PORT': 'invalid'}, 2, [], 'STUDIO_PORT must be'),
    ]
    for description, options, code, events, message in cases:
        with tempfile.TemporaryDirectory(prefix='melt-muse-startup-') as temporary:
            root = Path(temporary)
            tools_dir = root / 'tools'
            tools_dir.mkdir()

            def script(path, body):
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('#!/bin/sh\nset -eu\n' + body + '\n')
                path.chmod(0o700)

            (tools_dir / 'dirname').symlink_to(shutil.which('dirname'))
            script(tools_dir / 'uname', 'if [ "$1" = -s ]; then echo "${SYSTEM:-Darwin}"; else echo "${MACHINE:-x86_64}"; fi')
            script(root / 'php-fixture', '''case "$1" in
  -r) exit "${VERSION_FAIL:-0}" ;;
  bin/studio.php) echo check >> "$TEST_ROOT/events"; exit "${CHECK_FAIL:-0}" ;;
  *) echo server >> "$TEST_ROOT/events" ;;
esac''')
            script(root / 'brew-fixture', '''if [ "$1" = --prefix ]; then
  echo "$TEST_ROOT/php"
else
  [ "$*" = 'install php' ]
  echo install-php >> "$TEST_ROOT/events"
  [ "${PHP_INSTALL_FAIL:-0}" = 0 ]
  /bin/mkdir -p "$TEST_ROOT/php/bin"
  /bin/cp "$TEST_ROOT/php-fixture" "$TEST_ROOT/php/bin/php"
fi''')
            script(root / 'installer', '''echo install-brew >> "$TEST_ROOT/events"
[ "${BREW_SETUP_FAIL:-0}" = 0 ]
/bin/mkdir -p "${TEST_BREW%/*}"
/bin/cp "$TEST_ROOT/brew-fixture" "$TEST_BREW"''')
            script(tools_dir / 'curl', '''[ "$*" = '-fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh' ]
[ "${DOWNLOAD_FAIL:-0}" = 0 ]
/bin/cat "$TEST_ROOT/installer"''')
            brew_path = root / ('arm' if options.get('MACHINE') == 'arm64' else 'intel') / 'bin/brew'
            location = options.get('BREW_LOCATION', 'path')
            if location != 'missing':
                target = brew_path if location == 'fallback' else tools_dir / 'brew'
                target.parent.mkdir(parents=True, exist_ok=True)
                target.symlink_to(root / 'brew-fixture')
            if options.get('PHP_AVAILABLE'):
                (tools_dir / 'php').symlink_to(root / 'php-fixture')
            if options.get('PHP_INSTALLED'):
                (root / 'php/bin').mkdir(parents=True)
                (root / 'php/bin/php').symlink_to(root / 'php-fixture')

            # Redirect only fixed Homebrew locations; all setup logic stays intact.
            source = (ROOT / 'bin/start.sh').read_text()
            source = source.replace('/opt/homebrew', str(root / 'arm')).replace('/usr/local', str(root / 'intel'))
            (root / 'app/bin').mkdir(parents=True)
            (root / 'app/bin/start.sh').write_text(source)
            result = subprocess.run(['/bin/sh', str(root / 'app/bin/start.sh')], capture_output=True, text=True,
                                    env={'PATH': str(tools_dir), 'TEST_ROOT': str(root), 'TEST_BREW': str(brew_path), **options}, timeout=10)
            actual_events = (root / 'events').read_text().splitlines() if (root / 'events').exists() else []
            check(result.returncode == code and actual_events == events and message in result.stdout + result.stderr,
                  'startup: ' + description)


def main():
    startup_checks()
    with tempfile.TemporaryDirectory(prefix='melt-muse-tests-') as temporary, ExitStack() as cleanup:
        sandbox = Path(temporary) / 'app'
        shutil.copytree(ROOT, sandbox, ignore=shutil.ignore_patterns('storage', '.git', '.env', '*.log', '__pycache__'))
        # Canary credentials prove .env is actually private. They are not real API credentials.
        (sandbox / '.env').write_text('CLOUDFLARE_ACCOUNT_ID=' + '0' * 32 + '\nCLOUDFLARE_API_TOKEN=private-test-canary\n')
        env = dict(os.environ)
        env.pop('CLOUDFLARE_ACCOUNT_ID', None)
        env['CLOUDFLARE_API_TOKEN'] = ''  # No HTTP test may call the real provider.
        for source in sorted(sandbox.rglob('*.php')):
            subprocess.run(['php', '-l', str(source)], check=True, capture_output=True, env=env)
        check(True, 'all PHP syntax checks')
        provider = ThreadingHTTPServer(('127.0.0.1', 0), ProviderFixture)
        threading.Thread(target=provider.serve_forever, daemon=True).start()
        cleanup.callback(provider.server_close)
        cleanup.callback(provider.shutdown)
        subprocess.run(['php', str(sandbox / 'tests/checks.php'), 'client', f'http://127.0.0.1:{provider.server_port}/generate'], check=True, env=env)
        check(fixture_state['maximum'] == 2, 'fixture observed exactly two concurrent outbound transfers, never more')
        a, b = fixture_state['overlap']['overlap-success'], fixture_state['overlap']['overlap-failure']
        check(max(a[0], b[0]) < min(a[1], b[1]), 'success and failure request intervals overlap (not inferred from speed)')
        check('never' not in fixture_state['prompts'] and len(fixture_state['prompts']) == 17, 'invalid batches sent no requests; valid requests were not retried')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        server_log = open(Path(temporary) / 'php-server.log', 'wb')
        server = subprocess.Popen(['sh', 'bin/start.sh'], cwd=sandbox, env=env | {'STUDIO_PORT': str(port)}, stdout=server_log, stderr=server_log)
        base = f'http://127.0.0.1:{port}'
        cookies = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))

        def request(path, data=None, content_type=None, client=opener, headers=None):
            headers = headers or {}
            if content_type:
                headers['Content-Type'] = content_type
            req = urllib.request.Request(base + path, data=data, headers=headers)
            try:
                response = client.open(req, timeout=30)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.read(), response.headers

        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError('Temporary PHP server exited during startup.')
                try:
                    status, body, headers = request('/')
                    break
                except urllib.error.URLError:
                    time.sleep(0.05)
            else:
                raise RuntimeError('Temporary PHP server did not start.')
            check(status == 200 and b'Unwrap a better afternoon.' in body, 'local page loads with sample values')
            invalid_command = subprocess.run(['php', 'bin/studio.php', 'unknown'], cwd=sandbox, env=env, capture_output=True, text=True)
            check(invalid_command.returncode == 2 and 'Usage:' in invalid_command.stderr
                  and not (sandbox / 'storage/smoke').exists(), 'unknown terminal command reports usage without generation')
            guarded_smoke = subprocess.run(['php', 'bin/studio.php', 'smoke'], cwd=sandbox,
                                           env=env | {'CLOUDFLARE_API_TOKEN': 'test-token'}, capture_output=True, text=True)
            check(guarded_smoke.returncode == 2 and '--confirm-free-plan' in guarded_smoke.stderr
                  and not (sandbox / 'storage/smoke').exists(), 'live smoke command still requires explicit Free-plan confirmation')
            check(len(re.findall(rb'<input type="hidden"[^>]+name="products\[\d\]\[id\]"', body)) == 2,
                  'product IDs remain stable hidden fields')
            check(b'English only' in body and re.search(rb'id="language"[^>]+readonly', body), 'form states its English-only scope')
            check(any(c.name == 'melt_muse_owner' and c.has_nonstandard_attr('HttpOnly')
                      and c.get_nonstandard_attr('SameSite') == 'Lax' for c in cookies),
                  'private owner cookie permits returning by navigation while writes remain CSRF-protected')
            csrf = re.search(rb'name="csrf" value="([a-f0-9]+)"', body)[1].decode()
            sample = json.loads((sandbox / 'examples/sample-brief.json').read_text())

            def submit(brief, uploads=None, token=csrf):
                data, content_type = multipart(fields(brief, token), uploads or [])
                code, response, _ = request('/api/generate', data, content_type)
                return code, json.loads(response)

            code, saved = submit(sample, [('asset_0', '', b'', 'application/octet-stream')])
            check(code == 200 and saved['status'] == 'incomplete', 'valid brief saved as incomplete when credentials are missing')
            check(all('credentials are missing' in error for error in saved['manifest']['errors'].values()), 'missing credentials produce actionable per-product errors')
            run = saved['brief']['run_id']
            directory = sandbox / 'storage/runs' / run
            check(all(p['asset']['origin'] == 'needs_generation' for p in saved['brief']['products']), 'intentionally empty uploads recorded as needing generation')
            manifest = json.loads((directory / 'manifest.json').read_text())
            check(manifest['generation_requests'] == 0 and manifest['outputs'] == [], 'missing credentials produce zero generation requests and no fabricated outputs')
            check(manifest['compliance']['content']['status'] == 'passed'
                  and manifest['compliance']['brand']['status'] == 'not_checked'
                  and manifest['compliance']['brand']['checked_outputs'] == 0,
                  'empty campaign records passed content screening but no brand checks')
            check((directory / 'brief.json').stat().st_mode & 0o077 == 0, 'saved brief has private file permissions')
            check(request(saved['download_url'])[0] == 200, 'session can download its validated JSON')
            anonymous = urllib.request.build_opener()
            check(request(saved['download_url'], client=anonymous)[0] == 404, 'another session cannot download a private brief')
            foreign = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            foreign_page = request('/', client=foreign)[1]
            foreign_csrf = re.search(rb'name="csrf" value="([a-f0-9]+)"', foreign_page)[1].decode()
            data, content_type = multipart(fields(sample, foreign_csrf) | {'previous_run': run}, [])
            check(request('/api/generate', data, content_type, client=foreign)[0] == 403, 'another session cannot reuse a private previous run')
            baseline = len(list((sandbox / 'storage/runs').iterdir()))
            for field in ['campaign_message', 'audience', 'market', 'visual_direction', 'product_name', 'product_description']:
                prohibited = copy.deepcopy(sample)
                key = field
                if field.startswith('product_'):
                    key = 'products.1.' + field.removeprefix('product_')
                    prohibited['products'][1][field.removeprefix('product_')] = 'A MIRACLE cure.'
                else:
                    prohibited[field] = 'A MIRACLE cure.'
                code, response = submit(prohibited)
                check(code == 422 and 'prohibited' in response['errors'].get(key, ''),
                      'prohibited copy returns an actionable field error: ' + field)
            check(len(list((sandbox / 'storage/runs').iterdir())) == baseline,
                  'prohibited submissions create no runs or outputs')
            for field in ['campaign_message', 'audience', 'market', 'language', 'visual_direction']:
                invalid = copy.deepcopy(sample)
                invalid[field] = ' '
                code, response = submit(invalid)
                check(code == 422 and field in response['errors'], f'missing {field} rejected')
            invalid = copy.deepcopy(sample)
            invalid['language'] = 'French'
            code, response = submit(invalid)
            check(code == 422 and 'English only' in response['errors']['language'], 'unsupported language rejected before processing')
            invalid = copy.deepcopy(sample)
            invalid['products'][1]['id'] = invalid['products'][0]['id']
            check(submit(invalid)[0] == 422, 'duplicate product IDs rejected')
            invalid['products'][1]['id'] = '../escape'
            check(submit(invalid)[0] == 422, 'unsafe product ID rejected')
            invalid = copy.deepcopy(sample)
            invalid['products'].pop()
            check(submit(invalid)[0] == 422, 'fewer than two products rejected')
            invalid = copy.deepcopy(sample)
            invalid['products'][0]['description'] = ''
            check(submit(invalid)[0] == 422, 'missing product description rejected')
            check(submit(sample, token='incorrect')[0] == 403, 'invalid CSRF token rejected')
            check(request('/api/generate')[0] == 405, 'generation endpoint rejects GET')
            for content, label in [(b'<?php echo 1;', 'spoofed MIME'), (GOOD_PNG[:33], 'corrupt PNG'),
                                   (png(1, 1), 'undersized dimensions'), (GOOD_PNG + b'x' * (5 * 1024 * 1024), '5 MiB size limit'),
                                   (GOOD_PNG + b'x' * (7 * 1024 * 1024), 'PHP upload error')]:
                code, response = submit(sample, [('asset_0', 'photo.png', content, 'image/png')])
                check(code == 422 and 'asset_0' in response['errors'], label + ' rejected')
            check(len(list((sandbox / 'storage/runs').iterdir())) == baseline, 'rejected submissions leave no partial runs')
            code, uploaded = submit(sample, [('asset_0', '../../untrusted.php', GOOD_PNG, 'text/plain')])
            asset = uploaded['brief']['products'][0]['asset']
            check(code == 200 and asset['origin'] == 'user_upload' and asset['mime'] == 'image/png', 'valid decoded image accepted despite untrusted filename and claimed MIME')
            check(re.fullmatch(r'sources/[a-f0-9]{32}\.png', asset['path']) is not None, 'stored filename is application-generated')
            check(len(uploaded['manifest']['outputs']) == 3 and uploaded['status'] == 'incomplete', 'uploaded product renders even when the other product cannot generate')
            for output in uploaded['manifest']['outputs']:
                output_url = f"/output?run={uploaded['brief']['run_id']}&product={output['product_id']}&ratio={output['ratio']}"
                status, content, headers = request(output_url + '&download=1')
                size = struct.unpack('>II', content[16:24])
                check(status == 200 and size == tuple({'square': [1080,1080], 'vertical': [1080,1920], 'landscape': [1920,1080]}[output['ratio']]), 'PNG download has correct dimensions: ' + output['ratio'])
                check('attachment;' in headers['Content-Disposition'], 'individual download disposition: ' + output['ratio'])
                check(request(output_url, client=anonymous)[0] == 404, 'another session cannot access final output: ' + output['ratio'])
            code, complete = submit(sample, [('asset_0', 'bar.png', GOOD_PNG, 'image/png'), ('asset_1', 'truffles.png', GOOD_PNG, 'image/png')])
            check(code == 200 and complete['status'] == 'complete' and len(complete['manifest']['outputs']) == 6 and complete['manifest']['generation_requests'] == 0, 'two uploaded sources produce six PNGs without any generation')
            check(complete['manifest']['compliance']['brand']['status'] == 'passed'
                  and all(output['brand_compliance']['status'] == 'passed' for output in complete['manifest']['outputs']),
                  'uploaded sources also receive six brand compliance results')
            before = len(fixture_state['prompts'])
            subprocess.run(['php', str(sandbox / 'tests/checks.php'), 'campaign', f'http://127.0.0.1:{provider.server_port}/generate', uploaded['brief']['run_id']], check=True, env=env)
            check(len(fixture_state['prompts']) - before == 6, 'pipeline sends only the six expected missing-source requests across all revision/failure tests')
            def action(name, values=None, client=opener, token=csrf):
                data = urllib.parse.urlencode({'csrf': token, 'action': name, **(values or {})}).encode()
                code, response, _ = request('/api/campaign', data, 'application/x-www-form-urlencoded', client=client)
                return code, json.loads(response)

            before_workflow = len(fixture_state['prompts'])
            code, example = action('example')
            example_id = example['brief']['run_id']
            example_root = sandbox / 'storage/runs' / example_id
            check(code == 200 and example['status'] == 'complete' and len(example['manifest']['outputs']) == 6
                  and example['manifest']['generation_requests'] == 0, 'packaged example renders six images without credentials or generation')
            check('owner_hash' not in example['manifest'] and 'owner_hash' in json.loads((example_root / 'manifest.json').read_text()),
                  'ownership is stored privately, never included in API results')
            for output in example['manifest']['outputs']:
                expected = sandbox / 'examples/outputs' / output['product_id'] / (output['ratio'] + '.png')
                check(output['sha256'] == hashlib.sha256(expected.read_bytes()).hexdigest(),
                      'example reproduces approved PNG exactly: ' + output['product_id'] + '/' + output['ratio'])
            check(all(p['asset']['origin'] == 'example' and p['asset']['source_provenance'] for p in example['brief']['products']),
                  'packaged images retain provenance and are not recorded as newly generated')
            history = json.loads(request('/api/campaign')[1])['runs']
            check(example_id in [r['run_id'] for r in history], 'new campaign appears in saved history')
            check(json.loads(request('/api/campaign', client=foreign)[1])['runs'] == [], 'history is private to its browser owner')
            check(action('open', {'run': example_id}, client=foreign, token=foreign_csrf)[0] == 404
                  and request(example['bundle_url'], client=foreign)[0] == 404, 'foreign browser cannot open or bundle another campaign')
            check(action('open', {'run': '../escape'})[0] == 404 and action('example', token='wrong')[0] == 403,
                  'history actions reject traversal and invalid CSRF')

            renewed_cookies = http.cookiejar.CookieJar()
            for cookie in cookies:
                if cookie.name == 'melt_muse_owner':
                    renewed_cookies.set_cookie(copy.copy(cookie))
            renewed = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(renewed_cookies))
            renewed_page = request('/', client=renewed)[1]
            renewed_csrf = re.search(rb'name="csrf" value="([a-f0-9]+)"', renewed_page)[1].decode()
            check(renewed_csrf != csrf and action('open', {'run': example_id}, client=renewed, token=renewed_csrf)[0] == 200,
                  'saved campaign reopens after PHP session expiry using the private owner cookie')
            check(action('keep', {'run': example_id, 'kept': '1'})[1]['manifest']['kept'], 'Keep persists on the campaign manifest')
            check(action('delete', {'run': example_id})[0] == 422 and example_root.exists(), 'kept campaign cannot be deleted')
            check(action('keep', {'run': example_id, 'kept': 'invalid'})[0] == 422, 'malformed keep state is rejected')

            code, archive_bytes, headers = request(example['bundle_url'])
            with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
                check(code == 200 and headers['Content-Type'] == 'application/zip'
                      and set(archive.namelist()) == {o['path'] for o in example['manifest']['outputs']}
                      and len(archive.namelist()) == 6, 'campaign ZIP contains only the six product PNGs')
                check(all(archive.read(o['path']) == (example_root / o['path']).read_bytes() for o in example['manifest']['outputs']),
                      'ZIP contains the exact saved PNGs organized by product and ratio')
                check(not any(name.endswith('.json') for name in archive.namelist())
                      and json.loads((example_root / 'brief.json').read_text()) == example['brief']
                      and json.loads((example_root / 'manifest.json').read_text())['kept'],
                      'brief and manifest remain in private storage and are excluded from the ZIP')
            check(not list((sandbox / 'storage').glob('bundle-*')), 'temporary ZIP is removed after download')
            first_output = example_root / example['manifest']['outputs'][0]['path']
            original_png = first_output.read_bytes()
            first_output.write_bytes(b'damaged')
            check(request(example['bundle_url'])[0] == 500 and not list((sandbox / 'storage').glob('bundle-*')),
                  'bundle refuses changed outputs and cleans up failed archive')
            first_output.write_bytes(original_png)

            crop_values = {'run': example_id, 'product': 'sea-salt-dark-bar', 'ratio': 'square',
                           'crop[x]': '4', 'crop[y]': '6', 'crop[zoom]': '90'}
            for override in [{'crop[zoom]': '151'}, {'crop[x]': 'NaN'}, {'crop[y]': '-21'}, {'ratio': '../escape'}, {'product': 'other'}]:
                check(action('crop', crop_values | override)[0] == 422, 'invalid crop is rejected: ' + str(override))
            code, cropped = action('crop', crop_values)
            check(code == 200 and cropped['status'] == 'complete' and cropped['manifest']['generation_requests'] == 0
                  and cropped['manifest']['revision_of'] == example_id, 'crop saves a complete new version with no generation')
            check([p['asset']['sha256'] for p in cropped['brief']['products']] == [p['asset']['sha256'] for p in example['brief']['products']],
                  'crop preserves both original source hashes')
            for old, new in zip(example['manifest']['outputs'], cropped['manifest']['outputs']):
                changed = old['product_id'] == 'sea-salt-dark-bar' and old['ratio'] == 'square'
                check((old['sha256'] != new['sha256']) == changed and old['text'] == new['text']
                      and (old['width'], old['height']) == (new['width'], new['height'])
                      and new['brand_compliance']['status'] == 'passed',
                      'crop changes only the selected photo and preserves text/dimensions: ' + old['product_id'] + '/' + old['ratio'])
            reopened = action('open', {'run': cropped['brief']['run_id']})[1]
            check(reopened['brief']['products'][0]['crops']['square'] == {'x': 4, 'y': 6, 'zoom': 90}, 'reopening restores per-format crop settings')
            reset = action('crop', crop_values | {'run': cropped['brief']['run_id'], 'crop[x]': 0, 'crop[y]': 0, 'crop[zoom]': 100})[1]
            check([o['sha256'] for o in reset['manifest']['outputs']] == [o['sha256'] for o in example['manifest']['outputs']],
                  'reset restores all six approved output hashes')
            revision = copy.deepcopy(cropped['brief'])
            revision['campaign_message'] = 'Make a little time for a better afternoon.'
            revision['previous_run'] = cropped['brief']['run_id']
            code, revised = submit(revision)
            check(code == 200 and revised['status'] == 'complete' and revised['manifest']['generation_requests'] == 0
                  and revised['brief']['products'][0]['crops'] == cropped['brief']['products'][0]['crops'],
                  'longer headline reuses example sources and crop settings without generation')
            check(all(o['text']['message']['text'] == revision['campaign_message'] for o in revised['manifest']['outputs']),
                  'longer headline remains exact across all formats')

            package_source = sandbox / 'examples' / sample['products'][1]['asset']['path']
            package_bytes = package_source.read_bytes()
            package_source.unlink()
            try:
                code, partial = action('example')
            finally:
                package_source.write_bytes(package_bytes)
            check(code == 200 and partial['status'] == 'incomplete' and len(partial['manifest']['outputs']) == 3
                  and partial['manifest']['generation_requests'] == 0 and partial['manifest']['errors'],
                  'missing packaged source reports partial results without silently generating a replacement')
            code, partial_zip, _ = request(partial['bundle_url'])
            with zipfile.ZipFile(io.BytesIO(partial_zip)) as archive:
                check(code == 200 and len(archive.namelist()) == 3
                      and all(name.endswith('.png') for name in archive.namelist()),
                      'partial campaign ZIP contains only its three available PNGs')
            code, empty_response, _ = request(saved['bundle_url'])
            check(code == 500 and 'No campaign images' in json.loads(empty_response)['errors']['request']
                  and not list((sandbox / 'storage').glob('bundle-*')),
                  'campaign without images gives an actionable error and leaves no temporary ZIP')
            deleted_id = cropped['brief']['run_id']
            check(action('delete', {'run': deleted_id})[0] == 200 and not (sandbox / 'storage/runs' / deleted_id).exists()
                  and (sandbox / 'storage/trash' / deleted_id / 'manifest.json').exists(), 'Delete moves an unkept run to recoverable local trash')
            check(action('open', {'run': deleted_id})[0] == 404
                  and deleted_id not in [r['run_id'] for r in json.loads(request('/api/campaign')[1])['runs']],
                  'deleted run disappears from history and cannot be downloaded')
            check(action('keep', {'run': example_id, 'kept': '0'})[0] == 200
                  and action('delete', {'run': example_id})[0] == 200, 'Unkeep permits later deletion')
            check(len(fixture_state['prompts']) == before_workflow, 'example, crops, history and ZIP tests make zero provider requests')

            if '--keep-artifacts' in __import__('sys').argv:
                source = sandbox / 'storage/verification/controlled-run'
                destination = ROOT / 'storage/verification' / ('controlled-run-' + os.urandom(4).hex())
                shutil.copytree(source, destination)
                print('CONTROLLED TEST OUTPUTS (not live Cloudflare): ' + str(destination), flush=True)
            image_path = f"/asset?run={uploaded['brief']['run_id']}&product=sea-salt-dark-bar"
            status, image, image_headers = request(image_path)
            check(status == 200 and image == GOOD_PNG and image_headers['Content-Type'] == 'image/png', 'controlled source preview returns the saved image')
            check(request(image_path, client=anonymous)[0] == 404, 'source preview is denied to another session')
            for private in ['/.env', '/.env.example', '/src/bootstrap.php', '/bin/studio.php', '/router.php', '/public/index.php',
                            '/tests/run.py', '/storage/sessions/', f'/storage/runs/{run}/brief.json', f'/storage/runs/{run}/manifest.json',
                            '/%2e%2e/.env', '/storage/../.env']:
                status, response, _ = request(private)
                check(status == 404 and b'private-test-canary' not in response, 'private path denied: ' + private)
            check(request('/', headers={'Host': 'malicious.example'})[0] == 403, 'untrusted Host header rejected')
            status, _, _ = request('/api/generate', b'x' * (12 * 1024 * 1024 + 1), 'application/octet-stream')
            check(status == 413, 'oversized total request rejected explicitly')
            (sandbox / '.env').unlink()
            smoke = subprocess.run(['php', 'bin/studio.php', 'smoke'], cwd=sandbox, env=env, capture_output=True, text=True)
            check(smoke.returncode == 2 and 'No API request was sent' in smoke.stdout and not (sandbox / 'storage/smoke').exists(), 'missing credentials skip the real smoke test without creating an output')
        finally:
            server.terminate()
            server.wait(timeout=5)
            server_log.close()
        print(f'{passed} integration checks passed. All test files and servers were isolated and cleaned up.', flush=True)


if __name__ == '__main__':
    main()
