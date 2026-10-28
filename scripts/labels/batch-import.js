// Import one original per request so fifty large files never share one PHP body.
export async function importLabelFiles(files, send, onProgress, completed = new Set()) {
  if(files.length>50)throw new Error('Choose up to 50 files at a time.');
  const results=[];
  for(const file of files){
    const key=`${file.name}:${file.size}:${file.lastModified}`;
    if(completed.has(key))continue;
    try{
      const response=await send(file);
      const result=response.results?.[0];
      if(!result)throw new Error('This file could not be saved.');
      results.push(result);if(result.ok)completed.add(key);
    }catch(error){results.push({file:file.name,ok:false,error:error.message});}
    onProgress(results);
  }
  return results;
}
