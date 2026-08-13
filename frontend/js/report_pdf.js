// Builds a polished, self-contained PDF from the active transaction report.
(function (root) {
    'use strict';

    const palette = {
        ink:[23,32,51], muted:[100,116,139], line:[226,232,240], paper:[248,250,252],
        indigo:[79,70,229], indigoDark:[55,48,163], cyan:[6,182,212], emerald:[16,185,129],
        emeraldDark:[4,120,87], rose:[244,63,94], roseDark:[190,18,60], amber:[245,158,11], white:[255,255,255]
    };
    const totalPagesToken = '{total_pages_count_string}';

    function numberValue(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function clean(value, fallback) {
        const text = String(value === null || value === undefined ? '' : value).trim();
        return text || fallback || '';
    }

    function money(value) {
        const amount = numberValue(value);
        const absolute = Math.abs(amount).toLocaleString('en-GB', { minimumFractionDigits:2, maximumFractionDigits:2 });
        return `${amount < 0 ? '-' : ''}£${absolute}`;
    }

    function compactMoney(value) {
        return new Intl.NumberFormat('en-GB', { style:'currency', currency:'GBP', maximumFractionDigits:0 }).format(numberValue(value));
    }

    function formatDate(value) {
        if (!value) return '';
        const parsed = new Date(`${value}${String(value).length === 10 ? 'T12:00:00' : ''}`);
        if (Number.isNaN(parsed.getTime())) return String(value);
        return new Intl.DateTimeFormat('en-GB', { day:'2-digit', month:'short', year:'numeric' }).format(parsed).replace(/\s+/g, ' ');
    }

    function formatDateTime(date) {
        return new Intl.DateTimeFormat('en-GB', { dateStyle:'medium', timeStyle:'short' }).format(date).replace(/\s+/g, ' ');
    }

    function dimensions(doc) {
        return { width:doc.internal.pageSize.getWidth(), height:doc.internal.pageSize.getHeight() };
    }

    function setText(doc, colour, size, style) {
        doc.setTextColor.apply(doc, colour);
        doc.setFont('helvetica', style || 'normal');
        doc.setFontSize(size);
    }

    function roundedCard(doc, x, y, width, height, fill, stroke) {
        doc.setFillColor.apply(doc, fill || palette.white);
        doc.setDrawColor.apply(doc, stroke || palette.line);
        doc.setLineWidth(.25);
        doc.roundedRect(x, y, width, height, 2.4, 2.4, 'FD');
    }

    function periodFor(data, filters) {
        if (filters.start || filters.end) {
            return `${formatDate(filters.start) || 'Any date'} to ${formatDate(filters.end) || 'Any date'}`;
        }
        const dates = data.map(row => String(row.date || '')).filter(Boolean).sort();
        return dates.length ? `${formatDate(dates[0])} to ${formatDate(dates[dates.length - 1])}` : 'No dated transactions';
    }

    function metricsFor(data) {
        let income = 0;
        let spending = 0;
        data.forEach(row => {
            const amount = numberValue(row.amount);
            if (amount > 0) income += amount;
            if (amount < 0) spending += Math.abs(amount);
        });
        return { income, spending, net:income - spending, count:data.length };
    }

    function aggregate(data, field) {
        const totals = {};
        data.forEach(row => {
            const name = clean(row[field], 'Not assigned');
            if (!totals[name]) totals[name] = { name, count:0, income:0, spending:0 };
            const amount = numberValue(row.amount);
            totals[name].count += 1;
            if (amount > 0) totals[name].income += amount;
            if (amount < 0) totals[name].spending += Math.abs(amount);
        });
        return Object.values(totals).map(item => Object.assign(item, { net:item.income - item.spending }));
    }

    function filterRows(filters) {
        const rows = [];
        const add = (label, value) => { if (value) rows.push([label, value]); };
        const listSummary = values => {
            const items = Array.isArray(values) ? values.filter(Boolean) : [];
            if (items.length <= 6) return items.join(', ');
            return `${items.slice(0, 6).join(', ')} and ${items.length - 6} more`;
        };
        add('Period', filters.period);
        add('Categories', listSummary(filters.categories));
        add('Tags', listSummary(filters.tags));
        add('Groups', listSummary(filters.groups));
        add('Segments', listSummary(filters.segments));
        add('Description', filters.text);
        add('Memo', filters.memo);
        return rows;
    }

    async function captureChart(element) {
        if (!element || typeof root.html2canvas !== 'function' || !element.offsetWidth || !element.offsetHeight) return null;
        const svg = element.querySelector('svg');
        if (svg) {
            try {
                const clone = svg.cloneNode(true);
                clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                const width = Number(svg.getAttribute('width')) || svg.getBoundingClientRect().width;
                const height = Number(svg.getAttribute('height')) || svg.getBoundingClientRect().height;
                const source = new XMLSerializer().serializeToString(clone).replace(/var\(--highcharts-background-color\)/g, '#ffffff');
                const image = new Image();
                const loaded = new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = reject;
                });
                image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(source)}`;
                await loaded;
                const canvas = document.createElement('canvas');
                canvas.width = Math.ceil(width * 2);
                canvas.height = Math.ceil(height * 2);
                const context = canvas.getContext('2d');
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                return { data:canvas.toDataURL('image/png'), width:canvas.width, height:canvas.height };
            } catch (error) {
                // Fall through to HTML capture for non-standard or externally styled SVG charts.
            }
        }
        const marker = `report-pdf-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        element.setAttribute('data-report-pdf-chart', marker);
        try {
            const canvas = await root.html2canvas(element, {
                scale:2,
                useCORS:true,
                backgroundColor:'#ffffff',
                logging:false,
                onclone:clonedDocument => {
                    const clonedChart = clonedDocument.querySelector(`[data-report-pdf-chart="${marker}"]`);
                    if (!clonedChart) return;
                    clonedChart.querySelectorAll('svg [clip-path]').forEach(node => node.removeAttribute('clip-path'));
                    clonedChart.querySelectorAll('svg *').forEach(node => {
                        ['fill', 'stroke'].forEach(attribute => {
                            const value = node.getAttribute(attribute);
                            if (value && value.indexOf('var(') !== -1) node.setAttribute(attribute, '#ffffff');
                        });
                    });
                }
            });
            return { data:canvas.toDataURL('image/png'), width:canvas.width, height:canvas.height };
        } catch (error) {
            return null;
        } finally {
            element.removeAttribute('data-report-pdf-chart');
        }
    }

    function canvasSnapshot(width, height, painter) {
        const canvas = document.createElement('canvas');
        canvas.width = width * 2;
        canvas.height = height * 2;
        const context = canvas.getContext('2d');
        context.scale(2, 2);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        painter(context, width, height);
        return { data:canvas.toDataURL('image/png'), width:canvas.width, height:canvas.height };
    }

    function nativeMovementChart(data) {
        if (!data.length) return null;
        const values = new Map();
        data.forEach(row => {
            const date = new Date(row.date);
            if (Number.isNaN(date.getTime())) return;
            const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
            values.set(key, (values.get(key) || 0) + numberValue(row.amount));
        });
        const points = Array.from(values.entries()).sort((a, b) => a[0].localeCompare(b[0])).slice(-12);
        if (!points.length) return null;
        return canvasSnapshot(1040, 280, (context, width, height) => {
            const left = 82, right = 22, top = 24, bottom = 48;
            const minimum = Math.min(0, ...points.map(point => point[1]));
            const maximum = Math.max(0, ...points.map(point => point[1]));
            const range = maximum - minimum || 1;
            context.font = '12px Arial';
            context.textBaseline = 'middle';
            for (let index = 0; index <= 4; index += 1) {
                const value = maximum - range * index / 4;
                const y = top + (height - top - bottom) * index / 4;
                context.strokeStyle = '#e2e8f0';
                context.lineWidth = 1;
                context.beginPath(); context.moveTo(left, y); context.lineTo(width - right, y); context.stroke();
                context.fillStyle = '#64748b';
                context.textAlign = 'right';
                context.fillText(compactMoney(value), left - 10, y);
            }
            const xFor = index => points.length === 1 ? (left + width - right) / 2 : left + (width - left - right) * index / (points.length - 1);
            const yFor = value => top + (maximum - value) / range * (height - top - bottom);
            const zeroY = yFor(0);
            context.strokeStyle = '#94a3b8';
            context.lineWidth = 1.5;
            context.beginPath(); context.moveTo(left, zeroY); context.lineTo(width - right, zeroY); context.stroke();
            const gradient = context.createLinearGradient(0, top, 0, height - bottom);
            gradient.addColorStop(0, 'rgba(79,70,229,.24)');
            gradient.addColorStop(1, 'rgba(6,182,212,.02)');
            context.beginPath();
            points.forEach((point, index) => {
                const x = xFor(index), y = yFor(point[1]);
                if (!index) context.moveTo(x, y); else context.lineTo(x, y);
            });
            context.lineTo(xFor(points.length - 1), zeroY); context.lineTo(xFor(0), zeroY); context.closePath();
            context.fillStyle = gradient; context.fill();
            context.beginPath();
            points.forEach((point, index) => {
                const x = xFor(index), y = yFor(point[1]);
                if (!index) context.moveTo(x, y); else context.lineTo(x, y);
            });
            context.strokeStyle = '#4f46e5'; context.lineWidth = 4; context.lineJoin = 'round'; context.stroke();
            points.forEach((point, index) => {
                const x = xFor(index), y = yFor(point[1]);
                context.fillStyle = point[1] < 0 ? '#f43f5e' : '#4f46e5';
                context.beginPath(); context.arc(x, y, 5, 0, Math.PI * 2); context.fill();
                context.fillStyle = '#475569'; context.textAlign = 'center'; context.textBaseline = 'top';
                const date = new Date(`${point[0]}-01T00:00:00`);
                context.fillText(date.toLocaleDateString('en-GB', { month:'short', year:'2-digit' }), x, height - bottom + 14);
            });
        });
    }

    const chartColours = ['#4f46e5','#06b6d4','#10b981','#f59e0b','#f43f5e','#8b5cf6'];

    function nativeDonutChart(data, field) {
        const items = aggregate(data, field).map(item => ({ name:item.name, value:item.income + item.spending })).sort((a, b) => b.value - a.value).slice(0, 6);
        const total = items.reduce((sum, item) => sum + item.value, 0);
        if (!total) return null;
        return canvasSnapshot(500, 270, context => {
            const centreX = 140, centreY = 135, radius = 90;
            let angle = -Math.PI / 2;
            items.forEach((item, index) => {
                const next = angle + item.value / total * Math.PI * 2;
                context.beginPath(); context.arc(centreX, centreY, radius, angle, next); context.arc(centreX, centreY, 54, next, angle, true); context.closePath();
                context.fillStyle = chartColours[index % chartColours.length]; context.fill();
                angle = next;
            });
            context.textAlign = 'center'; context.textBaseline = 'middle'; context.fillStyle = '#172033';
            context.font = 'bold 22px Arial'; context.fillText('100%', centreX, centreY - 5);
            context.font = '12px Arial'; context.fillStyle = '#64748b'; context.fillText('of activity', centreX, centreY + 19);
            context.textAlign = 'left';
            items.forEach((item, index) => {
                const y = 42 + index * 34;
                context.fillStyle = chartColours[index % chartColours.length]; context.beginPath(); context.arc(280, y, 6, 0, Math.PI * 2); context.fill();
                context.font = '13px Arial'; context.fillStyle = '#172033'; context.fillText(item.name, 296, y - 6);
                context.font = '12px Arial'; context.fillStyle = '#64748b'; context.fillText(`${(item.value / total * 100).toFixed(1)}%`, 296, y + 10);
            });
        });
    }

    function nativeBarChart(data, field) {
        const items = aggregate(data, field).sort((a, b) => b.count - a.count).slice(0, 7);
        if (!items.length) return null;
        return canvasSnapshot(1040, 250, (context, width, height) => {
            const left = 50, right = 20, top = 18, bottom = 52;
            const maximum = Math.max(...items.map(item => item.count), 1);
            const slot = (width - left - right) / items.length;
            context.strokeStyle = '#e2e8f0'; context.lineWidth = 1;
            [0, .5, 1].forEach(part => {
                const y = top + (height - top - bottom) * part;
                context.beginPath(); context.moveTo(left, y); context.lineTo(width - right, y); context.stroke();
            });
            items.forEach((item, index) => {
                const barHeight = item.count / maximum * (height - top - bottom);
                const x = left + index * slot + slot * .17;
                const y = height - bottom - barHeight;
                context.fillStyle = chartColours[index % chartColours.length];
                context.fillRect(x, y, slot * .66, barHeight);
                context.textAlign = 'center'; context.font = 'bold 13px Arial'; context.fillStyle = '#172033'; context.fillText(String(item.count), x + slot * .33, y - 8);
                context.font = '12px Arial'; context.fillStyle = '#475569';
                const label = item.name.length > 13 ? `${item.name.slice(0, 12)}...` : item.name;
                context.fillText(label, x + slot * .33, height - bottom + 22);
            });
        });
    }

    function drawReportTitle(doc, options, metrics, period) {
        const page = dimensions(doc);
        const title = clean(options.reportName, 'Transaction Report');
        setText(doc, palette.indigoDark, 7.5, 'bold');
        doc.text('FINANCIAL ACTIVITY REPORT', 14, 27);
        setText(doc, palette.ink, 23, 'bold');
        doc.text(doc.splitTextToSize(title, page.width - 54), 14, 38);
        const description = clean(options.reportDescription, 'A clear record of the transactions included in this report.');
        setText(doc, palette.muted, 9.5, 'normal');
        doc.text(doc.splitTextToSize(description, page.width - 70), 14, 46);

        roundedCard(doc, page.width - 45, 27, 31, 21, [238,242,255], [199,210,254]);
        setText(doc, palette.indigoDark, 6.5, 'bold');
        doc.text('REPORT STATUS', page.width - 41, 33);
        setText(doc, palette.emeraldDark, 10, 'bold');
        doc.text('Complete', page.width - 41, 40);
        setText(doc, palette.muted, 6.5, 'normal');
        doc.text(`${metrics.count.toLocaleString('en-GB')} records`, page.width - 41, 44.5);

        const cards = [
            { label:'MONEY IN', value:compactMoney(metrics.income), colour:palette.emerald, tone:[236,253,245] },
            { label:'MONEY OUT', value:compactMoney(metrics.spending), colour:palette.rose, tone:[255,241,242] },
            { label:'NET MOVEMENT', value:compactMoney(metrics.net), colour:metrics.net >= 0 ? palette.indigo : palette.rose, tone:metrics.net >= 0 ? [238,242,255] : [255,241,242] },
            { label:'TRANSACTIONS', value:metrics.count.toLocaleString('en-GB'), colour:palette.cyan, tone:[236,254,255] }
        ];
        const gap = 3;
        const cardWidth = (page.width - 28 - gap * 3) / 4;
        cards.forEach((card, index) => {
            const x = 14 + index * (cardWidth + gap);
            roundedCard(doc, x, 55, cardWidth, 25, card.tone, palette.line);
            doc.setFillColor.apply(doc, card.colour);
            doc.roundedRect(x, 55, 1.8, 25, .8, .8, 'F');
            setText(doc, palette.muted, 6.3, 'bold');
            doc.text(card.label, x + 5, 63);
            setText(doc, palette.ink, 13, 'bold');
            doc.text(card.value, x + 5, 72);
            setText(doc, palette.muted, 6.2, 'normal');
            doc.text(index === 2 ? (metrics.net >= 0 ? 'Surplus' : 'Deficit') : index === 3 ? period : 'Across this report', x + 5, 77, { maxWidth:cardWidth - 8 });
        });
    }

    function narrativeFor(data, metrics) {
        const spendingCategories = aggregate(data.filter(row => numberValue(row.amount) < 0), 'category_name').sort((a, b) => b.spending - a.spending);
        const largest = spendingCategories[0];
        const movement = metrics.net > 0
            ? `The report closes with a surplus of ${money(metrics.net)}.`
            : metrics.net < 0 ? `The report closes with a deficit of ${money(Math.abs(metrics.net))}.` : 'Money in and money out are exactly balanced.';
        const category = largest ? ` The largest spending category is ${largest.name} at ${money(largest.spending)}.` : '';
        return `${movement} ${money(metrics.income)} was received and ${money(metrics.spending)} was paid out across ${metrics.count.toLocaleString('en-GB')} transactions.${category}`;
    }

    function drawBrief(doc, options, data, metrics, filters, y) {
        const page = dimensions(doc);
        const source = options.reportName ? 'Saved report' : 'Ad hoc report';
        const reportId = clean(options.reportId, 'Not saved');
        roundedCard(doc, 14, y, page.width - 28, 28, palette.white, palette.line);
        setText(doc, palette.indigoDark, 6.6, 'bold');
        doc.text('EXECUTIVE READOUT', 18, y + 7);
        setText(doc, palette.ink, 9.2, 'normal');
        const narrative = doc.splitTextToSize(narrativeFor(data, metrics), page.width - 86);
        doc.text(narrative, 18, y + 13);
        doc.setDrawColor.apply(doc, palette.line);
        doc.line(page.width - 62, y + 5, page.width - 62, y + 23);
        setText(doc, palette.muted, 6.3, 'bold');
        doc.text('SOURCE', page.width - 57, y + 9);
        doc.text('REPORT ID', page.width - 57, y + 18);
        setText(doc, palette.ink, 7.5, 'bold');
        doc.text(source, page.width - 36, y + 9);
        doc.text(reportId, page.width - 36, y + 18);
        return y + 34;
    }

    function drawFilters(doc, rows, y) {
        const page = dimensions(doc);
        setText(doc, palette.ink, 10.5, 'bold');
        doc.text('Report scope', 14, y);
        setText(doc, palette.muted, 7.2, 'normal');
        doc.text(rows.length ? 'The filters and date basis used to produce this document.' : 'No filters were applied; all available transactions are included.', 14, y + 5);
        if (!rows.length) return y + 11;

        const columns = 2;
        const gap = 4;
        const width = (page.width - 28 - gap) / columns;
        let cursorY = y + 10;
        for (let index = 0; index < rows.length; index += columns) {
            const pair = rows.slice(index, index + columns);
            const heights = pair.map(row => Math.max(11, doc.splitTextToSize(row[1], width - 8).length * 3.8 + 8));
            const height = Math.max.apply(null, heights);
            pair.forEach((row, pairIndex) => {
                const x = 14 + pairIndex * (width + gap);
                roundedCard(doc, x, cursorY, width, height, palette.paper, palette.line);
                setText(doc, palette.muted, 6.1, 'bold');
                doc.text(row[0].toUpperCase(), x + 4, cursorY + 5);
                setText(doc, palette.ink, 7.4, 'normal');
                doc.text(doc.splitTextToSize(row[1], width - 8), x + 4, cursorY + 10);
            });
            cursorY += height + 3;
        }
        return cursorY + 2;
    }

    function drawChartCard(doc, snapshot, x, y, width, height, title, subtitle) {
        roundedCard(doc, x, y, width, height, palette.white, palette.line);
        setText(doc, palette.ink, 9.2, 'bold');
        doc.text(title, x + 4, y + 7);
        setText(doc, palette.muted, 6.5, 'normal');
        doc.text(subtitle, x + 4, y + 12);
        if (!snapshot) {
            setText(doc, palette.muted, 8, 'normal');
            doc.text('No chart data available', x + width / 2, y + height / 2, { align:'center' });
            return;
        }
        const availableWidth = width - 8;
        const availableHeight = height - 19;
        const scale = Math.min(availableWidth / snapshot.width, availableHeight / snapshot.height);
        const imageWidth = snapshot.width * scale;
        const imageHeight = snapshot.height * scale;
        doc.addImage(snapshot.data, 'PNG', x + (width - imageWidth) / 2, y + 16 + (availableHeight - imageHeight) / 2, imageWidth, imageHeight, undefined, 'FAST');
    }

    function addSectionHeading(doc, eyebrow, title, copy, y) {
        setText(doc, palette.indigoDark, 6.5, 'bold');
        doc.text(eyebrow.toUpperCase(), 14, y);
        setText(doc, palette.ink, 17, 'bold');
        doc.text(title, 14, y + 9);
        setText(doc, palette.muted, 8, 'normal');
        doc.text(copy, 14, y + 15);
    }

    function tableValue(value) {
        return clean(value, 'Not assigned');
    }

    function addTransactionAppendix(doc, data, sections) {
        doc.addPage('a4', 'landscape');
        addSectionHeading(doc, 'Appendix 1', 'Transaction detail', 'Every transaction included in the report, with its classification context.', 24);
        const columns = [
            { header:'Date', dataKey:'date' }, { header:'Description', dataKey:'description' },
            { header:'Category', dataKey:'category' }, { header:'Tag', dataKey:'tag' },
            { header:'Group', dataKey:'group' }, { header:'Segment', dataKey:'segment' },
            { header:'Amount', dataKey:'amount' }
        ];
        const body = data.map(row => ({
            date:formatDate(row.date), description:clean(row.description, 'Untitled transaction'),
            category:tableValue(row.category_name), tag:tableValue(row.tag_name),
            group:tableValue(row.group_name), segment:tableValue(row.segment_name), amount:money(row.amount),
            _amount:numberValue(row.amount)
        }));
        const net = data.reduce((sum, row) => sum + numberValue(row.amount), 0);
        doc.autoTable({
            startY:43,
            margin:{ left:14, right:14, top:25, bottom:18 },
            head:[columns.map(column => column.header)],
            body:body.map(row => columns.map(column => row[column.dataKey])),
            foot:[['', 'Net movement', '', '', '', '', money(net)]],
            theme:'plain',
            styles:{ font:'helvetica', fontSize:7.2, cellPadding:{ top:2.2, right:2.2, bottom:2.2, left:2.2 }, textColor:palette.ink, lineColor:palette.line, lineWidth:{ bottom:.12 }, overflow:'linebreak', valign:'middle' },
            headStyles:{ fillColor:palette.indigoDark, textColor:palette.white, fontStyle:'bold', fontSize:6.6, cellPadding:{ top:2.6, right:2.2, bottom:2.6, left:2.2 } },
            footStyles:{ fillColor:[238,242,255], textColor:palette.indigoDark, fontStyle:'bold', lineWidth:0 },
            alternateRowStyles:{ fillColor:palette.paper },
            columnStyles:{ 0:{ cellWidth:24 }, 1:{ cellWidth:59 }, 2:{ cellWidth:32 }, 3:{ cellWidth:32 }, 4:{ cellWidth:32 }, 5:{ cellWidth:32 }, 6:{ cellWidth:28, halign:'right', fontStyle:'bold' } },
            showHead:'everyPage', showFoot:'lastPage', rowPageBreak:'avoid',
            didParseCell:function (hook) {
                if (hook.section === 'body' && hook.column.index === 6) {
                    const amount = body[hook.row.index] ? body[hook.row.index]._amount : 0;
                    hook.cell.styles.textColor = amount < 0 ? palette.roseDark : palette.emeraldDark;
                }
                if (hook.section === 'body' && hook.column.index >= 2 && hook.column.index <= 5 && hook.cell.raw === 'Not assigned') {
                    hook.cell.styles.textColor = palette.muted;
                    hook.cell.styles.fontStyle = 'italic';
                }
            },
            didDrawPage:function () { sections[doc.internal.getNumberOfPages()] = 'Transaction detail'; }
        });
    }

    function addClassificationAppendix(doc, data, metrics, sections) {
        const dimensionsToShow = [
            ['Category','category_name'], ['Tag','tag_name'], ['Group','group_name'], ['Segment','segment_name']
        ];
        const rows = [];
        dimensionsToShow.forEach(dimension => {
            aggregate(data, dimension[1]).sort((a, b) => b.spending - a.spending || b.income - a.income).forEach(item => {
                rows.push([
                    dimension[0], item.name, item.count.toLocaleString('en-GB'), money(item.income),
                    money(item.spending), money(item.net), metrics.spending ? `${(item.spending / metrics.spending * 100).toFixed(1)}%` : '0.0%'
                ]);
            });
        });
        if (!rows.length) return;
        doc.addPage('a4', 'landscape');
        addSectionHeading(doc, 'Appendix 2', 'Classification breakdown', 'Inflow, spending and net movement grouped across every reporting dimension.', 24);
        doc.autoTable({
            startY:43,
            margin:{ left:14, right:14, top:25, bottom:18 },
            head:[['Dimension','Classification','Transactions','Money in','Money out','Net','Share of spend']],
            body:rows,
            theme:'plain',
            styles:{ font:'helvetica', fontSize:7.4, cellPadding:{ top:2.2, right:2.5, bottom:2.2, left:2.5 }, textColor:palette.ink, lineColor:palette.line, lineWidth:{ bottom:.12 }, valign:'middle' },
            headStyles:{ fillColor:palette.indigoDark, textColor:palette.white, fontStyle:'bold', fontSize:6.6 },
            alternateRowStyles:{ fillColor:palette.paper },
            columnStyles:{ 0:{ cellWidth:29, fontStyle:'bold', textColor:palette.indigoDark }, 1:{ cellWidth:70 }, 2:{ cellWidth:25, halign:'right' }, 3:{ cellWidth:31, halign:'right', textColor:palette.emeraldDark }, 4:{ cellWidth:31, halign:'right', textColor:palette.roseDark }, 5:{ cellWidth:31, halign:'right', fontStyle:'bold' }, 6:{ cellWidth:30, halign:'right' } },
            showHead:'everyPage', rowPageBreak:'avoid',
            didDrawPage:function () { sections[doc.internal.getNumberOfPages()] = 'Classification breakdown'; }
        });
    }

    function drawChrome(doc, options, sections, generatedAt) {
        const pageCount = doc.internal.getNumberOfPages();
        for (let pageNumber = 1; pageNumber <= pageCount; pageNumber += 1) {
            doc.setPage(pageNumber);
            const page = dimensions(doc);
            doc.setFillColor.apply(doc, palette.indigo);
            doc.rect(0, 0, page.width * .38, 1.4, 'F');
            doc.setFillColor.apply(doc, palette.cyan);
            doc.rect(page.width * .38, 0, page.width * .32, 1.4, 'F');
            doc.setFillColor.apply(doc, palette.emerald);
            doc.rect(page.width * .70, 0, page.width * .30, 1.4, 'F');

            doc.setFillColor.apply(doc, palette.indigoDark);
            doc.circle(17, 10, 3.2, 'F');
            setText(doc, palette.white, 7.2, 'bold');
            doc.text(clean(options.siteTitle, 'Accounts').charAt(0).toUpperCase(), 17, 11.8, { align:'center' });
            setText(doc, palette.ink, 8.2, 'bold');
            doc.text(clean(options.siteTitle, 'Accounts'), 23, 9.2);
            setText(doc, palette.muted, 5.8, 'normal');
            doc.text('FINANCIAL REPORTING', 23, 13);
            setText(doc, palette.muted, 7, 'bold');
            doc.text(sections[pageNumber] || 'Report overview', page.width - 14, 10.5, { align:'right' });

            doc.setDrawColor.apply(doc, palette.line);
            doc.setLineWidth(.2);
            doc.line(14, page.height - 13, page.width - 14, page.height - 13);
            setText(doc, palette.muted, 6.2, 'normal');
            doc.text(`Prepared ${generatedAt}`, 14, page.height - 7.5);
            doc.text('Private and confidential', page.width / 2, page.height - 7.5, { align:'center' });
            doc.text(`Page ${pageNumber} of ${totalPagesToken}`, page.width - 14, page.height - 7.5, { align:'right' });
        }
        if (typeof doc.putTotalPages === 'function') doc.putTotalPages(totalPagesToken);
    }

    function filenameFor(date) {
        const pad = value => String(value).padStart(2, '0');
        return `transactions_report_${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}_${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}.pdf`;
    }

    async function build(options) {
        options = options || {};
        if (!root.jspdf || !root.jspdf.jsPDF) throw new Error('PDF generation is unavailable.');
        const doc = new root.jspdf.jsPDF({ orientation:'portrait', unit:'mm', format:'a4', compress:true, putOnlyUsedFonts:true });
        if (typeof doc.autoTable !== 'function') throw new Error('PDF table generation is unavailable.');
        const data = Array.isArray(options.data) ? options.data : [];
        const filters = Object.assign({ categories:[], tags:[], groups:[], segments:[] }, options.filters || {});
        filters.period = periodFor(data, filters);
        const metrics = metricsFor(data);
        const generated = options.generatedAt instanceof Date ? options.generatedAt : new Date();
        const generatedAt = formatDateTime(generated);
        const reportTitle = clean(options.reportName, 'Transaction Report');
        const sections = { 1:'Report overview' };
        doc.setProperties({
            title:`${clean(options.siteTitle, 'Accounts')} - ${reportTitle}`,
            subject:'Transaction activity and classification report',
            author:clean(options.siteTitle, 'Accounts'),
            creator:'Accounts Financial Reporting',
            keywords:'transactions, financial report, accounts'
        });

        drawReportTitle(doc, options, metrics, filters.period);
        let y = drawBrief(doc, options, data, metrics, filters, 86);
        y = drawFilters(doc, filterRows(filters), y);
        const firstPageHeight = dimensions(doc).height;
        const movement = nativeMovementChart(data) || await captureChart(options.charts && options.charts.movement);
        const movementHeight = Math.max(55, firstPageHeight - y - 20);
        drawChartCard(doc, movement, 14, y, dimensions(doc).width - 28, movementHeight, 'Movement over time', 'Transaction value grouped across the reporting period.');

        const chartSources = options.charts || {};
        const category = nativeDonutChart(data, 'category_name') || await captureChart(chartSources.category);
        const segment = nativeDonutChart(data, 'segment_name') || await captureChart(chartSources.segment);
        const tag = nativeBarChart(data, 'tag_name') || await captureChart(chartSources.tag);
        if (category || segment || tag) {
            doc.addPage('a4', 'portrait');
            sections[doc.internal.getNumberOfPages()] = 'Classification picture';
            addSectionHeading(doc, 'Overview 2', 'Classification picture', 'Where the activity sits across categories, segments and tags.', 24);
            const gap = 4;
            const half = (dimensions(doc).width - 28 - gap) / 2;
            drawChartCard(doc, category, 14, 45, half, 88, 'By category', 'Share of absolute transaction value.');
            drawChartCard(doc, segment, 14 + half + gap, 45, half, 88, 'By segment', 'Share of absolute transaction value.');
            drawChartCard(doc, tag, 14, 138, dimensions(doc).width - 28, 76, 'By tag', 'Tag distribution across the selected report.');

            const topSpending = aggregate(data.filter(row => numberValue(row.amount) < 0), 'category_name').sort((a, b) => b.spending - a.spending).slice(0, 5);
            if (topSpending.length) {
                setText(doc, palette.ink, 10.5, 'bold');
                doc.text('Largest spending categories', 14, 224);
                doc.autoTable({
                    startY:229, margin:{ left:14, right:14, bottom:18 },
                    head:[['Rank','Category','Transactions','Spending','Share']],
                    body:topSpending.map((item, index) => [String(index + 1), item.name, String(item.count), money(item.spending), metrics.spending ? `${(item.spending / metrics.spending * 100).toFixed(1)}%` : '0.0%']),
                    theme:'plain', styles:{ fontSize:7.2, cellPadding:1.8, lineColor:palette.line, lineWidth:{ bottom:.12 }, textColor:palette.ink },
                    headStyles:{ fillColor:[238,242,255], textColor:palette.indigoDark, fontStyle:'bold' },
                    columnStyles:{ 0:{ cellWidth:14 }, 2:{ halign:'right' }, 3:{ halign:'right', textColor:palette.roseDark, fontStyle:'bold' }, 4:{ halign:'right' } }
                });
            }
        }

        addTransactionAppendix(doc, data, sections);
        addClassificationAppendix(doc, data, metrics, sections);
        drawChrome(doc, options, sections, generatedAt);
        return { doc, filename:filenameFor(generated), metrics };
    }

    async function download(options) {
        const result = await build(options);
        result.doc.save(result.filename);
        return result;
    }

    root.ReportPdfExport = { build, download };
})(window);
