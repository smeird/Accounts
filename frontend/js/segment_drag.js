// Handles segment and category drag-and-drop assignments
(function(){
  const dragInfo = { categoryId: null, oldSegment: null };

  function handleDragStart(){
    const parent = this.closest('[data-segment-id]');
    dragInfo.categoryId = this.dataset.categoryId;
    dragInfo.oldSegment = parent && parent.dataset.segmentId ? parent.dataset.segmentId : null;
  }

  async function handleDrop(e){
    e.preventDefault();
    const newSegment = this.dataset.segmentId || null;
    if (!dragInfo.categoryId || newSegment === dragInfo.oldSegment) return;

    if (dragInfo.oldSegment && newSegment) {
      await fetch('../php_backend/public/segments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'move_category', segment_id: newSegment, old_segment_id: dragInfo.oldSegment, category_id: dragInfo.categoryId })
      });
    } else if (dragInfo.oldSegment && !newSegment) {
      await fetch('../php_backend/public/segments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_category', segment_id: dragInfo.oldSegment, category_id: dragInfo.categoryId })
      });
    } else if (!dragInfo.oldSegment && newSegment) {
      await fetch('../php_backend/public/segments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_category', segment_id: newSegment, category_id: dragInfo.categoryId })
      });
    }
    loadSegments();
  }

  function createCategoryCard(cat){
    const div = document.createElement('div');
    div.textContent = cat.name;
    div.className = 'inline-block px-2 py-1 text-xs font-semibold rounded bg-green-200 text-green-800 cursor-move';
    div.draggable = true;
    div.dataset.categoryId = cat.id;
    div.addEventListener('dragstart', handleDragStart);
    return div;
  }

  function addDropHandlers(el){
    el.addEventListener('dragover', e => e.preventDefault());
    el.addEventListener('drop', handleDrop);
  }

  function createSegmentCard(seg){
    const card = document.createElement('div');
    card.className = 'bg-white p-4 rounded shadow border border-gray-400 w-64 flex-shrink-0';
    card.dataset.segmentId = seg.id;

    const header = document.createElement('div');
    header.className = 'flex justify-between items-center mb-2';
    const title = document.createElement('span');
    title.className = 'inline-block px-2 py-1 text-xs font-semibold rounded bg-yellow-200 text-yellow-800';
    title.textContent = seg.name;
    header.appendChild(title);

    const actions = document.createElement('div');
    actions.className = 'flex gap-2';

    const editBtn = document.createElement('button');
    editBtn.className = 'text-indigo-600';
    editBtn.innerHTML = '<i class="fas fa-edit"></i>';
    editBtn.setAttribute('aria-label','Edit');
    editBtn.addEventListener('click', async () => {
      const name = prompt('Segment Name', seg.name);
      if (name === null) return;
      const description = prompt('Description', seg.description || '');
      if (description === null) return;
      await fetch('../php_backend/public/segments.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: seg.id, name, description })
      });
      loadSegments();
      if (typeof showMessage !== 'undefined') {
        showMessage('Segment updated');
      }
    });
    actions.appendChild(editBtn);

    const delBtn = document.createElement('button');
    delBtn.className = 'text-red-600';
    delBtn.innerHTML = '<i class="fas fa-trash"></i>';
    delBtn.setAttribute('aria-label','Delete');
    delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this segment?')) return;
      await fetch('../php_backend/public/segments.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: seg.id })
      });
      loadSegments();
    });
    actions.appendChild(delBtn);

    header.appendChild(actions);
    card.appendChild(header);

    if (seg.description) {
      const desc = document.createElement('p');
      desc.className = 'text-sm text-gray-600 mb-2';
      desc.textContent = seg.description;
      card.appendChild(desc);
    }

    const catWrap = document.createElement('div');
    catWrap.className = 'min-h-[3rem] flex flex-col gap-2 items-start';
    catWrap.dataset.segmentId = seg.id;
    (seg.categories || []).forEach(c => catWrap.appendChild(createCategoryCard(c)));
    card.appendChild(catWrap);

    addDropHandlers(catWrap);
    return card;
  }

  function createUnassignedCard(categories){
    const card = document.createElement('div');
    card.className = 'bg-white p-4 rounded shadow border border-gray-400 w-64 flex-shrink-0';
    const title = document.createElement('h2');
    title.className = 'font-semibold mb-2';
    title.textContent = 'Unassigned Categories';
    card.appendChild(title);
    const wrap = document.createElement('div');
    wrap.className = 'min-h-[3rem] flex flex-col gap-2 items-start';
    categories.forEach(c => wrap.appendChild(createCategoryCard(c)));
    card.appendChild(wrap);
    addDropHandlers(wrap);
    return card;
  }

  async function loadSegments(){
    const [segRes, catRes] = await Promise.all([
      fetch('../php_backend/public/segments.php'),
      fetch('../php_backend/public/categories.php')
    ]);
    const segments = await segRes.json();
    const categories = await catRes.json();

    const assigned = new Set();
    segments.forEach(s => (s.categories || []).forEach(c => assigned.add(c.id)));
    const unassigned = categories.filter(c => !assigned.has(c.id));

    const unassignedWrap = document.getElementById('unassigned');
    const container = document.getElementById('segment-container');
    unassignedWrap.innerHTML = '';
    container.innerHTML = '';
    unassignedWrap.appendChild(createUnassignedCard(unassigned));
    segments.forEach(s => container.appendChild(createSegmentCard(s)));
  }

  function init(){
    const form = document.getElementById('segment-form');
    if (form) {
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const name = document.getElementById('segment-name').value;
        const description = document.getElementById('segment-description').value;
        await fetch('../php_backend/public/segments.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, description })
        });
        document.getElementById('segment-name').value = '';
        document.getElementById('segment-description').value = '';
        loadSegments();
        if (typeof showMessage !== 'undefined') {
          showMessage('Segment created');
        }
      });
    }
    loadSegments();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
