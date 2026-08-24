(()=>{"use strict";
const init=()=>{
  const oneTime=document.getElementById("oneTime");
  const maxDownloads=document.getElementById("maxDownloads");
  const group=document.getElementById("maxDownloadsGroup");
  if(!oneTime||!maxDownloads)return;
  const platformDefault=Number(document.querySelector('meta[name="default-max-downloads"]')?.content||0);
  const apply=()=>{
    const single=oneTime.checked===true;
    if(single){
      maxDownloads.value="1";
      maxDownloads.disabled=true;
      maxDownloads.setAttribute("disabled","");
      maxDownloads.setAttribute("aria-disabled","true");
      if(group)group.classList.add("is-disabled");
    }else{
      maxDownloads.disabled=false;
      maxDownloads.removeAttribute("disabled");
      maxDownloads.setAttribute("aria-disabled","false");
      maxDownloads.value=String(platformDefault);
      if(group)group.classList.remove("is-disabled");
    }
  };
  oneTime.addEventListener("change",apply);
  oneTime.addEventListener("click",()=>requestAnimationFrame(apply));
  apply();
};
if(document.readyState==="loading"){
  document.addEventListener("DOMContentLoaded",init,{once:true});
}else{
  init();
}
})();
