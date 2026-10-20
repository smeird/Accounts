// Choose the dedicated phone product before desktop presentation is loaded.
(function () {
    'use strict';
    function destination(href, narrow, touchPhone, desktop) {
        const source=new URL(href);
        if(desktop||(!narrow&&!touchPhone))return null;
        const frontendPage=/\/frontend\/[^/]+\.html$/.test(source.pathname);
        const rootPage=/\/(?:settings|users|totp_setup)\.php$/.test(source.pathname);
        if(!frontendPage&&!rootPage)return null;
        const page=source.pathname.split('/').pop();
        if(['phone.html','offline.html','reauth_complete.html'].includes(page))return null;
        const target=new URL(frontendPage?'phone.html':'frontend/phone.html',source);
        if(page==='search.html'){
            target.search=source.search;target.searchParams.set('view','search');
        }else if(page==='transaction.html'){
            target.searchParams.set('view','search');
            if(source.searchParams.get('id'))target.searchParams.set('transaction',source.searchParams.get('id'));
        }else if(page==='monthly_statement.html'){
            target.searchParams.set('view','month');
            const month=Number(source.searchParams.get('month')),year=Number(source.searchParams.get('year'));
            if(month>=1&&month<=12&&year>=1900&&year<=2200){
                target.searchParams.set('view','search');
                target.searchParams.set('start',`${year}-${String(month).padStart(2,'0')}-01`);
                const end=new Date(Date.UTC(year,month,0)).getUTCDate();
                target.searchParams.set('end',`${year}-${String(month).padStart(2,'0')}-${end}`);
            }
        }else target.searchParams.set('view','overview');
        return target.href;
    }
    if(typeof module!=='undefined'&&module.exports){module.exports={destination};return;}
    let desktop=new URLSearchParams(location.search).get('interface')==='desktop';
    try { desktop=desktop||sessionStorage.getItem('accounts-desktop-interface')==='1'; } catch (_) {}
    const touchPhone=navigator.maxTouchPoints>0&&Math.min(screen.width,screen.height)<=600;
    const target=destination(location.href,matchMedia('(max-width:600px)').matches,touchPhone,desktop);
    if(target){document.documentElement.style.visibility='hidden';location.replace(target);}
})();
