// Render a pivot table with Tabulator and year filtering

document.addEventListener('DOMContentLoaded', () => {
  const yearSelect = document.getElementById('year-select');
  const refreshBtn = document.getElementById('refresh');
  const exportBtn = document.getElementById('export');
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  let table;

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
        renderPivot(data, year);
      })
      .catch(() => showMessage('Failed to load data', 'error'));
  }

  function renderPivot(data, year) {
    const keyField = year === 'all' ? 'year' : 'month';
    const segments = {};
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

      [segments[seg].__totals, segments[seg].categories[cat].__totals, segments[seg].categories[cat].tags[tag].__totals].forEach(totals => {
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
        bottomCalc: 'sum',
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
      bottomCalc: 'sum',
      bottomCalcFormatter: 'money',
      bottomCalcFormatterParams: { symbol: '£', precision: 2 }
    });

    function buildRow(name, totals, children) {
      const row = { item: name };
      order.forEach(k => (row[k] = totals[k] || 0));
      row.Total = totals.Total || 0;
      if (children && children.length) row._children = children;
      return row;
    }

    const tableData = Object.entries(segments).map(([segName, segObj]) => {
      const catRows = Object.entries(segObj.categories).map(([catName, catObj]) => {
        const tagRows = Object.entries(catObj.tags).map(([tagName, tagObj]) => buildRow(tagName, tagObj.__totals));
        return buildRow(catName, catObj.__totals, tagRows);
      });
      return buildRow(segName, segObj.__totals, catRows);
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
        dataTreeStartExpanded: false
      });
    }
  }
});

