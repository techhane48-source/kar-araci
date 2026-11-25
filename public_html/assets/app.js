// Hesap makinesi istemci mantığı. Kategoriler sunucudan window.CATEGORIES ile gelir
// (tek doğruluk kaynağı PHP'dedir); sunucu tarafı calc_profit ile aynı kalemler.
// Komisyon (%) ve KDV (%) elle girilebilir; kategori/pazaryeri seçimi öneri doldurur.

let mode = "profit";
let marketplace = "trendyol";
let cargoType = "seller";
let lastPrice = 0;   // ters hesapta bulunan son fiyat (kaydetme için)

const $ = id => document.getElementById(id);
const MP_KEY = {trendyol:"t",hepsiburada:"h",amazon:"a",n11:"n"};
const MP_LABEL = {trendyol:"Trendyol",hepsiburada:"Hepsiburada",amazon:"Amazon",n11:"N11",
                  aliexpress:"AliExpress",ciceksepeti:"ÇiçekSepeti",epttavm:"EPTTAVM"};
const GENERAL_RATE = {aliexpress:8, ciceksepeti:15, epttavm:10};
const TRENDYOL_SERVICE_FEE = 13.19;   // Melontik: Trendyol sabit hizmet bedeli
const STOPAJ_RATE = 1.0;              // Melontik: stopaj = KDV-hariç satış × %1

function buildCategories(){
  $("category").innerHTML = window.CATEGORIES.map((c,i)=>`<option value="${i}">${c.name}</option>`).join("");
}

function suggestedRate(){
  if(GENERAL_RATE[marketplace] !== undefined) return GENERAL_RATE[marketplace];
  const c = window.CATEGORIES[+$("category").value];
  let r = c[MP_KEY[marketplace]];
  if(marketplace === "n11") r += 2;
  return r;
}

function updateRateHint(){
  $("rate-hint").textContent = `${MP_LABEL[marketplace]} komisyon: %${suggestedRate().toFixed(2)} (KDV dahil)`;
}

function num(id){ return parseFloat($(id).value) || 0; }

// Stopaj: "otomatik" işaretliyse KDV-hariç satış × %1; değilse elle girilen ₺.
function stopajAutoOn(){
  const el = $("stopaj-auto");
  return el ? el.checked : true;
}

function stopajValue(P, kdvRate){
  if(stopajAutoOn()) return P / (1 + kdvRate/100) * (STOPAJ_RATE/100);
  return num("stopaj");
}

// Belirli bir satış fiyatında tüm kalemleri hesapla (PHP calc_profit ile birebir aynı).
function computeAt(P, cost, cargo, rateFrac, kdvRate, extra, serviceFee, returnFrac){
  const commission = P * rateFrac;
  const stopaj = stopajValue(P, kdvRate);
  const kdvFrac = kdvRate / (100 + kdvRate);
  let netKdv = (P - cost - commission - cargo - extra - serviceFee) * kdvFrac;
  if(netKdv < 0) netKdv = 0;   // Melontik: Net KDV negatif çıkarsa 0
  const returnLoss = P * returnFrac;
  const profit = P - cost - commission - cargo - extra - serviceFee - stopaj - netKdv - returnLoss;
  return {commission, stopaj, netKdv, returnLoss, profit};
}

function calculate(){
  const cost = num("cost"), price = num("price"), cargo = num("cargo");
  const rate = num("rate") / 100;
  const kdvRate = num("kdv");
  const extra = num("extra-fee");
  const serviceFee = num("service-fee");
  const returnRate = num("return-rate") / 100;

  if(mode === "profit"){
    const r = computeAt(price, cost, cargo, rate, kdvRate, extra, serviceFee, returnRate);
    const margin = price > 0 ? (r.profit/price)*100 : 0;
    const ratio = cost > 0 ? (r.profit/cost)*100 : 0;
    renderProfit(r.profit, margin, ratio, r.commission, r.netKdv, r.stopaj, extra, serviceFee, r.returnLoss);
  } else if(mode === "campaign"){
    const discount = num("discount") / 100;
    const salePrice = price * (1 - discount);
    const r = computeAt(salePrice, cost, cargo, rate, kdvRate, extra, serviceFee, returnRate);
    const margin = salePrice > 0 ? (r.profit/salePrice)*100 : 0;
    const ratio = cost > 0 ? (r.profit/cost)*100 : 0;
    renderCampaign(salePrice, discount, r.profit, margin, ratio, r.commission, r.netKdv, r.stopaj, extra, serviceFee, r.returnLoss);
  } else {
    // Ters hesap: binary search (PHP calc_price ile birebir aynı — Melontik "Fiyat Oluştur").
    const targetProfit = num("targetProfit");
    const targetMargin = num("targetMargin");
    const profitAt = (P) => computeAt(P, cost, cargo, rate, kdvRate, extra, serviceFee, returnRate).profit;
    const f = targetMargin > 0 ? (P) => profitAt(P) - P*targetMargin/100 : (P) => profitAt(P) - targetProfit;
    let lo = 0, hi = (cost + cargo + extra + serviceFee)*4 + 1000;
    let guard = 0;
    while(f(hi) < 0 && guard++ < 60) hi *= 2;
    if (!Number.isFinite(hi) || !Number.isFinite(f(hi)) || f(hi) < 0) {
      lastPrice = 0;
      $("result-label").textContent = "Önerilen Satış Fiyatı";
      $("result-value").textContent = "Hedefe ulaşılamıyor";
      $("result-value").classList.add("neg");
      ["kpi-margin","kpi-ratio","kpi-commission","kpi-kdv"].forEach(id => kpi(id, "—", ""));
      $("breakdown").innerHTML = "<tr><td>Mevcut giderlerle hedef mümkün değil.</td></tr>";
      return;
    }
    for(let i=0;i<200;i++){ const mid=(lo+hi)/2; if(f(mid)>0) hi=mid; else lo=mid; }
    const P = (lo+hi)/2;
    const r = computeAt(P, cost, cargo, rate, kdvRate, extra, serviceFee, returnRate);
    const margin = P > 0 ? (r.profit/P)*100 : 0;
    const ratio = cost > 0 ? (r.profit/cost)*100 : 0;
    renderPrice(P, r, margin, ratio, rate);
  }
}

function tl(v){
  if(!isFinite(v)) return "—";
  const s = v < 0 ? "-" : "";
  return s + "₺" + Math.abs(v).toLocaleString("tr-TR",{maximumFractionDigits:2});
}

function pct(v){
  if(!isFinite(v)) return "—";
  return "%" + v.toLocaleString("tr-TR",{maximumFractionDigits:1});
}

function kpi(id, val, cls){
  const el = $(id);
  if(!el) return;
  el.textContent = val;
  el.className = "k-value " + (cls || "");
}

function renderProfit(profit, margin, ratio, commission, netKdv, stopaj, extra, serviceFee, returnLoss){
  $("result-label").textContent = "Net Kâr";
  const v = $("result-value");
  v.textContent = tl(profit);
  v.classList.toggle("neg", profit < 0);
  kpi("kpi-margin", pct(margin), margin<0?"neg":"pos");
  kpi("kpi-ratio", pct(ratio), ratio<0?"neg":"pos");
  kpi("kpi-commission", tl(commission));
  kpi("kpi-kdv", tl(netKdv));

  const price = num("price"), cost = num("cost"), cargo = num("cargo");
  const stopajLabel = stopajAutoOn() ? "Stopaj (KDV-hariç × %"+STOPAJ_RATE+")" : "Stopaj";
  const rows = [
    ["Satış Fiyatı", price, ""],
    ["Alış Fiyatı", -cost, "neg"],
    ["Komisyon", -commission, "neg"],
    ["Kargo", -cargo, "neg"],
  ];
  if(extra > 0) rows.push(["Ek Bedel ("+cargoTypeLabel()+")", -extra, "neg"]);
  if(serviceFee > 0) rows.push(["Hizmet Bedeli", -serviceFee, "neg"]);
  if(stopaj > 0) rows.push([stopajLabel, -stopaj, "neg"]);
  if(returnLoss > 0) rows.push(["İade Kaybı", -returnLoss, "neg"]);
  rows.push(["KDV (ödenen)", -netKdv, "neg"]);
  let html = "<tr><th>Kalem</th><th>Tutar</th></tr>";
  rows.forEach(r=>{
    const cls = r[2] ? `class="${r[2]}"` : "";
    html += `<tr><td>${r[0]}</td><td ${cls}>${tl(r[1])}</td></tr>`;
  });
  $("breakdown").innerHTML = html;
}

function renderPrice(P, r, margin, ratio, rate){
  lastPrice = P;
  $("result-label").textContent = "Önerilen Satış Fiyatı";
  const v = $("result-value");
  v.textContent = tl(P);
  v.classList.remove("neg");
  kpi("kpi-margin", pct(margin), "");
  kpi("kpi-ratio", pct(ratio), "");
  kpi("kpi-commission", "%"+(rate*100).toFixed(2));
  kpi("kpi-kdv", tl(r.netKdv));

  const cost = num("cost"), cargo = num("cargo");
  const extra = num("extra-fee"), serviceFee = num("service-fee");
  const stopajLabel = stopajAutoOn() ? "Stopaj (KDV-hariç × %"+STOPAJ_RATE+")" : "Stopaj";
  const rows = [
    ["Satış Fiyatı", P, ""],
    ["Alış Fiyatı", -cost, "neg"],
    ["Komisyon", -r.commission, "neg"],
    ["Kargo", -cargo, "neg"],
  ];
  if(extra > 0) rows.push(["Ek Bedel ("+cargoTypeLabel()+")", -extra, "neg"]);
  if(serviceFee > 0) rows.push(["Hizmet Bedeli", -serviceFee, "neg"]);
  if(r.stopaj > 0) rows.push([stopajLabel, -r.stopaj, "neg"]);
  if(r.returnLoss > 0) rows.push(["İade Kaybı", -r.returnLoss, "neg"]);
  rows.push(["KDV (ödenen)", -r.netKdv, "neg"]);
  rows.push(["= Hedef Net Kâr", r.profit, "pos"]);
  let html = "<tr><th>Kalem</th><th>Tutar</th></tr>";
  rows.forEach(r=>{
    const cls = r[2] ? `class="${r[2]}"` : "";
    html += `<tr><td>${r[0]}</td><td ${cls}>${tl(r[1])}</td></tr>`;
  });
  $("breakdown").innerHTML = html;
}

function renderCampaign(salePrice, discount, profit, margin, ratio, commission, netKdv, stopaj, extra, serviceFee, returnLoss){
  $("result-label").textContent = "Kampanyalı Net Kâr";
  const v = $("result-value");
  v.textContent = tl(profit);
  v.classList.toggle("neg", profit < 0);
  kpi("kpi-margin", pct(margin), margin<0?"neg":"pos");
  kpi("kpi-ratio", pct(ratio), ratio<0?"neg":"pos");
  kpi("kpi-commission", tl(commission));
  kpi("kpi-kdv", tl(netKdv));

  const price = num("price"), cost = num("cost"), cargo = num("cargo");
  const discountAmt = price - salePrice;
  const stopajLabel = stopajAutoOn() ? "Stopaj (KDV-hariç × %"+STOPAJ_RATE+")" : "Stopaj";
  const verdict = profit >= 0
    ? "✓ Bu kampanya KÂRLI — " + tl(profit) + " kazanırsın, girebilirsin"
    : "✗ Bu kampanya ZARARDA — " + tl(profit) + " kaybedersin, girmemen önerilir";
  const rows = [
    ["Normal Satış Fiyatı", price, ""],
    ["İndirim (%"+(discount*100).toFixed(1)+")", -discountAmt, "neg"],
    ["Kampanyalı Fiyat", salePrice, "pos"],
    ["Alış Fiyatı", -cost, "neg"],
    ["Komisyon", -commission, "neg"],
    ["Kargo", -cargo, "neg"],
  ];
  if(extra > 0) rows.push(["Ek Bedel ("+cargoTypeLabel()+")", -extra, "neg"]);
  if(serviceFee > 0) rows.push(["Hizmet Bedeli", -serviceFee, "neg"]);
  if(stopaj > 0) rows.push([stopajLabel, -stopaj, "neg"]);
  if(returnLoss > 0) rows.push(["İade Kaybı", -returnLoss, "neg"]);
  rows.push(["KDV (ödenen)", -netKdv, "neg"]);
  let html = "<tr><th>Kalem</th><th>Tutar</th></tr>";
  rows.forEach(r=>{
    const cls = r[2] ? `class="${r[2]}"` : "";
    html += `<tr><td>${r[0]}</td><td ${cls}>${tl(r[1])}</td></tr>`;
  });
  html += `<tr class="verdict-row"><td colspan="2" class="${profit>=0?"pos":"neg"}">${verdict}</td></tr>`;
  $("breakdown").innerHTML = html;
}

function cargoTypeLabel(){
  return cargoType === "export" ? "İhracat" : cargoType === "sameday" ? "Aynı Gün" : "Ek Bedel";
}

function syncHizmetBedeli(){
  // Trendyol'da hizmet bedeli 13,19₺ otomatik (Melontik); diğer pazaryerlerinde elle girilir.
  const sf = $("service-fee");
  if(marketplace === "trendyol"){
    if(sf.value === "" || parseFloat(sf.value) === 0){
      sf.value = TRENDYOL_SERVICE_FEE.toFixed(2);
    }
  }
}

function refresh(){
  updateRateHint();
  $("target-block").style.display = mode==="price" ? "block":"none";
  $("campaign-block").style.display = mode==="campaign" ? "block":"none";
  $("price").disabled = mode==="price";
  calculate();
}

document.addEventListener("DOMContentLoaded", () => {
  if(!$("marketplace")) return; // hesap makinesi bu sayfada değil
  buildCategories();
  $("marketplace").addEventListener("change", ()=>{
    marketplace = $("marketplace").value;
    $("rate").value = suggestedRate().toFixed(2);
    syncHizmetBedeli();
    refresh();
  });
  $("cargoType").addEventListener("click", e=>{
    const b = e.target.closest("button"); if(!b) return;
    cargoType = b.dataset.c;
    document.querySelectorAll("#cargoType button").forEach(x=>x.classList.toggle("on", x===b));
    const extraBlock = $("extra-fee-block");
    const showExtra = cargoType !== "seller";
    extraBlock.style.display = showExtra ? "block":"none";
    if(showExtra){
      $("extra-fee-label").textContent = cargoType === "export" ? "İhracat Bedeli (₺)" : "Aynı Gün Teslimat Bedeli (₺)";
    }
    calculate();
  });
  document.querySelectorAll(".tabbar button").forEach(b=>{
    b.addEventListener("click", ()=>{
      mode = b.dataset.mode;
      document.querySelectorAll(".tabbar button").forEach(x=>x.classList.toggle("on", x===b));
      refresh();
    });
  });
  // Stopaj otomatik / elle geçişi
  const sa = $("stopaj-auto");
  if(sa){
    sa.addEventListener("change", ()=>{
      $("stopaj").disabled = sa.checked;
      if(sa.checked) $("stopaj").value = "";
      calculate();
    });
    $("stopaj").disabled = sa.checked;
  }
  ["cost","price","cargo","rate","kdv","targetProfit","targetMargin","extra-fee","service-fee","stopaj","return-rate"].forEach(id=>{
    $(id).addEventListener("input", calculate);
  });
  $("category").addEventListener("change", ()=>{
    $("rate").value = suggestedRate().toFixed(2);
    refresh();
  });
  $("rate").value = suggestedRate().toFixed(2);
  syncHizmetBedeli();
  refresh();
});

// ---- Landing: SSS akordeon + scroll animasyonları (hesap makinesi olmayan sayfalarda da çalışır) ----
document.addEventListener("DOMContentLoaded", () => {
  // SSS akordeon
  document.querySelectorAll(".faq-item").forEach(item=>{
    const q = item.querySelector(".faq-q");
    if(!q) return;
    q.addEventListener("click", ()=>{
      const wasOpen = item.classList.contains("open");
      document.querySelectorAll(".faq-item.open").forEach(x=>x.classList.remove("open"));
      if(!wasOpen) item.classList.add("open");
    });
  });

  // Fade-in scroll animasyonu
  if("IntersectionObserver" in window){
    const els = document.querySelectorAll(".step, .feature, .price-card, .faq-item, .hero-demo");
    const io = new IntersectionObserver(entries=>{
      entries.forEach(en=>{
        if(en.isIntersecting){
          en.target.style.opacity = "1";
          en.target.style.transform = "translateY(0)";
          io.unobserve(en.target);
        }
      });
    }, {threshold:.08});
    els.forEach(el=>{
      el.style.opacity = "0";
      el.style.transform = "translateY(14px)";
      el.style.transition = "opacity .5s ease, transform .5s ease";
      io.observe(el);
    });
  }
});
