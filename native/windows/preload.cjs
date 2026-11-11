const {contextBridge,ipcRenderer}=require('electron');
contextBridge.exposeInMainWorld('GangchilNative',Object.freeze({info:()=>ipcRenderer.invoke('printer-info'),configure:settings=>ipcRenderer.invoke('printer-configure',settings),test:()=>ipcRenderer.invoke('printer-test')}));
