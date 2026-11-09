'use strict';
function fitPrintContents() {
  let small=false;
  document.querySelectorAll('.print-content').forEach(node=>{
    node.style.fontSize='';
    let size=parseFloat(getComputedStyle(node).fontSize);
    while(node.scrollHeight>node.clientHeight+1 && size>2) {
      size-=0.25; node.style.fontSize=size+'px';
    }
    if(size<8) small=true;
  });
  document.querySelector('#printHint').textContent=small?'Uma folha A4 em paisagem. Conteúdo denso: confira a legibilidade antes de imprimir.':'A4 em paisagem · uma folha';
}
window.addEventListener('load',fitPrintContents);
window.addEventListener('beforeprint',fitPrintContents);
document.querySelector('#printPage').addEventListener('click',()=>{ fitPrintContents(); window.print(); });
