// Navigation-only context. No ledger records or editable values are persisted.
(() => {
    'use strict';
    const allowed = /^(?:search|monthly_statement|account|report|group_analysis|recurring_spend|pivot|instant|upload)\.html$/;
    function safeReturn(value) {
        try {
            const url = new URL(value, location.href);
            if (url.origin === location.origin && url.pathname.startsWith(new URL('.', location.href).pathname) && allowed.test(url.pathname.split('/').pop())) return url.href;
        } catch (_) {}
        return new URL('search.html', location.href).href;
    }
    function returnTarget() {
        return safeReturn(new URLSearchParams(location.search).get('return') || document.referrer || 'search.html');
    }
    function back(event) {
        event?.preventDefault();
        if(window.AppWeb?.hasEdits()){
            if(!window.confirm('Leave this page and discard your unfinished edits?'))return;
            document.querySelectorAll('form[data-preserve-edits]').forEach(form=>window.AppWeb.markSaved(form));
        }
        const target=returnTarget();
        if (history.length>1 && (document.referrer===target || (history.state?.transactionReturn?.href===target && history.state.transactionReturn.viaHistory))) history.back();
        else location.assign(target);
    }
    function detailUrl(id, extra={}) {
        const url=new URL('transaction.html',location.href);
        url.search=new URLSearchParams({id:String(id),return:location.href,...extra}).toString();
        return url.href;
    }
    if(new URL(location.href).pathname.endsWith('/transaction.html')){
        const href=returnTarget();
        if(history.state?.transactionReturn?.href!==href)history.replaceState({...history.state,transactionReturn:{href,viaHistory:document.referrer===href}},'',location.href);
    }
    window.TransactionNavigation={safeReturn,returnTarget,back,detailUrl};
})();
