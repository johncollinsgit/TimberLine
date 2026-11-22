"use strict";
const el = id => document.getElementById(id);
const statusText = message => { el("status").textContent = message; };
const asPromise = request => new Promise((resolve, reject) => {
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(request.error);
});
let db, stream, recorder, session, peer;
let pending = Promise.resolve();
const activeSessions = new Set();
let hasStorageError = false;
const transactions = (stores, mode, work) => new Promise((resolve, reject) => {
  const tx = db.transaction(stores, mode);
  tx.oncomplete = resolve;
  tx.onerror = () => reject(tx.error);
  tx.onabort = () => reject(tx.error || new Error("Storage transaction aborted."));
  work(tx);
});
function safe(action) {
  return async () => { try { await action(); } catch (error) { statusText(error.message || String(error)); } };
}
function controls() {
  const enabled = Boolean(stream);
  const busy = Boolean(recorder);
  el("allow").disabled = enabled || !db;
  el("start").disabled = !enabled || busy;
  el("stop").disabled = !busy || recorder.state === "inactive";
  el("mode").disabled = enabled;
  el("release").disabled = !enabled || busy;
  el("invite").disabled = !enabled || Boolean(peer);
  el("answer").disabled = !enabled || Boolean(peer);
  el("connect").disabled = !peer || peer.signalingState !== "have-local-offer";
  el("hang").disabled = !peer;
}
async function archive() {
  const recordings = await asPromise(db.transaction("recordings").objectStore("recordings").getAll());
  const list = el("archive");
  list.replaceChildren();
  if (!recordings.length) {
    const empty = document.createElement("li");
    empty.textContent = "No recordings saved on this browser.";
    list.append(empty);
  }
  for (const item of recordings.sort((a,b) => b.created - a.created)) {
    const li = document.createElement("li");
    const label = document.createElement("span");
    const state = item.complete ? "finished" : "partial";
    label.textContent = (item.name || "Guest") + " · " + new Date(item.created).toLocaleString() +
      " · " + (item.bytes / 1048576).toFixed(1) + " MB · " + state;
    li.append(label);
    const download = document.createElement("button");
    download.textContent = "Prepare download";
    download.disabled = item.bytes === 0 || activeSessions.has(item.id);
    download.addEventListener("click", safe(async () => {
      const tx = db.transaction("chunks");
      const range = IDBKeyRange.bound([item.id, 0], [item.id, Number.MAX_SAFE_INTEGER]);
      const parts = await asPromise(tx.objectStore("chunks").getAll(range));
      const blob = new Blob(parts.map(part => part.data), {type: item.mime});
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      const extension = item.mime.includes("mp4") ? (item.mime.startsWith("audio") ? "m4a" : "mp4") : "webm";
      link.download = (item.name || "guest").replace(/[^a-zA-Z0-9_-]/g, "_") + "-" + item.id.slice(0,8) + "." + extension;
      link.textContent = "Save recording";
      download.replaceWith(link);
      statusText("Your file is ready. Choose Save recording to download it.");
    }));
    const remove = document.createElement("button");
    remove.textContent = "Remove";
    // Give active recorders in other tabs time to save their next chunk.
    remove.disabled = activeSessions.has(item.id) || (!item.complete && Date.now() - (item.lastSave || item.created) < 60000);
    remove.addEventListener("click", safe(async () => {
      if (!confirm("Delete this recording from this browser? Download a copy first.")) return;
      await transactions(["recordings","chunks"], "readwrite", tx => {
        tx.objectStore("recordings").delete(item.id);
        tx.objectStore("chunks").delete(IDBKeyRange.bound([item.id,0],[item.id,Number.MAX_SAFE_INTEGER]));
      });
      await archive();
    }));
    li.append(download, remove);
    list.append(li);
  }
}
async function enable() {
  const audioOnly = el("mode").value === "audio";
  stream = await navigator.mediaDevices.getUserMedia({
    audio: {echoCancellation: true},
    video: audioOnly ? false : {width: {ideal: 1920}, height: {ideal: 1080}, frameRate: {ideal: 30}}
  });
  el("preview").srcObject = stream;
  controls();
  statusText("Devices ready. Start recording when everyone is ready.");
}
async function start() {
  if (!stream || recorder) return;
  const audioOnly = !stream.getVideoTracks().length;
  const formats = audioOnly ? ["audio/webm;codecs=opus","audio/mp4"] :
    ["video/webm;codecs=vp9,opus","video/webm;codecs=vp8,opus","video/mp4"];
  const mimeType = formats.find(format => MediaRecorder.isTypeSupported(format));
  const current = new MediaRecorder(stream, mimeType ? {mimeType} : {});
  const item = {id: crypto.randomUUID(), name: el("name").value.trim(), created: Date.now(),
    mime: current.mimeType || (audioOnly ? "audio/webm" : "video/webm"), bytes: 0, complete: false};
  recorder = current;
  session = item;
  controls();
  try {
    await transactions(["recordings"],"readwrite", tx => tx.objectStore("recordings").add(item));
  } catch (error) {
    recorder = null; session = null; controls(); throw error;
  }
  activeSessions.add(item.id);
  let seq = 0;
  pending = Promise.resolve();
  hasStorageError = false;
  current.ondataavailable = event => {
    if (!event.data.size || hasStorageError) return;
    const number = seq++;
    pending = pending.then(async () => {
      if (hasStorageError) return;
      const next = {...item, lastSave: Date.now(), bytes: item.bytes + event.data.size, mime: event.data.type || item.mime};
      await transactions(["chunks","recordings"],"readwrite", tx => {
        tx.objectStore("chunks").add({id:item.id, seq:number, data:event.data});
        tx.objectStore("recordings").put(next);
      });
      Object.assign(item,next);
    }).catch(error => {
      hasStorageError = true;
      statusText("Recording stopped. Storage could not save the last chunk. Download the saved portion. " + error.message);
      if (current.state !== "inactive") current.stop();
    });
  };
  current.onerror = event => {
    hasStorageError = true;
    statusText("Device recording interrupted. Download the saved portion. " + (event.error?.message || ""));
    if (current.state !== "inactive") current.stop();
  };
  current.onstop = safe(async () => {
    try {
      await pending;
      item.complete = !hasStorageError;
      await transactions(["recordings"],"readwrite",tx => tx.objectStore("recordings").put(item));
      if (!hasStorageError) statusText("Recording saved. Download the file for your host.");
    } finally {
      activeSessions.delete(item.id);
      recorder = null;
      session = null;
      controls();
      await archive();
    }
  });
  try {
    current.start(1000);
  } catch (error) {
    activeSessions.delete(item.id);
    recorder = null; session = null; controls(); throw error;
  }
  controls();
  statusText("Recording locally. Keep this page open.");
  await archive();
}
function hangup() {
  if (peer) peer.close();
  peer = null;
  el("remoteBox").hidden = true;
  el("remote").srcObject = null;
  el("outgoing").value = "";
  controls();
}
function startPeer() {
  const raw = el("ice").value.trim();
  const iceServers = raw ? JSON.parse(raw) : [];
  if (!Array.isArray(iceServers)) throw new Error("ICE servers must be a JSON array.");
  const pc = new RTCPeerConnection({iceServers});
  peer = pc;
  for (const track of stream.getTracks()) pc.addTrack(track, stream);
  pc.ontrack = event => {
    el("remote").srcObject = event.streams[0];
    el("remoteBox").hidden = false;
    el("remote").play().catch(() => statusText("Press Play on the remote preview to hear your call."));
  };
  pc.onconnectionstatechange = () => {
    if (pc.connectionState === "failed") statusText("Call could not connect. Try a host-supplied TURN relay. Local recording continues independently.");
    if (pc.connectionState === "connected") statusText("Call connected. Use Start recording on each device.");
  };
  controls();
  return pc;
}
async function exchange(role) {
  let pc;
  try {
    if (role === "connect") {
      const answer = JSON.parse(el("incoming").value);
      if (answer.type !== "answer") throw new Error("Paste the guest’s answer.");
      await peer.setRemoteDescription(answer);
      controls();
      return;
    }
    const invite = role === "answer" ? JSON.parse(el("incoming").value) : null;
    if (invite && invite.type !== "offer") throw new Error("Paste the host’s invitation.");
    pc = startPeer();
    if (invite) await pc.setRemoteDescription(invite);
    await pc.setLocalDescription(invite ? await pc.createAnswer() : await pc.createOffer());
    if (pc.iceGatheringState !== "complete") {
      await new Promise(resolve => {
        const finish = () => { clearTimeout(timer); pc.removeEventListener("icegatheringstatechange", ready); resolve(); };
        const ready = () => { if (pc.iceGatheringState === "complete") finish(); };
        const timer = setTimeout(finish, 10000);
        pc.addEventListener("icegatheringstatechange", ready);
      });
    }
    if (peer !== pc) return;
    el("outgoing").value = JSON.stringify(pc.localDescription);
    el("outgoing").select();
    statusText("Copy your call details and send them privately to the other person.");
    controls();
  } catch (error) {
    if (pc) hangup();
    throw error;
  }
}
el("allow").onclick = safe(enable);
el("start").onclick = safe(start);
el("stop").onclick = safe(() => { if (recorder?.state === "recording") recorder.stop(); controls(); });
el("release").onclick = safe(() => {
  hangup();
  stream.getTracks().forEach(track => track.stop());
  stream = null; el("preview").srcObject = null;
  controls(); statusText("Devices are off.");
});
el("invite").onclick = safe(() => exchange("invite"));
el("answer").onclick = safe(() => exchange("answer"));
el("connect").onclick = safe(() => exchange("connect"));
el("hang").onclick = safe(hangup);
window.addEventListener("beforeunload", event => {
  if (session) { event.preventDefault(); event.returnValue = ""; }
});
safe(async () => {
  controls();
  if (!window.isSecureContext || !navigator.mediaDevices || !window.MediaRecorder || !window.indexedDB) {
    throw new Error("Open this page over HTTPS in a browser that supports device recording and local storage.");
  }
  const opening = indexedDB.open("everbranch-studio-guest-v1",1);
  opening.onupgradeneeded = () => {
    opening.result.createObjectStore("recordings",{keyPath:"id"});
    opening.result.createObjectStore("chunks",{keyPath:["id","seq"]});
  };
  db = await asPromise(opening);
  controls(); await archive();
})();
