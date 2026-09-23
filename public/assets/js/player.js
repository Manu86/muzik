'use strict';

import { $, $$, api, state, esc, fmtDur, a11yRow } from './core.js?v=77';
import { getFavIds, toggleFav } from './favorites.js?v=77';

export const audio = new Audio();
audio.preload = 'auto';
audio.autoplay = true;

const audioBg = new Audio();
audioBg.preload = 'auto';

let wakeLock = null;
let wantPlay = false;
async function acquireWakeLock() {
  try {
    if ('wakeLock' in navigator && document.visibilityState === 'visible' && !wakeLock) {
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release', () => { wakeLock = null; });
    }
  } catch {}
}
function releaseWakeLock() {
  try { if (wakeLock) wakeLock.release(); } catch {}
  wakeLock = null;
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') {
    const entry = state.queue[state.index];
    if (wantPlay && entry && audio.currentSrc && !isLightSource(audio.currentSrc) && !audio.paused) {
      bgBlobSwap(String(entry.id), audio.currentTime || 0);
    }
    upcomingPrefetch();
    return;
  }
  acquireWakeLock();
  if (!audio.paused && audio.src) return;
  if (wantPlay && audio.paused && audio.src) audio.play().catch(() => {});
});

/* ------------------------------------------------------------------ */
/*  Player                                                             */
/* ------------------------------------------------------------------ */

let playingId = null;
let playToken = 0;
let earlyAdvanced = false;
let transitionHold = null;
function clearTransitionHold() {
  if (transitionHold) clearInterval(transitionHold.timer);
  transitionHold = null;
}
Object.defineProperty(state, 'playingId', { get: () => playingId, set: v => { playingId = v; } });

function toEntry(x) {
  if (x && typeof x === 'object') {
    return { id: x.id, title: x.title || '', artist: x.artist || '', duration: x.duration || '', album: x.album || '' };
  }
  const row = document.querySelector(`#content .track-row[data-id="${x}"], #content .art-row[data-id="${x}"]`);
  return {
    id: x,
    title: row ? row.dataset.title : 'Piste',
    artist: row ? (row.dataset.artist || '') : '',
    duration: row ? (row.dataset.duration || '') : '',
    album: row ? (row.dataset.album || '') : '',
  };
}

export function playQueue(ids, start = 0) {
  if (!ids.length) return;
  state.queue = ids.map(toEntry);
  state.index = start;
  play();
}

export function syncPlayBtn() {
  const empty = !state.queue.length;
  const btn = $('#btn-play');
  btn.disabled = empty;
  if (empty) setPlayBtn(false);
}

export async function renderQueue() {
  const list = $('#queue-list');
  const clear = $('#queue-clear');
  clear.style.display = state.queue.length ? '' : 'none';
  list.innerHTML = '';
  if (!state.queue.length) {
    list.innerHTML = '<div class="empty">File d\'attente vide.<br>Joue une piste pour la remplir.</div>';
    syncQueueInset();
    return;
  }
  const favIds = await getFavIds();
  const frag = document.createDocumentFragment();
  state.queue.forEach((e, i) => {
    const row = document.createElement('div');
    row.className = 'q-row' + (i === state.index ? ' cur' : '');
    const isFav = favIds.has(String(e.id));
    row.innerHTML = `
      <span class="tr-thumb"></span>
      <span class="q-info"><span class="t">${esc(e.title || 'Piste')}</span><span class="s">${esc(e.artist || '')}</span></span>
      <span class="q-dur">${fmtDur(e.duration || null)}</span>
      <button type="button" class="fav${isFav ? ' on' : ''}" data-fav aria-pressed="${isFav}" aria-label="${isFav ? 'Retirer des favoris' : 'Ajouter aux favoris'}" title="Ajouter aux favoris">${isFav ? '♥' : '♡'}</button>
      <button class="q-remove" type="button" title="Retirer" aria-label="Retirer de la file d'attente">✕</button>`;
    if (e.album) {
      const img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = '';
      img.src = 'api/art/' + e.album;
      img.onerror = () => img.remove();
      row.querySelector('.tr-thumb').appendChild(img);
    }
    a11yRow(row, (e.title || 'Piste') + (e.artist ? ' — ' + e.artist : ''));
    row.addEventListener('click', () => { state.index = i; play(); });
    row.querySelector('.fav').addEventListener('click', ev => {
      ev.stopPropagation();
      toggleFav(String(e.id), row.querySelector('.fav'));
    });
    row.querySelector('.q-remove').addEventListener('click', ev => {
      ev.stopPropagation();
      removeQueue(i);
    });
    frag.appendChild(row);
  });
  list.appendChild(frag);
  syncPlayBtn();
  syncQueueInset();
}

function removeQueue(i) {
  const wasCurrent = i === state.index;
  state.queue.splice(i, 1);
  if (wasCurrent) {
    if (!state.queue.length) {
      state.index = -1;
      wantPlay = false;
      state.playingId = null;
      playToken++;
      cancelHandoff();
      audio.pause();
      audio.src = '';
      clearPrefetch();
      clearPlaybackRetries();
      setPlayBtn(false);
      refreshSongInfo();
      mediaSessionUpdate();
      numberRows();
    } else {
      state.index = Math.min(i, state.queue.length - 1);
      play();
    }
  } else if (i < state.index) {
    state.index--;
    numberRows();
  }
  renderQueue();
}

export function setPlayBtn(playing) {
  $('#ico-play').style.display = playing ? 'none' : '';
  $('#ico-pause').style.display = playing ? '' : 'none';
}

function clearQueue() {
  state.queue = [];
  state.index = -1;
  wantPlay = false;
  state.playingId = null;
  playToken++;
  cancelHandoff();
  audio.pause();
  audio.src = '';
  cancelBgBlobSwap();
  clearPrefetch();
  clearPlaybackRetries();
  setPlayBtn(false);
  refreshSongInfo();
  mediaSessionUpdate();
  numberRows();
  renderQueue();
}

export function play() {
  playToken++;
  wantPlay = true;
  earlyAdvanced = false;
  clearTransitionHold();
  cancelBgBlobSwap();
  cancelHandoff();
  const entry = state.queue[state.index];
  if (!entry) return;
  const id = String(entry.id);
  state.playingId = id;
  setPlayBtn(true);
  const blob = cachedAudioUrl(id);
  const url = blob ? blob.url : audioUrl(id);
  const handoff = document.hidden && blob && !audio.paused && !!audio.currentSrc && audio.readyState >= 2;
  if (audio.currentSrc === url && audio.readyState >= 1) {
    audio.currentTime = 0;
  } else if (handoff) {
    const oldBlob = activeBlob;
    if (blob) activeBlob = blob;
    handoffActive = true;
    handoffNext(url, playToken, oldBlob);
  } else {
    releaseActiveBlob();
    if (blob) activeBlob = blob;
    audio.src = url;
  }
  if (document.hidden && !blob) bgBlobSwap(id, audio.currentTime || 0);
  if (!handoff) startPlayback();
  acquireWakeLock();
  refreshSongInfo();
  mediaSessionUpdate();
  numberRows();
  renderQueue();
  fetch('api/play/' + id, { method: 'POST' }).catch(() => {});
  upcomingPrefetch();
}

/* Handoff gapless vers la piste suivante en arrière-plan : la piste
   suivante démarre dans `audioBg` pendant que `audio` joue encore les
   dernières secondes, puis `audio` reprend la même source sans jamais
   passer par un état paused. Android ne révoque ainsi pas la session
   audio de l'onglet caché. */
function handoffNext(url, token, oldBlob) {
  audioBg.src = url;
  let finished = false;
  let dwellTimer = null;
  const finish = () => {
    if (finished) return;
    finished = true;
    clearTimeout(timeoutHandle);
    clearTimeout(dwellTimer);
    audio.removeEventListener('playing', onPlaying);
    audioBg.pause();
    audioBg.src = '';
  };
  const onPlaying = () => {
    if (token === playToken && !finished) {
      if (dwellTimer) clearTimeout(dwellTimer);
      dwellTimer = setTimeout(() => {
        if (!audio.paused && token === playToken && !finished) {
          finish();
          handoffActive = false;
        }
      }, 1500);
    }
  };
  const releaseOld = () => {
    if (oldBlob && oldBlob.url !== url) URL.revokeObjectURL(oldBlob.url);
    handoffActive = false;
  };
  const bridge = () => {
    audioBg.removeEventListener('canplay', bridge);
    audioBg.removeEventListener('error', fail);
    audioBg.play().then(() => {
      if (token !== playToken) return;
      const pos = audioBg.currentTime || 0;
      audio.addEventListener('playing', onPlaying);
      audio.src = url;
      try { audio.currentTime = pos; } catch {}
      releaseOld();
      startPlayback();
    }).catch(() => {
      if (token !== playToken) return;
      if (!finished) clearTimeout(timeoutHandle);
      if (!finished) clearTimeout(dwellTimer);
      audio.removeEventListener('playing', onPlaying);
      releaseOld();
      audio.src = url;
      startPlayback();
    });
  };
  const fail = () => {
    audioBg.removeEventListener('canplay', bridge);
    audioBg.removeEventListener('error', fail);
    if (token !== playToken) return;
    if (!finished) clearTimeout(timeoutHandle);
    if (!finished) clearTimeout(dwellTimer);
    audio.removeEventListener('playing', onPlaying);
    audioBg.pause();
    audioBg.src = '';
    releaseOld();
    audio.src = url;
    startPlayback();
  };
  const timeoutHandle = setTimeout(() => {
    if (token !== playToken) return;
    if (audio.src !== url && audioBg.readyState < 2) {
      audioBg.removeEventListener('canplay', bridge);
      audioBg.removeEventListener('error', fail);
      audioBg.pause();
      audioBg.src = '';
      releaseOld();
      audio.src = url;
      startPlayback();
    }
  }, 5000);
  audioBg.addEventListener('canplay', bridge);
  audioBg.addEventListener('error', fail);
}

function cancelHandoff() {
  try { audioBg.pause(); } catch {}
  audioBg.src = '';
  audioBg.removeAttribute('src');
  handoffActive = false;
}

let playbackRetryTimer = null;
let handoffActive = false;

export function clearPlaybackRetries() {
  if (playbackRetryTimer !== null) {
    clearInterval(playbackRetryTimer);
    playbackRetryTimer = null;
  }
}

function startPlayback() {
  if (!audio.src) return;
  const token = playToken;
  clearPlaybackRetries();

  const retry = () => {
    if (token !== playToken || !wantPlay || !audio.src || !audio.paused) return;
    audio.play().catch(() => {});
  };

  audio.play().catch(() => {
    if (token !== playToken) return;
    audio.addEventListener('canplay', retry, { once: true });
    audio.addEventListener('loadeddata', retry, { once: true });
    let tries = 0;
    playbackRetryTimer = setInterval(() => {
      tries++;
      if (token !== playToken || !wantPlay || !audio.src || !audio.paused || tries >= 30) {
        clearPlaybackRetries();
        return;
      }
      retry();
    }, 1000);
  });
}

function audioUrl(id) {
  return `api/stream/${id}?transcode=${state.transcode}`;
}

function streamUrl(id, bitrate) {
  return `api/stream/${id}?transcode=${bitrate}`;
}

const BG_TRANSCODE = 192;
function bgBitrate() {
  return state.transcode > 0 ? state.transcode : BG_TRANSCODE;
}
function isLightSource(url) {
  return url.startsWith('blob:') || /transcode=(64|96|128|192|256)/.test(url);
}

/* Bascule en arrière-plan vers un Blob local complet de la piste courante.
   Un Blob (téléchargement court, en mémoire) a une durée finie et ne dépend
   plus du réseau : la lecture verrouillée redevient fluide et les transitions
   (earlyadv) peuvent se déclencher normalement. Contrairement à un flux
   transcodé en direct dont la durée est indéfinie et le buffer limité. */
let bgBlobCtrl = null;

function cancelBgBlobSwap() {
  if (bgBlobCtrl) { bgBlobCtrl.abort(); bgBlobCtrl = null; }
}

function bgBlobSwap(entryId, pos) {
  cancelBgBlobSwap();
  const token = playToken;
  const ctrl = new AbortController();
  bgBlobCtrl = ctrl;
  fetch(streamUrl(String(entryId), bgBitrate()), { signal: ctrl.signal })
    .then(res => {
      if (!res.ok) throw new Error('nok');
      return res.blob();
    })
    .then(blob => {
      if (token !== playToken || !wantPlay || !document.hidden) return;
      const url = URL.createObjectURL(blob);
      const cur = audio.currentTime || pos || 0;
      releaseActiveBlob();
      activeBlob = { url, size: blob.size };
      audio.src = url;
      audio.addEventListener('loadeddata', () => {
        try { audio.currentTime = cur; } catch {}
      }, { once: true });
      startPlayback();
    })
    .catch(() => {})
    .finally(() => { if (bgBlobCtrl === ctrl) bgBlobCtrl = null; });
}

/* ------------------------------------------------------------------ */
/*  Tampon roulant des prochaines pistes en mémoire (Blob).           */
/*  Le chargement séquentiel conserve toujours les pistes les plus    */
/*  proches, même lorsque des fichiers FLAC remplissent vite le quota. */
/* ------------------------------------------------------------------ */
const prefetched = new Map();
const prefetchPending = new Map();
const prefetchQueue = [];
const prefetchDeferred = new Set();
const PREFETCH_PARALLEL = 2;
const PREFETCH_DEPTH = 4;
const PREFETCH_CACHE_MAX = 4;
const PREFETCH_MAX_BYTES = 160 * 1024 * 1024;
let prefetchBytes = 0;
let activeBlob = null;
let lastNearPrefetch = 0;

function fetchPrefetch(key, ctrl, tries) {
  return fetch(streamUrl(key, bgBitrate()), { signal: ctrl.signal })
    .then(r => { if (!r.ok) throw new Error(); return r.blob(); })
    .then(blob => {
      const url = URL.createObjectURL(blob);
      prefetched.set(key, { url, size: blob.size });
      prefetchBytes += blob.size;
      trimPrefetch();
    })
    .catch(err => {
      if (err && err.name === 'AbortError') throw err;
      if ((tries || 0) >= 2) {
        throw err;
      }
      return fetchPrefetch(key, ctrl, (tries || 0) + 1);
    });
}

function drainPrefetch() {
  while (prefetchPending.size < PREFETCH_PARALLEL && prefetchQueue.length) {
    const key = prefetchQueue.shift();
    if (prefetched.has(key) || prefetchPending.has(key)) continue;
    const ctrl = new AbortController();
    prefetchPending.set(key, ctrl);
    fetchPrefetch(key, ctrl, 0)
      .catch(() => {})
      .finally(() => { prefetchPending.delete(key); drainPrefetch(); });
  }
}

function prefetchDistance(key) {
  const len = state.queue.length;
  if (!len) return Infinity;
  const j = state.queue.findIndex(e => String(e.id) === key);
  if (j === -1) return Infinity;
  if (state.shuffle) return Math.abs(j - state.index);
  let d = (j - state.index + len) % len;
  if (d === 0) d = len;
  return d;
}

function trimPrefetch() {
  while ((prefetched.size > PREFETCH_CACHE_MAX || prefetchBytes > PREFETCH_MAX_BYTES) && prefetched.size > 1) {
    let farthestKey = null;
    let farthestDist = -1;
    prefetched.forEach((val, key) => {
      const d = prefetchDistance(key);
      if (d > farthestDist) { farthestDist = d; farthestKey = key; }
    });
    if (!farthestKey) break;
    const old = prefetched.get(farthestKey);
    prefetchBytes -= old.size;
    URL.revokeObjectURL(old.url);
    prefetched.delete(farthestKey);
    prefetchDeferred.add(farthestKey);
  }
}

function prefetchTrack(id) {
  const key = String(id);
  if (prefetched.has(key) || prefetchPending.has(key) || prefetchQueue.includes(key) || prefetchDeferred.has(key)) return;
  prefetchQueue.push(key);
  drainPrefetch();
}

function prefetchNow(id) {
  const key = String(id);
  const qi = prefetchQueue.indexOf(key);
  if (qi > 0) {
    prefetchQueue.splice(qi, 1);
    prefetchQueue.unshift(key);
  }
  prefetchDeferred.delete(key);
  prefetchTrack(key);
}

function clearPrefetch() {
  prefetchPending.forEach(c => { c.abort(); });
  prefetchPending.clear();
  prefetchQueue.length = 0;
  prefetchDeferred.clear();
  prefetched.forEach(v => URL.revokeObjectURL(v.url));
  prefetched.clear();
  prefetchBytes = 0;
  releaseActiveBlob();
}

function upcomingPrefetch() {
  const len = state.queue.length;
  if (len < 2) return;
  const slots = Math.min(PREFETCH_DEPTH, len - 1);
  if (state.shuffle) {
    const picks = new Set();
    while (picks.size < slots) {
      const n = Math.floor(Math.random() * len);
      if (n !== state.index) picks.add(n);
    }
    picks.forEach(n => prefetchTrack(state.queue[n].id));
    return;
  }
  for (let i = 1; i <= slots; i++) {
    let idx = state.index + i;
    if (idx >= len) {
      if (state.repeat) idx %= len;
      else break;
    }
    if (idx < len) prefetchTrack(state.queue[idx].id);
  }
}

setInterval(() => {
  if (!audio.paused && state.queue.length) upcomingPrefetch();
}, 10000);

audio.addEventListener('loadeddata', () => {
  if (state.queue.length > 1) upcomingPrefetch();
});

function cachedAudioUrl(id) {
  const key = String(id);
  const entry = prefetched.get(key);
  if (!entry) return null;
  prefetched.delete(key);
  prefetchBytes -= entry.size;
  prefetchDeferred.clear();
  return entry;
}

function releaseActiveBlob() {
  if (!activeBlob) return;
  URL.revokeObjectURL(activeBlob.url);
  activeBlob = null;
}

export function numberRows() {
  $$('.track-row, .art-row').forEach(row => {
    const playing = state.playingId === row.dataset.id;
    row.classList.toggle('playing', playing);
    row.classList.toggle('paused', playing && audio.paused);
  });
}

export function refreshSongInfo() {
  const img = $('#player-art-img');
  const note = $('#player-art-note');
  const entry = state.queue[state.index];
  if (!entry) {
    $('#player-title').textContent = 'Aucune piste';
    $('#player-artist').textContent = '';
    img.style.display = 'none';
    note.style.display = 'block';
    return;
  }
  $('#player-title').textContent = entry.title || 'Piste';
  $('#player-artist').textContent = entry.artist || '';
  if (entry.album) {
    img.src = 'api/art/' + entry.album;
    img.style.display = 'block';
    note.style.display = 'none';
    img.onerror = () => { img.style.display = 'none'; note.style.display = 'block'; img.onerror = null; };
  } else {
    img.style.display = 'none';
    note.style.display = 'block';
  }
}

export function togglePlayback() {
  if (audio.paused) { if (!audio.src) play(); else { wantPlay = true; audio.play().catch(() => {}); } setPlayBtn(true); }
  else { wantPlay = false; audio.pause(); clearPlaybackRetries(); setPlayBtn(false); }
}

$('#btn-play').addEventListener('click', togglePlayback);

$('#btn-next').addEventListener('click', next);
$('#btn-prev').addEventListener('click', prev);

function shuffleNextIndex() {
  const candidates = [];
  prefetched.forEach((_, key) => {
    const j = state.queue.findIndex(e => String(e.id) === key);
    if (j !== -1 && j !== state.index) candidates.push(j);
  });
  if (candidates.length) return candidates[Math.floor(Math.random() * candidates.length)];
  let n;
  do { n = Math.floor(Math.random() * state.queue.length); } while (state.queue.length > 1 && n === state.index);
  return n;
}

export function next() {
  if (!state.queue.length) return;
  if (state.shuffle) {
    state.index = shuffleNextIndex();
  } else {
    if (state.index >= state.queue.length - 1) {
      if (state.repeat) state.index = 0;
      else return;
    } else state.index++;
  }
  play();
}

export function prev() {
  if (!state.queue.length) return;
  if (audio.currentTime > 3) { audio.currentTime = 0; return; }
  state.index = state.index > 0 ? state.index - 1 : state.queue.length - 1;
  play();
}

let queueOpen = false;
function syncQueueInset() {
  const panel = $('#queue-panel');
  const height = queueOpen ? panel.offsetHeight : 0;
  document.documentElement.style.setProperty('--queue-panel-h', height + 'px');
}

export function toggleQueue() {
  queueOpen = !queueOpen;
  $('#queue-panel').hidden = !queueOpen;
  $('#btn-queue').classList.toggle('on', queueOpen);
  $('#nav-queue').classList.toggle('active', queueOpen);
  $('#btn-queue').setAttribute('aria-pressed', String(queueOpen));
  $('#nav-queue').setAttribute('aria-pressed', String(queueOpen));
  if (queueOpen) renderQueue();
  requestAnimationFrame(syncQueueInset);
}
export function openQueue() {
  if (!queueOpen) {
    queueOpen = true;
    $('#queue-panel').hidden = false;
    $('#btn-queue').classList.add('on');
    $('#nav-queue').classList.add('active');
  }
  $('#btn-queue').setAttribute('aria-pressed', 'true');
  $('#nav-queue').setAttribute('aria-pressed', 'true');
  renderQueue();
  requestAnimationFrame(syncQueueInset);
}

if ('ResizeObserver' in window) {
  new ResizeObserver(syncQueueInset).observe($('#queue-panel'));
}
window.addEventListener('resize', syncQueueInset);

$('#btn-queue').addEventListener('click', e => { e.stopPropagation(); toggleQueue(); });
$('#queue-clear').addEventListener('click', clearQueue);
$('#queue-close').addEventListener('click', e => { e.stopPropagation(); if (queueOpen) toggleQueue(); });
document.addEventListener('click', e => {
  if (queueOpen && !e.target.closest('#queue-panel') && !e.target.closest('#btn-queue') && !e.target.closest('#nav-queue')) toggleQueue();
});
document.addEventListener('click', e => {
  if (!e.target.closest('#search-box')) closeSearch();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && queueOpen) toggleQueue(); });

function nextTrackId() {
  if (state.shuffle) return null;
  let idx = state.index + 1;
  if (idx >= state.queue.length) {
    if (!state.repeat) return null;
    idx = 0;
  }
  return state.queue[idx] ? String(state.queue[idx].id) : null;
}

function endOfTrack() {
  if (state.repeat === 'one') {
    audio.currentTime = 0;
    startPlayback();
    fetch('api/play/' + state.playingId, { method: 'POST' }).catch(() => {});
    return;
  }
  if (state.index >= state.queue.length - 1 && !state.repeat && !state.shuffle) {
    wantPlay = false;
    clearPlaybackRetries();
    setPlayBtn(false);
    releaseWakeLock();
    mediaSessionUpdate();
    return;
  }
  if (transitionHold && transitionHold.token === playToken) return;
  const nextId = nextTrackId();
  if (nextId && document.hidden && !prefetched.has(nextId)) {
    prefetchNow(nextId);
    const holdTailAt = () => {
      try {
        if (audio.duration && isFinite(audio.duration) && audio.duration > 8) {
          audio.currentTime = Math.max(0, audio.duration - 8);
        } else {
          audio.currentTime = 0;
        }
      } catch {}
      audio.play().catch(() => {});
    };
    transitionHold = { token: playToken, id: nextId, holdTailAt };
    let waits = 0;
    transitionHold.timer = setInterval(() => {
      waits++;
      if (transitionHold && transitionHold.token !== playToken) {
        clearTransitionHold();
        return;
      }
      if (!transitionHold || transitionHold.id !== nextId) return;
      if (prefetched.has(nextId)) {
        clearTransitionHold();
        next();
        return;
      }
      if (document.hidden && audio.paused && wantPlay && audio.src) {
        transitionHold.holdTailAt();
      }
      if (waits >= 120) {
        clearTransitionHold();
        next();
      }
    }, 500);
    return;
  }
  next();
}

audio.addEventListener('ended', () => {
  if (handoffActive) return;
  if (!audio.ended && !(audio.duration && audio.currentTime >= audio.duration - 0.25)) return;
  endOfTrack();
});

audio.addEventListener('error', () => {
  const t = playToken;
  if (!state.queue.length || !state.playingId) return;
  const code = audio.error ? audio.error.code : -1;
  if (code === 4) {
    const advance = () => { if (t === playToken) next(); };
    setTimeout(advance, 500);
    return;
  }
  const retry = () => { if (t === playToken) play(); };
  setTimeout(retry, 1000);
});
audio.addEventListener('pause', () => { if (!wantPlay) releaseWakeLock(); numberRows(); mediaSessionUpdate(); });
audio.addEventListener('play', () => { acquireWakeLock(); numberRows(); mediaSessionUpdate(); });

export function mediaSessionUpdate() {
  if (!('mediaSession' in navigator)) return;
  const ms = navigator.mediaSession;
  const entry = state.queue[state.index];
  const title = entry ? entry.title : '';
  const artist = entry ? entry.artist : '';
  const album = entry && entry.album ? entry.album : null;
  ms.metadata = new MediaMetadata({
    title: title || 'Muzik',
    artist: artist || 'Muzik',
    album: album ? 'Muzik' : '',
    artwork: album ? [{ src: 'api/art/' + album, sizes: '512x512', type: 'image/jpeg' }] : [],
  });
  ms.setActionHandler('play', () => { if (audio.paused && audio.src) { wantPlay = true; audio.play().catch(() => {}); } });
  ms.setActionHandler('pause', () => { wantPlay = false; audio.pause(); });
  ms.setActionHandler('previoustrack', prev);
  ms.setActionHandler('nexttrack', next);
  ms.setActionHandler('seekto', e => { if (e.seekTime != null) audio.currentTime = e.seekTime; });
  // Pendant le changement de source, audio.paused vaut momentanement true.
  // Conserver l'etat demande evite qu'Android libere la session audio entre
  // deux morceaux lorsque la page est masquee ou l'ecran verrouille.
  ms.playbackState = wantPlay ? 'playing' : 'paused';
}

audio.addEventListener('timeupdate', () => {
  const pr = $('#player-progress');
  if (audio.duration && !isNaN(audio.duration)) {
    pr.max = Math.floor(audio.duration);
    pr.value = Math.floor(audio.currentTime);
  }
  $('#player-time').textContent = fmtDur(audio.currentTime) + ' / ' + fmtDur(audio.duration);
  if ('mediaSession' in navigator && 'setPositionState' in navigator.mediaSession && audio.duration) {
    try {
      navigator.mediaSession.setPositionState({
        duration: audio.duration,
        playbackRate: audio.playbackRate,
        position: audio.currentTime,
      });
    } catch {}
  }
  if (audio.duration && !isNaN(audio.duration)) {
    if (audio.duration - audio.currentTime < 30 && state.queue.length > 1) {
      const now = Date.now();
      if (now - lastNearPrefetch > 10000) {
        lastNearPrefetch = now;
        upcomingPrefetch();
      }
    }
    if (!earlyAdvanced && document.hidden && !audio.paused && !handoffActive && state.queue.length > 1
        && state.repeat !== 'one'
        && (state.shuffle || state.repeat || state.index < state.queue.length - 1)) {
      const remain = audio.duration - audio.currentTime;
      const lead = 2;
      if (remain > 0 && remain <= lead) {
        earlyAdvanced = true;
        endOfTrack();
      }
    }
  }
});

$('#player-progress').addEventListener('input', e => {
  if (audio.duration) audio.currentTime = parseFloat(e.target.value);
});

$('#btn-shuffle').addEventListener('click', e => {
  state.shuffle = !state.shuffle;
  e.target.classList.toggle('on', state.shuffle);
  e.target.setAttribute('aria-pressed', String(state.shuffle));
});

$('#btn-repeat').addEventListener('click', e => {
  state.repeat = state.repeat === false ? true : (state.repeat === true ? 'one' : false);
  e.target.classList.toggle('on', !!state.repeat);
  e.target.querySelector('#ico-repeat-all').style.display = state.repeat === 'one' ? 'none' : '';
  e.target.querySelector('#ico-repeat-one').style.display = state.repeat === 'one' ? '' : 'none';
  e.target.setAttribute('aria-pressed', String(!!state.repeat));
});

$('#player-transcode').addEventListener('change', e => {
  state.transcode = parseInt(e.target.value, 10);
  clearPrefetch();
  api.get('api/settings?set_key=transcode&value=' + state.transcode).then(() => {
    if (state.playingId) play();
  });
});
