const {test}=require('node:test'),assert=require('node:assert/strict');
const {consumeAlerts}=require('../order-alerts.cjs');
const payload=(ids,extra={})=>({user_id:1,server_time:100,mute_until:0,orders:ids.map(id=>({id,status:'pending_payment'})),...extra});
test('notifications are baselined, deduplicated across restarts, and isolated per account',()=>{
 const cursors={};assert.deepEqual(consumeAlerts(payload([2,1]),cursors),[]);
 assert.deepEqual(consumeAlerts(payload([3,2]),cursors).map(o=>o.id),[3]);
 const restored=JSON.parse(JSON.stringify(cursors));assert.deepEqual(consumeAlerts(payload([3,2]),restored),[]);
 assert.deepEqual(consumeAlerts(payload([9],{user_id:2}),restored),[]);
 assert.deepEqual(consumeAlerts(payload([4,3]),restored).map(o=>o.id),[4]);
});
test('muted orders and already confirmed orders are never replayed later',()=>{
 const c={1:10};assert.deepEqual(consumeAlerts(payload([11],{mute_until:-1}),c),[]);
 assert.deepEqual(consumeAlerts(payload([11]),c),[]);
 assert.deepEqual(consumeAlerts(payload([12],{mute_until:200}),c),[]);
 assert.deepEqual(consumeAlerts(payload([13],{orders:[{id:13,status:'completed'}]}),c),[]);
 assert.deepEqual(consumeAlerts(payload([14,13]),c).map(o=>o.id),[14]);
});
