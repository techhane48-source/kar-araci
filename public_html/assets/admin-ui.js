(()=>{'use strict';
const body=document.body,button=document.querySelector('.ui-menu-toggle'),sidebar=document.getElementById('ui-sidebar'),close=document.querySelector('.ui-menu-close'),backdrop=document.querySelector('.ui-menu-backdrop'),workspace=document.querySelector('.ui-workspace');
if(!button||!sidebar||!close||!backdrop||!workspace)return;body.classList.add('ui-js');
const media=matchMedia('(max-width:1023px)');let open=false;
function set(next,restore=false){
 open=next&&media.matches;body.classList.toggle('ui-menu-open',open);button.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;
 if(open){sidebar.setAttribute('role','dialog');sidebar.setAttribute('aria-modal','true');sidebar.setAttribute('aria-label','Ana menü');workspace.inert=true;close.focus();}
 else{workspace.inert=false;sidebar.removeAttribute('role');sidebar.removeAttribute('aria-modal');sidebar.removeAttribute('aria-label');if(restore&&media.matches)button.focus();}
}
button.addEventListener('click',()=>set(true));close.addEventListener('click',()=>set(false,true));backdrop.addEventListener('click',()=>set(false,true));
document.addEventListener('keydown',e=>{if(!open)return;if(e.key==='Escape'){e.preventDefault();set(false,true);}else if(e.key==='Tab'){const a=[...sidebar.querySelectorAll('a[href],button:not([disabled])')].filter(el=>el.getClientRects().length);const first=a[0],last=a[a.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
media.addEventListener('change',()=>set(false));})();