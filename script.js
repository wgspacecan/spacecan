// === DRAG & DROP UPLOAD ===
document.querySelectorAll('.dropzone').forEach(zone => {
const albumId = zone.id.split('-')[1];
const queue = [];
let activeUploads = 0;
const MAX_PARALLEL = 3; // Adjust based on server

// === Drag Events ===
['dragenter', 'dragover'].forEach(e => {
  zone.addEventListener(e, ev => {
    ev.preventDefault();
    ev.stopPropagation();
    zone.classList.add('dragover');
  });
});
['dragleave', 'drop'].forEach(e => {
  zone.addEventListener(e, ev => {
    ev.preventDefault();
    ev.stopPropagation();
    zone.classList.remove('dragover');
  });
});

// === Drop Handler ===
zone.addEventListener('drop', e => {
  e.preventDefault();
  zone.classList.remove('dragover');

  const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
  if (!files.length) return;

  files.forEach(file => {
    const upload = createUpload(file, albumId);
    queue.push(upload);
    upload.element.appendTo(zone);
  });

  processQueue();
});

// === Upload Class ===
function createUpload(file, albumId) {
  const uploadId = `${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  const chunkSize = 5 * 1024 * 1024;
  const totalChunks = Math.ceil(file.size / chunkSize);

  const el = {
    root: document.createElement('div'),
    name: document.createElement('div'),
    bar: document.createElement('div'),
    fill: document.createElement('div'),
    status: document.createElement('div'),
    cancel: document.createElement('button'),

    appendTo(parent) {
      this.root.style = 'margin:0.5rem 0; padding:0.5rem; background:#f9f9f9; border:1px solid #ddd; border-radius:4px; font-size:0.9rem;';
      this.name.textContent = file.name;
      this.name.style = 'margin-bottom:0.3rem; font-weight:500;';
      this.bar.style = 'height:6px; background:#eee; border-radius:3px; overflow:hidden;';
      this.fill.style = 'width:0%; height:100%; background:#0066cc; transition:width 0.2s;';
      this.status.style = 'margin-top:0.3rem; color:#555;';
      this.cancel.textContent = '×';
      this.cancel.style = 'float:right; background:none; border:none; color:#d32f2f; cursor:pointer; font-size:1.2rem;';

      this.bar.appendChild(this.fill);
      this.root.append(this.name, this.bar, this.status, this.cancel);
      parent.appendChild(this.root);
    },

    updateProgress(uploaded) {
      const pct = (uploaded / totalChunks * 100).toFixed(1);
      this.fill.style.width = pct + '%';
      this.status.textContent = `${uploaded}/${totalChunks} chunks`;
    },

    setStatus(msg, color = '#555') {
      this.status.textContent = msg;
      this.status.style.color = color;
    },

    remove() {
      this.root.remove();
    }
  };

  let uploaded = 0;
  let cancelled = false;

  el.cancel.onclick = () => {
    cancelled = true;
    el.setStatus('Cancelled', '#d32f2f');
    el.remove();
    activeUploads--;
    processQueue();
  };

  const uploadChunk = (index) => {
    if (cancelled || index >= totalChunks) {
      if (!cancelled) {
        el.setStatus('Complete', '#2e7d32');
        setTimeout(() => el.remove(), 1500);
      }
      activeUploads--;
      processQueue();
      return;
    }

    const start = index * chunkSize;
    const end = Math.min(start + chunkSize, file.size);
    const chunk = file.slice(start, end);

    const fd = new FormData();
    fd.append('chunk', chunk, file.name);
    fd.append('chunk_index', index);
    fd.append('total_chunks', totalChunks);
    fd.append('filename', file.name);
    fd.append('upload_id', uploadId);
    fd.append('album_id', albumId);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('upload.php', { method: 'POST', body: fd })
      .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.text();
      })
      .then(txt => {
        if (txt.trim() !== 'OK') throw new Error(txt);
        uploaded++;
        el.updateProgress(uploaded);
        uploadChunk(index + 1);
      })
      .catch(err => {
        if (!cancelled) {
          el.setStatus(`Failed: ${err.message}`, '#d32f2f');
          console.error('Upload error:', err);
        }
        activeUploads--;
        processQueue();
      });
  };

  return {
    element: el,
    start() {
      activeUploads++;
      el.setStatus('Starting...');
      uploadChunk(0);
    }
  };
}

// === Queue Processor ===
function processQueue() {
  while (activeUploads < MAX_PARALLEL && queue.length > 0) {
    const upload = queue.shift();
    upload.start();
  }
  if (queue.length === 0 && activeUploads === 0) {
    setTimeout(() => location.reload(), 1000);
  }
}
});

// === DELETE IMAGE ===
function delImg(id, albumId) {
  fetch('admin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'delete_image',
      id: id,
      csrf_token: CSRF_TOKEN
    })
  })
  .then(r => r.text())
  .then(txt => {
    if (txt.trim() === 'OK') {
      location.href = `admin.php?open=${albumId}`;
    } else {
      alert('Delete failed: ' + txt);
    }
  })
  .catch(() => alert('Network error'));
}