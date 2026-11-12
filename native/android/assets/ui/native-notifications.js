(()=>{
 if(typeof GangchilAndroid==='undefined')return;
 let dialog,busy=false,registered='',message='Enable order notifications on this device.';
 async function request(path,data){const r=await fetch('/staff/api/native-push/'+path,{method:data?'POST':'GET',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:data?JSON.stringify(data):undefined});const j=await r.json();if(!r.ok)throw Error(j.error||'Notification request failed');return j;}
 function draw(){if(dialog?.open)dialog.querySelector('[role=status]').textContent=message;}
 async function sync(enable=false){if(busy||!state.user)return;busy=true;try{
  if(enable){const settings=await request('config');if(!settings.ready)throw Error('Order push is not configured on the server yet.');GangchilAndroid.pushConfigure(JSON.stringify(settings.config));}
  const info=JSON.parse(GangchilAndroid.pushInfo());
  if(!info.enabled)return;
  if(!info.allowed){message='Allow notifications in Android settings to receive order alerts.';return;}
  if(info.token&&registered!==state.user.id+':'+info.token){await request('register',{device_key:info.deviceKey,token:info.token});GangchilAndroid.pushUser(String(state.user.id));registered=state.user.id+':'+info.token;message='Order notifications enabled on this device.';}
  else if(!info.token)message=enable?'Connecting notifications…':message;
 }catch(e){message=e.message;}finally{busy=false;draw();}}
 async function disable(){try{const info=JSON.parse(GangchilAndroid.pushInfo());await request('unregister',{device_key:info.deviceKey});GangchilAndroid.pushClear();registered='';message='Order notifications disabled.';draw();}catch(e){message=e.message;draw();throw e;}}
 function openSettings(){if(!dialog){dialog=document.createElement('dialog');dialog.className='pwa-dialog';dialog.innerHTML='<header><h2>Order notifications</h2><button type="button" class="icon-button" aria-label="Close">×</button></header><p role="status"></p><div class="pwa-actions"><button type="button" class="btn" data-enable>Enable notifications</button><button type="button" class="small" data-disable>Disable</button></div>';dialog.querySelector('header button').onclick=()=>dialog.close();dialog.querySelector('[data-enable]').onclick=()=>sync(true);dialog.querySelector('[data-disable]').onclick=()=>disable().catch(()=>{});document.body.append(dialog);}dialog.showModal();draw();}
 window.PWA={...window.PWA,openSettings,beforeLogout:disable,onSignedOut:()=>{GangchilAndroid.pushClear();registered='';},isOpen:()=>!!dialog?.open,close:()=>dialog?.close()};
 setInterval(()=>sync(),10000);
})();
