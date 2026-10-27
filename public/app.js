const $=s=>document.querySelector(s);const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));const state={page:'menu',menu:{categories:[],products:[],tables:[]},cart:{},user:null,orders:[],filter:'all',csrf:'',requestToken:null,submitting:false,search:'',draft:{table_id:'',customer_name:'',notes:''},pusher:null,realtime:'connecting',refreshBusy:false,refreshQueued:false,eventTimer:null};const money=n=>'৳'+Number(n||0).toLocaleString('en-BD',{maximumFractionDigits:2});const notice=s=>{const el=$('#notice');el.textContent=s;el.style.display='block';setTimeout(()=>el.style.display='none',3600)};async function api(url,options={}){const r=await fetch('/api'+url,{credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},...options});const body=await r.text();let j;try{j=JSON.parse(body)}catch(e){throw Error(`API returned ${r.status} ${r.statusText||''} instead of JSON. ${!body?'Empty PHP response (check API includes and PHP errors).':body.trim().startsWith('<')?'Host returned an HTML page (check .htaccess, PHP or InfinityFree browser verification).':'Invalid API response.'}`)}if(!r.ok)throw Error(j.error||`Request failed (${r.status})`);if(j.csrf)state.csrf=j.csrf;return j}const post=(url,data)=>api(url,{method:'POST',body:JSON.stringify(data)});const patch=(url,data)=>api(url,{method:'PATCH',body:JSON.stringify(data)});const can=p=>state.user&&(state.user.permissions?.[p]??({owner:['*'],manager:['orders:view','orders:create','orders:pay','orders:cancel','products:manage','tables:manage','reports:view'],chef:['orders:view','orders:prepare'],waiter:['orders:view','orders:create','orders:serve'],staff:['orders:view','orders:create']}[state.user.role]||[]).some(v=>v==='*'||v===p));
const orderViews=new Set(['orders','cashier','kitchen','service','dashboard']);
function homePage(){
  if(!state.user)return 'login';
  if(state.user.role==='chef')return 'kitchen';
  if(state.user.role==='waiter')return 'service';
  if(state.user.role==='manager')return 'cashier';
  if(state.user.role==='owner')return 'dashboard';
  return 'menu';
}
const pagePerm={dashboard:'reports:view',menu:'orders:create',cart:'orders:create',orders:'orders:view',cashier:'orders:pay',kitchen:'orders:prepare',service:'orders:serve',manage:'products:manage',reports:'reports:view',staff:'*',audit:'*'};
const pageNames={dashboard:'Overview',menu:'New Order',orders:'Orders',cashier:'Cash Desk',kitchen:'Kitchen',service:'Service',reports:'Reports',manage:'Menu & Tables',staff:'Team',audit:'Activity',login:'Staff Sign In'};
function go(page){
  if(page==='cart')page='menu';
  if(!state.user)page='login';
  else if(page==='login'||!pagePerm[page]||!can(pagePerm[page]))page=homePage();
  state.page=page;
  history.replaceState({},'',`#${page}`);
  render();
  if(orderViews.has(page))loadOrders();
  if(page==='reports')loadReports();
  if(page==='staff')loadStaff();
  if(page==='audit')loadAudit();
}
function nav(){
  document.body.classList.toggle('is-guest',!state.user);
  for(const b of document.querySelectorAll('[data-page]')){
    const permission=b.dataset.permission;
    b.hidden=!!permission&&!can(permission);
    b.classList.toggle('active',state.page===b.dataset.page);
    if(b.classList.contains('active'))b.setAttribute('aria-current','page');
    else b.removeAttribute('aria-current');
  }
  const count=$('#count');
  if(count)count.textContent=Object.values(state.cart).reduce((a,b)=>a+b,0)||'';
  $('#who').textContent=state.user?`${state.user.name} · ${state.user.role}`:'';
  $('#loginBtn').textContent=state.user?'Sign out':'Sign in';
  $('#loginBtn').onclick=state.user?logout:()=>go('login');
  const title=$('#viewTitle'),hint=$('#viewHint');
  if(title)title.textContent=pageNames[state.page]||'Operations';
  if(hint)hint.textContent=state.user?'GANGCHIL / '+state.user.role.toUpperCase():'Staff access only';
}
const buttons=(arr)=>arr.map(([text,fn])=>`<button class="btn" onclick="${fn}">${text}</button>`).join(' ');function render(){nav();const p=state.page;$('#app').innerHTML=({dashboard:dashboardPage,menu:menuPage,cart:menuPage,login:loginPage,orders:ordersPage,cashier:cashierPage,kitchen:kitchenPage,service:servicePage,manage:managePage,reports:reportsPage,staff:staffPage,audit:auditPage})[p]?.()||menuPage()}
function menuPage(){
  if(!can('orders:create'))return '<section class="empty-state"><h1>Staff Login Required</h1><p>Order entry is restricted to restaurant staff.</p></section>';
  const cats=state.menu.categories;
  const allProducts=state.menu.products.filter(p=>state.filter==='all'||String(p.category_id)===String(state.filter));
  const filtered=allProducts;const shownCount=allProducts.filter(p=>String(p.name).toLowerCase().includes(state.search.toLowerCase())).length;
  const ticket=Object.entries(state.cart).map(([id,q])=>({...state.menu.products.find(p=>String(p.id)===id),q})).filter(p=>p.id&&p.q>0);
  const total=ticket.reduce((amount,item)=>amount+Number(item.price)*item.q,0);
  const itemCount=ticket.reduce((count,item)=>count+item.q,0);
  const categoryButtons=[[ 'all','All items' ],...cats.map(c=>[String(c.id),c.name])];
  return `<div class="page-head"><div><div class="eyebrow">POINT OF SALE</div><h1>New Order</h1><p class="muted">Select items, check the ticket and send to Cash Desk.</p></div><span class="quiet-tag">● Staff terminal</span></div>
  <div class="pos-workspace">
    <section class="pos-catalog" aria-label="Menu products">
      <div class="catalog-tools"><label class="search-field"><span>⌕</span><input id="productSearch" type="search" aria-label="Search menu" placeholder="Search food or drinks…" value="${esc(state.search)}" oninput="searchProducts(this.value)" autocomplete="off"></label><span id="catalogCount" class="catalog-count">${shownCount} items</span></div>
      <div class="category-pills">${categoryButtons.map(([id,name])=>`<button type="button" class="${state.filter===id?'active':''}" onclick="filter('${id}')">${esc(name)}</button>`).join('')}</div>
      <div id="productGrid" class="product-grid">
        ${filtered.map(p=>`<button class="pos-product" type="button" data-name="${esc(String(p.name).toLowerCase())}" ${!String(p.name).toLowerCase().includes(state.search.toLowerCase())?'hidden':''} onclick="add(${p.id})" ${!p.available?'disabled':''} aria-label="Add ${esc(p.name)} to ticket">
          ${p.image_url?`<img loading="lazy" src="${esc(p.image_url)}" alt="">`:'<div class="product-art">✦</div>'}
          <span class="product-info"><strong>${esc(p.name)}</strong><small>${esc(p.description||'')}</small><b>${money(p.price)}</b></span>
          <span class="product-add">${p.available?'+':'Out'}</span>
        </button>`).join('')||'<div class="empty-state">No matching menu items. Try another category.</div>'}
      </div>
    </section>
    <aside class="pos-ticket" aria-label="Current ticket">
      <div class="ticket-heading"><div><div class="eyebrow">CURRENT TICKET</div><h2>Order details</h2></div><span class="ticket-count">${itemCount} items</span></div>
      <div class="ticket-items">
        ${ticket.map(p=>`<div class="ticket-line"><div class="ticket-line-title"><strong>${esc(p.name)}</strong><span>${money(Number(p.price)*p.q)}</span></div><div class="ticket-line-bottom"><small>${money(p.price)} each</small><div class="ticket-qty"><button type="button" aria-label="Remove one ${esc(p.name)}" onclick="qty(${p.id},-1)">−</button><span>${p.q}</span><button type="button" aria-label="Add one ${esc(p.name)}" onclick="qty(${p.id},1)">+</button></div></div></div>`).join('')||'<div class="ticket-empty"><div>▤</div><strong>Your ticket is empty</strong><span>Tap a menu item to add it</span></div>'}
      </div>
      <form class="ticket-checkout" onsubmit="place(event)">
        <label>Dining table<select id="table" onchange="updateDraft('table_id',this.value)"><option value="">Takeaway / No table</option>${state.menu.tables.map(t=>`<option value="${t.id}" ${String(state.draft.table_id)===String(t.id)?'selected':''}>${esc(t.name)}</option>`).join('')}</select></label>
        <label>Customer name <span class="form-optional">optional</span><input id="customer" value="${esc(state.draft.customer_name)}" maxlength="100" oninput="updateDraft('customer_name',this.value)" placeholder="Enter name"></label>
        <label>Kitchen notes <span class="form-optional">optional</span><textarea id="notes" maxlength="500" oninput="updateDraft('notes',this.value)" placeholder="Less spicy, no onions…">${esc(state.draft.notes)}</textarea></label>
        <div class="ticket-total"><span>Total</span><strong>${money(total)}</strong></div>
        <p class="ticket-help">Cash must be confirmed by Manager before the kitchen receives this ticket.</p>
        <button id="submitOrder" class="btn submit-ticket" type="submit" ${!ticket.length||state.submitting?'disabled':''}>${state.submitting?'Submitting…':'Send to Cash Desk →'}</button>
      </form>
    </aside>
  </div>`;
}
function updateDraft(field,value){if(Object.prototype.hasOwnProperty.call(state.draft,field))state.draft[field]=value}
function searchProducts(value){
  state.search=value;
  let visible=0;
  for(const card of document.querySelectorAll('.pos-product')){
    const show=card.dataset.name.includes(value.toLowerCase());
    card.hidden=!show;if(show)visible++;
  }
  const count=$('#catalogCount');if(count)count.textContent=visible+' items';
}
function filter(v){state.filter=v;render()}
function add(id){state.cart[id]=(state.cart[id]||0)+1;render()}
function qty(id,change){state.cart[id]=Math.max(0,(state.cart[id]||0)+change);if(!state.cart[id])delete state.cart[id];render()}
function cartPage(){return menuPage()}
async function place(e){
  e.preventDefault();
  if(state.submitting)return;
  state.submitting=true;
  const b=$('#submitOrder');if(b){b.disabled=true;b.textContent='Submitting…'}
  try{
    const items=Object.entries(state.cart).map(([product_id,quantity])=>({product_id:Number(product_id),quantity}));
    if(!items.length)throw Error('Choose at least one item');
    state.requestToken ||= crypto.randomUUID().replace(/-/g,'');
    const result=await post('/orders',{
      request_token:state.requestToken,items,
      table_id:state.draft.table_id||null,
      customer_name:state.draft.customer_name,notes:state.draft.notes
    });
    state.cart={};state.draft={table_id:'',customer_name:'',notes:''};state.requestToken=null;
    notice(`Order #${result.order.id} saved — collect cash at the Cash Desk`);
    go(can('orders:pay')?'cashier':'orders');
  }catch(error){notice(error.message)}
  finally{state.submitting=false;if(state.page==='menu')render()}
}
function loginPage(){return `<h1>Staff Sign In</h1><p class="muted">Only authorized staff can access operations.</p><form class="card" onsubmit="login(event)"><label>Email<input type="email" id="email" required autocomplete="username"></label><label>Password<input type="password" id="password" required autocomplete="current-password"></label><button class="btn">Sign In</button></form>`}async function login(e){e.preventDefault();try{const r=await post('/auth/login',{email:$('#email').value,password:$('#password').value});state.user=r.user;await loadMenu();connect();go(homePage())}catch(e){notice(e.message)}}async function logout(){await post('/auth/logout',{});state.user=null;state.csrf='';if(state.pusher){state.pusher.disconnect();state.pusher=null}clearTimeout(state.eventTimer);state.realtime='offline';go('login')}
const statuses={pending_payment:'Pending cash',new:'New order',preparing:'Preparing',ready:'Ready to serve',served:'Served',cancelled:'Cancelled'};const actions={pending_payment:['pay','Confirm Cash','orders:pay'],new:['prepare','Start Preparing','orders:prepare'],preparing:['ready','Mark Ready','orders:prepare'],ready:['serve','Mark Served','orders:serve']};function ordersPage(){if(!can('orders:view'))return `<h1>Orders</h1><div class="card">Staff login required.</div>`;let list=state.orders.filter(o=>state.filterOrder==='all'||!state.filterOrder||o.status===state.filterOrder);return `<div class="row"><h1>Orders</h1><button id="refreshOrdersButton" class="small" onclick="manualRefresh()" aria-label="Refresh orders without reloading the page">↻ Refresh</button></div><div class="muted"><span class="realtime-dot ${state.realtime}">●</span> ${state.realtime==='live'?'Live · Instant updates':state.realtime==='connecting'?'Connecting to live updates…':state.realtime==='manual'?'Manual refresh mode':'Live connection unavailable · use Refresh'} · Saves stay in MySQL</div><div class="toolbar">${['all','pending_payment','new','preparing','ready','served'].map(s=>`<button class="${(state.filterOrder||'all')===s?'active':''}" onclick="state.filterOrder='${s}';render()">${s==='all'?'All':statuses[s]}</button>`).join('')}</div><div class="grid">${list.map(o=>{const a=actions[o.status];return `<div class="card"><div class="row"><b>#${o.id} · ${esc(o.table_name||'Takeaway')}</b><span class="badge ${o.status}">${statuses[o.status]}</span></div><div class="muted">${new Date(o.created_at).toLocaleString()}</div><div style="margin:12px 0">${o.items.map(i=>`<div class="row"><span>${esc(i.name)} × ${i.quantity}</span><span>${money(Number(i.price)*i.quantity)}</span></div>`).join('')}</div><div class="row"><b>${money(o.total)}</b>${o.paid_at?`<a class="small" target="_blank" rel="noopener" href="/receipt.php?id=${o.id}">Print Receipt</a>`:''}${a&&can(a[2])?`<button class="btn" onclick="advance(${o.id},'${a[0]}')">${a[1]}</button>`:''}</div>${o.status==='pending_payment'&&can('orders:cancel')?`<button class="small" style="margin-top:10px" onclick="advance(${o.id},'cancel')">Cancel order</button>`:''}</div>`}).join('')||'<div class="card">No orders here.</div>'}</div>`}async function loadOrders(){if(!can('orders:view'))return false;if(state.refreshBusy){state.refreshQueued=true;return false}state.refreshBusy=true;let success=false;try{const response=await api('/orders');state.orders=response.orders;success=true;if(orderViews.has(state.page))render()}catch(e){notice('Could not refresh orders: '+e.message)}finally{state.refreshBusy=false;if(state.refreshQueued){state.refreshQueued=false;setTimeout(()=>loadOrders(),100)}}return success}async function manualRefresh(){const b=$('#refreshOrdersButton');if(b){b.disabled=true;b.textContent='Refreshing…'}const ok=await loadOrders();if(ok)notice('Order list refreshed · without full page reload')}async function advance(id,action){if(!confirm(`Confirm ${action} for order #${id}?`))return;try{await post(`/orders/${id}/${action}`,{});await loadOrders()}catch(e){notice(e.message)}}
function boardRefresh(){
  return `<button id="refreshOrdersButton" class="small action-refresh" type="button" onclick="manualRefresh()" aria-label="Refresh this board">↻ Refresh</button>`;
}
function stateHint(){
  return `<span class="state-hint"><i class="realtime-dot ${state.realtime}">●</i>${state.realtime==='live'?'Live updates active':state.realtime==='connecting'?'Connecting to Pusher…':state.realtime==='manual'?'Manual updates — Pusher not configured':'Connection unavailable · use Refresh'}</span>`;
}
function boardTitle(label,title,note){
  return `<div class="page-head"><div><div class="eyebrow">${label}</div><h1>${title}</h1><p class="muted">${note}</p></div>${boardRefresh()}</div><div class="connection-note">${stateHint()}</div>`;
}
function orderTicket(order,action){
  const all=Array.isArray(order.items)?order.items:[];
  const a=action===false?null:(action||actions[order.status]);
  const actionButton=a&&can(a[2])?`<button class="btn ticket-action" onclick="advance(${Number(order.id)},'${a[0]}')">${esc(a[1])}</button>`:'';
  return `<article class="work-ticket status-${esc(order.status)}">
    <div class="ticket-top"><strong>#${esc(order.id)}</strong><span class="badge ${esc(order.status)}">${esc(statuses[order.status]||order.status)}</span></div>
    <div class="ticket-meta"><b>${esc(order.table_name||'Takeaway')}</b><time>${esc(String(order.created_at||'').replace('T',' ').slice(0,16))}</time></div>
    ${order.customer_name?`<div class="ticket-customer">${esc(order.customer_name)}</div>`:''}
    <div class="ticket-food">${all.map(item=>`<div class="ticket-food-row"><span><b>${Number(item.quantity)}×</b> ${esc(item.name)}</span></div>`).join('')}</div>
    ${order.notes?`<div class="kitchen-note"><b>Kitchen note</b> · ${esc(order.notes)}</div>`:''}
    <div class="ticket-foot"><strong>${money(order.total)}</strong>${actionButton}</div>
  </article>`;
}
function cashierPage(){
  if(!can('orders:pay'))return '<div class="empty-state">Cash desk access denied.</div>';
  const due=state.orders.filter(o=>o.status==='pending_payment').sort((a,b)=>Number(a.id)-Number(b.id));
  const recent=state.orders.filter(o=>o.paid_at&&o.status!=='cancelled').slice(0,8);
  return boardTitle('PAYMENTS','Cash Desk','Confirm money received before releasing a kitchen ticket.')+
  `<div class="metric-strip"><div class="metric"><span>Awaiting cash</span><strong>${due.length}</strong></div><div class="metric"><span>Pending amount</span><strong>${money(due.reduce((n,o)=>n+Number(o.total),0))}</strong></div></div>
    <div class="board-section-head"><h2>Awaiting payment</h2><span>${due.length} tickets</span></div>
    <div class="ticket-grid">${due.map(o=>orderTicket(o,['pay','Confirm Cash','orders:pay'])).join('')||'<div class="empty-state"><b>Cash queue is clear</b><p>New tablet orders appear here automatically.</p></div>'}</div>
    <div class="board-section-head"><h2>Recently confirmed</h2><span>Latest ${recent.length}</span></div>
    <div class="ticket-grid ticket-grid-compact">${recent.map(o=>orderTicket(o,false)).join('')||'<div class="empty-state">No paid tickets yet.</div>'}</div>
    <p class="muted">Cash tender, change and register reconciliation are scheduled for Phase 2. This screen currently confirms cash received but does not record denominations.</p>`;
}
function kitchenPage(){
  if(!can('orders:prepare'))return '<div class="empty-state">Kitchen access denied.</div>';
  const queues=[
    ['New tickets','new','Waiting to start'],
    ['Preparing','preparing','Currently cooking'],
    ['Ready','ready','Waiting for service']
  ];
  return boardTitle('BACK OF HOUSE','Kitchen Display','Work new tickets in arrival order; preparation notes stay visible.')+
   `<div class="kds-board">${queues.map(([title,status,sub])=>{
      const list=state.orders.filter(o=>o.status===status).sort((a,b)=>Number(a.id)-Number(b.id));
      return `<section class="kds-column"><div class="kds-column-head"><div><h2>${title} <span class="queue-count">${list.length}</span></h2><p>${sub}</p></div></div><div class="kds-scroll">${list.map(o=>orderTicket(o,status==='ready'?false:undefined)).join('')||'<div class="queue-empty">No tickets</div>'}</div></section>`;
    }).join('')}</div>`;
}
function servicePage(){
  if(!can('orders:serve'))return '<div class="empty-state">Service access denied.</div>';
  const ready=state.orders.filter(o=>o.status==='ready').sort((a,b)=>Number(a.id)-Number(b.id));
  const served=state.orders.filter(o=>o.status==='served').slice(0,8);
  return boardTitle('FRONT OF HOUSE','Service Board','Pick up ready dishes, serve the table and mark the ticket served.')+
  `<div class="metric-strip"><div class="metric"><span>Ready for pickup</span><strong>${ready.length}</strong></div><div class="metric"><span>Recently served</span><strong>${served.length}</strong></div></div>
    <div class="board-section-head"><h2>Ready to serve</h2><span>${ready.length} waiting</span></div>
    <div class="ticket-grid">${ready.map(o=>orderTicket(o,['serve','Mark Served','orders:serve'])).join('')||'<div class="empty-state"><b>Nothing waiting for pickup</b><p>Kitchen-completed orders will appear here.</p></div>'}</div>
    <div class="board-section-head"><h2>Recently served</h2></div>
    <div class="ticket-grid ticket-grid-compact">${served.map(o=>orderTicket(o,false)).join('')||'<div class="empty-state">No served tickets in the recent list.</div>'}</div>`;
}
function dashboardPage(){
  if(!can('reports:view'))return '<div class="empty-state">Owner or manager access required.</div>';
  const orders=state.orders,open=orders.filter(o=>!['served','cancelled'].includes(o.status));
  const toPay=orders.filter(o=>o.status==='pending_payment');
  const preparing=orders.filter(o=>o.status==='new'||o.status==='preparing');
  const ready=orders.filter(o=>o.status==='ready');
  return boardTitle('OPERATIONS','Today’s Workspace','Current live queues. Sales totals are available in the Reports page.')+
    `<div class="metric-grid">
      <div class="metric"><span>Open tickets · recent list</span><strong>${open.length}</strong></div>
      <div class="metric"><span>Awaiting cash</span><strong>${toPay.length}</strong></div>
      <div class="metric"><span>In kitchen</span><strong>${preparing.length}</strong></div>
      <div class="metric"><span>Ready to serve</span><strong>${ready.length}</strong></div>
    </div>
    <div class="board-section-head"><h2>Work queues</h2></div>
    <div class="quick-grid">
     <button class="quick-card" onclick="go('menu')" ${!can('orders:create')?'disabled':''}><strong>New Order</strong><span>Take an order on the tablet</span><b>Open POS →</b></button>
     <button class="quick-card" onclick="go('cashier')" ${!can('orders:pay')?'disabled':''}><strong>Cash Desk</strong><span>${toPay.length} order(s) awaiting cash</span><b>Go to Cash →</b></button>
     <button class="quick-card" onclick="go('kitchen')" ${!can('orders:prepare')?'disabled':''}><strong>Kitchen</strong><span>${preparing.length} order(s) in production</span><b>View Board →</b></button>
     <button class="quick-card" onclick="go('reports')"><strong>Sales Reports</strong><span>Verified MySQL payment totals</span><b>Open Reports →</b></button>
    </div>
    <p class="muted">Open-ticket counts use the latest 150 orders from the current API, not a full-day accounting report. Full shift accounting is a separate V2 milestone.</p>`;
}
function managePage(){if(!state.user)return '<h1>Management</h1><div class="card">Staff login required.</div>';return `<h1>Operations</h1><p class="muted">${esc(state.user.role)} workspace</p><div class="grid">${can('orders:view')?'<button class="btn" onclick="go(\'orders\')">Live Orders</button>':''}${can('reports:view')?'<button class="btn alt" onclick="go(\'reports\')">Sales Reports</button>':''}${can('*')?'<button class="btn alt" onclick="go(\'staff\')">Staff & Roles</button><button class="btn alt" onclick="go(\'audit\')">Audit Log</button>':''}</div>${can('products:manage')?`<h2>Add Category</h2><form class="card" onsubmit="createCategory(event)"><label>Name<input id="catname" required></label><button class="btn">Save Category</button></form><h2>Add Product</h2><form class="card" onsubmit="createProduct(event)"><label>Name<input id="pname" required></label><label>Description<input id="pdesc"></label><label>Price (BDT)<input id="pprice" type="number" step="0.01" min="0" required></label><label>Category<select id="pcat">${state.menu.categories.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></label><label>Image HTTPS URL<input id="pimage" type="url" placeholder="https://..."></label><button class="btn">Save Product</button></form><h2>Product Availability</h2>${state.menu.products.map(p=>`<div class="card row"><div><b>${esc(p.name)}</b><div class="muted">${money(p.price)}</div></div><button class="small" onclick="toggleProduct(${p.id},${!p.available})">${p.available?'Mark Sold Out':'Make Available'}</button></div>`).join('')}`:''}${can('tables:manage')?`<h2>Add Table</h2><form class="card" onsubmit="createTable(event)"><label>Table name<input id="tname" required></label><button class="btn">Add Table</button></form>`:''}`}
async function createCategory(e){e.preventDefault();try{await post('/categories',{name:$('#catname').value});await loadMenu();render();notice('Category added')}catch(e){notice(e.message)}}async function createProduct(e){e.preventDefault();try{await post('/products',{name:$('#pname').value,description:$('#pdesc').value,price:Number($('#pprice').value),category_id:Number($('#pcat').value),image_url:$('#pimage').value||null});await loadMenu();render();notice('Product added')}catch(e){notice(e.message)}}async function toggleProduct(id,available){try{await patch('/products/'+id,{available});await loadMenu();render()}catch(e){notice(e.message)}}async function createTable(e){e.preventDefault();try{await post('/tables',{name:$('#tname').value});await loadMenu();render();notice('Table added')}catch(e){notice(e.message)}}
function reportsPage(){return `<h1>Sales Reports</h1><div class="toolbar"><input id="from" type="date" style="width:auto" value="${new Date(Date.now()-7*86400000).toISOString().slice(0,10)}"><input id="to" type="date" style="width:auto" value="${new Date().toISOString().slice(0,10)}"><button class="btn" onclick="loadReports()">Apply</button></div><div id="reportResults">Loading...</div>`}async function loadReports(){if(!can('reports:view'))return;try{const from=$('#from')?.value||new Date(Date.now()-7*86400000).toISOString().slice(0,10),to=$('#to')?.value||new Date().toISOString().slice(0,10);const r=await api(`/reports?from=${from}&to=${to}`);$('#reportResults').innerHTML=`<div class="grid"><div class="stat">Total Sales<strong>${money(r.sales.revenue)}</strong></div><div class="stat">Paid Orders<strong>${r.sales.orders}</strong></div></div><h2>Popular Items</h2>${r.top.map(p=>`<div class="card row">${esc(p.product_name)}<b>${p.quantity} sold</b></div>`).join('')}`}catch(e){notice(e.message)}}
function staffPage(){return `<h1>Staff & Roles</h1><div id="staffList">Loading...</div><h2>Add Staff</h2><form class="card" onsubmit="createStaff(event)"><label>Name<input id="sname" required></label><label>Email<input type="email" id="semail" required></label><label>Password (12+ characters)<input type="password" id="spass" minlength="12" required></label><label>Role<select id="srole">${['owner','manager','chef','waiter','staff'].map(r=>`<option>${r}</option>`).join('')}</select></label><button class="btn">Create Staff</button></form>`}async function loadStaff(){if(!can('*'))return;try{const r=await api('/staff');$('#staffList').innerHTML=r.staff.map(s=>`<div class="card row"><div><b>${esc(s.name)}</b><div class="muted">${esc(s.email)} · ${s.role} · ${s.active?'Active':'Disabled'}</div></div><button class="small" onclick="toggleStaff(${s.id},${!s.active})">${s.active?'Disable':'Enable'}</button></div>`).join('')}catch(e){notice(e.message)}}async function createStaff(e){e.preventDefault();try{await post('/staff',{name:$('#sname').value,email:$('#semail').value,password:$('#spass').value,role:$('#srole').value});go('staff');notice('Staff created')}catch(e){notice(e.message)}}async function toggleStaff(id,active){try{await patch('/staff/'+id,{active});loadStaff()}catch(e){notice(e.message)}}function auditPage(){return '<h1>Audit Log</h1><div id="auditList">Loading...</div>'}async function loadAudit(){if(!can('*'))return;try{const r=await api('/audit');$('#auditList').innerHTML=r.logs.map(l=>`<div class="card"><b>${esc(l.action)}</b> · ${esc(l.entity)} #${esc(l.entity_id)}<div class="muted">${esc(l.actor||'System')} · ${new Date(l.created_at).toLocaleString()}</div></div>`).join('')||'<div class="card">No activity yet.</div>'}catch(e){notice(e.message)}}
async function loadMenu(){state.menu=await api('/menu')}
function setRealtime(status){state.realtime=status;const pill=$('#connectionPill');if(pill){pill.className='connection-pill '+status;pill.textContent=status==='live'?'● Live':status==='connecting'?'● Connecting':status==='manual'?'● Manual':'● Offline'}if(orderViews.has(state.page))render()}
async function syncAfterConnection(){
  // Re-read server state, because Pusher events are transient during disconnects.
  if(orderViews.has(state.page))await loadOrders();
  else if(state.page==='menu'||state.page==='manage'||state.page==='cart'){try{await loadMenu();render()}catch(e){notice(e.message)}}
}
async function connect(){
  if(state.pusher){state.pusher.disconnect();state.pusher=null}
  setRealtime('connecting');
  try{
    const conf=await api('/realtime/config');
    if(!state.user)return;
    if(!conf.enabled || typeof window.Pusher!=='function'){setRealtime('manual');return}
    const pusher=new window.Pusher(conf.key,{
      cluster:conf.cluster,
      forceTLS:true,
      channelAuthorization:{
        endpoint:'/api/realtime/auth',
        transport:'ajax',
        headers:{'X-CSRF-Token':state.csrf}
      }
    });
    state.pusher=pusher;
    pusher.connection.bind('state_change',({current})=>{
      if(current==='disconnected'||current==='unavailable'||current==='failed')setRealtime('offline');
      if(current==='connecting'||current==='reconnecting')setRealtime('connecting');
    });
    const channel=pusher.subscribe('private-gangchil-staff');
    channel.bind('pusher:subscription_succeeded',()=>{setRealtime('live');syncAfterConnection()});
    channel.bind('pusher:subscription_error',()=>setRealtime('offline'));
    channel.bind('gangchil:changed',(event)=>{
      if(event?.kind==='menu'){
        if(['menu','manage','cart'].includes(state.page)){
          loadMenu().then(()=>{if(['menu','manage','cart'].includes(state.page))render()}).catch(()=>notice('Menu changed — tap Refresh'));
        }
        return;
      }
      // Short burst of updates = one order-list fetch, not one query per socket message.
      if(orderViews.has(state.page)){
        clearTimeout(state.eventTimer);
        state.eventTimer=setTimeout(()=>loadOrders(),120);
      }else if(state.page==='manage'||state.page==='menu')notice('Order update received · open Orders');
    });
  }catch(e){console.warn('Realtime connection unavailable',e);setRealtime('offline')}
}
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&state.user)syncAfterConnection()});
window.addEventListener('online',()=>{if(state.user&&state.realtime!=='live')syncAfterConnection()});
async function init(){try{const me=await api('/auth/me');state.user=me.user;if(state.user){await loadMenu();connect()}const requested=location.hash.slice(1);state.page=state.user&&(requested in pagePerm)&&can(pagePerm[requested])?requested:homePage();render();if(orderViews.has(state.page))loadOrders();if(state.page==='reports')loadReports();if(state.page==='staff')loadStaff();if(state.page==='audit')loadAudit();/* No timed polling: private Pusher events, reconnect sync and manual Refresh only. */}catch(e){$('#app').innerHTML=`<h1>GANGCHIL</h1><div class="card">Cannot connect to server. ${esc(e.message)} <button class="btn" onclick="location.reload()">Retry</button></div>`}}init();