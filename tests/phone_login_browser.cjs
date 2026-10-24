// Actual PHP-rendered sign-in, shared passkey client and recovery lifecycle.
// Credentials and API replies are synthetic; no production accounts are used.
const {chromium,webkit}=require('playwright');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const render=(mode='login',paper=false,embedded=false)=>execFileSync('php',['tests/render_login_fixture.php',mode,...(paper?['paper']:[]),...(embedded?['embedded']:[])],{cwd:root,encoding:'utf8'});
const html={login:render(),error:render('error'),token:render('token'),paper:render('login',true),embedded:render('login',false,true)};
(async()=>{
 const browser=await (process.env.ACCOUNTS_TEST_BROWSER==='webkit'?webkit:chromium).launch({headless:true});
 const contexts=[];let signedIn=true,username='Preview',verifyCount=0,lastForm='';
 try{
  async function context(options={}){
   const c=await browser.newContext({serviceWorkers:'block',viewport:{width:390,height:844},screen:{width:390,height:844},isMobile:true,hasTouch:true,...options});contexts.push(c);
   await c.addInitScript(()=>{
    class Credential {static async isConditionalMediationAvailable(){return true;}}
    Object.defineProperty(window,'PublicKeyCredential',{value:Credential,configurable:true});
    Object.defineProperty(navigator,'credentials',{value:{get:async options=>{
     if(options.mediation==='conditional'){
      window.__conditionalStarted=true;
      return new Promise((resolve,reject)=>options.signal?.addEventListener('abort',()=>reject(new DOMException('Cancelled','AbortError'))));
     }
     if(window.__cancelPasskey)throw new DOMException('Cancelled','NotAllowedError');
     const b=new Uint8Array([1,2]).buffer;
     return {id:'synthetic-passkey',rawId:b,type:'public-key',response:{clientDataJSON:b,authenticatorData:b,signature:b,userHandle:null},getClientExtensionResults:()=>({})};
    }},configurable:true});
   });
   await c.route('https://accounts.test/**',route=>{
    const req=route.request(),url=new URL(req.url()),p=url.pathname;
    if(p==='/index.php'){
     const mode=url.searchParams.get('fixture')||'login';
     if(req.method()==='POST'){lastForm=req.postData();return route.fulfill({contentType:'text/html',body:mode==='token'?html.token:html.error});}
     return route.fulfill({contentType:'text/html',body:url.searchParams.get('embedded')==='1'?html.embedded:html[mode]||html.login});
    }
    if(p.endsWith('/passkey_login_options.php'))return route.fulfill({json:{publicKey:{challenge:'AQI',rpId:'accounts.test',userVerification:'required'}}});
    if(p.endsWith('/passkey_login_verify.php')){verifyCount++;signedIn=true;assert.equal(req.postDataJSON().id,'synthetic-passkey');return route.fulfill({json:{redirect:new URL(req.headers().referer||'https://accounts.test/').searchParams.get('embedded')==='1'?'frontend/reauth_complete.html':'frontend/phone.html?view=overview'}});}
    if(p.endsWith('/current_user.php'))return route.fulfill({status:signedIn?200:401,json:signedIn?{username,statement_import_revision:'initial'}:{error:'Authentication required'}});
    if(p==='/frontend/phone.html')return route.fulfill({contentType:'text/html',body:'<!doctype html><title>Signed in</title><p>Shared passkey redirect reached.</p>'});
    if(p==='/recovery.html')return route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="frontend/css/phone.css"><link rel="stylesheet" href="frontend/css/phone-auth.css"><body data-phone-app><main class="phone-main"><form data-preserve-edits><input id="draft" value="original"></form></main><script src="frontend/js/web_app.js"></script>'});
    const file=path.resolve(root,'.'+decodeURIComponent(p));
    if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile())return route.fulfill({status:404,body:''});
    const types={'.js':'application/javascript','.css':'text/css','.html':'text/html','.png':'image/png','.woff2':'font/woff2','.webmanifest':'application/manifest+json'};
    return route.fulfill({contentType:types[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
   });return c;
  }
  const c=await context(),page=await c.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('https://accounts.test/index.php');
  await page.waitForFunction(()=>window.__conditionalStarted);
  assert.equal(await page.locator('html').evaluate(e=>e.classList.contains('phone-login')),true);
  assert.equal(await page.locator('.login-story').isVisible(),false);
  assert.equal(await page.locator('#login-username').getAttribute('autocomplete'),'username webauthn');
  assert.equal(await page.locator('#login-password').getAttribute('autocomplete'),'current-password');
  assert.equal(await page.evaluate(()=>document.activeElement.id),'','Phone does not force keyboard focus');
  assert.equal(await page.locator('#login-username').evaluate(e=>getComputedStyle(e).fontSize),'16px');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await page.screenshot({path:'/private/tmp/phone-login-light.png'});
  await page.evaluate(()=>window.__cancelPasskey=true);await page.locator('#passkey-login-button').click();
  await page.waitForFunction(()=>document.getElementById('passkey-login-status').textContent.includes('cancelled'));
  await page.locator('#login-username').fill('Preview');await page.locator('#login-password').fill('test-only-wrong-password');await page.locator('#login-form button').click();
  await page.locator('.login-error').waitFor();assert.match(lastForm,/username=Preview/);assert.match(lastForm,/password=/);
  await page.screenshot({path:'/private/tmp/phone-login-error.png'});
  await page.goto('https://accounts.test/index.php?fixture=token');
  assert.equal(await page.locator('#login-token').getAttribute('autocomplete'),'one-time-code');
  assert.equal(await page.locator('#login-token').getAttribute('pattern'),'[0-9]{6}');
  assert.equal(await page.locator('#login-token').getAttribute('autofocus'),null);
  assert.notEqual(await page.evaluate(()=>document.activeElement.id),'login-token');
  await page.screenshot({path:'/private/tmp/phone-login-code.png'});
  await page.goto('https://accounts.test/index.php');await page.locator('#passkey-login-button').click();
  await page.waitForURL('**/frontend/phone.html?view=overview');assert.equal(verifyCount,1,'Shared passkey options/verify flow keeps its redirect');
  const dark=await context({colorScheme:'dark'});await dark.addInitScript(()=>localStorage.setItem('professionalThemeEnabled','true'));
  const darkPage=await dark.newPage();await darkPage.goto('https://accounts.test/index.php?fixture=paper');
  assert.equal(await darkPage.locator('body').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(16, 25, 29)','Phone dark scheme is independent of desktop Paper');
  assert.equal(await darkPage.locator('.login-stage').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(16, 25, 29)');
  assert.equal(await darkPage.locator('.login-panel').evaluate(e=>getComputedStyle(e).backgroundColor),'rgba(0, 0, 0, 0)');
  await darkPage.screenshot({path:'/private/tmp/phone-login-dark.png'});
  const desktop=await context({viewport:{width:1440,height:1000},screen:{width:1440,height:1000},isMobile:false,hasTouch:false});
  const desktopPage=await desktop.newPage();await desktopPage.goto('https://accounts.test/index.php?fixture=paper');
  assert.equal(await desktopPage.locator('html').evaluate(e=>e.classList.contains('phone-login')),false);
  assert.equal(await desktopPage.locator('.login-story').isVisible(),true);
  assert.equal(await desktopPage.evaluate(()=>document.activeElement.id),'login-username');
  await desktopPage.screenshot({path:'/private/tmp/desktop-login-paper.png'});
  await page.goto('https://accounts.test/index.php');await page.setViewportSize({width:844,height:390});
  await page.waitForFunction(()=>document.documentElement.classList.contains('phone-login'),{},{timeout:3000}).catch(async error=>{console.log(await page.evaluate(()=>({touch:navigator.maxTouchPoints,screen:[screen.width,screen.height],inner:[innerWidth,innerHeight],coarse:matchMedia('(pointer:coarse)').matches,desktop:sessionStorage.getItem('accounts-desktop-interface')})));throw error;});
  assert.equal(await page.locator('html').evaluate(e=>e.classList.contains('phone-login')),true,'Landscape touch phone retains the phone sign-in');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await page.screenshot({path:'/private/tmp/phone-login-landscape.png'});
  await page.setViewportSize({width:390,height:844});
  await page.evaluate(()=>sessionStorage.setItem('accounts-desktop-interface','1'));
  await page.goto('https://accounts.test/index.php');assert.equal(await page.locator('.login-story').isVisible(),true,'Full website preference is honoured');
  await page.evaluate(()=>sessionStorage.removeItem('accounts-desktop-interface'));
  const storage=await context();await storage.addInitScript(()=>{for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{get(){throw new Error('Disabled');}});});
  const sp=await storage.newPage();await sp.goto('https://accounts.test/index.php');assert.equal(await sp.locator('html').evaluate(e=>e.classList.contains('phone-login')),true,'Unavailable storage does not prevent phone sign-in');
  signedIn=true;username='Preview';await page.goto('https://accounts.test/recovery.html');
  await page.evaluate(()=>fetch('php_backend/public/current_user.php'));await page.locator('#draft').fill('unfinished');
  signedIn=false;await page.evaluate(()=>fetch('php_backend/public/current_user.php'));
  await page.waitForFunction(()=>document.querySelector('.web-app-session').open);
  const bounds=await page.locator('.web-app-session').boundingBox();assert.equal(Math.round(bounds.width),390);assert.equal(Math.round(bounds.height),844);
  await page.getByRole('button',{name:'Sign in here',exact:true}).click();
  const frame=page.frameLocator('.web-app-session iframe');
  await frame.locator('.login-panel').waitFor();assert.equal(await frame.locator('.login-story').isVisible(),false);
  assert.equal(await frame.locator('html').evaluate(e=>e.classList.contains('phone-login')),true,'Recovery iframe follows its phone parent');
  assert.equal(await page.getByRole('button',{name:'Sign in here',exact:true}).isVisible(),true,'The existing frame restart action remains available');
  await page.screenshot({path:'/private/tmp/phone-login-recovery.png'});
  username='Other';await frame.locator('#passkey-login-button').click();
  await page.waitForFunction(()=>document.querySelector('[data-session-status]').textContent.includes('different user'));
  assert.equal(await page.locator('[data-session-status]').isVisible(),true,'Original-user guard remains visible outside the embedded screen');
  username='Preview';await page.getByRole('button',{name:'Continue after signing in',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('.web-app-session').open);
  assert.equal(await page.locator('#draft').inputValue(),'unfinished','Recovery preserves the original draft');
  assert.deepEqual(errors,[]);
  console.log('PASS: Real login template, phone/desktop/light/dark/landscape, native credentials/code annotations, conditional/manual passkeys, error fallback, blocked storage and original-user full-screen recovery');
 }finally{for(const c of contexts)await c.close();await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
