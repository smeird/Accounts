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
    } else if (dragInfo.oldCategory && !newCategory) {
      await fetch('../php_backend/public/categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'remove_tag', category_id: dragInfo.oldCategory, tag_id: dragInfo.tagId })
      });
    } else if (!dragInfo.oldCategory && newCategory) {
      await fetch('../php_backend/public/categories.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_tag', category_id: newCategory, tag_id: dragInfo.tagId })
      });
    }
    loadCategories();
  }

  function createTagBadge(tag){
    const span = document.createElement('span');
    span.textContent = tag.name;
    // Use the same styling as badges in tables for consistent appearance
    span.className = 'inline-block px-2 py-1 text-xs font-semibold rounded bg-indigo-200 text-indigo-800 cursor-move';
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

    card.className = 'cards cards-tight w-full flex gap-4 items-start';

    card.dataset.categoryId = cat.id;

    const nameCol = document.createElement('div');
    // Stack the title above the action icons for a cleaner layout
    nameCol.className = 'w-[10%] flex flex-col items-start';

    const title = document.createElement('h2');
    title.className = 'font-semibold';
    title.textContent = cat.name;
    nameCol.appendChild(title);

    const actions = document.createElement('div');
    actions.className = 'flex gap-2 mt-1';

    const editBtn = document.createElement('button');
    editBtn.className = 'text-indigo-600';
    editBtn.innerHTML = '<i class="fas fa-edit"></i>';
    editBtn.setAttribute('aria-label','Edit');
    editBtn.addEventListener('click', async () => {
      const name = prompt('Category Name', cat.name);
      if (name === null) return;
      const description = prompt('Description', cat.description || '');
      if (description === null) return;
      await fetch('../php_backend/public/categories.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: cat.id, name, description })
      });
      loadCategories();
      if (typeof showMessage !== 'undefined') {
        showMessage('Category updated');
      }
    });
    actions.appendChild(editBtn);

    const delBtn = document.createElement('button');
    delBtn.className = 'text-red-600';
    delBtn.innerHTML = '<i class="fas fa-trash"></i>';
    delBtn.setAttribute('aria-label','Delete');
    delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this category?')) return;
      await fetch('../php_backend/public/categories.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: cat.id })
      });
      loadCategories();
    });
    actions.appendChild(delBtn);

    nameCol.appendChild(actions);
    card.appendChild(nameCol);

    const descCol = document.createElement('div');
    // Allocate only 20% of the row to the description column
    descCol.className = 'w-[20%] text-sm text-gray-600';
    descCol.textContent = cat.description || '';
    card.appendChild(descCol);

    const tagWrap = document.createElement('div');

    tagWrap.className = 'flex-1 min-h-[3rem] flex flex-row flex-wrap items-start gap-2';

    tagWrap.dataset.categoryId = cat.id;
    (cat.tags || []).forEach(t => tagWrap.appendChild(createTagBadge(t)));
    card.appendChild(tagWrap);

    addDropHandlers(tagWrap);
    return card;
  }

  function createUnassignedCard(tags){
    const card = document.createElement('div');
    card.className = 'cards cards-tight w-full';
    const title = document.createElement('h2');
    title.className = 'font-semibold mb-2';
    title.textContent = 'Unassigned Tags';
    card.appendChild(title);
    const tagWrap = document.createElement('div');
    tagWrap.className = 'min-h-[3rem] flex flex-row flex-wrap items-start gap-2';
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
      });
    }
    loadCategories();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
