const status=document.getElementById('label-access-status');
if(status){
 try{
  const helper=window.ForestryEmbeddedApp?.getShopifySessionToken;
  if(!helper)throw new Error('Open Custom Labels from MF Wholesale Backstage.');
  const token=await helper();if(!token)throw new Error('Your Shopify session is unavailable. Reopen the app.');
  const response=await fetch(location.href,{headers:{Authorization:`Bearer ${token}`,Accept:'text/html'}});
  if(!response.ok)throw new Error('Artwork access requires an active tenant admin or assigned label manager.');
  const html=await response.text();document.open();document.write(html);document.close();
 }catch(error){status.textContent=error.message;}
}
