// The archive remains immediately playable while new Studio episodes load from RSS.
async function loadStudioEpisodes(container) {
  if (!container) return;
  try {
    const response = await fetch('/podcast/rss.xml', {credentials: 'omit', cache: 'no-cache'});
    if (!response.ok) return;
    const feed = new DOMParser().parseFromString(await response.text(), 'application/xml');
    const namespace = 'https://theeverbranch.com/ns/studio/1';
    const entries = document.createDocumentFragment();
    function textNode(tag, text) {
      const node = document.createElement(tag);
      node.textContent = text;
      return node;
    }
    function mediaUrl(value) {
      if (!value) return null;
      const url = new URL(value, location.origin);
      return url.origin === location.origin && /^\/podcast\/media\/[a-f0-9]{64}\.(mp3|mp4|vtt)$/.test(url.pathname) ? url.pathname : null;
    }
    for (const item of feed.querySelectorAll('channel > item')) {
      const guid = item.querySelector('guid')?.textContent || '';
      if (!/^urn:uuid:[a-f0-9-]{36}$/.test(guid) || !item.getElementsByTagNameNS(namespace, 'revision').length) continue;
      const audio = mediaUrl(item.querySelector('enclosure')?.getAttribute('url'));
      if (!audio) continue;
      const assets = [...item.getElementsByTagNameNS(namespace, 'asset')];
      const resource = kind => mediaUrl(assets.find(a => a.getAttribute('kind') === kind)?.getAttribute('url'));
      const article = document.createElement('article');
      article.className = 'panel podcast-episode';
      article.id = 'episode-' + guid.slice(9);
      const title = item.querySelector('title')?.textContent || 'Episode';
      article.append(textNode('h3', title), textNode('p', item.querySelector('description')?.textContent || ''));
      const video = resource('episode_video');
      const player = document.createElement(video ? 'video' : 'audio');
      player.preload = 'none';
      player.controls = true;
      player.src = video || audio;
      player.setAttribute('aria-label', title);
      if (video) {
        player.playsInline = true;
        player.width = 320;
        player.setAttribute('style','max-width:100%;height:auto');
        const caption = mediaUrl(assets.find(a => a.getAttribute('kind') === 'captions' && (!a.hasAttribute('for') || mediaUrl(a.getAttribute('for')) === video))?.getAttribute('url'));
        if (caption) {
          const track = document.createElement('track');
          Object.assign(track, {kind: 'captions', src: caption, srclang: 'en', label: 'English'});
          player.append(track);
        }
      }
      const link = textNode('a', 'Listen to the audio');
      link.className = 'text-link';
      link.href = audio;
      article.append(player, document.createElement('br'), link);
      entries.append(article);
    }
    container.prepend(entries);
    const id = location.hash.slice(1);
    if (id.startsWith('episode-')) document.getElementById(id)?.scrollIntoView();
  } catch {
    // The existing archive is available even when the feed request fails.
  }
}
