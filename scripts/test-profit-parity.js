'use strict';
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const engine=require('../public_html/assets/profit.js');
const php=process.env.PHP_BIN||'php';
const fixtures=JSON.parse(execFileSync(php,[path.join(__dirname,'profit-fixtures.php')],{encoding:'utf8'}));
for(const f of fixtures){
  const profit=engine.calcProfit(f.direct);
  for(const key of ['profit','margin','ratio','commission','net_kdv','stopaj','return_loss','totalCuts']) {
    assert.ok(Math.abs(profit[key]-f.profit[key])<1e-7,`İleri hesap uyuşmuyor: ${key}`);
  }
  const price=engine.calcPrice(f.input);
  assert.equal(price.ok,f.price.ok);
  if(price.ok){assert.equal(price.price,f.price.price);assert.ok(Math.abs(price.profit-f.price.profit)<1e-7);}
}
assert.equal(engine.calcPrice({cost:100,rate:20,targetMargin:90}).ok,false);
assert.throws(()=>engine.calcProfit({cost:-1}));
console.log(`PASS PHP/JavaScript: ${fixtures.length} karma KDV, manuel/otomatik stopaj ve hedef senaryosu`);
