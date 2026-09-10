(function(){'use strict';
const form=document.getElementById('ffla-ps-form');if(!form)return;
const tabs=Array.from(form.querySelectorAll('[data-ps-tab]'));
form.querySelector('.ffla-ps-tabs').setAttribute('role','tablist');
function activate(name,focus){tabs.forEach(t=>{const on=t.dataset.psTab===name;t.setAttribute('role','tab');t.setAttribute('aria-selected',String(on));t.setAttribute('aria-controls','ps-panel-'+t.dataset.psTab);t.tabIndex=on?0:-1;if(on&&focus)t.focus();});
form.querySelectorAll('[data-ps-panel]').forEach(p=>{p.hidden=p.dataset.psPanel!==name;p.setAttribute('role','tabpanel');p.setAttribute('aria-labelledby','ps-tab-'+p.dataset.psPanel);});}
tabs.forEach((t,i)=>{t.addEventListener('click',()=>activate(t.dataset.psTab,false));t.addEventListener('keydown',e=>{let n;if(e.key==='ArrowRight')n=(i+1)%tabs.length;if(e.key==='ArrowLeft')n=(i+tabs.length-1)%tabs.length;if(e.key==='Home')n=0;if(e.key==='End')n=tabs.length-1;if(n!==undefined){e.preventDefault();activate(tabs[n].dataset.psTab,true);}});});activate('general',false);
form.querySelectorAll('[data-method-search]').forEach(input=>input.addEventListener('input',()=>{input.closest('fieldset').querySelectorAll('[data-method-row]').forEach(row=>{row.hidden=!row.textContent.toLowerCase().includes(input.value.toLowerCase());});}));
const preview=document.getElementById('ffla-ps-preview');
// Match the PHP allowlist; never interpret arbitrary CSS declarations.
function color(value,depth=0){
 if(typeof value!=='string'||value.length>256||depth>4)return '';
 value=value.trim();
 if(/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i.test(value))return value;
 if(/^--[a-zA-Z0-9_-]+$/.test(value))return 'var('+value+')';
 const match=value.match(/^var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*(.+))?\)$/);
 if(!match)return '';
 if(match[2]===undefined)return 'var('+match[1]+')';
 const fallback=color(match[2],depth+1);
 return fallback?'var('+match[1]+', '+fallback+')':'';
}
function refresh(){preview.querySelectorAll('[data-preview-text]').forEach(el=>{el.textContent=form.elements['ps['+el.dataset.previewText+']'].value;});Object.entries({accent:'--ffla-delivery-accent',background:'--ffla-delivery-bg',text_color:'--ffla-delivery-text'}).forEach(([key,css])=>{const value=color(form.elements['ps['+key+']'].value);if(value)preview.style.setProperty(css,value);else preview.style.removeProperty(css);});}
form.addEventListener('input',refresh);refresh();
form.querySelectorAll('[data-preview-width]').forEach(button=>button.addEventListener('click',()=>{preview.dataset.width=button.dataset.previewWidth;form.querySelectorAll('[data-preview-width]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));}));
form.addEventListener('invalid',e=>{const panel=e.target.closest('[data-ps-panel]');if(panel)activate(panel.dataset.psPanel,false);},true);
})();
