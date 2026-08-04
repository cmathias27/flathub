(() => {
  const state = {
    videos: [],
    filtered: [],
    ratings: {},
    sort: 'date_desc',
    query: '',
    currentId: null,
    history: [],
    historyIndex: -1,
    shuffle: false,
    discover: false,
  };

  const el = {
    grid: document.getElementById('videoGrid'), empty: document.getElementById('emptyState'), loading: document.getElementById('loadingState'),
    gridView: document.getElementById('gridView'), playerView: document.getElementById('playerView'), search: document.getElementById('searchInput'), sort: document.getElementById('sortSelect'),
    backBtn: document.getElementById('backBtn'), logoLink: document.getElementById('logoLink'), mainVideo: document.getElementById('mainVideo'), playerTitle: document.getElementById('playerTitle'),
    playerDate: document.getElementById('playerDate'), playerSize: document.getElementById('playerSize'), playerDuration: document.getElementById('playerDuration'), playerRating: document.getElementById('playerRating'), upNextList: document.getElementById('upNextList'),
    previousBtn: document.getElementById('previousBtn'), shuffleBtn: document.getElementById('shuffleBtn'), discoverBtn: document.getElementById('discoverBtn'), nextBtn: document.getElementById('nextBtn'),
  };

  function initial(title) { return (title || '?').trim().charAt(0).toUpperCase() || '?'; }
  function escapeHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
  function getRating(id) { return state.ratings[id] || { average: 0, count: 0 }; }

  function ratingTemplate(v, compact = false) {
    const r = getRating(v.id), avg = Number(r.average || 0);
    const stars = [1,2,3,4,5].map(n => `<button class="rating-star${n <= Math.round(avg) ? ' is-filled' : ''}" data-rating="${n}" data-id="${v.id}" aria-label="Noter ${n} sur 5">★</button>`).join('');
    return `<div class="rating${compact ? ' rating-compact' : ''}" data-rating-widget="${v.id}" title="${r.count ? `${avg}/5 (${r.count} vote${r.count > 1 ? 's' : ''})` : 'Pas encore notée'}">${stars}<span class="rating-value">${r.count ? avg.toFixed(1) : '—'}</span>${r.count ? `<span class="rating-count">(${r.count})</span>` : ''}</div>`;
  }

  function cardTemplate(v) {
    return `<div class="video-card" data-id="${v.id}"><div class="thumb-wrap" data-stream="${v.stream_url}"><img class="thumb-img" src="${v.thumb_url}" alt="" loading="lazy"><video class="thumb-preview" muted loop playsinline preload="none"></video><span class="duration-badge">${v.duration_h}</span></div><div class="card-info"><div class="card-avatar">${initial(v.title)}</div><div class="card-text"><p class="card-title" title="${escapeHtml(v.title)}">${escapeHtml(v.title)}</p><div class="card-meta">${v.date_rel} · ${v.size_h} · <span class="source-badge">${escapeHtml(v.source_label || '')}</span></div>${ratingTemplate(v, true)}</div></div></div>`;
  }

  function upNextTemplate(v, isActive) {
    return `<div class="up-next-item${isActive ? ' active' : ''}" data-id="${v.id}"><div class="up-next-thumb" data-stream="${v.stream_url}"><img class="thumb-img" src="${v.thumb_url}" alt="" loading="lazy"><video class="thumb-preview" muted loop playsinline preload="none"></video><span class="duration-badge">${v.duration_h}</span></div><div class="up-next-text"><p class="up-next-title" title="${escapeHtml(v.title)}">${escapeHtml(v.title)}</p><div class="up-next-meta">${v.date_rel} · <span class="source-badge">${escapeHtml(v.source_label || '')}</span></div>${ratingTemplate(v, true)}</div></div>`;
  }

  function applySortAndFilter() {
    let list = [...state.videos];
    const q = state.query.trim().toLowerCase();
    if (q) list = list.filter(v => v.title.toLowerCase().includes(q));
    const rating = v => Number(getRating(v.id).average || 0);
    const votes = v => Number(getRating(v.id).count || 0);
    switch (state.sort) {
      case 'date_desc': list.sort((a,b) => b.mtime-a.mtime); break;
      case 'date_asc': list.sort((a,b) => a.mtime-b.mtime); break;
      case 'title_asc': list.sort((a,b) => a.title.localeCompare(b.title)); break;
      case 'size_desc': list.sort((a,b) => b.size-a.size); break;
      case 'rating_desc': list.sort((a,b) => rating(b)-rating(a) || votes(b)-votes(a) || b.mtime-a.mtime); break;
      case 'rating_asc': list.sort((a,b) => rating(a)-rating(b) || b.mtime-a.mtime); break;
      case 'rating_5': list = list.filter(v => rating(v) >= 5); list.sort((a,b) => votes(b)-votes(a)); break;
      case 'rating_4': list = list.filter(v => rating(v) >= 4); list.sort((a,b) => rating(b)-rating(a)); break;
      case 'rating_3': list = list.filter(v => rating(v) >= 3); list.sort((a,b) => rating(b)-rating(a)); break;
    }
    state.filtered = list; renderGrid();
  }

  const HOVER_DELAY = 350;
  function startPreview(video, streamUrl) { video.muted = true; if (video.dataset.loadedSrc !== streamUrl) { video.src=streamUrl; video.dataset.loadedSrc=streamUrl; } video.play().then(()=>video.classList.add('active')).catch(()=>{}); }
  function stopPreview(video) { video.classList.remove('active'); video.pause(); video.removeAttribute('src'); delete video.dataset.loadedSrc; video.load(); }
  function attachHoverPreviews(container, selector) { container.querySelectorAll(selector).forEach(wrap => { const video=wrap.querySelector('.thumb-preview'), streamUrl=wrap.dataset.stream; if(!video||!streamUrl)return; let timer=null; wrap.addEventListener('mouseenter',()=>{timer=setTimeout(()=>startPreview(video,streamUrl),HOVER_DELAY)}); wrap.addEventListener('mouseleave',()=>{clearTimeout(timer);stopPreview(video)}); }); }

  function renderGrid() { el.grid.innerHTML=state.filtered.map(cardTemplate).join(''); el.empty.hidden=state.filtered.length!==0; attachHoverPreviews(el.grid,'.thumb-wrap'); }

  function renderPlayerRating(v) { el.playerRating.innerHTML = ratingTemplate(v); }

  function getPlaybackPool() {
    const pool = state.filtered.length ? state.filtered : state.videos;
    return pool.length ? pool : state.videos;
  }

  function updateNavigationButtons() {
    el.previousBtn.disabled = state.historyIndex <= 0;
    el.shuffleBtn.classList.toggle('is-active', state.shuffle);
    el.shuffleBtn.setAttribute('aria-pressed', String(state.shuffle));
    el.discoverBtn.classList.toggle('is-active', state.discover);
    el.discoverBtn.setAttribute('aria-pressed', String(state.discover));
  }

  function openPlayer(id, {addHistory = true} = {}) {
    const video=state.videos.find(v=>v.id===id); if(!video)return;
    if (addHistory) {
      if (state.history[state.historyIndex] !== id) {
        state.history = state.history.slice(0, state.historyIndex + 1);
        state.history.push(id);
        state.historyIndex = state.history.length - 1;
      }
    }
    state.currentId=id;
    el.mainVideo.src=video.stream_url; el.playerTitle.textContent=video.title; el.playerDate.textContent=video.date_h; el.playerSize.textContent=video.size_h; el.playerDuration.textContent=video.duration_h; renderPlayerRating(video);
    const others=state.videos.filter(v=>v.id!==id).sort((a,b)=>b.mtime-a.mtime); el.upNextList.innerHTML=others.map(v=>upNextTemplate(v,false)).join(''); attachHoverPreviews(el.upNextList,'.up-next-thumb');
    el.gridView.hidden=true; el.playerView.hidden=false; updateNavigationButtons(); window.scrollTo({top:0,behavior:'instant'}); el.mainVideo.play().catch(()=>{});
  }

  function nextSequential() {
    const pool = getPlaybackPool();
    if (!pool.length) return;
    const currentIndex = pool.findIndex(v => v.id === state.currentId);
    const nextIndex = currentIndex >= 0 ? (currentIndex + 1) % pool.length : 0;
    openPlayer(pool[nextIndex].id);
  }

  function pickPriorityVideo(pool) {
    const candidates = pool.filter(v => v.id !== state.currentId);
    const source = candidates.length ? candidates : pool;
    if (!source.length) return null;

    // Discover : on privilégie d'abord les vidéos jamais notées.
    if (state.discover) {
      const unrated = source.filter(v => Number(getRating(v.id).count || 0) === 0);
      if (unrated.length) {
        return unrated[Math.floor(Math.random() * unrated.length)];
      }

      // Une fois tout découvert, privilégier les meilleures notes.
      const ranked = [...source].sort((a, b) => {
        const ar = Number(getRating(a.id).average || 0);
        const br = Number(getRating(b.id).average || 0);
        const ac = Number(getRating(a.id).count || 0);
        const bc = Number(getRating(b.id).count || 0);
        return br - ar || bc - ac || b.mtime - a.mtime;
      });
      return ranked[0];
    }

    // Sans Discover, le mode aléatoire reste vraiment aléatoire.
    return source[Math.floor(Math.random() * source.length)];
  }

  function nextVideo() {
    const pool = getPlaybackPool();
    if (!pool.length) return;
    if (!state.shuffle) {
      nextSequential();
      return;
    }
    const next = pickPriorityVideo(pool);
    if (next) openPlayer(next.id);
  }

  function previousVideo() {
    if (state.historyIndex <= 0) return;
    state.historyIndex -= 1;
    openPlayer(state.history[state.historyIndex], {addHistory: false});
  }

  function closePlayer(){el.mainVideo.pause();el.mainVideo.removeAttribute('src');el.mainVideo.load();el.playerView.hidden=true;el.gridView.hidden=false;state.currentId=null;updateNavigationButtons();}

  async function loadRatings(){ const res=await fetch('api/ratings.php'); const data=await res.json(); state.ratings=data.ratings||{}; }
  async function rateVideo(id, rating){
    try { const res=await fetch('api/ratings.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,rating})}); const data=await res.json(); if(!res.ok)throw new Error(data.error||'Erreur'); state.ratings[id]=data.rating; applySortAndFilter(); if(state.currentId===id){const v=state.videos.find(x=>x.id===id); if(v)renderPlayerRating(v);} } catch(e){ console.error(e); alert('Impossible d’enregistrer la note.'); }
  }
  async function loadVideos(){ try { const [res]=await Promise.all([fetch('api/videos.php'),loadRatings()]); const data=await res.json(); state.videos=data.videos||[]; el.loading.hidden=true; applySortAndFilter(); } catch(err){el.loading.innerHTML='<p>Impossible de charger la bibliothèque.</p>';console.error(err);} }

  el.grid.addEventListener('click',e=>{const star=e.target.closest('.rating-star'); if(star){e.stopPropagation();rateVideo(star.dataset.id,Number(star.dataset.rating));return;} const card=e.target.closest('.video-card');if(card)openPlayer(card.dataset.id);});
  el.upNextList.addEventListener('click',e=>{const item=e.target.closest('.up-next-item');if(item)openPlayer(item.dataset.id);});
  el.playerRating.addEventListener('click',e=>{const star=e.target.closest('.rating-star');if(star){e.stopPropagation();rateVideo(star.dataset.id,Number(star.dataset.rating));}});
  el.search.addEventListener('input',e=>{state.query=e.target.value;applySortAndFilter();});
  el.sort.addEventListener('change',e=>{state.sort=e.target.value;applySortAndFilter();});
  el.backBtn.addEventListener('click',closePlayer); el.logoLink.addEventListener('click',e=>{e.preventDefault();closePlayer();});
  el.previousBtn.addEventListener('click',previousVideo);
  el.nextBtn.addEventListener('click',nextVideo);
  el.shuffleBtn.addEventListener('click',()=>{state.shuffle=!state.shuffle;updateNavigationButtons();});
  el.discoverBtn.addEventListener('click',()=>{state.discover=!state.discover; if(state.discover) state.shuffle=true; updateNavigationButtons();});
  el.mainVideo.addEventListener('ended',()=>{nextVideo();});
  loadVideos();
})();
