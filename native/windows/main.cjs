'use strict';
const {app,BrowserWindow,ipcMain,protocol,net,safeStorage,session,Tray,Menu}=require('electron');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');

const ORIGIN='https://gangchil.42web.io';let win,tray,config={},busy=false,store,closing=false;
const file=()=>path.join(app.getPath('userData'),'printer.json');
function persist(){fs.writeFileSync(file(),JSON.stringify(config),{mode:0o600});}
function readToken(){try{return config.token?safeStorage.decryptString(Buffer.from(config.token,'base64')):''}catch{return ''}}
async function call(route,data,token){const response=await net.fetch(ORIGIN+'/staff/api/'+route,{method:data?'POST':'GET',credentials:'include',headers:{'Content-Type':'application/json',...(token?{'X-Print-Token':token}:{})},body:data?JSON.stringify(data):undefined});let json;try{json=await response.json()}catch{throw Error('Hosting verification or API error. Open the app and sign in again.')}if(!response.ok)throw Error(json.error||'Server request failed');return json;}
async function printers(){return (await win.webContents.getPrintersAsync()).filter(p=>!/(Microsoft Print to PDF|OneNote|XPS|Fax)/i.test(p.name)).map(p=>({name:String(p.name),status:Number(p.status)||0,isDefault:p.isDefault===true}));}
function nativeUIScript(source){return source+'\n;void 0;';}
function permitted(event){return event.sender===win?.webContents&&event.senderFrame===win.webContents.mainFrame&&new URL(event.senderFrame.url).origin===ORIGIN;}
function handle(name,fn){ipcMain.handle(name,async(event,...args)=>{if(!permitted(event))throw Error('Untrusted app frame');return fn(...args)});}
function journal(){try{return JSON.parse(fs.readFileSync(store,'utf8'))}catch{return null}}
function saveJob(job){const tmp=store+'.tmp';fs.writeFileSync(tmp,JSON.stringify(job),{mode:0o600});fs.renameSync(tmp,store);}
async function sendReceipt(job){let printWin;try{printWin=new BrowserWindow({show:false,webPreferences:{nodeIntegration:false,contextIsolation:true,sandbox:true,javascript:true}});printWin.webContents.setWindowOpenHandler(()=>({action:'deny'}));
 const font=fs.readFileSync(path.join(__dirname,'HindSiliguri-Regular.ttf')).toString('base64');
 const html=job.html.replace('</head>',`<style>@font-face{font-family:'Hind Siliguri';src:url(data:font/ttf;base64,${font})}body{font-family:'Hind Siliguri',Arial,sans-serif;margin:0 auto}</style></head>`);
 await printWin.loadURL('data:text/html;charset=utf-8,'+encodeURIComponent(html));
 await printWin.webContents.executeJavaScript('document.fonts.ready.then(() => true)');
 const height=await printWin.webContents.executeJavaScript('Math.ceil(document.body.getBoundingClientRect().height)');
 await new Promise((resolve,reject)=>printWin.webContents.print({silent:true,deviceName:config.printer,printBackground:true,color:false,margins:{marginType:'none'},pageSize:{width:58000,height:Math.max(40000,Math.min(1500000,Math.ceil(height*25400/96)+8000))}},(ok,error)=>ok?resolve():reject(Error(error||'Printer rejected receipt'))));
 }finally{printWin?.destroy();}}
async function tick(){if(busy||closing||!config.printer||!readToken())return;busy=true;try{
 const token=readToken();let job=journal();
 if(job&&job.stage==='submitted'){await call('printing/worker/result',{id:job.id,claim:job.claim,status:'submitted'},token);fs.unlinkSync(store);job=null;}
 else if(job&&['dispatching','unknown'].includes(job.stage)){await call('printing/worker/recover',{id:job.id,claim:job.claim},token);fs.unlinkSync(store);job=null;}
 if(job?.stage==='claimed'){/* Never replay a stale begin after a restart. Claim expires safely. */fs.unlinkSync(store);}
 const available=await printers();if(!available.some(p=>p.name===config.printer)){status('Printer disconnected: '+config.printer);return;}
 const answer=await call('printing/worker/claim',{},token);if(!answer.job){status(answer.blocked?'Receipt needs review in Printer settings':'Ready · '+config.printer);return;}
 job={...answer.job,stage:'claimed'};saveJob(job);
 // Persist uncertainty before the network transition, so a crash cannot authorize replay.
 job.stage='dispatching';saveJob(job);
 try{await call('printing/worker/begin',{id:job.id,claim:job.claim},token);}catch(e){job.stage='unknown';saveJob(job);throw e;}
 try{await sendReceipt(job);job.stage='submitted';saveJob(job);}catch(e){job.stage='unknown';saveJob(job);await call('printing/worker/result',{id:job.id,claim:job.claim,status:'unknown',detail:e.message},token);fs.unlinkSync(store);throw e;}
 await call('printing/worker/result',{id:job.id,claim:job.claim,status:'submitted'},token);fs.unlinkSync(store);status('Receipt sent · #'+String(job.order_id).padStart(6,'0'));
 }catch(e){status(e.message)}finally{busy=false;}}
let lastStatus='Select a printer';function status(value){lastStatus=value;win?.webContents.send('printer-status',value);}
if(!app.requestSingleInstanceLock())app.quit();else{
 app.on('second-instance',()=>{win?.show();win?.focus()});
 app.whenReady().then(()=>{
  try{config=JSON.parse(fs.readFileSync(file(),'utf8'))}catch{}if(!config.deviceKey){config.deviceKey=crypto.randomUUID();persist();}store=path.join(app.getPath('userData'),'active-receipt.json');


  session.defaultSession.setPermissionRequestHandler((_wc,permission,callback)=>callback(permission==='notifications'));
  win=new BrowserWindow({width:1280,height:850,minWidth:390,minHeight:600,title:'Gangchil',icon:path.join(__dirname,'app-icon.ico'),autoHideMenuBar:true,webPreferences:{preload:path.join(__dirname,'preload.cjs'),contextIsolation:true,nodeIntegration:false,sandbox:true,backgroundThrottling:false}});
  win.webContents.on('did-finish-load',()=>{if(new URL(win.webContents.getURL()).origin===ORIGIN)win.webContents.executeJavaScript(nativeUIScript(fs.readFileSync(path.join(__dirname,'native-ui.js'),'utf8'))).catch(e=>status(e.message));});
  win.webContents.setWindowOpenHandler(({url})=>{if(url==='about:blank'||new URL(url).origin===ORIGIN)return {action:'allow',overrideBrowserWindowOptions:{webPreferences:{nodeIntegration:false,contextIsolation:true,sandbox:true,preload:undefined}}};return {action:'deny'}});win.webContents.on('will-navigate',(e,url)=>{if(new URL(url).origin!==ORIGIN)e.preventDefault()});
  handle('printer-info',async()=>({printers:await printers(),selected:config.printer||'',deviceKey:config.deviceKey,paired:!!readToken(),status:lastStatus}));
  handle('printer-configure',async value=>{if(busy)throw Error('Wait for the current receipt to finish');if(typeof value?.name!=='string'||!(await printers()).some(p=>p.name===value.name))throw Error('Select an installed printer');if(typeof value.token!=='string'||!/^[a-f0-9]{64}$/.test(value.token))throw Error('Sign in to connect printing');if(!safeStorage.isEncryptionAvailable())throw Error('Windows credential protection unavailable');config.printer=value.name;config.token=safeStorage.encryptString(value.token).toString('base64');persist();status('Ready · '+config.printer);setTimeout(tick,100);return {ok:true};});
  handle('printer-test',async()=>{if(busy)throw Error('A receipt is being processed');if(!config.printer)throw Error('Select a printer');busy=true;try{await sendReceipt({html:'<!doctype html><html><head><meta charset="utf-8"><style>@page{size:58mm auto;margin:0}body{width:48mm;font:16px Arial}</style></head><body><h2>GANGCHIL</h2><p>TEST RECEIPT · বাংলা</p><p>58mm paper / 48mm print area</p><p>No payment recorded.</p></body></html>'});return {ok:true}}finally{busy=false}});
  tray=new Tray(path.join(__dirname,'app-icon.png'));tray.setToolTip('Gangchil receipt printing');tray.setContextMenu(Menu.buildFromTemplate([{label:'Open Gangchil',click:()=>win.show()},{label:'Exit Gangchil',click:()=>app.quit()}]));tray.on('double-click',()=>win.show());win.on('close',event=>{if(!closing){event.preventDefault();win.hide();}});
  win.loadURL(ORIGIN+'/staff/');setInterval(tick,4000);win.on('closed',()=>{win=null;app.quit()});
 });app.on('before-quit',()=>{closing=true});
}
