// Initialise pivot table with WebDataRocks and provide year filtering

document.addEventListener('DOMContentLoaded', () => {
  const yearSelect = document.getElementById('year-select');
  const refreshBtn = document.getElementById('refresh');
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  let pivot; 

  // Fetch available months to populate years list
  fetch('../php_backend/public/transaction_months.php')
    .then(r => r.json())
    .then(months => {
      const years = Array.from(new Set(months.map(m => m.year))).sort((a,b) => b - a);
      years.forEach(y => {
        const opt = document.createElement('option');
        opt.value = y;
        opt.textContent = y;
        yearSelect.appendChild(opt);
      });
      loadData(yearSelect.value);
    })
    .catch(() => showMessage('Failed to load years','error'));

  refreshBtn.addEventListener('click', () => loadData(yearSelect.value));

  function loadData(year){
    let url = '../php_backend/public/export_data.php';
    if(year !== 'all'){
      url += `?start=${year}-01-01&end=${year}-12-31`;
    }
    fetch(url)
      .then(r => r.json())
      .then(rows => {
        const data = rows.map(r => ({
          ...r,
          year: r.date.substring(0,4),
          month: monthNames[new Date(r.date).getMonth()]
        }));
        const report = {
          dataSource: { data },
          slice: {
            rows: [{ uniqueName: 'category_name' }],
            columns: [{ uniqueName: 'month' }],
            measures: [{ uniqueName: 'amount', aggregation: 'sum', format: 'GBP' }]
          },
          formats: [{
            name: 'GBP',
            currencySymbol: '£',
            thousandsSeparator: ',',
            decimalSeparator: '.',
            decimalPlaces: 2
          }]
        };
        if(pivot){
          pivot.setReport(report);
        } else {
          pivot = new WebDataRocks({
            container: '#pivot-table',
            toolbar: true,
            report
          });
        }
      })
      .catch(() => showMessage('Failed to load data','error'));
  }
});
