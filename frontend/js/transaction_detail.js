// Renders one transaction as an answer-first detail view with safe, editable classification controls.
(function () {
    'use strict';

    const preciseMoney = new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP', minimumFractionDigits:2, maximumFractionDigits:2 });
    const transactionDate = new Intl.DateTimeFormat('en-GB', { day:'numeric', month:'short', year:'numeric' });
    const params = new URLSearchParams(window.location.search);
    const transactionId = params.get('id');
    let transaction = null;
    let selectedTag = null;
    let tagSearchTimer = null;
    let tagSearchController = null;
    let tagSearchSequence = 0;
    let activeTagOption = -1;

    function byId(id) { return document.getElementById(id); }
    function setText(id, value) { const element=byId(id); if(element) element.textContent=value; }
    function dateValue(value) { return new Date(value + (String(value).length===10?'T12:00:00':'')); }
    function notify(message, type) { if(typeof window.showMessage==='function') window.showMessage(message,type); }
    function showError(message) { const error=byId('transaction-error'); error.textContent=message; error.hidden=false; }
    function clearError() { byId('transaction-error').hidden=true; }

    async function requestJson(url, options) {
        const response = await fetch(url, options);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || 'The request could not be completed.');
        return payload;
    }

    function headerActions() {
        const wrap=document.createElement('div'); wrap.className='transaction-header-actions';
        let backTarget='search.html';
        if(document.referrer){try{const referrer=new URL(document.referrer);if(referrer.origin===location.origin&&referrer.pathname!==location.pathname)backTarget=referrer.href;}catch(error){backTarget='search.html';}}
        const back=document.createElement('a'); back.href=backTarget; back.className='transaction-header-link';
        const backIcon=document.createElement('i'); backIcon.className='fas fa-arrow-left'; backIcon.setAttribute('aria-hidden','true');
        const backLabel=document.createElement('span'); backLabel.textContent='Back'; back.append(backIcon,backLabel);
        const search=document.createElement('a'); search.href='search.html'; search.className='transaction-header-link transaction-header-link--primary';
        const searchIcon=document.createElement('i'); searchIcon.className='fas fa-magnifying-glass'; searchIcon.setAttribute('aria-hidden','true');
        const searchLabel=document.createElement('span'); searchLabel.textContent='Search'; search.append(searchIcon,searchLabel);
        wrap.append(back,search); return wrap;
    }

    function appendDetail(list, label, value, modifier) {
        if (value === null || typeof value === 'undefined' || value === '') return;
        const row=document.createElement('div'); row.className='transaction-detail-row'+(modifier?' transaction-detail-row--'+modifier:'');
        const term=document.createElement('dt'); term.textContent=label;
        const detail=document.createElement('dd'); detail.textContent=value;
        row.append(term,detail); list.appendChild(row);
    }

    function metaChip(iconClass, label, value, modifier) {
        const link=document.createElement('a'); link.href='search.html?value='+encodeURIComponent(value); link.className='transaction-meta-chip'+(modifier?' transaction-meta-chip--'+modifier:''); link.setAttribute('aria-label','Search for '+label+' '+value);
        const icon=document.createElement('i'); icon.className='fas '+iconClass; icon.setAttribute('aria-hidden','true');
        const text=document.createElement('span'); text.textContent=value; link.append(icon,text); return link;
    }

    function renderHero(tx) {
        const value=Number(tx.amount)||0;
        const direction=value<0?'Money out':value>0?'Money in':'No movement';
        const amount=byId('transaction-hero-amount'); amount.classList.remove('transaction-tone--positive','transaction-tone--negative');
        if(value>0) amount.classList.add('transaction-tone--positive'); if(value<0) amount.classList.add('transaction-tone--negative');
        setText('transaction-hero-reference','Reference #'+tx.id);
        setText('transaction-hero-direction',direction);
        setText('transaction-hero-amount',preciseMoney.format(value));
        setText('transaction-hero-description',tx.description || 'Untitled transaction');
        setText('transaction-hero-date',transactionDate.format(dateValue(tx.date)));
        setText('transaction-hero-date-label','statement date');
        setText('transaction-account',tx.account_name || 'Unknown account');
        setText('transaction-account-detail',[tx.sort_code,tx.account_number].filter(Boolean).join(' · ') || 'No bank detail');
        setText('transaction-tag',tx.tag_name || 'Untagged');
        setText('transaction-category',tx.category_name || 'Uncategorised');
        setText('transaction-segment',tx.segment_name || 'No segment');
        setText('transaction-transfer',tx.transfer_id?'Transfer':'Standard');
        setText('transaction-transfer-note',tx.transfer_id?'Linked as transfer #'+tx.transfer_id:'Included in reporting');
    }

    function renderRecord(tx) {
        const value=Number(tx.amount)||0;
        setText('transaction-type-badge',tx.ofx_type || (value<0?'Debit':'Credit'));
        setText('transaction-receipt-direction',value<0?'Payment out':value>0?'Payment in':'Zero-value movement');
        setText('transaction-receipt-amount',preciseMoney.format(value));
        const meta=byId('transaction-meta'); meta.replaceChildren();
        if(tx.tag_name) meta.appendChild(metaChip('fa-tag','tag',tx.tag_name,''));
        if(tx.category_name) meta.appendChild(metaChip('fa-folder-open','category',tx.category_name,'category'));
        if(tx.segment_name) meta.appendChild(metaChip('fa-chart-pie','segment',tx.segment_name,'segment'));
        if(tx.group_name) meta.appendChild(metaChip('fa-layer-group','group',tx.group_name,'group'));
        if(!meta.children.length){const status=document.createElement('span');status.className='transaction-status';status.textContent='No labels assigned yet';meta.appendChild(status);}

        const core=byId('transaction-core-details'); core.replaceChildren();
        appendDetail(core,'Description',tx.description || 'Untitled transaction');
        appendDetail(core,'Memo',tx.memo || 'No memo supplied','memo');
        appendDetail(core,'Account',tx.account_name || 'Unknown account');
        appendDetail(core,'Statement date',transactionDate.format(dateValue(tx.date)));
        appendDetail(core,'Group',tx.group_name || 'No group');
        appendDetail(core,'Transfer',tx.transfer_id?'Yes — transfer #'+tx.transfer_id:'No');

        const technical=byId('transaction-technical-details'); technical.replaceChildren();
        appendDetail(technical,'Transaction reference','#'+tx.id);
        appendDetail(technical,'Sort code',tx.sort_code || 'Not supplied');
        appendDetail(technical,'Account number',tx.account_number || 'Not supplied');
        appendDetail(technical,'OFX type',tx.ofx_type || 'Not supplied');
        appendDetail(technical,'OFX ID',tx.ofx_id || 'Not supplied');
        appendDetail(technical,'Bank OFX ID',tx.bank_ofx_id || 'Not supplied');
    }

    function addOption(select, value, label, selected, disabled) {
        const option=document.createElement('option'); option.value=String(value); option.textContent=label; option.selected=Boolean(selected); option.disabled=Boolean(disabled); select.appendChild(option); return option;
    }

    function setTagPickerOpen(open) {
        const search=byId('tag-search'), results=byId('tag-results');
        results.hidden=!open;
        search.setAttribute('aria-expanded',open?'true':'false');
        if(!open){search.removeAttribute('aria-activedescendant');activeTagOption=-1;}
    }

    function setTagSearchStatus(message, tone) {
        const status=byId('tag-search-status');
        status.textContent=message;
        status.classList.toggle('is-selected',tone==='selected');
        status.classList.toggle('is-error',tone==='error');
    }

    function selectExistingTag(tag) {
        window.clearTimeout(tagSearchTimer);
        if(tagSearchController)tagSearchController.abort();
        tagSearchSequence++;
        selectedTag={id:Number(tag.id),name:String(tag.name)};
        byId('tag-select').value=String(selectedTag.id);
        byId('tag-search').value=selectedTag.name;
        byId('tag').value='';
        byId('tag-results').replaceChildren();
        setTagPickerOpen(false);
        setTagSearchStatus('Selected existing tag: '+selectedTag.name,'selected');
    }

    function clearExistingTagSelection(clearSearch) {
        window.clearTimeout(tagSearchTimer);
        if(tagSearchController)tagSearchController.abort();
        tagSearchSequence++;
        selectedTag=null;
        byId('tag-select').value='';
        if(clearSearch)byId('tag-search').value='';
        byId('tag-results').replaceChildren();
        setTagPickerOpen(false);
        setTagSearchStatus('Start typing, or focus the field to browse tags alphabetically.');
    }

    function updateActiveTagOption(index) {
        const options=Array.from(byId('tag-results').querySelectorAll('[role="option"]'));
        if(!options.length)return;
        activeTagOption=(index+options.length)%options.length;
        options.forEach((option,optionIndex)=>option.setAttribute('aria-selected',optionIndex===activeTagOption?'true':'false'));
        const active=options[activeTagOption];
        byId('tag-search').setAttribute('aria-activedescendant',active.id);
        active.scrollIntoView({block:'nearest'});
    }

    function renderTagResults(data, query) {
        const results=byId('tag-results');
        const matches=Array.isArray(data.tags)?data.tags:[];
        results.replaceChildren(); activeTagOption=-1;
        if(!matches.length){const empty=document.createElement('div');empty.className='transaction-tag-picker__empty';empty.textContent=query?'No existing tags match “'+query+'”.':'No existing tags are available.';results.appendChild(empty);setTagPickerOpen(true);setTagSearchStatus('No matching existing tags found.');return;}
        matches.forEach(tag=>{
            const option=document.createElement('button'),name=document.createElement('span'),identifier=document.createElement('span');
            option.type='button'; option.id='transaction-tag-option-'+tag.id; option.className='transaction-tag-picker__option'; option.setAttribute('role','option'); option.setAttribute('aria-selected','false');
            name.className='transaction-tag-picker__option-name'; name.textContent=tag.name;
            identifier.className='transaction-tag-picker__option-id'; identifier.textContent='#'+tag.id;
            option.append(name,identifier); option.addEventListener('click',()=>selectExistingTag(tag)); results.appendChild(option);
        });
        setTagPickerOpen(true);
        const qualifier=query?' matching':' alphabetical';
        const suffix=data.has_more?' Keep typing to narrow the list.':'';
        setTagSearchStatus(matches.length+qualifier+' tag'+(matches.length===1?'':'s')+'.'+suffix);
    }

    async function searchExistingTags(query) {
        if(tagSearchController)tagSearchController.abort();
        tagSearchController=new AbortController();
        const sequence=++tagSearchSequence;
        const params=new URLSearchParams({options:'1',q:query,limit:'20'});
        setTagSearchStatus('Searching existing tags…');
        try{const data=await requestJson('../php_backend/public/tags.php?'+params.toString(),{signal:tagSearchController.signal});if(sequence!==tagSearchSequence)return;renderTagResults(data,query);}
        catch(error){if(error.name==='AbortError')return;byId('tag-results').replaceChildren();setTagPickerOpen(false);setTagSearchStatus(error.message||'Existing tags could not be loaded.','error');}
    }

    function configureTagPicker(tx) {
        const picker=byId('transaction-tag-picker'),search=byId('tag-search'),tagInput=byId('tag');
        if(tx.tag_id&&tx.tag_name)selectExistingTag({id:tx.tag_id,name:tx.tag_name});
        else if(tx.tag_name)tagInput.value=tx.tag_name;

        search.addEventListener('input',()=>{
            if(tagSearchController)tagSearchController.abort();
            tagSearchSequence++; selectedTag=null; byId('tag-select').value=''; window.clearTimeout(tagSearchTimer);
            const query=search.value.trim();
            tagSearchTimer=window.setTimeout(()=>searchExistingTags(query),140);
        });
        search.addEventListener('focus',()=>{if(!byId('tag-results').children.length)searchExistingTags(selectedTag?selectedTag.name:search.value.trim());else setTagPickerOpen(true);});
        search.addEventListener('keydown',event=>{
            const options=Array.from(byId('tag-results').querySelectorAll('[role="option"]'));
            if(event.key==='ArrowDown'&&options.length){event.preventDefault();updateActiveTagOption(activeTagOption+1);}
            else if(event.key==='ArrowUp'&&options.length){event.preventDefault();updateActiveTagOption(activeTagOption-1);}
            else if(event.key==='Enter'&&activeTagOption>=0&&options[activeTagOption]){event.preventDefault();options[activeTagOption].click();}
            else if(event.key==='Escape')setTagPickerOpen(false);
        });
        document.addEventListener('pointerdown',event=>{if(!picker.contains(event.target))setTagPickerOpen(false);});
        tagInput.addEventListener('input',()=>{if(tagInput.value.trim())clearExistingTagSelection(true);});
    }

    function populateEditor(tx, groups, categories) {
        const categorySelect=byId('category'), groupSelect=byId('group');
        configureTagPicker(tx);
        const segments={};
        categories.forEach(category=>{const segment=category.segment_name||'Unassigned';if(!segments[segment]){const group=document.createElement('optgroup');group.label=segment;segments[segment]=group;categorySelect.appendChild(group);}const option=document.createElement('option');option.value=String(category.id);option.textContent=category.name;option.selected=String(category.id)===String(tx.category_id);segments[segment].appendChild(option);});
        groups.forEach(group=>{if(group.active||String(group.id)===String(tx.group_id))addOption(groupSelect,group.id,group.name,String(group.id)===String(tx.group_id),!group.active&&String(group.id)!==String(tx.group_id));});
        const newGroup=addOption(groupSelect,'__new','Add a new group…',false,false);
        groupSelect.addEventListener('change',async function(){
            if(groupSelect.value!=='__new')return;
            const name=prompt('Enter new group name:'); if(!name){groupSelect.value='';return;}
            groupSelect.disabled=true;
            try{const created=await requestJson('../php_backend/public/groups.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name})});if(!created.id)throw new Error('The group could not be created.');const option=addOption(groupSelect,created.id,name,true,false);groupSelect.insertBefore(option,newGroup);groupSelect.value=String(created.id);notify('Group created');}
            catch(error){showError(error.message||'The group could not be created.');groupSelect.value='';}
            finally{groupSelect.disabled=false;}
        });
    }

    function configureActions(tx) {
        const technical=document.querySelector('.transaction-technical'); let technicalWasOpen=false;
        window.addEventListener('beforeprint',()=>{technicalWasOpen=technical.open;technical.open=true;});
        window.addEventListener('afterprint',()=>{technical.open=technicalWasOpen;});
        byId('print-transaction').addEventListener('click',()=>window.print());
        const transfer=byId('mark-transfer');
        if(tx.transfer_id){transfer.disabled=true;transfer.classList.add('is-complete');transfer.querySelector('i').className='fas fa-check';transfer.querySelector('span').textContent='Already a transfer';return;}
        transfer.addEventListener('click',async function(){
            clearError(); transfer.disabled=true; transfer.querySelector('span').textContent='Marking…'; setText('transaction-status','Updating transfer status…');
            try{const result=await requestJson('../php_backend/public/mark_transaction_transfer.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:tx.id})});if(result.status!=='ok')throw new Error(result.error||'The transaction could not be marked as a transfer.');notify('Transaction marked as transfer');setText('transaction-transfer','Transfer');setText('transaction-transfer-note','Excluded from reporting totals');transfer.classList.add('is-complete');transfer.querySelector('i').className='fas fa-check';transfer.querySelector('span').textContent='Marked as transfer';setText('transaction-status','Transfer status updated');}
            catch(error){showError(error.message||'The transaction could not be marked as a transfer.');transfer.disabled=false;transfer.querySelector('span').textContent='Mark as transfer';setText('transaction-status','Transfer update failed');}
        });
    }

    async function saveTransaction(event) {
        event.preventDefault(); clearError();
        const save=byId('save-transaction'); save.disabled=true; save.querySelector('span').textContent='Saving…'; setText('transaction-status','Saving classification…');
        const payload={transaction_id:transaction.id,account_id:transaction.account_id,description:transaction.description,group_id:byId('group').value,category_id:byId('category').value};
        const tagId=byId('tag-select').value, tagName=byId('tag').value.trim(); if(tagId)payload.tag_id=tagId;else if(tagName)payload.tag_name=tagName;
        try{const result=await requestJson('../php_backend/public/update_transaction.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});if(result.status!=='ok')throw new Error(result.error||'The transaction could not be saved.');notify('Transaction classification saved');setText('transaction-status','Changes saved');save.querySelector('i').className='fas fa-check';save.querySelector('span').textContent='Saved';save.classList.add('is-complete');try{transaction=await requestJson('../php_backend/public/transaction.php?id='+encodeURIComponent(transaction.id));renderHero(transaction);renderRecord(transaction);}catch(refreshError){}setTimeout(()=>{save.classList.remove('is-complete');save.querySelector('span').textContent='Save changes';save.disabled=false;},1200);}
        catch(error){showError(error.message||'The transaction could not be saved.');setText('transaction-status','Save failed');save.disabled=false;save.querySelector('span').textContent='Save changes';}
    }

    async function loadTransaction() {
        window.updatePageHeader(transactionDetailMain,{actions:headerActions()});
        if(!transactionId){byId('transaction-loading').hidden=true;showError('No transaction reference was provided. Open a transaction from Search or Reports and try again.');return;}
        try{
            const results=await Promise.all([
                requestJson('../php_backend/public/transaction.php?id='+encodeURIComponent(transactionId)),
                requestJson('../php_backend/public/groups.php'),
                requestJson('../php_backend/public/categories.php')
            ]);
            transaction=results[0]; renderHero(transaction); renderRecord(transaction); populateEditor(transaction,results[1],results[2]); configureActions(transaction); byId('transaction-form').addEventListener('submit',saveTransaction); byId('transaction-loading').hidden=true; byId('transaction-workspace').hidden=false;
        }catch(error){byId('transaction-loading').hidden=true;showError(error.message||'The transaction could not be loaded.');setText('transaction-hero-description','Transaction detail unavailable. Please return to Search and try again.');}
    }

    loadTransaction();
})();
