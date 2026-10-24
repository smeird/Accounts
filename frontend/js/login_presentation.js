// Presentation only. Authentication and validated return paths stay shared.
(function () {
    'use strict';
    function usePhone({narrow,touchPhone,desktop,embedded,parentPhone}) {
        if (embedded && parentPhone !== null) return parentPhone;
        return !desktop && (narrow || touchPhone);
    }
    if (typeof module !== 'undefined' && module.exports) {module.exports={usePhone};return;}
    const root=document.documentElement,params=new URLSearchParams(location.search);
    const embedded=params.get('embedded')==='1';
    function apply(){
        let desktop=params.get('interface')==='desktop',parentPhone=null;
        try{desktop=desktop||sessionStorage.getItem('accounts-desktop-interface')==='1';}catch(_){}
        try{if(embedded&&parent!==window)parentPhone=parent.document.body.hasAttribute('data-phone-app');}catch(_){}
        const phone=usePhone({narrow:matchMedia('(max-width:600px)').matches,touchPhone:(navigator.maxTouchPoints>0||matchMedia('(pointer:coarse)').matches)&&Math.min(screen.width,screen.height)<=600,desktop,embedded,parentPhone});
        const wasPhone=root.classList.contains('phone-login');
        root.classList.toggle('phone-login',phone);
        if(wasPhone!==phone)document.dispatchEvent(new Event('login:presentation-change'));
        const heading=document.getElementById('login-heading');
        if(heading){if(phone)heading.setAttribute('aria-level','1');else heading.removeAttribute('aria-level');}
        const dark=phone&&matchMedia('(prefers-color-scheme: dark)').matches;
        const theme=document.querySelector('meta[name="theme-color"]');if(theme)theme.content=phone?(dark?'#10191d':'#f5f7f8'):'#312e81';
    }
    function viewport(){
        const view=window.visualViewport;
        // Do not resize the page around a user's pinch zoom.
        if(view&&view.scale===1)root.style.setProperty('--login-viewport-height',view.height+'px');
    }
    apply();viewport();
    window.addEventListener('resize',apply);
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change',apply);
    window.visualViewport?.addEventListener('resize',viewport);
    document.addEventListener('DOMContentLoaded',()=>{
        apply();
        // Keep the passkey action visible without forcing an iPhone keyboard.
        // Code entry intentionally never receives automatic focus.
        if(!root.classList.contains('phone-login')&&!embedded)document.querySelector('[data-desktop-autofocus]')?.focus();
    });
})();
