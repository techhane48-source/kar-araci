'use strict';
const dns = require('node:dns').promises;
const net = require('node:net');
const policy = require('../config/tracker-policy.json');

function trackerHost(raw, asset = false) {
  if (typeof raw !== 'string' || raw.length > 4096 || /[\x00-\x20\x7f\\]/.test(raw)) throw new Error('Geçersiz takip URL’si');
  const u = new URL(raw);
  const allowed = asset ? [...policy.page_hosts, ...policy.asset_hosts] : policy.page_hosts;
  if (u.protocol !== 'https:' || u.username || u.password || (u.port && u.port !== '443') || !allowed.includes(u.hostname)) {
    throw new Error('İzin verilmeyen takip adresi');
  }
  return u.hostname;
}
function publicIpv4(ip) {
  if (net.isIP(ip) !== 4) return false;
  const number = (value) => value.split('.').reduce((a, v) => (a * 256 + Number(v)) >>> 0, 0);
  const value = number(ip);
  return !policy.blocked_ipv4_cidrs.some((cidr) => {
    const [base, bits] = cidr.split('/');
    const mask = (0xffffffff << (32 - Number(bits))) >>> 0;
    return (value & mask) === (number(base) & mask);
  });
}
async function resolveHosts(lookup = (host) => dns.resolve4(host)) {
  const pins = new Map();
  for (const host of [...policy.page_hosts, ...policy.asset_hosts]) {
    try {
      const ips = await lookup(host);
      if (ips.length && ips.every(publicIpv4)) pins.set(host, ips[0]);
    } catch (_) { /* Erişilemeyen veya özel adresli host bu turda kullanılamaz. */ }
  }
  return pins;
}
async function guardPage(page, pins) {
  await page.setBypassServiceWorker(true);
  await page.setRequestInterception(true);
  page.on('request', (request) => {
    let allowed = false;
    try {
      // Ana sayfa gezinmeleri yalnızca pazaryerlerine; alt kaynaklar sınırlı CDN listesine.
      const host = trackerHost(request.url(), !request.isNavigationRequest());
      allowed = pins.has(host) && !['image', 'media', 'font'].includes(request.resourceType());
    } catch (_) {}
    const pending = allowed ? request.continue() : request.abort('blockedbyclient');
    pending.catch(() => {});
  });
}
module.exports = { trackerHost, publicIpv4, resolveHosts, guardPage };
