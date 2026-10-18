export const words = text => (text.toLowerCase().normalize('NFKD').replace(/[’']/g,'').match(/[a-z0-9]+/g) || []);
// Match a short recognized phrase near the reader's last position. A missed or
// misheard word should not send the reader to an unrelated repeated passage.
export function locateSpeech(script, speech, previous = 0) {
  const spoken=words(speech).slice(-14), target=words(script);
  if(spoken.length<2 || !target.length)return previous;
  let best=previous, bestScore=0;
  for(let end=Math.max(1,previous-35);end<Math.min(target.length,previous+160);end++) {
    let cursor=end, matched=0;
    for(let i=spoken.length-1;i>=0;i--){let found=-1;for(let gap=0;gap<3;gap++)if(cursor-gap>=0 && target[cursor-gap]===spoken[i]){found=cursor-gap;break;}if(found>=0){matched++;cursor=found-1;}else cursor--;}
    const ratio=matched/spoken.length, score=ratio-Math.abs(end-previous)*0.001;
    if(matched>=Math.min(3,spoken.length) && ratio>=0.55 && score>bestScore){best=end;bestScore=score;}
  }
  return best;
}
