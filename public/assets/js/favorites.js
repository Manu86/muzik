'use strict';

import { api, state } from './core.js?v=77';

let favoriteIds = new Set();
let onFavoritesChanged = () => {};

export function configureFavorites(callback) {
  onFavoritesChanged = callback;
}

export async function getFavIds() {
  try {
    const songs = await api.get('api/favorites');
    favoriteIds = new Set(songs.map(song => String(song.id)));
  } catch {}
  return favoriteIds;
}

export async function toggleFav(id, button) {
  const has = favoriteIds.has(id);
  await api.get(`api/favorites?action=${has ? 'remove' : 'add'}&id=${id}`);
  if (has) favoriteIds.delete(id); else favoriteIds.add(id);
  button.textContent = has ? '♡' : '♥';
  button.classList.toggle('on', !has);
  button.setAttribute('aria-pressed', has ? 'false' : 'true');
  button.setAttribute('aria-label', has ? 'Ajouter aux favoris' : 'Retirer des favoris');
  if (state.view === 'favorites') onFavoritesChanged();
}
