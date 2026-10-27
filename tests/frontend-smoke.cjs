'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

let code = fs.readFileSync('public/app.js','utf8');
assert.match(code,/function kitchenPage\(/);
assert.match(code,/function cashierPage\(/);
assert.match(code,/function servicePage\(/);
assert.doesNotMatch(code,/pollOrders\(|setInterval\(/);
code = code.replace(/init\(\);\s*$/,'');
const fakeNode=()=>({
 value:'',textContent:'',innerHTML:'',dataset:{name:''},
 classList:{toggle(){},contains(){return false}},setAttribute(){},removeAttribute(){}
});
const sandbox={
 document:{
  body:{classList:{toggle(){}}},
  querySelector:()=>fakeNode(),
  querySelectorAll:()=>[],
  addEventListener(){}
 },
 window:{addEventListener(){}},
 history:{replaceState(){}},
 location:{hash:'#dashboard'},
 crypto:{randomUUID:()=> 'f7b6a27e-cf39-4da8-af7a-74ac8d5350bd'},
 setTimeout,clearTimeout,confirm:()=>true,console,fetch:async()=>{throw Error('network not needed for UI tests')}
};
vm.runInNewContext(code+'\n globalThis.__test = {state,homePage,can,menuPage,cashierPage,kitchenPage,servicePage,dashboardPage,orderTicket};',sandbox);
const p=sandbox.__test;
p.state.menu={
 categories:[{id:1,name:'Kabsa'}],
 products:[{id:3,name:'Chicken Kabsa',category_id:1,description:'Rice',price:350,available:true,image_url:null}],
 tables:[{id:1,name:'Table 1'}]
};
p.state.cart={3:2};
p.state.orders=[
 {id:1,status:'pending_payment',total:'700.00',table_name:'Table 1',created_at:'2026-10-27 12:00:00',notes:'Less spicy',items:[{name:'Chicken Kabsa',quantity:2,price:'350'}]},
 {id:2,status:'new',total:'350.00',table_name:'Table 2',created_at:'2026-10-27 12:01:00',notes:'No onions',items:[{name:'Chicken Kabsa',quantity:1,price:'350'}]},
 {id:3,status:'preparing',total:'350.00',table_name:'Table 3',created_at:'2026-10-27 12:02:00',items:[{name:'Chicken Kabsa',quantity:1,price:'350'}]},
 {id:4,status:'ready',total:'350.00',table_name:'Table 4',created_at:'2026-10-27 12:03:00',items:[{name:'Chicken Kabsa',quantity:1,price:'350'}]}
];

const homes={owner:'dashboard',manager:'cashier',chef:'kitchen',waiter:'service',staff:'menu'};
for(const role of Object.keys(homes)){
 p.state.user={id:7,name:'Test '+role,role,permissions:{}};
 assert.equal(p.homePage(),homes[role]);
 assert.equal(!!p.can('orders:pay'),role==='owner'||role==='manager');
 assert.equal(!!p.can('orders:prepare'),role==='owner'||role==='chef');
 assert.equal(!!p.can('orders:serve'),role==='owner'||role==='waiter');
 const pos=p.menuPage();
 if(role==='chef')assert.match(pos,/Staff Login Required/);
 else assert.match(pos,/Chicken Kabsa|Staff Login Required/);
 for(const name of ['cashierPage','kitchenPage','servicePage','dashboardPage']){
  const page=p[name]();
  assert.ok(page.length>0,name+' empty for '+role);
 }
}

p.state.user={id:7,name:'Chef',role:'chef',permissions:{}};
const kitchen=p.kitchenPage();
assert.match(kitchen,/New tickets/);
assert.match(kitchen,/Preparing/);
assert.match(kitchen,/No onions/);
assert.match(kitchen,/Start Preparing/);
assert.doesNotMatch(kitchen,/Mark Served/);

p.state.user={id:8,name:'Manager',role:'manager',permissions:{}};
const cashier=p.cashierPage();
assert.match(cashier,/Awaiting payment/);
assert.match(cashier,/Confirm Cash/);
assert.doesNotMatch(cashier,/Start Preparing/);

p.state.user={id:9,name:'Waiter',role:'waiter',permissions:{}};
assert.match(p.servicePage(),/Mark Served/);

p.state.user={id:1,name:'Owner',role:'owner',permissions:{}};
assert.match(p.menuPage(),/Send to Cash Desk/);
assert.match(p.dashboardPage(),/Recent|Overview|Workspace/);
assert.doesNotMatch(p.orderTicket(p.state.orders[1],false),/Start Preparing/);
console.log('GANGCHIL POS V2 frontend smoke tests PASSED');
