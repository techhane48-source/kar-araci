'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const { trackerHost, publicIpv4, resolveHosts } = require('./tracker-policy');
const { createPinnedProxy } = require('./pinned-proxy');

test('HTTPS pazaryeri adresleri kabul edilir', () => {
  assert.equal(trackerHost('https://www.trendyol.com/urun?x=1'), 'www.trendyol.com');
  assert.equal(trackerHost('https://www.amazon.com.tr/dp/123'), 'www.amazon.com.tr');
  assert.throws(() => trackerHost('https://cdn.dsmcdn.com/script.js'));
  assert.equal(trackerHost('https://cdn.dsmcdn.com/script.js', true), 'cdn.dsmcdn.com');
});
test('özel, kimlikli ve yabancı URL’ler reddedilir', () => {
  for (const url of ['http://www.trendyol.com', 'file:///etc/passwd', 'https://127.0.0.1',
    'https://localhost', 'https://169.254.169.254', 'https://[::1]',
    'https://www.trendyol.com.evil.example', 'https://evil.example@www.trendyol.com',
    'https://www.trendyol.com:8443', 'https://www.trendyol.com./',
    'https://www.trendyol.com\\@localhost', 'https://www.trendyol.com\n']) assert.throws(() => trackerHost(url), url);
});
test('özel ve rezerve IP’ler reddedilir', () => {
  for (const ip of ['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '192.0.2.1', '198.18.0.1', '224.0.0.1', '0.0.0.0', '::1']) assert.equal(publicIpv4(ip), false, ip);
  assert.equal(publicIpv4('93.184.216.34'), true);
});
test('karışık özel/genel DNS yanıtı güvenli sayılmaz', async () => {
  const pins = await resolveHosts(async (host) => host === 'www.trendyol.com' ? ['93.184.216.34', '127.0.0.1'] : ['93.184.216.34']);
  assert.equal(pins.has('www.trendyol.com'), false);
  assert.equal(pins.get('www.amazon.com.tr'), '93.184.216.34');
});
function tunnel(proxy, destination) {
  return new Promise((resolve, reject) => {
    const u = new URL(proxy.url);
    const req = http.request({ hostname: u.hostname, port: u.port, method: 'CONNECT', path: destination });
    req.on('connect', (response, socket) => resolve({ status: response.statusCode, socket }));
    req.on('error', reject);
    req.end();
  });
}
test('proxy doğrudan IP, localhost, yabancı host ve diğer portları engeller', async () => {
  let connects = 0;
  const proxy = await createPinnedProxy(new Map([['www.trendyol.com', '93.184.216.34']]), () => { connects++; throw new Error('unexpected'); });
  try {
    for (const target of ['127.0.0.1:443', '169.254.169.254:443', 'localhost:443', 'evil.example:443',
      'www.trendyol.com:80', 'www.trendyol.com:443/anything', 'user@www.trendyol.com:443']) {
      const result = await tunnel(proxy, target);
      assert.equal(result.status, 403, target);
      result.socket.destroy();
    }
    assert.equal(connects, 0);
  } finally { await proxy.close(); }
});
test('proxy yeniden DNS sorgulamak yerine doğrulanan IP’ye bağlanır', async () => {
  const fixture = net.createServer((socket) => socket.on('data', () => socket.end('fixture-response')));
  await new Promise((resolve) => fixture.listen(0, '127.0.0.1', resolve));
  let actual;
  const proxy = await createPinnedProxy(new Map([['www.trendyol.com', '93.184.216.34']]), (options) => {
    actual = options;
    return net.connect({ host: '127.0.0.1', port: fixture.address().port });
  });
  try {
    const result = await tunnel(proxy, 'www.trendyol.com:443');
    assert.equal(result.status, 200);
    assert.deepEqual(actual, { host: '93.184.216.34', port: 443 });
    const body = new Promise((resolve) => { let value=''; result.socket.on('data', (b) => value+=b); result.socket.on('end', () => resolve(value)); });
    result.socket.write('fixture-request');
    assert.equal(await body, 'fixture-response');
    result.socket.destroy();
  } finally { await proxy.close(); await new Promise((resolve) => fixture.close(resolve)); }
});
