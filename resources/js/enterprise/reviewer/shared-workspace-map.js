/* Closed, compiled official-plan reference. No fetch, native authority, title matching or GPS. */
(() => {
  'use strict';
  const contract=Object.freeze({schema_version:1,catalog:'lees-mcrae-official-map-v1',catalog_sha256:'71d604a832a6ebf9e7e4e5203eb62b11aea54322471d394f456ddd99de85e287',points_sha256:'8ffc5109e1b9772a1ed7bb91b2e5dada8c246cc272cc621c620f9f253c5c0826',document_sha256:'d2daae03a679d9c93f8b62dbe289734c09e4c57f127f09ca258f85713eae5839',source_url:'https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf',source_page:2,plan_page:1,coordinate_basis:'Printed map-number label centers on the source page, percentages; not GPS or surveyed positions.'});
  const keys=['schema_version','catalog','catalog_sha256','map_number','source_url','source_page'];
  const refuse=()=>{throw new Error('Official building map reference is unavailable.');};
  function validate(reference){
    if(!reference||typeof reference!=='object'||Array.isArray(reference)||Reflect.ownKeys(reference).length!==keys.length||!keys.every(k=>Object.hasOwn(reference,k)&&Object.getOwnPropertyDescriptor(reference,k)?.get===undefined&&Object.getOwnPropertyDescriptor(reference,k)?.set===undefined))refuse();
    if(!keys.filter(k=>k!=='map_number').every(k=>reference[k]===contract[k])||typeof reference.map_number!=='string'||(!/^([1-9]|[1-5]\d|6[0-8])$/.test(reference.map_number)||String(Number(reference.map_number))!==reference.map_number))refuse();
    return Object.freeze(Object.fromEntries(keys.map(k=>[k,reference[k]])));
  }
  // Omission preserves old graphs. A present null/invalid descriptor always refuses.
  function buildingDto(building){
    if(!building||typeof building!=='object')refuse();
    if(!Object.hasOwn(building,'map_reference'))return Object.freeze({});
    const reference=validate(building.map_reference);
    return Object.freeze({map_number:reference.map_number,source_url:reference.source_url});
  }
  globalThis.EverbranchSharedWorkspaceMap=Object.freeze({contract,validate,buildingDto});
})();
