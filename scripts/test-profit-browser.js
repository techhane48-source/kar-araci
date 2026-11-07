'use strict';
const assert=require('node:assert/strict');
const puppeteer=require('../crawler/node_modules/puppeteer-core');
(async()=>{
 const browser=await puppeteer.launch({executablePath:process.env.CHROME_PATH||'/usr/bin/chromium',headless:true,
   args:process.env.KARFIYAT_TEST_NO_SANDBOX==='1'?['--no-sandbox']:[]});
 try{
  const page=await browser.newPage();
  await page.setRequestInterception(true);
  page.on('request',r=>(r.url().startsWith('http://127.0.0.1:8090/')?r.continue():r.abort()).catch(()=>{}));
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:8090/giris.php');
  await page.type('input[name=email]','profit@example.test');
  await page.type('input[name=password]','Test-Profit-Only!');
  await Promise.all([page.waitForNavigation(),page.click('button[type=submit]')]);
  await page.goto('http://127.0.0.1:8090/');
  await page.waitForFunction(()=>typeof KarProfit!=='undefined' && typeof calculate==='function');
  await page.evaluate(()=>{
    for(const [id,value] of Object.entries({cost:200,price:400,cargo:50,rate:21.36,kdv:20,'extra-fee':0,'service-fee':0,stopaj:0,'return-rate':0}))document.getElementById(id).value=value;
    document.getElementById('stopaj-auto').checked=false;
    document.getElementById('stopaj-auto').dispatchEvent(new Event('change'));
    calculate();
  });
  assert.match(await page.$eval('#result-value',e=>e.textContent),/53,8/);
  console.log('PASS Tarayıcı: manuel sıfır giderle ürün kârı 53,80 TL');
  await page.click('[data-mode=price]');
  await page.evaluate(()=>{document.getElementById('targetMargin').value=90;calculate();});
  assert.equal(await page.$eval('#result-value',e=>e.textContent),'—');
  assert.equal(await page.$eval('#save-btn',e=>e.disabled),true);
  assert.match(await page.$eval('#breakdown',e=>e.textContent),/ulaşılamıyor/);
  console.log('PASS Tarayıcı: ulaşılamayan marj gösterilir, kaydetme engellenir');
  await page.evaluate(()=>{document.getElementById('targetMargin').value=20;calculate();});
  assert.equal(await page.$eval('#save-btn',e=>e.disabled),false);
  assert.equal(await page.evaluate(()=>lastCalculationValid && lastPrice>0 && lastPrice<100000),true);
  await page.click('[data-mode=campaign]');
  await page.evaluate(()=>{document.getElementById('price').value=400;document.getElementById('discount').value=25;calculate();});
  const dialogs=[];
  page.on('dialog',async d=>{dialogs.push(d.message());await d.accept(d.type()==='prompt'?'Browser campaign fixture':undefined);});
  const response=page.waitForResponse(r=>r.url().endsWith('/api-save.php') && r.request().method()==='POST');
  await page.click('#save-btn');
  const result=await response;
  const body=JSON.parse(result.request().postData());
  assert.equal(body.price,300);
  assert.equal(body.stopaj_mode,'manual');
  assert.equal((await result.json()).ok,true);
  console.log('PASS Tarayıcı: kampanya kaydı indirimli 300 TL fiyatını gönderir');
  await page.waitForFunction(()=>!document.getElementById('save-btn').disabled);
  assert.deepEqual(errors,[]);
  console.log('PASS Tarayıcı: JavaScript hatası yok');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
