const fs=require('node:fs'),path=require('node:path');
const types={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json','.svg':'image/svg+xml','.png':'image/png','.ico':'image/x-icon','.ttf':'font/ttf','.woff':'font/woff','.woff2':'font/woff2'};
function assetFor(url,root){const u=new URL(url);let p=decodeURIComponent(u.pathname);if(['/', '/staff','/staff/'].includes(p))p='/index.html';if(p.split(/[\\/]/).includes('..'))return null;const file=path.resolve(root,'.'+p);if(!file.startsWith(path.resolve(root)+path.sep)||!types[path.extname(file)]||!fs.existsSync(file))return null;return{file,mime:types[path.extname(file)]};}
module.exports={assetFor};
