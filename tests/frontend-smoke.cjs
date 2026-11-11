const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const storage=new Map(),elements=new Map();let sends=0,payments=0,rejectPayment=false;
const el=id=>{if(!elements.has(id))elements.set(id,{style:{},classList:{add(){},remove(){},toggle(){}},setAttribute(){},textContent:'',focus(){}});return elements.get(id)};
const ctx={console,URL,Date,Intl,crypto:require('node:crypto').webcrypto,setTimeout:()=>0,clearTimeout(){},setInterval:()=>0,confirm:()=>true,history:{replaceState(){},pushState(){}},location:{hash:'',pathname:'/',search:'',assign(){}},addEventListener(){},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},document:{querySelector:el,querySelectorAll:()=>[],getElementById:el,addEventListener(){},body:{classList:{add(){},remove(){},toggle(){}}},documentElement:{classList:{add(){},remove(){}}}},fetch:async(url,options={})=>{let body;if(url.endsWith('/pay')){payments++;body=rejectPayment?{error:'Cash rejected'}:{order:{id:42,status:'confirmed'}};}else body={order:{id:42,status:'pending_payment',table_label:'5'}};return {ok:!rejectPayment||!url.endsWith('/pay'),status:rejectPayment?503:200,text:async()=>JSON.stringify(body)}}};ctx.window=ctx;ctx.AutoPrint={afterCash:id=>{assert.equal(id,42);sends++}};vm.createContext(ctx);
vm.runInContext(fs.readFileSync('public/app.js','utf8').replace(/^init\(\);$/m,''),ctx);
const run=s=>vm.runInContext(s,ctx);
(async()=>{
 assert.equal(run("TablePicker.normalize('Table 5')"),'5');assert.equal(run("TablePicker.normalize('6')"),'');
 run("state.cart={7:3};state.draft={table:'5',notes:'No nuts'};persistCustomerCart();state.cart={};state.draft={};restoreCustomerCart()");assert.equal(run('state.cart[7]'),3);assert.equal(run('state.draft.table'),'5');
 storage.set('gangchil.customer.cart.v1',JSON.stringify({version:1,cart:{7:-1,8:31,9:2},draft:{}}));run('restoreCustomerCart()');assert.equal(run('Object.keys(state.cart).join()'),'9');
 assert.match(run("checkoutRows([{id:1,name:'<script>',image_url:'x.jpg',price:10,q:2}])"),/&lt;script&gt;/);
 assert.match(run("Receipt.flag({receipt_submitted_at:'now'})"),/Sent to printer/);assert.match(run("Receipt.flag({receipt_printed_at:'now'})"),/Printed/);
 run("state.user={id:1,role:'owner'};loadOrders=async()=>{}");
 await Promise.all([run('Payment.receive(42)'),run('Payment.receive(42)')]);assert.equal(payments,1);assert.equal(sends,1);
 rejectPayment=true;await run('Payment.receive(42)');assert.equal(payments,2);assert.equal(sends,1,'failed payment must not print');
 console.log('PASS: cart reload and validation, assigned-table cash flow, payment deduplication, no print on failed payment, image rows and receipt states');
})().catch(e=>{console.error(e);process.exitCode=1});
