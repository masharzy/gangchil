'use strict';
// Each account has its own durable cursor. Muted orders are consumed, not replayed.
function consumeAlerts(payload, cursors) {
 const user=String(payload.user_id||'');
 if(!user)return [];
 const orders=payload.orders||[], previous=cursors[user];
 const newest=Math.max(Number(previous)||0,...orders.map(o=>Number(o.id)||0));
 cursors[user]=newest;
 if(previous===undefined||payload.mute_until===-1||Number(payload.mute_until)>Number(payload.server_time))return [];
 return orders.filter(o=>o.status==='pending_payment'&&Number(o.id)>previous).sort((a,b)=>a.id-b.id);
}
module.exports={consumeAlerts};
