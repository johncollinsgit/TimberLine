/** Use in the separate connected Website renderer (including Carolina Barrel).
 * Bundle /product-visualization with that renderer; keep camera frames local.
 * The descriptor comes from the current authorized Website catalog response.
 */
export async function mountProductVisualization(container, descriptor){
  container.replaceChildren();
  if(!descriptor)return; // Gate off, unpublished, or not configured.
  if(!document.querySelector('link[data-product-visualization]')){
    const style=document.createElement('link');style.rel='stylesheet';style.href='/product-visualization/viewer.css';style.dataset.productVisualization='';document.head.append(style);
  }
  await import('/product-visualization/viewer.js');
  if(!container.isConnected)return;
  const viewer=document.createElement('everbranch-product-viewer');
  viewer.setAttribute('data-config',JSON.stringify(descriptor));container.replaceChildren(viewer);
  return()=>viewer.remove(); // Route change/unmount stops the camera.
}
