
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
    const groups = {};
    data.forEach(r => {
      const cat = r.category_name || 'Uncategorised';
      const key = r[keyField];
      if (!groups[cat]) groups[cat] = {};
      groups[cat][key] = (groups[cat][key] || 0) + parseFloat(r.amount);
    });

    const colSet = new Set();
    Object.values(groups).forEach(obj => Object.keys(obj).forEach(k => colSet.add(k)));

    const order = Array.from(colSet).sort((a, b) => {
      if (keyField === 'month') {
        return monthNames.indexOf(a) - monthNames.indexOf(b);
      }
      return Number(a) - Number(b);
    });

    const columns = [{ title: 'Category', field: 'category', frozen: true }];
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

    const tableData = Object.entries(groups).map(([category, vals]) => {
      const row = { category };
      let total = 0;
      order.forEach(name => {
        const v = vals[name] || 0;
        row[name] = v;
        total += v;
      });
      row.Total = total;
      return row;
    });

    if (table) {
      table.setColumns(columns);
      table.setData(tableData);
      const cols = table.getColumns();
      if (cols.length) cols[0].freeze(true);
    } else {
      table = tailwindTabulator('#pivot-table', {
        data: tableData,
        columns,
        layout: 'fitDataStretch',
        pagination: false
      });
    }
  }
});


