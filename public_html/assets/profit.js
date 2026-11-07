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
    const {cost=0,price=0,cargo=0,rate=0,kdv=20,extra_fee=0,service_fee=0,stopaj=0,return_rate=0}=p;
    validate([cost,price,cargo,extra_fee,service_fee,stopaj],[rate,kdv,return_rate]);
    const commission=price*rate/100,return_loss=price*return_rate/100;
    const net_kdv=Math.max(0,(price-cost-commission-cargo-extra_fee-service_fee)*kdv/(100+kdv));
    const profit=price-cost-commission-cargo-extra_fee-service_fee-stopaj-net_kdv-return_loss;
    return {ok:true,price,sale:price,cost,cargo,profit,margin:price>0?profit/price*100:0,
      ratio:cost>0?profit/cost*100:0,commission,net_kdv,extra_fee,service_fee,stopaj,return_loss,
      totalCuts:commission+cargo+extra_fee+service_fee+stopaj+net_kdv+return_loss};
  }
  function calcPrice(p){
    try{
      const {cost=0,cargo=0,rate=0,kdv=20,extra_fee=0,service_fee=0,stopaj=0,return_rate=0,
        targetProfit=0,targetMargin=0,stopaj_mode='manual',stopaj_rate=1}=p;
      validate([cost,cargo,extra_fee,service_fee,targetProfit,stopaj],[rate,kdv,return_rate,targetMargin,stopaj_rate]);
      const vat=kdv/(100+kdv),automatic=stopaj_mode==='auto';
      const withholding=automatic?stopaj_rate/(100+kdv):0;
      const slope=(1-rate/100)*(1-vat)-return_rate/100-withholding-targetMargin/100;
      if(slope<=1e-12) throw new Error('Bu kesintilerle hedef kâra ulaşılamıyor.');
      const fixed=cost+cargo+extra_fee+service_fee;
      const exact=(fixed*(1-vat)+(automatic?0:stopaj)+(targetMargin>0?0:targetProfit))/slope;
      const price=Math.max(0.01,Math.ceil(exact*100-1e-7)/100);
      if(!Number.isFinite(price)||price>MAX_AMOUNT) throw new Error('Hedef için gereken fiyat izin verilen sınırı aşıyor.');
      const result=calcProfit({...p,price,stopaj:automatic?price/(1+kdv/100)*stopaj_rate/100:stopaj});
      if(result.profit+1e-7<(targetMargin>0?price*targetMargin/100:targetProfit)) throw new Error('Hedef fiyat hesaplanamadı.');
      return result;
    }catch(e){return {ok:false,price:0,profit:null,margin:null,ratio:null,error:e.message};}
  }
  return {calcProfit,calcPrice,validate,MAX_AMOUNT};
});
