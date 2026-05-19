/* Canticle — frontend JS (vanilla, no build step) */

// ── Compose modal open / close ────────────────────────────────────────────────
function openCompose() {
  var modal = document.getElementById('compose-modal');
  if (!modal) return;
  modal.classList.add('open');
  setTimeout(function() {
    var ta = document.getElementById('compose-textarea');
    if (ta) ta.focus();
  }, 50);
}
function closeCompose() {
  var modal = document.getElementById('compose-modal');
  if (modal) modal.classList.remove('open');
}

// Button that opens the modal
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-compose-open]')) openCompose();
  if (e.target.closest('[data-compose-close]')) closeCompose();
});

// Backdrop click closes modal
var _modal = document.getElementById('compose-modal');
if (_modal) {
  _modal.addEventListener('click', function(e) {
    if (e.target === _modal) closeCompose();
  });
}

// Escape key closes modal
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeCompose();
});

// Submit compose form
var _composeForm = document.getElementById('compose-form');
if (_composeForm) {
  _composeForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    var form = e.target;
    var data = new FormData(form);
    var body = {};
    for (var pair of data.entries()) {
      var key = pair[0], val = pair[1];
      if (key === 'media_ids[]') {
        body.media_ids = body.media_ids || [];
        body.media_ids.push(val);
      } else if (key === 'poll[options][]') {
        body.poll = body.poll || {};
        body.poll.options = body.poll.options || [];
        body.poll.options.push(val);
      } else if (key.startsWith('poll[')) {
        var pollKey = key.slice(5, -1);
        body.poll = body.poll || {};
        body.poll[pollKey] = val;
      } else {
        body[key] = val;
      }
    }
    var csrfToken = document.querySelector('meta[name=csrf-token]');
    var res = await fetch('/api/v1/statuses', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken ? csrfToken.content : '',
      },
      body: JSON.stringify(body)
    });
    if (res.ok) {
      closeCompose();
      form.reset();
      var preview = form.querySelector('.media-preview');
      if (preview) preview.innerHTML = '';
      window.location.reload();
    } else {
      var err = await res.json();
      alert(err.error || 'Failed to post');
    }
  });
}

// ── Compose: character counter ────────────────────────────────────────────────
document.querySelectorAll('[data-compose]').forEach(form => {
  const ta    = form.querySelector('textarea[name="status"]');
  const count = form.querySelector('.compose-count');
  const max   = parseInt(form.dataset.maxChars || '500', 10);

  if (!ta || !count) return;

  const update = () => {
    const len = ta.value.length;
    count.textContent = max - len;
    count.classList.toggle('warn', len > max * 0.9);
  };
  ta.addEventListener('input', update);
  update();
});

// ── Content warnings ──────────────────────────────────────────────────────────
document.querySelectorAll('.cw-toggle').forEach(btn => {
  btn.addEventListener('click', () => {
    const body = btn.nextElementSibling;
    if (body) body.classList.toggle('open');
  });
});

// ── Relative timestamps ───────────────────────────────────────────────────────
function relativeTime(date) {
  const diff = (Date.now() - date.getTime()) / 1000;
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h';
  if (diff < 604800)return Math.floor(diff / 86400) + 'd';
  return date.toLocaleDateString();
}

document.querySelectorAll('time[datetime]').forEach(el => {
  const d = new Date(el.getAttribute('datetime'));
  if (!isNaN(d)) {
    el.textContent = relativeTime(d);
    el.title = d.toLocaleString();
  }
});

// ── API action buttons (fav, boost, reply via fetch) ─────────────────────────
async function apiPost(url, data = {}) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '',
    },
    body: JSON.stringify(data),
  });
  return res.json();
}

document.addEventListener('click', async e => {
  const btn = e.target.closest('[data-action]');
  if (!btn) return;
  e.preventDefault();

  const action   = btn.dataset.action;
  const statusId = btn.dataset.id;

  if (!statusId) return;

  if (action === 'favourite') {
    const active = btn.classList.toggle('active');
    const countEl = btn.querySelector('.count');
    apiPost(`/api/v1/statuses/${statusId}/${active ? 'favourite' : 'unfavourite'}`)
      .then(s => { if (countEl) countEl.textContent = s.favourites_count; });
  }

  if (action === 'reblog') {
    const active = btn.classList.toggle('active');
    const countEl = btn.querySelector('.count');
    apiPost(`/api/v1/statuses/${statusId}/${active ? 'reblog' : 'unreblog'}`)
      .then(s => { if (countEl) countEl.textContent = s.reblogs_count || s.reblog?.reblogs_count; });
  }
});

// ── Compose: image upload preview ─────────────────────────────────────────────
document.querySelectorAll('[data-media-upload]').forEach(input => {
  input.addEventListener('change', async e => {
    const form    = input.closest('form');
    const preview = form.querySelector('.media-preview');
    if (!preview) return;

    for (const file of input.files) {
      const fd = new FormData();
      fd.append('file', file);

      try {
        const res  = await fetch('/api/v2/media', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.id) {
          // Add hidden input with media ID
          const hidden = document.createElement('input');
          hidden.type  = 'hidden';
          hidden.name  = 'media_ids[]';
          hidden.value = data.id;
          form.appendChild(hidden);

          // Show thumbnail
          const img = document.createElement('img');
          img.src   = data.preview_url || data.url;
          img.alt   = data.description || '';
          img.style = 'width:80px;height:80px;object-fit:cover;border-radius:6px;margin-right:.4rem';
          preview.appendChild(img);

          // Show alt text
          if (data.description) {
            img.title = data.description;
          }
        }
      } catch (err) {
        console.error('Media upload failed', err);
      }
    }
    // Clear input so same file can be re-selected
    input.value = '';
  });
});

// ── Poll: show/hide poll builder ──────────────────────────────────────────────
function setPollEnabled(poll, enabled) {
  // Enable or disable every input/select inside the poll builder so the browser
  // skips them for both validation and FormData serialisation when hidden.
  poll.querySelectorAll('input, select').forEach(el => {
    el.disabled = !enabled;
  });
}

document.querySelectorAll('[data-toggle-poll]').forEach(btn => {
  btn.addEventListener('click', () => {
    const poll = document.querySelector('.poll-builder');
    if (!poll) return;
    const showing = poll.style.display === 'none';
    poll.style.display = showing ? 'block' : 'none';
    setPollEnabled(poll, showing);
  });
});

// ── Poll: add option ──────────────────────────────────────────────────────────
document.querySelectorAll('[data-add-poll-option]').forEach(btn => {
  btn.addEventListener('click', () => {
    const container = document.querySelector('.poll-options-container');
    const maxOpts   = parseInt(btn.dataset.max || '4', 10);
    if (!container) return;
    const current = container.querySelectorAll('.poll-opt').length;
    if (current >= maxOpts) return;
    const div = document.createElement('div');
    div.className = 'poll-opt';
    // New options start enabled because the poll builder is already visible
    div.innerHTML = `<input type="text" name="poll[options][]" placeholder="Option ${current + 1}" maxlength="50" style="width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:4px;background:var(--bg);color:var(--text)">`;
    container.appendChild(div);
  });
});
