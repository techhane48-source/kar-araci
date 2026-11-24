(function(root,factory){
  const api=factory();
  if(typeof module==='object' && module.exports) module.exports=api;
  else root.KarProfit=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  const MAX_AMOUNT=1000000000;
  function validate(amounts,percentages=[]){
    if(amounts.some(v=>typeof v!=='number'||!Number.isFinite(v)||v<0||v>MAX_AMOUNT)) throw new Error('Tutarlar 0 ile 1 milyar TL arasında olmalı.');
    if(percentages.some(v=>typeof v!=='number'||!Number.isFinite(v)||v<0||v>100)) throw new Error('Oranlar %0 ile %100 arasında olmalı.');
  }
  function calcProfit(p){
    const {cost=0,price=0,cargo=0,rate=0,kdv=20,extra_fee=0,service_fee=0,stopaj=0,return_rate=0,marketplace=""}=p;
    validate([cost,price,cargo,extra_fee,service_fee,stopaj],[rate,kdv,return_rate]);
    const commission=price*rate/100,return_loss=price*return_rate/100;
    const productVat=kdv/(100+kdv), expenseVat=marketplace==='trendyol'?1/6:productVat;
    const vat={sale:price*productVat,cost:cost*productVat,commission:commission*expenseVat,
      cargo:cargo*expenseVat,extra_fee:extra_fee*expenseVat,service_fee:service_fee*expenseVat};
    const vat_balance=vat.sale-vat.cost-vat.commission-vat.cargo-vat.extra_fee-vat.service_fee;
    const net_kdv=Math.max(0,vat_balance);
    const profit=price-cost-commission-cargo-extra_fee-service_fee-stopaj-net_kdv-return_loss;
    return {ok:true,price,sale:price,cost,cargo,profit,margin:price>0?profit/price*100:0,
      ratio:cost>0?profit/cost*100:0,commission,net_kdv,vat_balance,vat,extra_fee,service_fee,stopaj,return_loss,
      totalCuts:commission+cargo+extra_fee+service_fee+stopaj+net_kdv+return_loss};
  }
  function calcPrice(p){
    try{
      const {cost=0,cargo=0,rate=0,kdv=20,extra_fee=0,service_fee=0,stopaj=0,return_rate=0,
        targetProfit=0,targetMargin=0,stopaj_mode='manual',stopaj_rate=1,marketplace=""}=p;
      validate([cost,cargo,extra_fee,service_fee,targetProfit,stopaj],[rate,kdv,return_rate,targetMargin,stopaj_rate]);
      const vat=kdv/(100+kdv),automatic=stopaj_mode==='auto';
      const withholding=automatic?stopaj_rate/(100+kdv):0;
      const expenseVat=marketplace==='trendyol'?1/6:vat;
      const grossSlope=1-rate/100-return_rate/100-withholding-targetMargin/100;
      const slope=grossSlope-vat+rate/100*expenseVat;
      if(Math.min(slope,grossSlope)<=1e-12) throw new Error('Bu kesintilerle hedef kâra ulaşılamıyor.');
      const expenses=cargo+extra_fee+service_fee;
      const targetFixed=(automatic?0:stopaj)+(targetMargin>0?0:targetProfit);
      const exact=Math.max((cost+expenses+targetFixed)/grossSlope,
        (cost*(1-vat)+expenses*(1-expenseVat)+targetFixed)/slope);
      const price=Math.max(0.01,Math.ceil(exact*100-1e-7)/100);
      if(!Number.isFinite(price)||price>MAX_AMOUNT) throw new Error('Hedef için gereken fiyat izin verilen sınırı aşıyor.');
      const result=calcProfit({...p,price,stopaj:automatic?price/(1+kdv/100)*stopaj_rate/100:stopaj});
      if(result.profit+1e-7<(targetMargin>0?price*targetMargin/100:targetProfit)) throw new Error('Hedef fiyat hesaplanamadı.');
      return result;
    }catch(e){return {ok:false,price:0,profit:null,margin:null,ratio:null,error:e.message};}
  }
  return {calcProfit,calcPrice,validate,MAX_AMOUNT};
});
