'use strict';
const http = require('node:http');
const net = require('node:net');
const { trackerHost } = require('./tracker-policy');

// Chromium'un WebSocket/popup dahil tüm HTTP(S) bağlantıları bu kapıdan geçer.
// Yalnızca önceden doğrulanmış hostların sabit genel IP'lerine 443 tüneli açılır.
async function createPinnedProxy(pins, connect = (options) => net.connect(options)) {
  const sockets = new Set();
  const server = http.createServer((req, res) => { res.writeHead(403); res.end(); });
  server.on('connection', (socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
    socket.on('error', () => {});
    socket.setTimeout(30000, () => socket.destroy());
  });
  server.on('connect', (req, client, head) => {
    let host;
    try {
      // CONNECT yalnızca host:443 biçimindedir. Yol, kimlik ve diğer portlar reddedilir.
      if (!/^[a-z0-9.-]+:443$/i.test(req.url)) throw new Error('Invalid CONNECT');
      host = trackerHost(`https://${req.url}`, true);
      if (!pins.has(host)) throw new Error('Unresolved host');
    } catch (_) {
      client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
      return;
    }
    const upstream = connect({ host: pins.get(host), port: 443 });
    sockets.add(upstream);
    upstream.on('close', () => sockets.delete(upstream));
    upstream.setTimeout(30000, () => upstream.destroy());
    upstream.on('error', () => client.destroy());
    client.on('error', () => upstream.destroy());
    client.on('close', () => upstream.destroy());
    upstream.on('connect', () => {
      if (client.destroyed) { upstream.destroy(); return; }
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      client.pipe(upstream);
      upstream.pipe(client);
    });
  });
  server.on('clientError', (_, socket) => socket.destroy());
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  return {
    url: `http://127.0.0.1:${server.address().port}`,
    close: () => new Promise((resolve) => {
      for (const socket of sockets) socket.destroy();
      server.close(resolve);
    }),
  };
}
module.exports = { createPinnedProxy };
