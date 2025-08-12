// Handles category and tag drag-and-drop assignments
(function(){
  const dragInfo = { tagId: null, oldCategory: null };

  function handleDragStart(){
    const parent = this.closest('[data-category-id]');
    dragInfo.tagId = this.dataset.tagId;
    dragInfo.oldCategory = parent && parent.dataset.categoryId ? parent.dataset.categoryId : null;
  }

  async function handleDrop(e){
    e.preventDefault();
    const newCategory = this.dataset.categoryId || null;
    if (!dragInfo.tagId || newCategory === dragInfo.oldCategory) return;

    if (dragInfo.oldCategory && newCategory) {
      await fetch('../php_backend/public/categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_tag', category_id: dragInfo.oldCategory, tag_id: dragInfo.tagId })
      });
      await fetch('../php_backend/public/categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_tag', category_id: newCategory, tag_id: dragInfo.tagId })
      });
      showMessage('Tag moved');
    } else if (dragInfo.oldCategory && !newCategory) {
      await fetch('../php_backend/public/categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_tag', category_id: dragInfo.oldCategory, tag_id: dragInfo.tagId })
      });
      showMessage('Tag removed');
    } else if (!dragInfo.oldCategory && newCategory) {
      await fetch('../php_backend/public/categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_tag', category_id: newCategory, tag_id: dragInfo.tagId })
      });
      showMessage('Tag assigned');
    }
    loadCategories();
  }

  function createTagBadge(tag){
    const span = document.createElement('span');
    span.textContent = tag.name;
    span.className = 'bg-blue-200 text-blue-800 px-2 py-1 rounded cursor-move w-full text-center';
    span.draggable = true;
    span.dataset.tagId = tag.id;
    span.addEventListener('dragstart', handleDragStart);
    return span;
  }

  function addDropHandlers(el){
    el.addEventListener('dragover', e => e.preventDefault());
    el.addEventListener('drop', handleDrop);
  }

  function createCategoryCard(cat){
    const card = document.createElement('div');
    card.className = 'bg-white p-4 rounded shadow w-64 flex-shrink-0';
    card.dataset.categoryId = cat.id;

    const header = document.createElement('div');
    header.className = 'flex justify-between items-center mb-2';
    const title = document.createElement('h2');
    title.className = 'font-semibold';
    title.textContent = cat.name;
    header.appendChild(title);

    const delBtn = document.createElement('button');
    delBtn.className = 'text-red-600';
    delBtn.innerHTML = '<i class="fas fa-trash"></i>';
    delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this category?')) return;
      await fetch('../php_backend/public/categories.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: cat.id })
      });
      loadCategories();
      showMessage('Category deleted');
    });
    header.appendChild(delBtn);
    card.appendChild(header);

    if (cat.description) {
      const desc = document.createElement('p');
      desc.className = 'text-sm text-gray-600 mb-2';
      desc.textContent = cat.description;
      card.appendChild(desc);
    }

    const tagWrap = document.createElement('div');
    tagWrap.className = 'min-h-[3rem] flex flex-col gap-2';
    tagWrap.dataset.categoryId = cat.id;
    (cat.tags || []).forEach(t => tagWrap.appendChild(createTagBadge(t)));
    card.appendChild(tagWrap);

    addDropHandlers(tagWrap);
    return card;
  }

  function createUnassignedCard(tags){
    const card = document.createElement('div');
    card.className = 'bg-white p-4 rounded shadow w-64 flex-shrink-0';
    const title = document.createElement('h2');
    title.className = 'font-semibold mb-2';
    title.textContent = 'Unassigned Tags';
    card.appendChild(title);
    const tagWrap = document.createElement('div');
    tagWrap.className = 'min-h-[3rem] flex flex-col gap-2';
    tags.forEach(t => tagWrap.appendChild(createTagBadge(t)));
    card.appendChild(tagWrap);
    addDropHandlers(tagWrap);
    return card;
  }

  async function loadCategories(){
    const [catRes, untagRes] = await Promise.all([
      fetch('../php_backend/public/categories.php'),
      fetch('../php_backend/public/tags.php?unassigned=1')
    ]);
    const cats = await catRes.json();
    const unassigned = await untagRes.json();
    const unassignedWrap = document.getElementById('unassigned');
    const container = document.getElementById('category-container');
    unassignedWrap.innerHTML = '';
    container.innerHTML = '';
    unassignedWrap.appendChild(createUnassignedCard(unassigned));
    cats.forEach(c => container.appendChild(createCategoryCard(c)));
  }

  function init(){
    const form = document.getElementById('category-form');
    if (form) {
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const name = document.getElementById('category-name').value;
        const description = document.getElementById('category-description').value;
        await fetch('../php_backend/public/categories.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, description })
        });
        document.getElementById('category-name').value = '';
        document.getElementById('category-description').value = '';
        loadCategories();
        showMessage('Category created');
      });
    }
    loadCategories();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
