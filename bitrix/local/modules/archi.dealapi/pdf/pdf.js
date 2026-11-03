// Builds the A4 PDF from a scraped payload with jsPDF + jspdf-autotable.
// Every page gets a header (title, page X / Y) and a footer (graph type,
// footer note, client signature box, print date). The table body is laid
// out by autoTable inside the margins, so rows can never collide with the
// footer or be split across pages.
// Exposes window.ArchiPdf.
(function () {
  'use strict';

  const FONT = 'NotoSansGeorgian';
  const FOOTER_NOTE = 'გრაფიკი მოიცავს პროცენტს. სრულ დაფარვამდე გასაღები არ გადაეცემა.';
  const DEFAULT_HEAD_COLOR = [0, 105, 74];

  // A4 portrait, millimetres.
  const PAGE = { w: 210, h: 297 };
  const M = { left: 12, right: 12, top: 19, bottom: 62 }; // autoTable margins
  const HEADER = { titleY: 12, lineY: 15 };
  const FOOTER = { top: PAGE.h - 58 }; // 239 mm — everything below is footer
  const SIGN_BOX_H = 28;

  const pad2 = (n) => String(n).padStart(2, '0');
  function formatDateTime(d) {
    return `${pad2(d.getDate())}.${pad2(d.getMonth() + 1)}.${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
  }
  function formatDateFile(d) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
  }

  function registerFonts(doc) {
    doc.addFileToVFS('NotoSansGeorgian-Regular.ttf', window.ARCHI_FONT_REGULAR);
    doc.addFont('NotoSansGeorgian-Regular.ttf', FONT, 'normal');
    doc.addFileToVFS('NotoSansGeorgian-Bold.ttf', window.ARCHI_FONT_BOLD);
    doc.addFont('NotoSansGeorgian-Bold.ttf', FONT, 'bold');
    doc.setFont(FONT, 'normal');
  }

  const NUMERIC_RE = /^[\d.,\s$%\-–()]+$/;
  function isNumericColumn(rows, ci) {
    let n = 0, num = 0;
    for (const r of rows) {
      const v = r[ci];
      if (v === undefined || v === '') continue;
      n++;
      if (NUMERIC_RE.test(v)) num++;
    }
    return n > 0 && num / n >= 0.8;
  }

  function columnStyles(header, rows) {
    const cols = header.length;
    const avail = PAGE.w - M.left - M.right;
    const styles = {};
    let fixed = 0;
    const isIndex = (h, i) => i === 0 && (/^#|№|N$/.test(h) || h.length <= 2);
    const isDate = (h) => /თარიღ/.test(h);
    for (let i = 0; i < cols; i++) {
      if (isIndex(header[i], i)) { styles[i] = { cellWidth: 10, halign: 'center' }; fixed += 10; }
      else if (isDate(header[i])) { styles[i] = { cellWidth: 26, halign: 'center' }; fixed += 26; }
    }
    const rest = cols - Object.keys(styles).length;
    const w = rest > 0 ? (avail - fixed) / rest : avail;
    for (let i = 0; i < cols; i++) {
      if (!styles[i]) styles[i] = { cellWidth: w, halign: isNumericColumn(rows, i) ? 'right' : 'left' };
    }
    return styles;
  }

  function drawHeader(doc, payload, pageNo, total) {
    doc.setFont(FONT, 'bold');
    doc.setFontSize(11);
    doc.setTextColor(20);
    const title = payload.dealId ? `გადახდის გრაფიკი · გარიგება #${payload.dealId}` : 'გადახდის გრაფიკი';
    doc.text(title, M.left, HEADER.titleY);
    doc.setFont(FONT, 'normal');
    doc.setFontSize(9);
    doc.setTextColor(90);
    doc.text(`გვ. ${pageNo} / ${total}`, PAGE.w - M.right, HEADER.titleY, { align: 'right' });
    doc.setDrawColor(180);
    doc.setLineWidth(0.3);
    doc.line(M.left, HEADER.lineY, PAGE.w - M.right, HEADER.lineY);
  }

  // Date printed on the signature line: the deal's registration date. On the
  // calculator page it is shown as "ხელშეკრულების თარიღი" (fed from the deal
  // card's "გაფორმების თარიღი"); either label is accepted.
  const SIGN_DATE_RE = /ხელშეკრულების თარიღ|გაფორმების თარიღ/;
  function signatureDate(payload) {
    const f = (payload.fields || []).find((x) => SIGN_DATE_RE.test(x.label || '') && x.value && !x.fromPlaceholder);
    return f ? f.value : '';
  }

  function drawFooter(doc, payload, pageNo, total, printedAt) {
    const x0 = M.left, x1 = PAGE.w - M.right;
    let y = FOOTER.top;

    doc.setDrawColor(180);
    doc.setLineWidth(0.3);
    doc.line(x0, y, x1, y);

    y += 6;
    doc.setFont(FONT, 'bold');
    doc.setFontSize(10);
    doc.setTextColor(20);
    doc.text(`გრაფიკის ტიპი: ${payload.graphType || '—'}`, x0, y);

    y += 6;
    doc.setFontSize(9.5);
    doc.text(FOOTER_NOTE, x0, y);

    // signature box
    y += 4;
    doc.setDrawColor(120);
    doc.setLineWidth(0.4);
    doc.rect(x0, y, x1 - x0, SIGN_BOX_H);
    doc.setFont(FONT, 'normal');
    doc.setFontSize(9);
    doc.setTextColor(40);
    doc.text('კლიენტის ხელმოწერა:', x0 + 4, y + 7);
    doc.text('თარიღი:', x0 + 118, y + 7);
    doc.setDrawColor(60);
    doc.setLineWidth(0.3);
    const lineY = y + SIGN_BOX_H - 6;
    doc.line(x0 + 4, lineY, x0 + 104, lineY);
    doc.line(x0 + 118, lineY, x1 - 4, lineY);
    const signDate = signatureDate(payload);
    if (signDate) {
      doc.setFont(FONT, 'normal');
      doc.setFontSize(11);
      doc.setTextColor(20);
      doc.text(signDate, x0 + 122, lineY - 2);
    }

    // meta line
    y += SIGN_BOX_H + 5;
    doc.setFontSize(8);
    doc.setTextColor(120);
    doc.text(`დაბეჭდილია: ${formatDateTime(printedAt)}`, x0, y);
    doc.text(`გვ. ${pageNo} / ${total}`, x1, y, { align: 'right' });
  }

  // Fields that must not appear in the PDF header (toggles / switches):
  // the user wants only the value inputs and the graph-type select.
  const SKIP_LABEL_RE = /რესტრუქტურიზაცია|ინტერვალ|ხელით|არასტანდარტული/;
  function headerFields(payload) {
    return (payload.fields || []).filter((f) =>
      (f.label || f.value) && f.kind !== 'toggle' && !SKIP_LABEL_RE.test(f.label || ''));
  }

  // Page-1 summary mimicking the on-screen form: 4 columns, small grey label
  // above a boxed value (like an input). Then notes. Returns next y.
  function drawSummary(doc, payload, y) {
    const x0 = M.left, x1 = PAGE.w - M.right;
    const COLS = 4;
    const gap = 4;
    const colW = (x1 - x0 - gap * (COLS - 1)) / COLS;
    const labelH = 3.4;   // per label line
    const boxH = 8;
    const rowGap = 3;
    const fields = headerFields(payload);

    for (let i = 0; i < fields.length; i += COLS) {
      const row = fields.slice(i, i + COLS);
      // labels may wrap; align all boxes of the row under the tallest label
      doc.setFont(FONT, 'normal');
      doc.setFontSize(7.5);
      const labelLines = row.map((f) => doc.splitTextToSize(f.label || '', colW));
      const maxLines = Math.max(1, ...labelLines.map((l) => l.length));
      const boxY = y + labelH * maxLines + 1;

      row.forEach((f, c) => {
        const x = x0 + c * (colW + gap);
        doc.setFont(FONT, 'normal');
        doc.setFontSize(7.5);
        doc.setTextColor(90);
        doc.text(labelLines[c], x + 0.5, y + labelH - 0.6);

        // box
        const muted = f.disabled || f.fromPlaceholder;
        doc.setDrawColor(muted ? 200 : 170);
        doc.setFillColor(muted ? 240 : 255);
        doc.setLineWidth(0.25);
        doc.roundedRect(x, boxY, colW, boxH, 1, 1, 'FD');

        // value
        doc.setFont(FONT, f.kind === 'select' ? 'bold' : 'normal');
        doc.setTextColor(muted ? 140 : 20);
        let value = f.value || '';
        const maxW = colW - 4;
        // shrink the font (down to 7pt) before resorting to an ellipsis
        let size = 9.5;
        doc.setFontSize(size);
        while (size > 7 && doc.getTextWidth(value) > maxW) { size -= 0.5; doc.setFontSize(size); }
        while (value && doc.getTextWidth(value) > maxW) value = value.slice(0, -2) + '…';
        doc.text(value, x + 2, boxY + boxH / 2 + 1.3);
      });

      y = boxY + boxH + rowGap;
    }

    const notes = (payload.notes || []).filter((n) => !n.isFooterNote && n.text);
    if (notes.length) {
      y += 2;
      doc.setFont(FONT, 'normal');
      doc.setFontSize(8.5);
      doc.setTextColor(80);
      for (const n of notes) {
        const lines = doc.splitTextToSize(n.text, x1 - x0);
        doc.text(lines, x0, y);
        y += lines.length * 3.8 + 1;
      }
    }
    doc.setTextColor(20);
    return y;
  }

  function buildPdf(payload, opts) {
    opts = opts || {};
    const printedAt = opts.now ? new Date(opts.now) : new Date();
    const jsPDF = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
    if (!jsPDF) throw new Error('jsPDF not loaded');
    const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
    registerFonts(doc);

    // page 1: summary
    let y = M.top + 2;
    y = drawSummary(doc, payload, y);
    y += 3;

    const header = payload.table.header || [];
    const rows = payload.table.rows || [];
    const headColor = payload.table.headerColor || DEFAULT_HEAD_COLOR;

    if (y > PAGE.h - M.bottom - 30) { doc.addPage(); y = M.top; }

    if (rows.length === 0) {
      doc.setFont(FONT, 'normal');
      doc.setFontSize(10);
      doc.text('ცხრილი ცარიელია.', M.left, y + 4);
    } else {
      doc.autoTable({
        head: header.length ? [header] : undefined,
        body: rows,
        startY: y,
        margin: { left: M.left, right: M.right, top: M.top, bottom: M.bottom },
        theme: 'plain',
        styles: {
          font: FONT,
          fontStyle: 'normal',
          fontSize: 9,
          cellPadding: { top: 1.5, bottom: 1.5, left: 1.5, right: 1.5 },
          lineColor: [225, 225, 225],
          lineWidth: 0.1,
          overflow: 'ellipsize',
          valign: 'middle',
          textColor: [20, 20, 20],
        },
        headStyles: {
          fillColor: headColor,
          textColor: [255, 255, 255],
          fontStyle: 'bold',
          fontSize: 8.5,
          halign: 'center',
          valign: 'middle',
          overflow: 'linebreak', // long column names wrap instead of being cut
          lineWidth: 0,
        },
        bodyStyles: { lineWidth: { bottom: 0.1 } },
        alternateRowStyles: { fillColor: [244, 244, 244] },
        columnStyles: columnStyles(header, rows),
        rowPageBreak: 'avoid',
        showHead: 'everyPage',
        pageBreak: 'auto',
      });
    }

    // headers + footers on every page (drawn last so the total is known)
    const total = doc.getNumberOfPages();
    for (let p = 1; p <= total; p++) {
      doc.setPage(p);
      drawHeader(doc, payload, p, total);
      drawFooter(doc, payload, p, total, printedAt);
    }
    doc.setPage(1);
    return doc;
  }

  function fileName(payload, now) {
    const d = now ? new Date(now) : new Date();
    const id = payload.dealId ? `_${payload.dealId}` : '';
    return `grafiki${id}_${formatDateFile(d)}.pdf`;
  }

  window.ArchiPdf = { buildPdf, fileName, FOOTER_NOTE, formatDateTime };
})();
