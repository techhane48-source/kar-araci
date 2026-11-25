const fs=require('fs'),vm=require('vm');
const rows=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
const values={};
const context={document:{getElementById:id=>values[id],addEventListener:()=>{}},console};
vm.createContext(context);
vm.runInContext(fs.readFileSync('public_html/assets/app.js','utf8'),context);
const calc=vm.runInContext('computeAt',context),solver=vm.runInContext('minimumPriceCents',context);
let fails=0;
for(const row of rows){
  values['cost-vat']={value:String(row.purchase)};
  values['negative-vat']={checked:row.negative};
  values['stopaj-auto']={checked:true};
  values['stopaj-rate']={value:String(row.stopRate)};
  const at=p=>calc(p,row.cost,row.cargo,row.rate/100,row.vat,row.extra,row.service,row.ret/100);
  const objective=p=>at(p).profit-(row.margin>0?p*row.margin/100:row.tp);
  const a=row.vat/(100+row.vat)-row.rate/100/6;
  const b=row.cost*row.purchase/(100+row.purchase)+(row.cargo+row.extra+row.service)/6;
  const cents=solver(objective,!row.negative&&a>0?b/a:null);
  const price=cents===null?null:cents/100;
  if(price!==row.price||(price!==null&&Math.abs(at(price).profit-row.profit)>1e-7)){fails++;console.error('Mismatch',row,price);}
}
console.log('PHP/browser parity:',rows.length,'cases;',fails,'failures');
process.exit(fails?1:0);
