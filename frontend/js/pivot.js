
// Render a pivot table with Tabulator and year filtering

document.addEventListener('DOMContentLoaded', () => {
  const yearSelect = document.getElementById('year-select');
  const refreshBtn = document.getElementById('refresh');

  const exportBtn = document.getElementById('export');
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  let table;
  let rawData = [];
  let detailTable;
  let currentKeyField;

  const detailCard = document.getElementById('detail-card');
  const detailTitle = document.getElementById('detail-title');
  const detailClose = document.getElementById('detail-close');
  if (detailClose) detailClose.addEventListener('click', () => detailCard.classList.add('hidden'));

  // Populate year dropdown
  fetch('../php_backend/public/transaction_months.php')
    .then(r => r.json())
    .then(months => {
      const years = Array.from(new Set(months.map(m => m.year))).sort((a, b) => b - a);

      years.forEach(y => {
        const opt = document.createElement('option');
        opt.value = y;
        opt.textContent = y;
        yearSelect.appendChild(opt);
      });
      loadData(yearSelect.value);
    })

    .catch(() => showMessage('Failed to load years', 'error'));

  refreshBtn.addEventListener('click', () => loadData(yearSelect.value));
  exportBtn.addEventListener('click', () => {
    if (table) table.download('csv', 'pivot.csv');
  });

  function loadData(year) {
    let url = '../php_backend/public/export_data.php';
    if (year !== 'all') {

      url += `?start=${year}-01-01&end=${year}-12-31`;
    }
    fetch(url)
      .then(r => r.json())
      .then(rows => {
        const data = rows.map(r => ({
          ...r,

          year: r.date.substring(0, 4),
          month: monthNames[new Date(r.date).getMonth()]
        }));
        rawData = data;
        renderPivot(data, year);
      })
      .catch(() => showMessage('Failed to load data', 'error'));
  }

  function renderPivot(data, year) {
    currentKeyField = year === 'all' ? 'year' : 'month';
    const keyField = currentKeyField;

    const segments = {};
    const grandTotals = {};

    const keys = new Set();

    data.forEach(r => {
      const seg = r.segment_name || 'Unsegmented';
      const cat = r.category_name || 'Uncategorised';
      const tag = r.tag_name || 'Untagged';
      const key = r[keyField];
      const amount = parseFloat(r.amount);
      keys.add(key);

      if (!segments[seg]) segments[seg] = { __totals: {}, categories: {} };
      if (!segments[seg].categories[cat]) segments[seg].categories[cat] = { __totals: {}, tags: {} };
      if (!segments[seg].categories[cat].tags[tag]) segments[seg].categories[cat].tags[tag] = { __totals: {} };


      [grandTotals, segments[seg].__totals, segments[seg].categories[cat].__totals, segments[seg].categories[cat].tags[tag].__totals].forEach(totals => {

        totals[key] = (totals[key] || 0) + amount;
        totals.Total = (totals.Total || 0) + amount;
      });
    });

    const order = Array.from(keys).sort((a, b) => {

      if (keyField === 'month') {
        return monthNames.indexOf(a) - monthNames.indexOf(b);
      }
      return Number(a) - Number(b);
    });


    const columns = [{ title: 'Item', field: 'item', frozen: true }];

    order.forEach(name => {
      columns.push({
        title: name,
        field: name,
        hozAlign: 'right',
        formatter: 'money',
        formatterParams: { symbol: '£', precision: 2 },

        bottomCalc: () => grandTotals[name] || 0,

        bottomCalcFormatter: 'money',
        bottomCalcFormatterParams: { symbol: '£', precision: 2 }
      });
    });
    columns.push({
      title: 'Total',
      field: 'Total',
      hozAlign: 'right',
      formatter: 'money',
      formatterParams: { symbol: '£', precision: 2 },

      bottomCalc: () => grandTotals.Total || 0,

      bottomCalcFormatter: 'money',
      bottomCalcFormatterParams: { symbol: '£', precision: 2 }
    });


    function buildRow(name, totals, children, meta = {}) {
      const row = { item: name, ...meta };
      order.forEach(k => (row[k] = totals[k] || 0));
      row.Total = totals.Total || 0;
      if (children && children.length) row._children = children;
      return row;
    }

    const tableData = Object.entries(segments).map(([segName, segObj]) => {
      const catRows = Object.entries(segObj.categories).map(([catName, catObj]) => {
        const tagRows = Object.entries(catObj.tags).map(([tagName, tagObj]) =>
          buildRow(tagName, tagObj.__totals, null, { segment: segName, category: catName, tag: tagName })
        );
        return buildRow(catName, catObj.__totals, tagRows, { segment: segName, category: catName });
      });
      return buildRow(segName, segObj.__totals, catRows, { segment: segName });
    });

    if (table) {
      table.setColumns(columns);
      table.setData(tableData);

    } else {
      table = tailwindTabulator('#pivot-table', {
        data: tableData,
        columns,
        layout: 'fitDataStretch',

        pagination: false,
        dataTree: true,
        dataTreeStartExpanded: false,
        cellClick: handleCellClick
      });
    }
  }

  function handleCellClick(e, cell) {
    const field = cell.getField();
    if (field === 'item') return;
    const rowData = cell.getRow().getData();
    const filters = {};
    if (rowData.segment) filters.segment_name = rowData.segment;
    if (rowData.category) filters.category_name = rowData.category;
    if (rowData.tag) filters.tag_name = rowData.tag;
    if (field !== 'Total') filters[currentKeyField] = field;

    const rows = rawData.filter(r => {
      if (filters.segment_name && r.segment_name !== filters.segment_name) return false;
      if (filters.category_name && r.category_name !== filters.category_name) return false;
      if (filters.tag_name && r.tag_name !== filters.tag_name) return false;
      if (filters[currentKeyField] && r[currentKeyField] !== filters[currentKeyField]) return false;
      return true;
    });

    if (detailTitle) detailTitle.textContent = `${rowData.item} - ${field}`;
    if (detailCard) {
      detailCard.classList.remove('hidden');
      detailCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if (detailTable) {
      detailTable.setData(rows);
    } else {
      detailTable = tailwindTabulator('#detail-table', {
        data: rows,
        layout: 'fitDataStretch',
        pagination: false,
        columns: [
          { title: 'Date', field: 'date' },
          { title: 'Description', field: 'description' },
          {
            title: 'Amount',
            field: 'amount',
            hozAlign: 'right',
            formatter: 'money',
            formatterParams: { symbol: '£', precision: 2 }
          }
        ]
      });
    }
  }
});


