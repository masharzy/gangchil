'use strict';
const {app,BrowserWindow,ipcMain,protocol,net,safeStorage,session,Tray,Menu}=require('electron');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn}=require('node:child_process');const {assetFor}=require('./assets.cjs');const {receiptDocument}=require('./receipt.cjs');

const ORIGIN='https://gangchil.42web.io';let win,tray,config={},busy=false,store,closing=false,printWin,monitor,monitorAt=0,monitorStates=new Map(),wakeAgain=false,authReady=Promise.resolve();
const fontData=()=>fs.readFileSync(path.join(__dirname,'HindSiliguri-Regular.ttf')).toString('base64');let cachedFont;
const file=()=>path.join(app.getPath('userData'),'printer.json');
function persist(){fs.writeFileSync(file(),JSON.stringify(config),{mode:0o600});}
function readToken(){try{return config.token?safeStorage.decryptString(Buffer.from(config.token,'base64')):''}catch{return ''}}
async function call(route,data,token){const response=await net.fetch(ORIGIN+'/staff/api/'+route,{method:data?'POST':'GET',credentials:'include',bypassCustomProtocolHandlers:true,headers:{'Content-Type':'application/json',...(token?{'X-Print-Token':token}:{})},body:data?JSON.stringify(data):undefined});let json;try{json=await response.json()}catch{throw Error('Hosting verification or API error. Open the app and sign in again.')}if(!response.ok)throw Error(json.error||'Server request failed');return json;}
async function printers(){return (await win.webContents.getPrintersAsync()).filter(p=>!/(Microsoft Print to PDF|OneNote|XPS|Fax)/i.test(p.name)).map(p=>{const live=Date.now()-monitorAt<12000?monitorStates.get(p.name):null;const offline=!!(Number(p.status)&128)||live?.state==='offline';return {name:String(p.name),status:Number(p.status)||0,isDefault:p.isDefault===true,available:!offline,connection:offline?'offline':live?.state||'unknown',reason:live?.reason||'Windows has not confirmed physical connectivity'};});}
function startMonitor(){if(process.platform!=='win32')return;monitor=spawn('powershell.exe',['-NoLogo','-NoProfile','-NonInteractive','-Command',fs.readFileSync(path.join(__dirname,'printer-monitor.ps1'),'utf8')],{windowsHide:true,stdio:['ignore','pipe','ignore']});let buffer='';monitor.stdout.on('data',chunk=>{buffer+=chunk;let at;while((at=buffer.indexOf('\n'))>=0){const line=buffer.slice(0,at);buffer=buffer.slice(at+1);try{const values=JSON.parse(line);monitorStates=new Map((Array.isArray(values)?values:[values]).map(x=>[x.name,x]));monitorAt=Date.now();win?.webContents.send('printer-updated');}catch{}}});monitor.on('error',()=>{monitorAt=0});}
function wakePrint(){if(busy){wakeAgain=true;return;}setTimeout(tick,0);}
async function warmAuthentication(){const auth=new BrowserWindow({show:false,webPreferences:{nodeIntegration:false,contextIsolation:true,sandbox:true}});try{await auth.loadURL(ORIGIN+'/staff/api/health');for(let n=0;n<100;n++){if(await auth.webContents.executeJavaScript('document.body.innerText.includes(\'"ok":true\')'))return;await new Promise(r=>setTimeout(r,100));}}finally{auth.destroy();}}
async function assetHandler(request){const url=new URL(request.url);if(url.origin!==ORIGIN)return net.fetch(request,{bypassCustomProtocolHandlers:true});if(/^\/(?:staff\/)?api(?:\/|$)/.test(url.pathname)){if(!url.pathname.endsWith('/health'))await authReady;return net.fetch(request,{bypassCustomProtocolHandlers:true,credentials:'include'});}const asset=assetFor(request.url,path.join(__dirname,'ui'));if(asset)return new Response(fs.readFileSync(asset.file),{headers:{'Content-Type':asset.mime+'; charset=utf-8','Cache-Control':'no-cache'}});if(url.pathname.startsWith('/uploads/')||url.pathname==='/aes.js')return net.fetch(request,{bypassCustomProtocolHandlers:true,credentials:'include'});return new Response('Not found',{status:404});}
function nativeUIScript(source){return source+'\n;void 0;';}
function permitted(event){return event.sender===win?.webContents&&event.senderFrame===win.webContents.mainFrame&&new URL(event.senderFrame.url).origin===ORIGIN;}
function handle(name,fn){ipcMain.handle(name,async(event,...args)=>{if(!permitted(event))throw Error('Untrusted app frame');return fn(...args)});}
function journal(){try{return JSON.parse(fs.readFileSync(store,'utf8'))}catch{return null}}
function saveJob(job){const tmp=store+'.tmp';fs.writeFileSync(tmp,JSON.stringify(job),{mode:0o600});fs.renameSync(tmp,store);}
function ensurePrintWindow(){if(!printWin||printWin.isDestroyed()){printWin=new BrowserWindow({width:800,height:600,show:false,webPreferences:{nodeIntegration:false,contextIsolation:true,sandbox:true}});printWin.webContents.setWindowOpenHandler(()=>({action:'deny'}));}}
async function sendReceipt(job){
 ensurePrintWindow();
 cachedFont ||= fontData();await printWin.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(receiptDocument(job,cachedFont)));
 await printWin.webContents.executeJavaScript('document.fonts.ready.then(() => true)');
 const bounds=await printWin.webContents.executeJavaScript(`(()=>{const receipt=document.querySelector('.receipt');return {height:Math.ceil(receipt.getBoundingClientRect().height),width:receipt.getBoundingClientRect().width,overflow:[...receipt.querySelectorAll('*')].some(e=>e.scrollWidth>e.clientWidth+1)}})()`);
 if(bounds.overflow)throw Error('Receipt content exceeds its safe print area. Printing held to avoid clipping.');

 await new Promise((resolve,reject)=>printWin.webContents.print({silent:true,deviceName:config.printer,printBackground:true,color:false,landscape:false,scaleFactor:100,usePrinterDefaultPageSize:true,margins:{marginType:'printableArea'}},(ok,error)=>ok?resolve():reject(Error(error||'Printer rejected receipt'))));
}
async function tick(){if(busy||closing||!config.printer||!readToken())return;busy=true;try{
 const token=readToken();let job=journal();
 if(job&&job.stage==='submitted'){await call('printing/worker/result',{id:job.id,claim:job.claim,status:'submitted'},token);fs.unlinkSync(store);job=null;}
 else if(job&&['dispatching','unknown'].includes(job.stage)){await call('printing/worker/recover',{id:job.id,claim:job.claim},token);fs.unlinkSync(store);job=null;}
 if(job?.stage==='claimed'){/* Never replay a stale begin after a restart. Claim expires safely. */fs.unlinkSync(store);}
 const available=await printers();if(!available.some(p=>p.name===config.printer&&p.available!==false)){status('Printer disconnected: '+config.printer);return;}
 const answer=await call('printing/worker/claim',{},token);if(!answer.job){status(answer.blocked?'Receipt needs review in Printer settings':'Printer selected · '+config.printer);return;}
 job={...answer.job,stage:'claimed'};saveJob(job);
 // Persist uncertainty before the network transition, so a crash cannot authorize replay.
 job.stage='dispatching';saveJob(job);
 try{await call('printing/worker/begin',{id:job.id,claim:job.claim},token);}catch(e){job.stage='unknown';saveJob(job);throw e;}
 try{await sendReceipt(job);job.stage='submitted';saveJob(job);}catch(e){job.stage='unknown';saveJob(job);await call('printing/worker/result',{id:job.id,claim:job.claim,status:'unknown',detail:e.message},token);fs.unlinkSync(store);throw e;}
 await call('printing/worker/result',{id:job.id,claim:job.claim,status:'submitted'},token);fs.unlinkSync(store);status('Receipt sent · #'+String(job.order_id).padStart(6,'0'));
 }catch(e){status(e.message)}finally{busy=false;if(wakeAgain){wakeAgain=false;wakePrint();}}}
let lastStatus='Select a printer';function status(value){lastStatus=value;win?.webContents.send('printer-status',value);}
if(!app.requestSingleInstanceLock())app.quit();else{
 app.on('second-instance',()=>{win?.show();win?.focus()});
 app.whenReady().then(()=>{
  try{config=JSON.parse(fs.readFileSync(file(),'utf8'))}catch{}if(!config.deviceKey){config.deviceKey=crypto.randomUUID();persist();}store=path.join(app.getPath('userData'),'active-receipt.json');


  protocol.handle('https',assetHandler);authReady=warmAuthentication().catch(e=>status('Backend connection: '+e.message));
  session.defaultSession.setPermissionRequestHandler((_wc,permission,callback)=>callback(permission==='notifications'));
  win=new BrowserWindow({width:1280,height:850,minWidth:390,minHeight:600,title:'Gangchil',icon:path.join(__dirname,'app-icon.ico'),autoHideMenuBar:true,webPreferences:{preload:path.join(__dirname,'preload.cjs'),contextIsolation:true,nodeIntegration:false,sandbox:true,backgroundThrottling:false}});

  win.webContents.setWindowOpenHandler(({url})=>{if(url==='about:blank'||new URL(url).origin===ORIGIN)return {action:'allow',overrideBrowserWindowOptions:{webPreferences:{nodeIntegration:false,contextIsolation:true,sandbox:true,preload:undefined}}};return {action:'deny'}});win.webContents.on('will-navigate',(e,url)=>{if(new URL(url).origin!==ORIGIN)e.preventDefault()});
  handle('printer-info',async()=>({printers:await printers(),selected:config.printer||'',deviceKey:config.deviceKey,paired:!!readToken(),platform:'windows',status:lastStatus}));
  handle('printer-wake',async()=>{wakePrint();return {ok:true}});
  handle('printer-configure',async value=>{if(busy)throw Error('Wait for the current receipt to finish');if(typeof value?.name!=='string'||!(await printers()).some(p=>p.name===value.name&&p.available!==false))throw Error('Select an available installed printer');if(value.token){if(typeof value.token!=='string'||!/^[a-f0-9]{64}$/.test(value.token))throw Error('Invalid device registration');if(!safeStorage.isEncryptionAvailable())throw Error('Windows credential protection unavailable');config.token=safeStorage.encryptString(value.token).toString('base64');}config.printer=value.name;delete config.profile;persist();status('Printer selected · '+config.printer);wakePrint();return {ok:true};});
  handle('printer-test',async()=>{if(busy)throw Error('A receipt is being processed');if(!config.printer)throw Error('Select a printer');busy=true;try{await sendReceipt({order:{id:123456,table_label:'5',total:12345.67,paid_at_iso:'2026-11-12T09:16:59+06:00',paid_at:'2026-11-12 09:16:59'},items:[{product_name:'Test receipt · বাংলা খাবার · A long product name to check wrapping',quantity:1,unit_price:12345.67}]});return {ok:true}}finally{busy=false}});
  tray=new Tray(path.join(__dirname,'app-icon.png'));tray.setToolTip('Gangchil receipt printing');tray.setContextMenu(Menu.buildFromTemplate([{label:'Open Gangchil',click:()=>win.show()},{label:'Exit Gangchil',click:()=>app.quit()}]));tray.on('double-click',()=>win.show());win.on('close',event=>{if(!closing){event.preventDefault();win.hide();}});
  cachedFont=fontData();ensurePrintWindow();printWin.loadURL('data:text/html,<html><body></body></html>').catch(()=>{});startMonitor();win.loadURL(ORIGIN+'/staff/');setInterval(tick,4000);win.on('closed',()=>{win=null;app.quit()});
 });app.on('before-quit',()=>{closing=true;monitor?.kill();printWin?.destroy()});
}
