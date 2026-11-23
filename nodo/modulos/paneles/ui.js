// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo paneles · 2.0.0 (Ola 4): Paneles IoT por áreas. Portada con tarjetas por área; dentro, paneles de cinco tipos:
// tarjetas (señales sueltas), energía (curvas, referencias e informe), equipos (grilla por sector con detalle y análisis),
// sinóptico (esquema en vivo, variables, tendencias y eventos) y eventos (indicadores, filtros, lista y ranking).
(() => {
  const { h, fecha, aviso, panel, api, puede, kpis, alClicKpi, csv, icono, grafico } = Nucleo;
  const EST = { ok: ['#43a047', 'verde', 'Normal'], fuera: ['#fb8c00', 'nar', 'Fuera de rango'], alarma: ['#e53935', 'rojo', 'En alarma'], 'sin-datos': ['#90a4ae', 'gris', 'Sin datos'] };
  const SEM = { verde: ['#43a047', 'verde', 'Normal'], amarillo: ['#f9a825', 'ama', 'Atención'], rojo: ['#e53935', 'rojo', 'Fuera de referencia'], 'sin-datos': ['#90a4ae', 'gris', 'Sin datos'] };
  const PAL = ['#1e88e5', '#e53935', '#43a047', '#fb8c00', '#8e24aa', '#00897b', '#6d4c41', '#3949ab', '#c0ca33', '#546e7a', '#d81b60', '#5e35b1'];
  const num = (v, d = 1) => v == null || Number.isNaN(+v) ? '—' : Number(v).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });
  const tms = (ts) => new Date(String(ts).replace(' ', 'T')).getTime();
  const hace = (ts) => { if (!ts) return 'nunca'; const s = (Date.now() - tms(ts)) / 1000; return s < 90 ? 'recién' : s < 3600 ? `hace ${Math.round(s / 60)} min` : s < 86400 ? `hace ${Math.round(s / 3600)} h` : `hace ${Math.round(s / 86400)} d`; };
  const fcorta = (ts) => new Date(tms(ts)).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const SV = (d) => `<svg class="i" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
  // íconos propios de las áreas (los del núcleo siguen valiendo por su nombre)
  const ICO = { rayo: '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>', gota: '<path d="M12 3c4 5 6 8.2 6 11a6 6 0 0 1-12 0c0-2.8 2-6 6-11z"/>', termo: '<path d="M10 14V5a2 2 0 0 1 4 0v9a4 4 0 1 1-4 0z"/><path d="M12 9v6"/>',
    cruz: '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>', frasco: '<path d="M9 3h6M10 3v5l-4 6v5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-5l-4-6V3"/><path d="M7 15h10"/>', aire: '<path d="M3 8h11a3 3 0 1 0-3-3M3 12h15a3 3 0 1 1-3 3M3 16h7"/>',
    copo: '<path d="M12 2v20M4 7l16 10M20 7 4 17M9 4l3 3 3-3M9 20l3-3 3 3"/>', engranaje: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>' };
  const ico = (n) => ICO[n] ? SV(ICO[n]) : icono(n || 'paneles');
  let reloj = null, filtroPortada = '';

  if (!document.getElementById('pa-css')) { const st = document.createElement('style'); st.id = 'pa-css'; st.textContent = `
    .pa-areas{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,250px),1fr));gap:12px}
    .pa-area{position:relative;text-align:left;font:inherit;color:inherit;background:#fff;border:1px solid var(--borde);border-top:4px solid var(--ac);border-radius:var(--r);padding:14px 14px 12px;box-shadow:var(--sombra);cursor:pointer;transition:transform .15s,box-shadow .15s;display:flex;flex-direction:column;gap:6px;min-width:0}
    .pa-area:hover,.pa-area:focus-visible{transform:translateY(-2px);box-shadow:var(--sombra2)}.pa-area.tenue{opacity:.45}
    .pa-area .pa-ai{display:flex;gap:10px;align-items:center}.pa-area .pa-ai span{flex:none;width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--ac) 14%,#fff);color:var(--ac)}
    .pa-area .pa-ai span .i{width:22px;height:22px}.pa-area b{font-size:15.5px}.pa-area p{margin:0;font-size:12.5px;color:var(--gris)}
    .pa-chips{display:flex;gap:5px;flex-wrap:wrap}.pa-chips i{font-style:normal;font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 8px;background:#eceff1;color:#455a64}
    .pa-chips i.ok{background:#e8f5e9;color:#2e7d32}.pa-chips i.al{background:#fde7e7;color:#c62828}.pa-chips i.fu{background:#fff1e0;color:#ef6c00}
    .pa-cab{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 12px}.pa-cab h2{margin:0;font-size:18px;display:flex;gap:8px;align-items:center;color:var(--ac,var(--az))}.pa-cab .sep{flex:1}
    .pa-graf{position:relative;margin-top:6px;touch-action:pan-y}.pa-graf svg{display:block;width:100%;height:auto;font-size:11px}.pa-graf svg text{fill:#5f6f84}
    .pa-ley{position:absolute;pointer-events:none;background:rgba(255,255,255,.97);border:1px solid var(--borde);border-radius:10px;box-shadow:var(--sombra2);padding:6px 9px;font-size:12px;min-width:150px;z-index:3;display:none}
    .pa-ley div{display:flex;gap:6px;align-items:center;white-space:nowrap}.pa-ley i,.pa-sw i{display:inline-block;width:10px;height:10px;border-radius:3px}
    .pa-sw{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.pa-sw button{border:1px solid var(--borde);background:#fff;border-radius:999px;padding:3px 10px;font:inherit;font-size:12px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
    .pa-sw button.off{opacity:.45;text-decoration:line-through}
    .pa-per{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:4px 0 6px}.pa-per input[type=date]{padding:5px 6px;border:1px solid var(--borde);border-radius:8px;font:inherit;font-size:13px}
    .pa-cs{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0}.pa-cs button,.pa-per button{border:1px solid var(--borde);background:#fff;border-radius:999px;padding:5px 11px;font:inherit;font-size:12.5px;font-weight:600;color:var(--gris);cursor:pointer}
    .pa-cs button.activo,.pa-per button.activo{background:var(--az);border-color:var(--az);color:#fff}
    .pa-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:8px}
    .pa-c{position:relative;border:1px solid var(--borde);border-left:5px solid var(--sc);border-radius:12px;background:#fff;box-shadow:var(--sombra);padding:8px 10px;cursor:pointer;text-align:left;font:inherit;color:inherit;min-width:0}
    .pa-c.sel{outline:2px solid var(--az);outline-offset:1px}.pa-c .t{font-size:12px;color:var(--gris);display:flex;justify-content:space-between;gap:6px}.pa-c .t b{color:var(--txt);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .pa-c .v{font-size:22px;font-weight:700;line-height:1.25}.pa-c .v small{font-size:12px;font-weight:600;color:var(--gris);margin-left:3px}.pa-c .r{font-size:11px;color:var(--gris)}
    .pa-c .mm{display:flex;gap:4px;margin-top:4px}.pa-c .mm span{flex:1;border:1px solid var(--borde);border-radius:7px;padding:2px 4px;font-size:11px;background:#fbfdff;text-align:center;cursor:pointer}.pa-c .mm span:hover{border-color:var(--az3)}
    .pa-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--sc);flex:none}
    .pa-est{display:flex;gap:10px;align-items:center;flex-wrap:wrap;border:1px solid var(--borde);border-left:6px solid var(--sc);border-radius:12px;padding:8px 12px;background:color-mix(in srgb,var(--sc) 7%,#fff);margin-bottom:10px}
    .pa-est b{font-size:15px}.pa-est .sep{flex:1}
    .pa-gr h3{margin:14px 0 6px;font-size:14px;color:var(--az)}.pa-gr h3 small{font-weight:400;color:var(--gris)}
    .pa-eqs{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,178px),1fr));gap:8px;align-items:start}
    .pa-eq{border:1px solid var(--borde);border-top:4px solid var(--sc);border-radius:12px;background:#fff;box-shadow:var(--sombra);padding:8px 10px;cursor:pointer;text-align:left;font:inherit;color:inherit;min-width:0}
    .pa-eq.sel{outline:2px solid var(--az);outline-offset:1px}.pa-eq .n{display:flex;justify-content:space-between;gap:6px;align-items:center}.pa-eq .n b{font-size:14px}
    .pa-eq dl{display:grid;grid-template-columns:auto 1fr;gap:1px 8px;margin:6px 0 0;font-size:12px}.pa-eq dt{color:var(--gris)}.pa-eq dd{margin:0;text-align:right;font-weight:600}
    .pa-det{border:1px solid var(--az3);border-radius:var(--r);background:#fbfdff;padding:12px;margin:10px 0 4px;box-shadow:var(--sombra)}
    .pa-det .pa-cab h2{font-size:16px}
    .pa-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--borde);margin:0 0 10px}.pa-tabs button{border:0;background:none;padding:7px 12px;font:inherit;font-weight:600;color:var(--gris);cursor:pointer;border-bottom:3px solid transparent}
    .pa-tabs button.activo{color:var(--az);border-bottom-color:var(--az)}
    .pa-sin svg{width:100%;height:auto;display:block;max-height:430px}.pa-sin .sv{cursor:pointer}.pa-sin .sv rect{fill:#fff;stroke:#90a4ae;rx:6}.pa-sin .sv:hover rect{stroke:var(--az)}
    .pa-sin .sv text{font-size:12px;fill:#16263a}.pa-sin .sv .e{font-size:10px;fill:#5f6f84}
    .pa-tab{width:100%;border-collapse:collapse;font-size:13px}.pa-tab th,.pa-tab td{padding:5px 8px;border-bottom:1px solid var(--borde);text-align:left}.pa-tab td.n{text-align:right;font-variant-numeric:tabular-nums}
    .pa-tab tr.clic{cursor:pointer}.pa-tab tr.clic:hover td{background:#f3f8ff}table.pa-tab{display:table}
    .pa-an{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:8px;margin-bottom:8px}
    @media (max-width:520px){.pa-c .v{font-size:19px}.pa-cab h2{font-size:16px}}
    @media print{.pa-graf,.pa-sw,.pa-per,.pa-cs{break-inside:avoid}}`; document.head.appendChild(st); }

  function chispa(v, color) {
    if (!v || v.length < 2) return '<svg class="chispa" viewBox="0 0 100 34" aria-hidden="true"></svg>';
    const mi = Math.min(...v), ma = Math.max(...v), r = ma - mi || 1;
    const p = v.map((y, i) => `${(i / (v.length - 1) * 100).toFixed(1)},${(30 - (y - mi) / r * 26).toFixed(1)}`).join(' ');
    return `<svg class="chispa" viewBox="0 0 100 34" preserveAspectRatio="none" aria-hidden="true"><polygon points="0,34 ${p} 100,34" fill="${color}" opacity=".13"/><polyline points="${p}" fill="none" stroke="${color}" stroke-width="1.6" vector-effect="non-scaling-stroke"/></svg>`;
  }

  // ------------------------------------------------------------------ período: rápido (1 h a 30 días) o entre fechas
  const PERIODOS = [['1', '1 h'], ['6', '6 h'], ['24', '24 h'], ['168', '7 días'], ['720', '30 días']];
  const qsPer = (P) => P.desde ? `desde=${P.desde}&hasta=${P.hasta}` : `horas=${P.horas}`;
  const txtPer = (P) => P.desde ? `del ${P.desde.split('-').reverse().join('/')} al ${P.hasta.split('-').reverse().join('/')}` : (PERIODOS.find((x) => x[0] === String(P.horas)) || [0, P.horas + ' h'])[1];
  function selPeriodo(cont, P, alCambiar, lista = PERIODOS) {
    const hoy = new Date().toISOString().slice(0, 10);
    cont.innerHTML = `<div class="pa-per">${lista.map(([v, t]) => `<button data-h="${v}" class="${!P.desde && String(P.horas) === v ? 'activo' : ''}">${t}</button>`).join('')}
      <button data-h="f" class="${P.desde ? 'activo' : ''}">Entre fechas</button><span class="pa-fe" ${P.desde ? '' : 'hidden'}><input type="date" data-f="d" value="${h(P.desde || hoy)}" max="${hoy}"> a <input type="date" data-f="h" value="${h(P.hasta || hoy)}" max="${hoy}"> <button data-h="ver">Ver</button></span></div>`;
    cont.querySelectorAll('button').forEach((b) => b.onclick = () => {
      if (b.dataset.h === 'f') { cont.querySelector('.pa-fe').hidden = false; return; }
      if (b.dataset.h === 'ver') { const d = cont.querySelector('[data-f=d]').value, x = cont.querySelector('[data-f=h]').value; if (!d || !x) return; P.desde = d < x ? d : x; P.hasta = d < x ? x : d; delete P.horas; }
      else { P.horas = +b.dataset.h; delete P.desde; delete P.hasta; }
      selPeriodo(cont, P, alCambiar, lista); alCambiar(P);
    });
  }

  // ------------------------------------------------------------------ curva en el tiempo: varias series, grilla, leyenda al pasar, marca y bandas de referencia
  // S: [{n, color, unidad, dec, pts: [[ts, prom, min, max]]}] · o: {t0, t1, paso_s, bandas: [{de, a, color}], marca, alto, alMarcar(ts)}
  function curvaT(cont, S, o = {}) {
    const st = { ocultas: new Set(), marca: o.marca || null };
    S.forEach((s, i) => { s.color = s.color || PAL[i % PAL.length]; s.T = s.pts.map((p) => tms(p[0])); });
    const t0 = o.t0, t1 = o.t1 > o.t0 ? o.t1 : o.t0 + 1;
    const dibujar = () => {
      const vis = S.filter((_, i) => !st.ocultas.has(i)); const W = Math.max(300, Math.round(cont.clientWidth || 640)), H = o.alto || (W < 520 ? 220 : 270);
      const uni = [...new Set(vis.map((s) => s.unidad || ''))].slice(0, 2), dos = uni.length > 1, L = 52, R = dos ? 52 : 14, T = 12, B = 26, iw = W - L - R, ih = H - T - B;
      const rango = (u, k) => { let mi = Infinity, ma = -Infinity; vis.filter((s) => (s.unidad || '') === u).forEach((s) => s.pts.forEach((p) => { const a = vis.length === 1 ? p[2] : p[1], b = vis.length === 1 ? p[3] : p[1]; if (a < mi) mi = a; if (b > ma) ma = b; }));
        if (k === 0) (o.bandas || []).forEach((b) => [b.de, b.a].forEach((v) => { if (v != null && isFinite(mi) && Math.abs(v - (mi + ma) / 2) < Math.max(ma - mi, Math.abs(ma) * .25 || 1) * 3) { mi = Math.min(mi, v); ma = Math.max(ma, v); } }));
        if (!isFinite(mi)) { mi = 0; ma = 1; } const m = (ma - mi || Math.abs(ma) || 1) * 0.08; return [mi - m, ma + m]; };
      const E = uni.map((u, k) => { const [mi, ma] = rango(u, k); return { u, mi, ma, y: (v) => T + (1 - (v - mi) / (ma - mi)) * ih }; });
      const ey = (s) => E[Math.max(0, uni.indexOf(s.unidad || ''))] || E[0];
      const x = (t) => L + (t - t0) / (t1 - t0) * iw, dec = (k) => Math.max(0, Math.min(3, Math.ceil(-Math.log10(((E[k].ma - E[k].mi) / 4) || 1)) + 1));
      let g = '';
      if (E.length) for (let k = 0; k <= 4; k++) { const v = E[0].mi + (E[0].ma - E[0].mi) * k / 4, y = E[0].y(v).toFixed(1); g += `<line x1="${L}" x2="${W - R}" y1="${y}" y2="${y}" stroke="#e3eaf2"/><text x="${L - 5}" y="${+y + 4}" text-anchor="end">${num(v, dec(0))}</text>`;
        if (dos) { const v2 = E[1].mi + (E[1].ma - E[1].mi) * k / 4; g += `<text x="${W - R + 5}" y="${+y + 4}">${num(v2, dec(1))}</text>`; } }
      const sp = t1 - t0, largo = sp > 36 * 3600e3, nx = W < 520 ? 4 : 6;
      for (let k = 0; k <= nx; k++) { const t = t0 + sp * k / nx, xx = x(t).toFixed(1), d = new Date(t);
        const lab = largo ? d.toLocaleString('es-AR', sp > 8 * 86400e3 ? { day: '2-digit', month: '2-digit' } : { day: '2-digit', month: '2-digit', hour: '2-digit' }) : d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
        g += `<line x1="${xx}" x2="${xx}" y1="${T}" y2="${T + ih}" stroke="#eef2f7"/><text x="${xx}" y="${H - 7}" text-anchor="${k === 0 ? 'start' : k === nx ? 'end' : 'middle'}">${lab}</text>`; }
      if (E.length) g += `<text x="${L - 5}" y="${T - 2}" text-anchor="end" style="font-weight:600">${h(E[0].u)}</text>` + (dos ? `<text x="${W - R + 5}" y="${T - 2}" style="font-weight:600">${h(E[1].u)}</text>` : '');
      let bandas = '';
      if (E.length) (o.bandas || []).forEach((b) => { const y1 = E[0].y(b.a ?? E[0].ma), y0 = E[0].y(b.de ?? E[0].mi), ya = Math.max(T, Math.min(y1, y0)), yb = Math.min(T + ih, Math.max(y1, y0));
        if (yb > ya) bandas += `<rect x="${L}" y="${ya.toFixed(1)}" width="${iw}" height="${(yb - ya).toFixed(1)}" fill="${b.color}" opacity=".1"/>`;
        [b.de, b.a].forEach((v) => { if (v != null && E[0].y(v) >= T && E[0].y(v) <= T + ih) bandas += `<line x1="${L}" x2="${W - R}" y1="${E[0].y(v).toFixed(1)}" y2="${E[0].y(v).toFixed(1)}" stroke="${b.color}" stroke-dasharray="5 4" opacity=".8"/>`; }); });
      const corte = Math.max(2.5 * (o.paso_s || 300) * 1000, (t1 - t0) / 150);
      let lineas = '';
      vis.forEach((s) => { const e = ey(s); let d = '', banda = [], prev = null; const tramos = [], sueltos = [];
        // cada serie corta la línea donde le faltan datos según su propio ritmo (un historial cada hora no es un hueco)
        const dif = s.T.slice(1).map((t, i) => t - s.T[i]).sort((a, b) => a - b), cs = Math.max(corte, 3 * (dif[dif.length >> 1] || 0));
        s.pts.forEach((p, i) => { const t = s.T[i], X = x(t).toFixed(1); if (prev == null || t - prev > cs) { if (i && (i === 1 || s.T[i - 1] - (s.T[i - 2] ?? -Infinity) > cs)) sueltos.push(i - 1); d += `M${X},${e.y(p[1]).toFixed(1)}`; if (banda.length) tramos.push(banda); banda = []; } else d += `L${X},${e.y(p[1]).toFixed(1)}`; banda.push([X, e.y(p[2]).toFixed(1), e.y(p[3]).toFixed(1)]); prev = t; });
        if (banda.length) tramos.push(banda);
        if (s.pts.length > 1 && s.T.length > 1 && s.T[s.T.length - 1] - s.T[s.T.length - 2] > cs) sueltos.push(s.T.length - 1);
        lineas += sueltos.map((k) => `<circle cx="${x(s.T[k]).toFixed(1)}" cy="${e.y(s.pts[k][1]).toFixed(1)}" r="2.4" fill="${s.color}"/>`).join('');
        if (vis.length === 1) tramos.forEach((b) => { if (b.length > 1) lineas += `<polygon points="${b.map((q) => q[0] + ',' + q[2]).join(' ')} ${b.slice().reverse().map((q) => q[0] + ',' + q[1]).join(' ')}" fill="${s.color}" opacity=".13"/>`; });
        lineas += s.pts.length > 1 ? `<path d="${d}" fill="none" stroke="${s.color}" stroke-width="1.8" stroke-linejoin="round"/>` : s.pts.length ? `<circle cx="${x(s.T[0])}" cy="${e.y(s.pts[0][1])}" r="3.5" fill="${s.color}"/>` : ''; });
      const marca = st.marca ? (() => { const t = tms(st.marca); if (t < t0 || t > t1) return ''; const X = x(t).toFixed(1); return `<line x1="${X}" x2="${X}" y1="${T}" y2="${T + ih}" stroke="#ef6c00" stroke-width="1.6"/><text x="${Math.min(W - R - 2, +X + 4)}" y="${T + 10}" text-anchor="${+X > W - 120 ? 'end' : 'start'}" style="fill:#e65100;font-weight:700">${h(fcorta(st.marca))}</text>`; })() : '';
      cont.innerHTML = `<div class="pa-graf"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Curva">${bandas}${g}${lineas}${marca}<line class="cur" y1="${T}" y2="${T + ih}" stroke="#607d8b" stroke-dasharray="3 3" visibility="hidden"/>${vis.map((s, i) => `<circle class="cp" data-i="${i}" r="3.6" fill="${s.color}" stroke="#fff" stroke-width="1.4" visibility="hidden"/>`).join('')}</svg><div class="pa-ley"></div></div>
        ${S.length > 1 ? `<div class="pa-sw">${S.map((s, i) => `<button data-i="${i}" class="${st.ocultas.has(i) ? 'off' : ''}" title="Mostrar u ocultar"><i style="background:${s.color}"></i>${h(s.n)}</button>`).join('')}</div>` : ''}
        ${vis.every((s) => !s.pts.length) ? '<p class="mini">Sin lecturas en ese período.</p>' : ''}`;
      cont.querySelectorAll('.pa-sw button').forEach((b) => b.onclick = () => { const i = +b.dataset.i; st.ocultas.has(i) ? st.ocultas.delete(i) : st.ocultas.add(i); if (st.ocultas.size === S.length) st.ocultas.delete(i); dibujar(); });
      const svg = cont.querySelector('svg'), ley = cont.querySelector('.pa-ley'), cerca = (s, t) => { let a = 0, b = s.T.length - 1; if (b < 0) return -1; while (b - a > 1) { const m = (a + b) >> 1; if (s.T[m] < t) a = m; else b = m; } return Math.abs(s.T[a] - t) <= Math.abs(s.T[b] - t) ? a : b; };
      const tEn = (ev) => { const r = svg.getBoundingClientRect(), cx = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left; return [t0 + ((cx / r.width * W) - L) / iw * (t1 - t0), cx, r]; };
      const mover = (ev) => { const [t, cx, r] = tEn(ev); if (t < t0 || t > t1) return salir();
        let filas = '', tt = null; vis.forEach((s, i) => { const k = cerca(s, t); const c = svg.querySelector(`.cp[data-i="${i}"]`); if (k < 0 || Math.abs(s.T[k] - t) > corte) { c.setAttribute('visibility', 'hidden'); return; }
          tt = tt ?? s.pts[k][0]; c.setAttribute('cx', x(s.T[k])); c.setAttribute('cy', ey(s).y(s.pts[k][1])); c.setAttribute('visibility', 'visible');
          filas += `<div><i style="background:${s.color}"></i>${h(s.n)} <b style="margin-left:auto">${num(s.pts[k][1], s.dec ?? 2)} ${h(s.unidad || '')}</b></div>`; });
        const X = L + (t - t0) / (t1 - t0) * iw, cur = svg.querySelector('.cur'); cur.setAttribute('x1', X); cur.setAttribute('x2', X); cur.setAttribute('visibility', 'visible');
        if (!filas) { ley.style.display = 'none'; return; }
        ley.innerHTML = `<div style="font-weight:700;margin-bottom:2px">${h(fcorta(tt))}</div>${filas}`; ley.style.display = 'block';
        const lw = ley.offsetWidth; ley.style.left = Math.max(0, Math.min(r.width - lw, cx + (cx > r.width / 2 ? -lw - 12 : 12))) + 'px'; ley.style.top = '8px'; };
      const salir = () => { ley.style.display = 'none'; svg.querySelectorAll('.cur,.cp').forEach((e) => e.setAttribute('visibility', 'hidden')); };
      svg.onmousemove = mover; svg.ontouchstart = mover; svg.ontouchmove = mover; svg.onmouseleave = salir;
      svg.onclick = (ev) => { const [t] = tEn(ev); if (t < t0 || t > t1 || !vis.length) return; const s = vis.find((q) => q.pts.length); if (!s) return; st.marca = s.pts[cerca(s, t)][0]; dibujar(); o.alMarcar?.(st.marca); };
    };
    dibujar();
    return { marcar(ts) { st.marca = ts; dibujar(); }, dibujar };
  }
  // pide varias señales de un panel y las dibuja; devuelve los datos (para CSV y para marcar)
  async function cargarCurvas(cont, pid, sel, P, extra = {}) {
    cont.innerHTML = '<p class="mini">Cargando…</p>';
    const J = await api('GET', `m/paneles/curvas/${pid}?p=${encodeURIComponent(sel.map((s) => s.clave).join(','))}&${qsPer(P)}${extra.n ? '&n=' + extra.n : ''}`);
    const S = J.series.map((x, i) => ({ n: sel[i].n || x.etiqueta || x.punto, unidad: sel[i].unidad ?? x.unidad, dec: sel[i].dec ?? 2, color: sel[i].color, pts: x.puntos, resumen: x.resumen, clave: x.clave }));
    const C = curvaT(cont, S, { t0: tms(J.desde), t1: tms(J.hasta), paso_s: J.paso_s, bandas: extra.bandas, marca: extra.marca, alMarcar: extra.alMarcar });
    return { J, S, C };
  }
  const csvCurvas = (nombre, R) => { if (!R) return; const ts = [...new Set(R.S.flatMap((s) => s.pts.map((p) => p[0])))].sort();
    const m = R.S.map((s) => new Map(s.pts.map((p) => [p[0], p[1]])));
    csv(`${nombre}_${R.J.desde.slice(0, 10)}_a_${R.J.hasta.slice(0, 10)}`, [{ t: 'Fecha', v: (t) => t }, ...R.S.map((s, i) => ({ t: `${s.n} (${s.unidad || ''})`, v: (t) => m[i].has(t) ? String(m[i].get(t)).replace('.', ',') : '' }))], ts); };

  // ------------------------------------------------------------------ tablero de inicio
  Nucleo.tablero(async (el) => {
    if (!puede('paneles.ver')) { el.remove(); return; }
    const r = (await api('GET', 'm/paneles/resumen')).resumen;
    if (!r.paneles) { el.remove(); return; }
    el.innerHTML = `<div class="tarjeta"><h2>Instalaciones</h2>${kpis([{ t: 'Señales', n: r.senales, sub: `${r.paneles} panel${r.paneles === 1 ? '' : 'es'}`, icono: 'paneles', color: '#1e88e5', k: 'todas' },
      { t: 'En alarma', n: r.alarma + r.alarmas_activas, sub: r.alarmas_activas ? `${r.alarmas_activas} de equipos` : 'ahora', icono: 'alarmas', color: '#e53935', k: 'alarma' }, { t: 'Fuera de rango', n: r.fuera, sub: 'ahora', icono: 'telemetria', color: '#fb8c00', k: 'fuera' },
      { t: 'Sin datos', n: r['sin-datos'], sub: 'no informan', icono: 'reloj', color: '#78909c', k: 'sin-datos' }])}</div>`;
    alClicKpi(el, (k) => { filtroPortada = k === 'todas' ? '' : k; Nucleo.ir('paneles'); });
  }, { id: 'paneles.resumen', titulo: 'Instalaciones' });

  Nucleo.pestana({ id: 'paneles', titulo: 'Paneles IoT', orden: 42, cap: 'paneles.ver', icono: 'paneles', desc: 'Áreas con tableros de las instalaciones: energía, climatización, ósmosis, quirófanos…', render: async (el) => {
    clearInterval(reloj);
    const a = Nucleo.parametros.get('area');
    if (!a) return portada(el);
    return vistaArea(el, a, Nucleo.parametros.get('panel'));
  } });

  // ------------------------------------------------------------------ portada: una tarjeta por área (como el centro de Capacitaciones)
  async function portada(el) {
    const J = await api('GET', 'm/paneles/portada'); const A = J.areas;
    const tot = { ok: 0, fuera: 0, alarma: 0, 'sin-datos': 0, act: 0 }; A.forEach((a) => { Object.keys(a.cuenta).forEach((k) => tot[k] += a.cuenta[k]); tot.act += a.alarmas_activas; });
    const tiene = (a, k) => !k || (k === 'alarma' ? a.cuenta.alarma + a.alarmas_activas > 0 : a.cuenta[k] > 0);
    const pintar = () => {
      el.innerHTML = `${A.length ? kpis([{ t: 'Áreas', n: A.length, sub: 'que podés ver', icono: 'paneles', color: '#1e88e5', k: 'todas', activo: !filtroPortada },
        { t: 'En alarma', n: tot.alarma + tot.act, sub: tot.act ? `${tot.act} informadas por equipos` : 'señales', icono: 'alarmas', color: '#e53935', k: 'alarma', activo: filtroPortada === 'alarma' },
        { t: 'Fuera de rango', n: tot.fuera, sub: 'señales', icono: 'telemetria', color: '#fb8c00', k: 'fuera', activo: filtroPortada === 'fuera' },
        { t: 'Sin datos', n: tot['sin-datos'], sub: 'no informan', icono: 'reloj', color: '#78909c', k: 'sin-datos', activo: filtroPortada === 'sin-datos' },
        { t: 'Normales', n: tot.ok, sub: 'señales', icono: 'ok', color: '#43a047', k: 'ok', activo: filtroPortada === 'ok' }]) : ''}
        ${A.length ? `<div class="pa-areas">${A.map((a) => { const c = a.cuenta, pn = a.paneles.length;
          return `<button class="pa-area ${tiene(a, filtroPortada) ? '' : 'tenue'}" data-a="${h(a.clave)}" style="--ac:${h(a.color)}"><div class="pa-ai"><span>${ico(a.icono)}</span><div><b>${h(a.nombre)}</b>${a.activo ? '' : ' <span class="et gris">oculta</span>'}<p>${pn ? `${pn} panel${pn === 1 ? '' : 'es'}` : 'sin paneles todavía'}</p></div></div>
            ${a.descripcion ? `<p>${h(a.descripcion)}</p>` : ''}<div class="pa-chips">${c.alarma + a.alarmas_activas ? `<i class="al">${c.alarma + a.alarmas_activas} en alarma</i>` : ''}${c.fuera ? `<i class="fu">${c.fuera} fuera de rango</i>` : ''}${c['sin-datos'] ? `<i>${c['sin-datos']} sin datos</i>` : ''}${c.ok ? `<i class="ok">${c.ok} normales</i>` : ''}</div>
            <p>${a.paneles.map((p) => h(p.nombre)).join(' · ')}</p></button>`; }).join('')}</div>`
          : `<div class="tarjeta"><p class="mini">${J.configura ? 'Todavía no hay áreas. Se crean en Configuración › Paneles IoT (o importando un paquete).' : 'Todavía no hay paneles para tu usuario.'}</p></div>`}
        ${J.configura ? `<div class="acciones"><button class="b sec" id="paNuevo">＋ Nuevo panel</button></div>` : ''}`;
      alClicKpi(el, (k) => { filtroPortada = k === 'todas' || filtroPortada === k ? '' : k; pintar(); });
      el.querySelectorAll('.pa-area').forEach((b) => b.onclick = () => Nucleo.ir('paneles?area=' + encodeURIComponent(b.dataset.a)));
      if (el.querySelector('#paNuevo')) el.querySelector('#paNuevo').onclick = () => editar(null, null, () => Nucleo.ir('paneles'));
    };
    pintar();
    reloj = setInterval(async () => { if (!el.isConnected || !el.querySelector('.pa-areas')) return clearInterval(reloj); if (document.hidden || document.querySelector('#panel.abierto')) return;
      try { const j = await api('GET', 'm/paneles/portada'); A.splice(0, A.length, ...j.areas); Object.keys(tot).forEach((k) => tot[k] = 0); A.forEach((a) => { Object.keys(a.cuenta).forEach((k) => tot[k] += a.cuenta[k]); tot.act += a.alarmas_activas; }); pintar(); } catch (e) { /* sin red */ } }, 30000);
  }

  // ------------------------------------------------------------------ un área: sus paneles en solapas, cada uno según su tipo
  async function vistaArea(el, clave, pSel) {
    const cfg = puede('paneles.configurar');
    let J; try { J = await api('GET', 'm/paneles/paneles?area=' + encodeURIComponent(clave)); } catch (e) { el.innerHTML = `<div class="aviso mal">${h(e.message)}</div><button class="b sec" id="paV">← Volver a las áreas</button>`; el.querySelector('#paV').onclick = () => Nucleo.ir('paneles'); return; }
    const info = clave === '-' ? { nombre: 'Otros paneles', color: '#607d8b', icono: 'paneles' } : ((await api('GET', 'm/paneles/portada')).areas.find((a) => a.clave === clave) || { nombre: clave, color: '#1e88e5', icono: 'paneles' });
    let P = J.paneles, actual = P.find((p) => String(p.id) === String(pSel))?.id ?? P.find((p) => p.activo)?.id ?? P[0]?.id ?? null, ctl = null;
    el.innerHTML = `<div class="pa-cab" style="--ac:${h(info.color)}"><button class="b sec" id="paV">${icono('izq')} Áreas</button><h2>${ico(info.icono)} ${h(info.nombre)}</h2><span class="sep"></span>${cfg ? `<button class="b sec" id="paN">＋ Nuevo panel</button>` : ''}</div>
      <div class="subp" id="paSub"></div><div id="paC"></div>`;
    el.querySelector('#paV').onclick = () => Nucleo.ir('paneles');
    if (el.querySelector('#paN')) el.querySelector('#paN').onclick = () => editar(null, clave === '-' ? null : clave, async (id) => { actual = id; await recargar(); pintar(); });
    const recargar = async () => { P = (await api('GET', 'm/paneles/paneles?area=' + encodeURIComponent(clave))).paneles; };
    const solapas = () => { el.querySelector('#paSub').innerHTML = P.map((p) => { const mal = p.cuenta.alarma + p.cuenta.fuera + p.alarmas_activas;
        return `<button data-p="${p.id}" class="${actual === p.id ? 'activo' : ''}" title="${h(p.descripcion || '')}">${h(p.nombre)}${p.activo ? '' : ' (oculto)'}${mal ? ` <em style="background:#e53935;color:#fff">${mal}</em>` : ''}</button>`; }).join('');
      el.querySelectorAll('#paSub button').forEach((b) => b.onclick = () => { actual = +b.dataset.p; history.replaceState(null, '', `#paneles?area=${encodeURIComponent(clave)}&panel=${actual}`); pintar(); }); };
    const pintar = () => {
      solapas(); const c = el.querySelector('#paC'), p = P.find((x) => x.id === actual);
      if (!p) { c.innerHTML = `<div class="tarjeta"><p class="mini">Esta área todavía no tiene paneles${cfg ? '. Tocá «＋ Nuevo panel».' : '.'}</p></div>`; ctl = null; return; }
      const R = { tarjetas: vTarjetas, energia: vEnergia, equipos: vEquipos, sinoptico: vSinoptico, eventos: vEventos }[p.tipo] || vTarjetas;
      c.innerHTML = ''; ctl = R(c, p, { cfg, editar: () => editar(p, clave === '-' ? null : clave, async () => { await recargar(); pintar(); }) }) || null;
    };
    pintar();
    // se actualiza solo cada 30 s mientras la pestaña esté a la vista y no haya un panel lateral abierto
    reloj = setInterval(async () => { if (!el.isConnected || !el.querySelector('#paC')) return clearInterval(reloj); if (document.hidden || document.querySelector('#panel.abierto')) return;
      try { await recargar(); solapas(); const p = P.find((x) => x.id === actual); if (p && ctl?.actualizar) ctl.actualizar(p); else if (p) pintar(); } catch (e) { /* sin red: se reintenta en el próximo paso */ } }, 30000);
  }
  const herrPanel = (p, x, extra = '') => `<div class="herr"><span class="cuenta">${h(p.descripcion || '')}</span><span class="sep"></span>${extra}${x.cfg ? `<button class="b sec" data-ed="1">${icono('config')} Configurar</button>` : ''}</div>`;
  const enlazarEd = (c, x) => { const b = c.querySelector('[data-ed]'); if (b) b.onclick = x.editar; };

  // ------------------------------------------------------------------ tipo «tarjetas»: señales sueltas con estado y curva de 24 h
  function vTarjetas(c, p, x) {
    let filtro = '';
    const pintar = (p) => {
      const its = p.items, cu = p.cuenta, lista = its.filter((i) => !filtro || i.estado === filtro);
      c.innerHTML = `${kpis([{ t: 'Señales', n: its.length, sub: 'de este panel', icono: 'paneles', color: '#1e88e5', k: 'todas', activo: !filtro }, { t: 'Normales', n: cu.ok, sub: 'en rango', icono: 'ok', color: '#43a047', k: 'ok', activo: filtro === 'ok' },
        { t: 'En alarma', n: cu.alarma, sub: 'ahora', icono: 'alarmas', color: '#e53935', k: 'alarma', activo: filtro === 'alarma' }, { t: 'Fuera de rango', n: cu.fuera, sub: 'ahora', icono: 'telemetria', color: '#fb8c00', k: 'fuera', activo: filtro === 'fuera' },
        { t: 'Sin datos', n: cu['sin-datos'], sub: 'no informan', icono: 'reloj', color: '#78909c', k: 'sin-datos', activo: filtro === 'sin-datos' }])}
        <div class="tarjeta">${herrPanel(p, x, filtro ? '<button class="b sec" id="pTodo">Ver todas</button>' : '')}
        ${lista.length ? `<div class="senales">${lista.map((i) => { const e = EST[i.estado];
          return `<button class="senal" data-i="${i.id}" style="--sc:${e[0]}"><div class="st"><b>${h(i.titulo)}</b><span class="et ${e[1]}">${e[2]}</span></div>
            <div class="sv">${num(i.valor, i.decimales)}<small>${h(i.unidad)}</small></div>
            <div class="sp"><span>${i.minimo != null || i.maximo != null ? `Normal: ${i.minimo != null ? num(i.minimo, i.decimales) : '…'} a ${i.maximo != null ? num(i.maximo, i.decimales) : '…'}` : h(i.equipo || '')}</span><span>${hace(i.ts)}</span></div>
            ${i.alarma_etiqueta ? `<div class="sp"><span>Alarma ${h(i.alarma_etiqueta)}</span></div>` : ''}${chispa(i.chispa, e[0])}</button>`; }).join('')}</div>`
          : `<p class="mini">${its.length ? 'Ninguna señal en ese estado.' : 'Este panel todavía no tiene señales.'}</p>`}</div>`;
      alClicKpi(c, (k) => { filtro = k === 'todas' || filtro === k ? '' : k; pintar(p); });
      c.querySelectorAll('.senal').forEach((b) => b.onclick = () => historial(its.find((i) => i.id === +b.dataset.i)));
      if (c.querySelector('#pTodo')) c.querySelector('#pTodo').onclick = () => { filtro = ''; pintar(p); };
      enlazarEd(c, x);
    };
    pintar(p);
    return { actualizar: pintar };
  }
  // historial de una señal (panel lateral): período, mínimo/promedio/máximo, curva y CSV
  function historial(i) {
    const PER = [['6', '6 h'], ['24', '24 h'], ['168', '7 días'], ['720', '30 días'], ['2160', '90 días'], ['8760', '1 año']];
    const pn = panel(i.titulo, `<p class="mini">${h([i.equipo, i.codigo, i.punto].filter(Boolean).join(' · '))}</p><div id="hP"></div><div id="hK"></div><div id="hG"></div><div class="herr" style="margin-top:10px"><span class="cuenta" id="hN"></span><span class="sep"></span><button class="b sec" id="hCsv">${icono('bajar')} Descargar CSV</button></div>`);
    const g = (s) => pn.querySelector(s), P = { horas: 24 }; let J = null;
    const ver = async () => {
      g('#hG').innerHTML = '<p class="mini">Cargando…</p>';
      try { J = await api('GET', `m/paneles/serie/${i.id}?${qsPer(P)}`); } catch (e) { g('#hG').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; return; }
      const R = J.resumen, d = J.item.decimales, u = J.item.unidad;
      g('#hK').innerHTML = R ? kpis([{ t: 'Mínimo', n: num(R.min, d), sub: `${u} · ${fcorta(R.min_ts)}`, icono: 'abajo', color: '#1e88e5', k: 'min' }, { t: 'Promedio', n: num(R.prom, d), sub: u, icono: 'telemetria', color: '#00897b' }, { t: 'Máximo', n: num(R.max, d), sub: `${u} · ${fcorta(R.max_ts)}`, icono: 'arriba', color: '#e53935', k: 'max' }]) : '';
      g('#hN').textContent = R ? `${R.lecturas} lectura${R.lecturas === 1 ? '' : 's'} · un punto cada ${J.paso_s >= 3600 ? (J.paso_s / 3600) + ' h' : (J.paso_s / 60) + ' min'}` : '';
      const bandas = [J.item.minimo, J.item.maximo].some((v) => v != null) ? [{ de: J.item.minimo, a: J.item.maximo, color: '#43a047' }] : [];
      const C = curvaT(g('#hG'), [{ n: i.titulo, unidad: u, dec: d, color: '#0d47a1', pts: J.puntos.map((q) => [q.ts, q.prom, q.min, q.max]) }], { t0: tms(J.desde), t1: tms(J.hasta), paso_s: J.paso_s, bandas });
      alClicKpi(g('#hK'), (k) => C.marcar(k === 'min' ? R.min_ts : R.max_ts));   // la tarjeta marca en la curva el día y la hora de ese valor
    };
    selPeriodo(g('#hP'), P, ver, PER);
    g('#hCsv').onclick = () => J && csv(`${i.titulo}_${J.desde.slice(0, 10)}_a_${J.hasta.slice(0, 10)}`, [{ t: 'Fecha', v: (q) => q.ts }, { t: `Promedio (${J.item.unidad})`, v: (q) => String(q.prom).replace('.', ',') },
      { t: 'Mínimo', v: (q) => String(q.min).replace('.', ',') }, { t: 'Máximo', v: (q) => String(q.max).replace('.', ',') }, { t: 'Lecturas', v: (q) => q.n }], J.puntos);
    ver();
  }

  // ------------------------------------------------------------------ tipo «energia»: un medidor con referencias, curvas e informe
  const semDe = (r, v) => { if (v == null) return 'sin-datos'; if (!r) return null; const en = (z) => Array.isArray(z) && (z[0] == null || v >= z[0]) && (z[1] == null || v <= z[1]); return en(r.verde) ? 'verde' : en(r.amarillo) ? 'amarillo' : 'rojo'; };
  const bandasDe = (r) => r ? [r.amarillo && { de: r.amarillo[0], a: r.amarillo[1], color: '#f9a825' }, r.verde && { de: r.verde[0], a: r.verde[1], color: '#43a047' }].filter(Boolean) : [];
  function vEnergia(c, p0, x) {
    let p = p0; const V = p.vista || {}, REF = V.referencias || {}, act = V.activo;
    const CUR = (V.curvas || []).map((k, i) => ({ id: k.id || String(i), titulo: k.titulo, puntos: k.puntos, unidad: k.unidad }));
    const S = { P: { horas: 24 }, curvas: CUR.length ? [CUR[0].id] : [], tmp: null, R: null, stats: {} };
    const et = (pt) => p.puntos.find((q) => q.punto === pt);
    const cartas = () => p.items.length ? p.items.map((i) => ({ punto: i.punto, titulo: i.titulo, valor: i.valor, unidad: i.unidad, dec: i.decimales, ts: i.ts, estado: i.estado }))
      : Object.keys(REF).map((k) => { const q = et(k); return { punto: k, titulo: REF[k].titulo || q?.etiqueta || k, valor: q?.valor ?? null, unidad: REF[k].unidad || q?.unidad || '', dec: REF[k].decimales ?? 2, ts: q?.ts, estado: q?.estado || 'sin-datos' }; });
    const colorCarta = (k) => k.estado === 'alarma' ? EST.alarma[0] : k.estado === 'sin-datos' ? EST['sin-datos'][0] : SEM[semDe(REF[k.punto], k.valor)]?.[0] || EST[k.estado][0];
    c.innerHTML = `<div id="eE"></div><div class="tarjeta">${herrPanel(p, x)}<div class="pa-cards" id="eC"></div>
      <h3 style="margin:16px 0 4px">Curvas</h3><div class="pa-cs" id="eS"></div><div id="eP"></div><div id="eG"></div>
      <div class="herr" style="margin-top:8px"><span class="cuenta" id="eN"></span><span class="sep"></span><button class="b sec" id="eCsv">${icono('bajar')} CSV de la curva</button></div></div><div id="eV"></div>`;
    enlazarEd(c, x); const g = (s) => c.querySelector(s);
    const estado = () => {
      const ks = cartas().filter((k) => REF[k.punto]); const n = { verde: 0, amarillo: 0, rojo: 0, 'sin-datos': 0 }; ks.forEach((k) => n[k.estado === 'sin-datos' ? 'sin-datos' : semDe(REF[k.punto], k.valor)]++);
      const peor = n.rojo ? 'rojo' : n.amarillo ? 'amarillo' : n.verde ? 'verde' : 'sin-datos', ult = p.puntos.reduce((a, q) => (!a || q.ts > a ? q.ts : a), null);
      g('#eE').innerHTML = `<div class="pa-est" style="--sc:${SEM[peor][0]}"><span class="pa-dot"></span><b>Estado general: ${SEM[peor][2]}</b><span class="mini">${n.verde} en verde · ${n.amarillo} en atención · ${n.rojo} fuera de referencia${n['sin-datos'] ? ` · ${n['sin-datos']} sin datos` : ''} · ${act ? h(act) + ' · ' : ''}actualizado ${hace(ult)}</span>
        <span class="sep"></span><button class="b sec" id="eI">${icono('imprimir')} Informe</button><button class="b sec" id="eW">${icono('bajar')} Informe en Word</button></div><div id="eIM"></div>`;
      g('#eI').onclick = () => informeEnergia(p, S.P, false, g('#eIM')); g('#eW').onclick = () => informeEnergia(p, S.P, true, g('#eIM'));
    };
    const tarjetas = () => { g('#eC').innerHTML = cartas().map((k) => { const r = REF[k.punto], s = S.stats[k.punto], sm = semDe(r, k.valor);
        return `<div class="pa-c ${S.tmp === k.punto ? 'sel' : ''}" data-p="${h(k.punto)}" style="--sc:${colorCarta(k)}" role="button" tabindex="0"><div class="t"><b title="${h(k.titulo)}">${h(k.titulo)}</b><span>${k.estado === 'sin-datos' ? 'sin datos' : sm ? SEM[sm][2] : ''}</span></div>
          <div class="v">${num(k.valor, k.dec)}<small>${h(k.unidad)}</small></div><div class="r">${r?.verde ? `Ref. ${num(r.verde[0], k.dec)} a ${num(r.verde[1], k.dec)}` : hace(k.ts)}</div>
          ${s ? `<div class="mm"><span data-m="${h(s.min_ts)}" title="Marcar en la curva">mín ${num(s.min, k.dec)} · ${fcorta(s.min_ts)}</span><span data-m="${h(s.max_ts)}" title="Marcar en la curva">máx ${num(s.max, k.dec)} · ${fcorta(s.max_ts)}</span></div>` : ''}</div>`; }).join('') || '<p class="mini">Sin tarjetas: agregá «referencias» o señales al panel.</p>';
      g('#eC').querySelectorAll('.pa-c').forEach((d) => { const elegir = (marca) => { S.tmp = d.dataset.p; tarjetas(); chips(); curva(marca); };
        d.onclick = (e) => { const m = e.target.closest('[data-m]'); elegir(m ? m.dataset.m : null); }; d.onkeydown = (e) => { if (e.key === 'Enter') elegir(null); }; }); };
    const chips = () => { g('#eS').innerHTML = (S.tmp ? `<button class="activo" data-t="1">${h(cartas().find((k) => k.punto === S.tmp)?.titulo || S.tmp)} ✕</button>` : '') + CUR.map((k) => `<button data-c="${h(k.id)}" class="${!S.tmp && S.curvas.includes(k.id) ? 'activo' : ''}">${h(k.titulo)}</button>`).join('');
      g('#eS').querySelectorAll('button').forEach((b) => b.onclick = () => { if (b.dataset.t) { S.tmp = null; } else { const id = b.dataset.c; if (S.tmp) { S.tmp = null; S.curvas = [id]; }
          else if (S.curvas.includes(id)) { if (S.curvas.length > 1) S.curvas = S.curvas.filter((z) => z !== id); } else { const uni = new Set(S.curvas.map((z) => CUR.find((k) => k.id === z)?.unidad || '')); uni.add(CUR.find((k) => k.id === id)?.unidad || ''); S.curvas = uni.size > 2 ? [id] : [...S.curvas, id]; } }
        tarjetas(); chips(); curva(); }); };
    const seleccion = () => {
      const pts = S.tmp ? [{ pt: S.tmp, u: undefined }] : S.curvas.flatMap((id) => { const k = CUR.find((z) => z.id === id); return (k?.puntos || []).map((pt) => ({ pt, u: k.unidad, varias: (k.puntos.length > 1 || S.curvas.length > 1) ? k.titulo : null })); });
      return pts.slice(0, 16).map((z) => { const q = et(z.pt), k = cartas().find((y) => y.punto === z.pt); return { clave: `${act}|${z.pt}`, n: k?.titulo || REF[z.pt]?.titulo || q?.etiqueta || z.pt, unidad: z.u ?? q?.unidad, dec: k?.dec ?? REF[z.pt]?.decimales ?? 2, pt: z.pt }; });
    };
    const curva = async (marca) => {
      const sel = seleccion(); if (!sel.length) { g('#eG').innerHTML = '<p class="mini">Este panel no tiene curvas configuradas.</p>'; return; }
      const r0 = REF[sel[0].pt], mismo = r0 && sel.every((s) => JSON.stringify(REF[s.pt]?.verde) === JSON.stringify(r0.verde));
      try { S.R = await cargarCurvas(g('#eG'), p.id, sel, S.P, { bandas: mismo ? bandasDe(r0) : [], marca }); g('#eN').textContent = `${txtPer(S.P)} · un punto cada ${S.R.J.paso_s >= 3600 ? S.R.J.paso_s / 3600 + ' h' : S.R.J.paso_s / 60 + ' min'}`; }
      catch (e) { g('#eG').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; }
    };
    // mínimo y máximo del período de cada tarjeta, con el día y la hora en que ocurrieron
    const stats = async () => { const ks = cartas().slice(0, 16); if (!ks.length || !act) return;
      try { const J = await api('GET', `m/paneles/curvas/${p.id}?p=${encodeURIComponent(ks.map((k) => `${act}|${k.punto}`).join(','))}&${qsPer(S.P)}&n=300`); S.stats = {}; J.series.forEach((s, i) => { if (s.resumen) S.stats[ks[i].punto] = s.resumen; }); tarjetas(); } catch (e) { /* las tarjetas siguen sin mín/máx */ } };
    const variables = () => { g('#eV').innerHTML = p.puntos.length ? Nucleo.seccion(`Todas las variables del medidor (${p.puntos.length})`, `<table class="pa-tab"><tr><th>Variable</th><th>Señal</th><th style="text-align:right">Valor</th><th>Unidad</th><th>Referencia</th></tr>${p.puntos.map((q) => { const sm = semDe(REF[q.punto], q.valor);
      return `<tr><td>${h(q.etiqueta || q.punto)}</td><td class="mini">${h(q.punto)}</td><td class="n">${num(q.valor, 2)}</td><td>${h(q.unidad)}</td><td>${sm ? `<span class="et ${SEM[sm][1]}">${SEM[sm][2]}</span>` : ''}</td></tr>`; }).join('')}</table><p class="mini">Actualizado ${hace(p.puntos.reduce((a, q) => (!a || q.ts > a ? q.ts : a), null))}.</p>`) : ''; };
    g('#eCsv').onclick = () => csvCurvas(`energia_${p.nombre}`, S.R);
    selPeriodo(g('#eP'), S.P, () => { curva(); stats(); });
    estado(); tarjetas(); chips(); curva(); stats(); variables();
    return { actualizar(n) { p = n; estado(); tarjetas(); if (!document.querySelector('.desp[open]')) variables(); if (!S.P.desde && S.P.horas <= 6) curva(); } };
  }
  // Informe de energía (estilo certificación): imprimir o Word (.docx que arma el núcleo)
  async function informeEnergia(p, P, word, m) {
    aviso(m, 'info', 'Armando el informe…');
    let J; try { J = await api('GET', `m/paneles/informe/${p.id}?${qsPer(P.horas && P.horas < 24 ? { horas: 24 } : P)}`); } catch (e) { return aviso(m, 'mal', e.message); }
    const nodo = Nucleo.sesion?.nodo?.nombre || '', d = (s) => s ? fcorta(s) : '—', ref = (v) => v.verde ? `${num(v.verde[0], v.decimales)} a ${num(v.verde[1], v.decimales)} ${v.unidad}` : '—';
    const pc = (v) => `${num(v.porcentaje.verde, 1)} / ${num(v.porcentaje.amarillo, 1)} / ${num(v.porcentaje.rojo, 1)}`;
    const filas = J.variables.map((v) => [v.titulo, ref(v), v.resumen ? num(v.resumen.prom, v.decimales) : '—', v.resumen ? `${num(v.resumen.min, v.decimales)} (${d(v.resumen.min_ts)})` : '—', v.resumen ? `${num(v.resumen.max, v.decimales)} (${d(v.resumen.max_ts)})` : '—', pc(v), SEM[v.estado][2]]);
    const E = J.energia, fE = E ? [['Energía consumida', `${num(E.kwh, 1)} kWh${E.por_contador ? ` (contador del medidor; por integración de la potencia: ${num(E.kwh_integrado, 1)} kWh)` : ' (integración de la potencia)'}`], ['Demanda máxima', `${num(E.demanda_max, 1)} ${E.unidad} (${d(E.demanda_max_ts)})`], ['Demanda promedio', `${num(E.demanda_prom, 1)} ${E.unidad}`],
      ...(E.fp_prom != null ? [['Factor de potencia promedio', num(E.fp_prom, 3)]] : []), ['Cobertura de datos del período', `${num(E.cobertura, 1)} %`]] : null;
    const tit = `Informe de energía · ${p.nombre}`, sub = `${nodo} · medidor ${J.activo} · período ${d(J.desde)} a ${d(J.hasta)} · emitido el ${fecha(new Date().toISOString())}`;
    const CAB = ['Variable', 'Referencia (verde)', 'Promedio', 'Mínimo (fecha)', 'Máximo (fecha)', '% verde / amarillo / rojo', 'Estado'], FIR = ['Responsable de mantenimiento', 'Ingeniería / Bioingeniería', 'Auditoría'];
    const criterio = 'Cada variable se evalúa por intervalos del período: «Normal» si al menos el 95 % del tiempo estuvo en la referencia verde; «Atención» si el 95 % estuvo dentro de verde o amarillo; si no, «Fuera de referencia».';
    if (word) {
      const b = [{ tipo: 'h2', texto: `Estado general: ${SEM[J.estado][2]}` }, { tipo: 'p', texto: criterio }, { tipo: 'tabla', cab: CAB, filas: filas.map((f) => f.map(String)) }];
      if (fE) b.push({ tipo: 'h2', texto: 'Energía' }, { tipo: 'tabla', cab: null, filas: fE });
      b.push({ tipo: 'h2', texto: 'Observaciones' }, { tipo: 'tabla', cab: null, filas: [['Observaciones', '\n\n\n']] }, { tipo: 'firmas', items: FIR });
      try { const r = await api('POST', 'docx', { modelo: { titulo: tit, subtitulo: sub, bloques: b }, archivo: `informe_energia_${p.nombre}_${J.desde.slice(0, 10)}_${J.hasta.slice(0, 10)}` });
        const bin = Uint8Array.from(atob(r.base64), (ch) => ch.charCodeAt(0)); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([bin], { type: r.tipo })); a.download = r.nombre; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000);
        aviso(m, 'ok', 'Informe descargado.'); } catch (e) { aviso(m, 'mal', e.message); }
      return;
    }
    const t = (cab, fl) => `<table>${cab ? `<tr>${cab.map((z) => `<th style="width:auto">${h(z)}</th>`).join('')}</tr>` : ''}${fl.map((r) => `<tr>${r.map((z, i) => !cab && i === 0 ? `<th>${h(z)}</th>` : `<td>${h(z)}</td>`).join('')}</tr>`).join('')}</table>`;
    const html = `<h1>${h(tit)}</h1><div>${h(sub)}</div><h2>Estado general: ${SEM[J.estado][2]}</h2><p>${h(criterio)}</p>${t(CAB, filas)}${fE ? `<h2>Energía</h2>${t(null, fE)}` : ''}
      <h2>Observaciones</h2><table><tr><th>Observaciones</th><td><br><br><br></td></tr></table><div class="firmas">${FIR.map((z) => `<div>${h(z)}</div>`).join('')}</div>`;
    document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const dv = document.createElement('div'); dv.className = 'imprimible soloimp'; dv.innerHTML = html; document.body.appendChild(dv); m.innerHTML = ''; window.print();
  }

  // ------------------------------------------------------------------ tipo «equipos»: grilla por sector; el detalle se abre aparte (no agranda las otras tarjetas)
  const estadoDe = (pts, tar = []) => !pts.length || (tar.length && !pts.some((q) => tar.includes(q.punto))) ? 'sin-datos' : pts.some((q) => q.estado === 'alarma') ? 'alarma' : pts.some((q) => q.estado === 'fuera') ? 'fuera' : pts.every((q) => q.estado === 'sin-datos') ? 'sin-datos' : 'ok';
  function vEquipos(c, p0, x) {
    let p = p0; const V = p.vista || {}, G = V.grupos?.length ? V.grupos : [{ titulo: '', activos: V.activos || [] }], TAR = V.tarjeta || [], ETQ = V.etiquetas || {}, NOM = V.nombres || {};
    const S = { filtro: '', q: '', sel: null, tab: 'tend', P: { horas: 24 }, curva: 0, tmp: null, R: null };
    const por = () => { const m = new Map(); p.puntos.forEach((q) => { if (!m.has(q.codigo)) m.set(q.codigo, []); m.get(q.codigo).push(q); }); return m; };
    const nombre = (cod, pts) => NOM[cod] || pts?.[0]?.equipo || cod, etq = (q) => ETQ[q.punto] || q.etiqueta || q.punto;
    const pintar = () => {
      const M = por(), todos = G.flatMap((g) => g.activos), cu = { ok: 0, fuera: 0, alarma: 0, 'sin-datos': 0 }; todos.forEach((a) => cu[estadoDe(M.get(a) || [], TAR)]++);
      const pasa = (a) => { const pts = M.get(a) || []; return (!S.filtro || estadoDe(pts, TAR) === S.filtro) && (!S.q || (nombre(a, pts) + ' ' + a).toLowerCase().includes(S.q)); };
      c.innerHTML = `${kpis([{ t: 'Equipos', n: todos.length, sub: 'en este panel', icono: 'paneles', color: '#1e88e5', k: 'todas', activo: !S.filtro }, { t: 'Normales', n: cu.ok, sub: 'informando', icono: 'ok', color: '#43a047', k: 'ok', activo: S.filtro === 'ok' },
        { t: 'En alarma', n: cu.alarma, sub: p.alarmas_activas ? `${p.alarmas_activas} alarmas activas` : 'ahora', icono: 'alarmas', color: '#e53935', k: 'alarma', activo: S.filtro === 'alarma' }, { t: 'Fuera de rango', n: cu.fuera, sub: 'alguna variable', icono: 'telemetria', color: '#fb8c00', k: 'fuera', activo: S.filtro === 'fuera' },
        { t: 'Sin datos', n: cu['sin-datos'], sub: 'no informan', icono: 'reloj', color: '#78909c', k: 'sin-datos', activo: S.filtro === 'sin-datos' }])}
        <div class="tarjeta">${herrPanel(p, x, `<input type="search" id="qE" placeholder="Buscar equipo" value="${h(S.q)}" style="max-width:220px">`)}
        ${G.map((gr, gi) => { const vis = gr.activos.filter(pasa); if (!vis.length) return '';
          return `<div class="pa-gr">${gr.titulo ? `<h3>${h(gr.titulo)}${gr.nota ? ` <small>· ${h(gr.nota)}</small>` : ''}</h3>` : ''}<div class="pa-eqs">${vis.map((a) => { const pts = M.get(a) || [], e = EST[estadoDe(pts, TAR)], sinVar = TAR.length && !pts.some((q) => TAR.includes(q.punto));
            return `<button class="pa-eq ${S.sel === a ? 'sel' : ''}" data-a="${h(a)}" style="--sc:${e[0]}"><div class="n"><b>${h(nombre(a, pts))}</b><span class="et ${e[1]}">${e[2]}</span></div><div class="mini">${h(a)}${pts[0] ? ' · ' + hace(pts.reduce((z, q) => (!z || q.ts > z ? q.ts : z), null)) : ''}</div>
              ${sinVar ? `<p class="mini" style="margin:6px 0 0">No informa sus variables${pts.length ? ' (solo el estado de comunicación)' : ''}.</p>` : ''}<dl>${(sinVar ? [] : TAR.length ? TAR.map((t) => pts.find((q) => q.punto === t)).filter(Boolean) : pts.slice(0, 4)).map((q) => `<dt>${h(etq(q))}</dt><dd style="${q.estado === 'fuera' || q.estado === 'alarma' ? 'color:' + EST[q.estado][0] : ''}">${num(q.valor, 1)} ${h(q.unidad === '1' ? '' : q.unidad)}</dd>`).join('')}</dl></button>`; }).join('')}</div>
            ${S.sel && vis.includes(S.sel) ? `<div class="pa-det" id="dEq"></div>` : ''}</div>`; }).join('') || '<p class="mini">Ningún equipo con ese filtro.</p>'}</div>`;
      alClicKpi(c, (k) => { S.filtro = k === 'todas' || S.filtro === k ? '' : k; pintar(); });
      const q = c.querySelector('#qE'); q.oninput = () => { S.q = q.value.trim().toLowerCase(); const pos = q.selectionStart; pintar(); const n = c.querySelector('#qE'); n.focus(); n.setSelectionRange(pos, pos); };
      c.querySelectorAll('.pa-eq').forEach((b) => b.onclick = () => { S.sel = S.sel === b.dataset.a ? null : b.dataset.a; S.tmp = null; S.curva = 0; pintar(); if (S.sel) c.querySelector('#dEq')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
      enlazarEd(c, x); if (S.sel && c.querySelector('#dEq')) detalle(c.querySelector('#dEq'), S.sel, M.get(S.sel) || []);
    };
    const curvasDe = (pts) => { const hay = new Set(pts.map((q) => q.punto)); const l = (V.curvas || []).map((k) => ({ titulo: k.titulo, puntos: k.puntos.filter((z) => hay.has(z)) })).filter((k) => k.puntos.length);
      return l.length ? l : (TAR.length ? TAR : pts.slice(0, 4).map((q) => q.punto)).filter((z) => hay.has(z)).map((z) => ({ titulo: ETQ[z] || pts.find((q) => q.punto === z)?.etiqueta || z, puntos: [z] })); };
    function detalle(d, a, pts) {
      const tabs = [['tend', 'Tendencias'], ['var', `Variables (${pts.length})`], ...(V.analisis?.length ? [['ana', 'Análisis de fallas']] : [])];
      d.innerHTML = `<div class="pa-cab"><h2>${h(nombre(a, pts))}</h2><span class="mini">${h(a)}${pts[0]?.equipo && pts[0].equipo !== nombre(a, pts) ? ' · ' + h(pts[0].equipo) : ''}</span><span class="sep"></span><button class="b sec" id="dX" aria-label="Cerrar">✕</button></div>
        <div class="pa-tabs">${tabs.map(([k, t]) => `<button data-t="${k}" class="${S.tab === k ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="dC"></div>`;
      d.querySelector('#dX').onclick = () => { S.sel = null; pintar(); };
      d.querySelectorAll('.pa-tabs button').forEach((b) => b.onclick = () => { S.tab = b.dataset.t; detalle(d, a, pts); });
      const dc = d.querySelector('#dC');
      if (S.tab === 'var') { dc.innerHTML = `<table class="pa-tab"><tr><th>Variable</th><th>Señal</th><th style="text-align:right">Valor</th><th>Unidad</th><th>Actualizado</th><th>Estado</th></tr>${pts.map((q) => `<tr class="clic" data-p="${h(q.punto)}"><td>${h(etq(q))}</td><td class="mini">${h(q.punto)}</td><td class="n">${num(q.valor, 2)}</td><td>${h(q.unidad === '1' ? '' : q.unidad)}</td><td>${hace(q.ts)}</td><td><span class="et ${EST[q.estado][1]}">${EST[q.estado][2]}</span></td></tr>`).join('')}</table><p class="mini">Tocá una variable para ver su tendencia.</p>`;
        dc.querySelectorAll('tr[data-p]').forEach((tr) => tr.onclick = () => { S.tmp = tr.dataset.p; S.tab = 'tend'; detalle(d, a, pts); }); return; }
      if (S.tab === 'ana') return analisis(dc, a, pts);
      const CV = curvasDe(pts);
      dc.innerHTML = `<div class="pa-cs" id="dS"></div><div id="dP"></div><div id="dG"></div><div class="herr" style="margin-top:6px"><span class="sep"></span><button class="b sec" id="dCsv">${icono('bajar')} CSV</button></div>`;
      const ver = async () => { const pp = S.tmp ? [S.tmp] : CV[S.curva]?.puntos || []; if (!pp.length) { dc.querySelector('#dG').innerHTML = '<p class="mini">Este equipo no tiene variables para graficar.</p>'; return; }
        const r = pp.length === 1 ? (V.rangos || {})[pp[0]] : null;
        try { S.R = await cargarCurvas(dc.querySelector('#dG'), p.id, pp.map((z) => ({ clave: `${a}|${z}`, n: ETQ[z] || pts.find((q) => q.punto === z)?.etiqueta || z, dec: 2 })), S.P, { bandas: r ? [{ de: r[0], a: r[1], color: '#43a047' }] : [] }); } catch (e) { dc.querySelector('#dG').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; } };
      const chips = () => { dc.querySelector('#dS').innerHTML = (S.tmp ? `<button class="activo" data-t="1">${h(ETQ[S.tmp] || pts.find((q) => q.punto === S.tmp)?.etiqueta || S.tmp)} ✕</button>` : '') + CV.map((k, i) => `<button data-i="${i}" class="${!S.tmp && S.curva === i ? 'activo' : ''}">${h(k.titulo)}</button>`).join('');
        dc.querySelectorAll('#dS button').forEach((b) => b.onclick = () => { if (b.dataset.t) S.tmp = null; else { S.tmp = null; S.curva = +b.dataset.i; } chips(); ver(); }); };
      dc.querySelector('#dCsv').onclick = () => csvCurvas(`${nombre(a, pts)}`, S.R);
      selPeriodo(dc.querySelector('#dP'), S.P, ver); chips(); ver();
    }
    // análisis de fallas en el período: desvío respecto de la consigna, saturación (por ejemplo, válvula al 100 %) y tiempo fuera de rango
    async function analisis(dc, a, pts) {
      const A = (V.analisis || []).filter((r) => [r.real, r.consigna, r.punto].filter(Boolean).every((z) => pts.some((q) => q.punto === z)));
      dc.innerHTML = `<div id="aP"></div><div id="aK"></div><div id="aG"></div><p class="mini" id="aN"></p>`;
      if (!A.length) { dc.querySelector('#aK').innerHTML = '<p class="mini">Este equipo no informa las variables que usa el análisis.</p>'; return; }
      const calc = async () => {
        const pp = [...new Set(A.flatMap((r) => [r.real, r.consigna, r.punto].filter(Boolean)))].slice(0, 16); dc.querySelector('#aK').innerHTML = '<p class="mini">Calculando…</p>';
        let J; try { J = await api('GET', `m/paneles/curvas/${p.id}?p=${encodeURIComponent(pp.map((z) => `${a}|${z}`).join(','))}&${qsPer(S.P)}&n=1500`); } catch (e) { dc.querySelector('#aK').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; return; }
        const ser = Object.fromEntries(pp.map((z, i) => [z, new Map(J.series[i].puntos.map((q) => [q[0], q[1]]))])); const K = []; let marcaTs = null;
        A.forEach((r) => {
          if (r.tipo === 'desvio') { let n = 0, f = 0, mx = 0, mts = null, s = 0; ser[r.real].forEach((v, t) => { const cg = ser[r.consigna].get(t); if (cg == null) return; const dv = v - cg; n++; s += dv; if (Math.abs(dv) > (r.tolerancia ?? 1)) f++; if (Math.abs(dv) > Math.abs(mx)) { mx = dv; mts = t; } });
            K.push({ t: r.titulo || 'Desvío de la consigna', n: n ? `${num(f * 100 / n, 1)} %` : '—', sub: n ? `del tiempo fuera de ±${num(r.tolerancia ?? 1, 1)} · máx ${num(mx, 1)} · prom ${num(s / n, 2)}` : 'sin datos coincidentes', icono: 'telemetria', color: f / Math.max(1, n) > .2 ? '#e53935' : f ? '#fb8c00' : '#43a047', k: mts || '' }); }
          else if (r.tipo === 'saturacion' || r.tipo === 'rango') { const l = [...ser[r.punto]]; let f = 0, rach = 0, mr = 0, mts = null; const paso = J.paso_s / 3600;
            l.forEach(([t, v]) => { const malo = r.tipo === 'saturacion' ? v >= (r.mayor_que ?? 95) : ((r.minimo != null && v < r.minimo) || (r.maximo != null && v > r.maximo)); if (malo) { f++; rach++; if (rach > mr) { mr = rach; mts = t; } } else rach = 0; });
            K.push({ t: r.titulo || (r.tipo === 'saturacion' ? 'Saturación' : 'Fuera de rango'), n: l.length ? `${num(f * 100 / l.length, 1)} %` : '—', sub: l.length ? `del tiempo · racha más larga ${num(mr * paso, 1)} h` : 'sin datos', icono: 'alarmas', color: f / Math.max(1, l.length) > .2 ? '#e53935' : f ? '#fb8c00' : '#43a047', k: mts || '' }); }
        });
        dc.querySelector('#aK').innerHTML = kpis(K);
        dc.querySelector('#aN').textContent = `${txtPer(S.P)} · tocá un indicador para marcar en la curva el momento del peor desvío o de la racha más larga.`;
        const sel = [...new Set(A.flatMap((r) => [r.real, r.consigna, r.punto].filter(Boolean)))].map((z) => ({ clave: `${a}|${z}`, n: ETQ[z] || pts.find((q) => q.punto === z)?.etiqueta || z, dec: 1 }));
        const CS = curvaT(dc.querySelector('#aG'), sel.map((s, i) => ({ n: s.n, unidad: J.series[i].unidad, dec: 1, pts: J.series[i].puntos })), { t0: tms(J.desde), t1: tms(J.hasta), paso_s: J.paso_s, marca: marcaTs });
        alClicKpi(dc.querySelector('#aK'), (k) => k && CS.marcar(k));
      };
      selPeriodo(dc.querySelector('#aP'), S.P, calc); calc();
    }
    pintar();
    return { actualizar(n) { p = n; if (!S.sel) pintar(); } };   // con un detalle abierto no se redibuja (no pierde la curva ni el período)
  }

  // ------------------------------------------------------------------ tipo «sinoptico»: esquema con valores en vivo, variables, tendencias y eventos
  // plantilla «osmosis»: cada casillero se enlaza a una señal con vista.slots {casillero: PUNTO}
  const CAS = { estado: [20, 14, 'Estado'], cloro: [20, 300, 'Cloro libre'], c_entrada: [128, 120, 'Conductividad entrada'], q_entrada: [150, 276, 'Caudal entrada'], alimentacion: [262, 120, 'Presión alimentación'],
    membrana: [470, 120, 'Presión membranas'], rechazo: [470, 280, 'Rechazo de sales'], concentrado: [600, 300, 'Presión concentrado'], q_concentrado: [600, 344, 'Caudal concentrado'],
    q_permeado: [660, 66, 'Caudal permeado'], c_salida: [660, 16, 'Conductividad permeado'], recuperacion: [790, 300, 'Recuperación'] };
  function osmosisSvg(V, val, marcha) {
    const ag = marcha ? '#1e88e5' : '#b0bec5', an = marcha ? 'class="flujo"' : '';   // marcha: true, false o null (sin datos recientes)
    const caja = (k) => { const s = V.slots?.[k]; if (!s) return ''; const [x, y, t] = CAS[k]; const q = val(s);
      const txt = (k === 'estado' ? (q == null ? 'sin datos' : (V.estados?.[String(Math.round(q.valor))] || (q.valor > 0 ? 'En marcha' : 'Detenido'))) : q == null ? '—' : `${num(q.valor, Math.abs(q.valor) >= 100 ? 0 : 1)} ${q.unidad === '1' ? '' : q.unidad}`) + (q && q.estado === 'sin-datos' && k === 'estado' ? ' · sin datos recientes' : '');
      const st = q ? EST[q.estado] : EST['sin-datos'];
      return `<g class="sv" data-p="${h(s)}" tabindex="0" role="button"><title>${h(t)}: ${h(txt)}</title><rect x="${x}" y="${y}" width="${k === 'estado' ? 170 : 126}" height="40" style="stroke:${st[0]}"/><text class="e" x="${x + 8}" y="${y + 14}">${h(t)}</text><text x="${x + 8}" y="${y + 32}" style="font-weight:700${k === 'estado' ? ';fill:' + (marcha ? '#2e7d32' : '#5f6f84') : ''}">${h(txt)}</text></g>`; };
    return `<svg viewBox="0 0 920 390" role="img" aria-label="Sinóptico de ósmosis inversa"><style>.flujo{stroke-dasharray:10 8;animation:paf 1.2s linear infinite}@keyframes paf{to{stroke-dashoffset:-18}}</style>
      <rect x="20" y="160" width="92" height="120" rx="10" fill="#e3f2fd" stroke="#90a4ae"/><rect x="21" y="200" width="90" height="79" rx="9" fill="#bbdefb"/><text x="66" y="152" text-anchor="middle" class="e" style="font-size:12px;fill:#5f6f84">Agua de red</text>
      <path d="M112 250H174M226 250H262M330 250H400" stroke="${ag}" stroke-width="7" fill="none" ${an}/><circle cx="200" cy="250" r="26" fill="#fff" stroke="#546e7a" stroke-width="2"/><path d="M190 238l22 12-22 12z" fill="#546e7a"/>
      <text x="200" y="296" text-anchor="middle" style="font-size:11px;fill:#5f6f84">Bomba</text>
      <rect x="268" y="222" width="24" height="56" rx="8" fill="#eceff1" stroke="#78909c"/><rect x="300" y="222" width="24" height="56" rx="8" fill="#eceff1" stroke="#78909c"/><text x="296" y="296" text-anchor="middle" style="font-size:11px;fill:#5f6f84">Prefiltros</text>
      <rect x="400" y="206" width="300" height="58" rx="29" fill="#f1f8e9" stroke="#689f38" stroke-width="2"/><path d="M430 235H670" stroke="#aed581" stroke-width="2" stroke-dasharray="4 5"/><text x="550" y="198" text-anchor="middle" style="font-size:12px;fill:#33691e">Membranas de ósmosis inversa</text>
      <path d="M700 222H790V200" stroke="${ag}" stroke-width="7" fill="none" ${an}/><path d="M700 252H740V350H900" stroke="${marcha ? '#8d6e63' : '#b0bec5'}" stroke-width="6" fill="none" ${an}/>
      <text x="900" y="372" text-anchor="end" style="font-size:11px;fill:#5f6f84">Concentrado a desagüe</text>
      <rect x="790" y="140" width="110" height="140" rx="10" fill="#e1f5fe" stroke="#90a4ae"/><rect x="791" y="182" width="108" height="97" rx="9" fill="#b3e5fc"/><text x="845" y="132" text-anchor="middle" style="font-size:12px;fill:#5f6f84">Agua tratada</text>
      ${Object.keys(CAS).map(caja).join('')}</svg>`;
  }
  function vSinoptico(c, p0, x) {
    let p = p0; const V = p.vista || {}, act = V.activo, S = { tab: 'sin', P: { horas: 24 }, sel: [], R: null, q: '' };
    const val = (pt) => p.puntos.find((q) => q.punto === pt) || null;
    const marcha = () => { const e = V.slots?.estado && val(V.slots.estado), qp = V.slots?.q_permeado && val(V.slots.q_permeado), z = e || qp; if (!z || z.estado === 'sin-datos') return null; return z.valor > 0; };
    const CV = () => (V.curvas || []).length ? V.curvas : p.puntos.slice(0, 12).map((q) => ({ titulo: q.etiqueta || q.punto, puntos: [q.punto] }));
    const pintar = () => {
      const cu = { ok: 0, fuera: 0, alarma: 0, 'sin-datos': 0 }; p.puntos.forEach((q) => cu[q.estado]++);
      const pr = V.slots?.recuperacion && val(V.slots.recuperacion), cs = V.slots?.c_salida && val(V.slots.c_salida), mr = marcha(), eq = V.slots?.estado && val(V.slots.estado);
      c.innerHTML = `${kpis([{ t: 'Estado', n: mr == null ? 'Sin datos' : eq ? (V.estados?.[String(Math.round(eq.valor))] || (mr ? 'En marcha' : 'Detenido')) : mr ? 'En marcha' : 'Detenido', sub: `${act} · última lectura ${hace(p.puntos.reduce((a, q) => (!a || q.ts > a ? q.ts : a), null))}`, icono: mr == null ? 'reloj' : 'ok', color: mr == null ? '#78909c' : mr ? '#43a047' : '#fb8c00', k: 'sin' },
        { t: 'Alarmas activas', n: p.alarmas_activas, sub: 'informadas por el equipo', icono: 'alarmas', color: p.alarmas_activas ? '#e53935' : '#43a047', k: 'ev' },
        ...(pr ? [{ t: pr.etiqueta || 'Recuperación', n: num(pr.valor, 1), sub: pr.unidad === '1' ? '' : pr.unidad, icono: 'telemetria', color: '#1e88e5', k: 'tend:' + pr.punto }] : []),
        ...(cs ? [{ t: cs.etiqueta || 'Conductividad de salida', n: num(cs.valor, 1), sub: cs.unidad, icono: 'telemetria', color: EST[cs.estado][0], k: 'tend:' + cs.punto }] : []),
        { t: 'Variables', n: p.puntos.length, sub: `${cu.fuera + cu.alarma} fuera de rango o en alarma`, icono: 'paneles', color: cu.fuera + cu.alarma ? '#fb8c00' : '#00897b', k: 'var' }])}
        <div class="tarjeta">${herrPanel(p, x)}<div class="pa-tabs">${[['sin', 'Sinóptico'], ['var', 'Variables'], ['tend', 'Tendencias'], ['ev', 'Eventos y alarmas']].map(([k, t]) => `<button data-t="${k}" class="${S.tab === k ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="sC"></div></div>`;
      alClicKpi(c, (k) => { if (k.startsWith('tend:')) { S.sel = [k.slice(5)]; S.tab = 'tend'; } else S.tab = k; pintar(); });
      c.querySelectorAll('.pa-tabs button').forEach((b) => b.onclick = () => { S.tab = b.dataset.t; pintar(); });
      enlazarEd(c, x); const sc = c.querySelector('#sC');
      if (S.tab === 'sin') { sc.innerHTML = (V.plantilla || 'lista') === 'osmosis' ? `<div class="pa-sin">${osmosisSvg(V, val, marcha())}</div><p class="mini">Valores en vivo; se actualizan cada 30 s. Tocá un valor para ver su tendencia.</p>`
          : `<div class="pa-cards">${p.puntos.map((q) => `<div class="pa-c sv" data-p="${h(q.punto)}" style="--sc:${EST[q.estado][0]}"><div class="t"><b>${h(q.etiqueta || q.punto)}</b></div><div class="v">${num(q.valor, 1)}<small>${h(q.unidad === '1' ? '' : q.unidad)}</small></div><div class="r">${hace(q.ts)}</div></div>`).join('')}</div>`;
        sc.querySelectorAll('.sv').forEach((g) => { const ir = () => { S.sel = [g.dataset.p]; S.tab = 'tend'; pintar(); }; g.onclick = ir; g.onkeydown = (e) => { if (e.key === 'Enter') ir(); }; }); }
      else if (S.tab === 'var') { const l = p.puntos.filter((q) => !S.q || (q.etiqueta + ' ' + q.punto).toLowerCase().includes(S.q));
        sc.innerHTML = `<div class="herr"><input type="search" id="sQ" placeholder="Buscar variable" value="${h(S.q)}" style="max-width:240px"><span class="sep"></span><button class="b sec" id="sCsv">${icono('bajar')} CSV</button></div>
          <table class="pa-tab"><tr><th>Variable</th><th>Señal</th><th style="text-align:right">Valor</th><th>Unidad</th><th>Actualizado</th><th>Estado</th></tr>${l.map((q) => `<tr class="clic" data-p="${h(q.punto)}"><td>${h(q.etiqueta || q.punto)}</td><td class="mini">${h(q.punto)}</td><td class="n">${num(q.valor, 2)}</td><td>${h(q.unidad === '1' ? '' : q.unidad)}</td><td>${hace(q.ts)}</td><td><span class="et ${EST[q.estado][1]}">${EST[q.estado][2]}</span></td></tr>`).join('')}</table>`;
        const q = sc.querySelector('#sQ'); q.oninput = () => { S.q = q.value.trim().toLowerCase(); pintar(); const n = c.querySelector('#sQ'); n.focus(); n.setSelectionRange(n.value.length, n.value.length); };
        sc.querySelector('#sCsv').onclick = () => csv(`variables_${act}`, [{ t: 'Variable', v: (z) => z.etiqueta || z.punto }, { t: 'Señal', v: (z) => z.punto }, { t: 'Valor', v: (z) => String(z.valor).replace('.', ',') }, { t: 'Unidad', v: (z) => z.unidad }, { t: 'Fecha', v: (z) => z.ts }], l);
        sc.querySelectorAll('tr[data-p]').forEach((tr) => tr.onclick = () => { S.sel = [tr.dataset.p]; S.tab = 'tend'; pintar(); }); }
      else if (S.tab === 'tend') { const L = CV(); if (!S.sel.length && L[0]) S.sel = L[0].puntos.slice();
        sc.innerHTML = `<div class="pa-cs">${L.map((k, i) => `<button data-i="${i}" class="${JSON.stringify(k.puntos) === JSON.stringify(S.sel) ? 'activo' : ''}">${h(k.titulo)}</button>`).join('')}</div><div id="sP"></div><div id="sG"></div><div class="herr" style="margin-top:6px"><span class="sep"></span><button class="b sec" id="sCsv">${icono('bajar')} CSV</button></div>`;
        const ver = async () => { try { S.R = await cargarCurvas(sc.querySelector('#sG'), p.id, S.sel.map((z) => ({ clave: `${act}|${z}`, n: val(z)?.etiqueta || z, dec: 2 })), S.P, { bandas: S.sel.length === 1 && (V.rangos || {})[S.sel[0]] ? [{ de: V.rangos[S.sel[0]][0], a: V.rangos[S.sel[0]][1], color: '#43a047' }] : [] }); } catch (e) { sc.querySelector('#sG').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; } };
        sc.querySelectorAll('.pa-cs button').forEach((b) => b.onclick = () => { S.sel = L[+b.dataset.i].puntos.slice(); pintar(); });
        sc.querySelector('#sCsv').onclick = () => csvCurvas(`tendencias_${act}`, S.R); selPeriodo(sc.querySelector('#sP'), S.P, ver); ver(); }
      else vEventos(sc, p, x, { embebido: true });
    };
    pintar();
    return { actualizar(n) { p = n; if (S.tab === 'sin' || S.tab === 'var') pintar(); } };
  }

  // ------------------------------------------------------------------ tipo «eventos»: indicadores que filtran, período, búsqueda, por día, ranking y lista
  const durac = (m) => m == null ? '' : m < 60 ? `${m} min` : m < 1440 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min` : `${num(m / 1440, 1)} días`;
  function vEventos(c, p, x, o = {}) {
    const S = { P: { horas: 720 }, nivel: '', estado: '', q: '', cat: '' }; let J = null;
    c.innerHTML = `<div id="vK"></div><div class="${o.embebido ? '' : 'tarjeta'}">${o.embebido ? '' : herrPanel(p, x)}<div id="vP"></div><div style="display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 8px"><input type="search" id="vQ" placeholder="Buscar en el mensaje o el equipo" style="flex:1;min-width:180px"><select id="vC"><option value="">Todas las categorías</option></select><button class="b sec" id="vCsv">${icono('bajar')} CSV</button></div>
      <div id="vB"></div><div id="vR"></div><h3 style="margin:14px 0 6px;font-size:14px">Lista <small class="mini" id="vN"></small></h3><div id="vL"></div></div>`;
    if (!o.embebido) enlazarEd(c, x); const g = (s) => c.querySelector(s);
    const cargar = async () => {
      g('#vL').innerHTML = '<p class="mini">Cargando…</p>';
      try { J = await api('GET', `m/paneles/eventos/${p.id}?${qsPer(S.P)}&nivel=${S.nivel}&estado=${S.estado}&q=${encodeURIComponent(S.q)}&categoria=${encodeURIComponent(S.cat)}&limite=500`); } catch (e) { g('#vL').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; return; }
      const K = J.kpis, ac = (k) => (k === 'activa' && S.estado === 'activa') || (k === S.nivel && !S.estado) || (k === 'todas' && !S.nivel && !S.estado);
      g('#vK').innerHTML = kpis([{ t: 'Alarmas activas', n: K.activas, sub: 'sin terminar', icono: 'alarmas', color: K.activas ? '#e53935' : '#43a047', k: 'activa', activo: ac('activa') },
        { t: 'Alarmas', n: K.alarmas, sub: txtPer(S.P), icono: 'alarmas', color: '#c62828', k: 'alarma', activo: ac('alarma') }, { t: 'Eventos', n: K.eventos, sub: txtPer(S.P), icono: 'hist', color: '#1e88e5', k: 'evento', activo: ac('evento') },
        { t: 'Hoy', n: K.hoy, sub: 'alarmas y eventos', icono: 'reloj', color: '#00897b' }, { t: 'Total', n: K.total, sub: 'en el período', icono: 'paneles', color: '#546e7a', k: 'todas', activo: ac('todas') }]);
      alClicKpi(g('#vK'), (k) => { if (k === 'activa') { S.estado = S.estado === 'activa' ? '' : 'activa'; S.nivel = S.estado ? 'alarma' : ''; } else { S.estado = ''; S.nivel = k === 'todas' || S.nivel === k ? '' : k; } cargar(); });
      const sc = g('#vC'); if (sc.options.length <= 1) J.categorias.filter((z) => z.categoria !== '').forEach((z) => sc.insertAdjacentHTML('beforeend', `<option value="${h(z.categoria)}">${h(z.categoria)} (${z.n})</option>`));
      g('#vB').innerHTML = J.por_dia.length > 1 ? `<h3 style="margin:8px 0 4px;font-size:14px">Por día</h3>${grafico({ tipo: 'apiladas', etiquetas: J.por_dia.map((d) => d.dia.slice(8, 10) + '/' + d.dia.slice(5, 7)), series: [{ n: 'Alarmas', valores: J.por_dia.map((d) => +d.alarmas), color: '#e53935' }, { n: 'Eventos', valores: J.por_dia.map((d) => +d.eventos), color: '#1e88e5' }], alto: 170, leyenda: true })}` : '';
      g('#vR').innerHTML = J.ranking.length ? `<h3 style="margin:14px 0 6px;font-size:14px">Los más frecuentes</h3>${Nucleo.tabla([{ t: 'Mensaje', v: (r) => r.mensaje }, { t: 'Nivel', html: true, v: (r) => `<span class="et ${r.nivel === 'alarma' ? 'rojo' : 'azul'}">${r.nivel}</span>` }, { t: 'Veces', v: (r) => r.n },
        { t: 'Duración promedio', v: (r) => durac(r.minutos != null ? +r.minutos : null) }, { t: 'Última', v: (r) => fcorta(r.ultima) }], J.ranking, (r) => { S.q = r.mensaje; g('#vQ').value = r.mensaje; cargar(); })}` : '';
      g('#vN').textContent = `· ${J.lista.length < J.total ? `${J.lista.length} de ${J.total}` : J.total}${S.q || S.cat || S.nivel ? ' con el filtro' : ''}`;
      g('#vL').innerHTML = Nucleo.tabla([{ t: 'Inicio', v: (e) => fcorta(e.ts) }, { t: 'Fin', html: true, v: (e) => e.fin ? `${h(fcorta(e.fin))}${e.minutos != null ? ` <span class="mini">· ${durac(e.minutos)}</span>` : ''}` : e.nivel === 'alarma' ? '<span class="et rojo">activa</span>' : '' },
        { t: 'Nivel', html: true, v: (e) => `<span class="et ${e.nivel === 'alarma' ? 'rojo' : 'azul'}">${e.nivel}</span>` }, { t: 'Equipo', v: (e) => e.activo || '' }, { t: 'Mensaje', v: (e) => e.mensaje }, { t: 'Categoría', v: (e) => e.categoria || '' }], J.lista);
    };
    const q = g('#vQ'); let tq = null; q.oninput = () => { clearTimeout(tq); tq = setTimeout(() => { S.q = q.value.trim(); cargar(); }, 350); };
    g('#vC').onchange = (e) => { S.cat = e.target.value; cargar(); };
    g('#vCsv').onclick = () => J && csv(`eventos_${p.nombre}_${J.desde.slice(0, 10)}_a_${J.hasta.slice(0, 10)}`, [{ t: 'Inicio', v: (e) => e.ts }, { t: 'Fin', v: (e) => e.fin || '' }, { t: 'Minutos', v: (e) => e.minutos ?? '' }, { t: 'Nivel', v: (e) => e.nivel }, { t: 'Equipo', v: (e) => e.activo || '' }, { t: 'Mensaje', v: (e) => e.mensaje }, { t: 'Categoría', v: (e) => e.categoria || '' }], J.lista);
    selPeriodo(g('#vP'), S.P, cargar, [['24', '24 h'], ['168', '7 días'], ['720', '30 días'], ['2160', '90 días'], ['8760', '1 año']]); cargar();
    return { actualizar() { if (S.P.horas && S.P.horas <= 24) cargar(); } };
  }

  // ------------------------------------------------------------------ configurar un panel (panel lateral)
  async function editar(p, areaClave, alGuardar) {
    const [puntos, ar] = await Promise.all([api('GET', 'm/paneles/puntos').then((j) => j.puntos), api('GET', 'm/paneles/areas')]);
    const ROLES = Object.fromEntries(Object.entries(ar.opciones.roles).filter(([k]) => k !== 'AD')), TIPOS = ar.opciones.tipos;
    const tiene = (q) => p?.items.find((i) => i.activo_id === q.activo_id && i.punto === q.punto), k = (q) => q.activo_id + '|' + q.punto;
    const cuerpo = panel(p ? 'Configurar: ' + p.nombre : 'Nuevo panel', `<form class="form" id="eF"><div class="fgrid">
      <div class="fcampo" style="grid-column:span 8"><label>Nombre <b class="req">*</b></label><input name="nombre" required maxlength="80" value="${h(p?.nombre || '')}" placeholder="Ej.: Sala de máquinas"></div>
      <div class="fcampo" style="grid-column:span 4"><label>Color</label><input name="color" type="color" value="${h(p?.color || '#1e88e5')}" style="height:38px;padding:3px"></div>
      <div class="fcampo" style="grid-column:span 6"><label>Área</label><select name="area"><option value="">(sin área)</option>${ar.areas.map((a) => `<option value="${h(a.clave)}" ${(p ? p.area : areaClave) === a.clave ? 'selected' : ''}>${h(a.nombre)}</option>`).join('')}</select></div>
      <div class="fcampo" style="grid-column:span 6"><label>Tipo</label><select name="tipo">${Object.entries(TIPOS).map(([v, t]) => `<option value="${v}" ${(p?.tipo || 'tarjetas') === v ? 'selected' : ''}>${h(t)}</option>`).join('')}</select></div>
      <div class="fcampo" style="grid-column:span 12"><label>Descripción</label><input name="descripcion" maxlength="200" value="${h(p?.descripcion || '')}" placeholder="Opcional"></div>
      <div class="fcampo" style="grid-column:span 12"><label>Lo ven estos roles <span class="mini" style="font-weight:400">(además de las reglas del área; sin marcar ninguno y sin usuarios = todos los que ven el área)</span></label><div class="chips" id="eR">${Object.entries(ROLES).map(([v, t]) => `<label><input type="checkbox" value="${v}" ${p?.roles?.includes(v) ? 'checked' : ''}> ${h(t)}</label>`).join('')}</div></div>
      <div class="fcampo" style="grid-column:span 12"><label>Y además estos usuarios</label><input name="usuarios" value="${h((p?.usuarios || []).join(', '))}" placeholder="Nombres de usuario separados por coma"></div>
      <div class="fcampo" style="grid-column:span 4"><label>Orden</label><input name="orden" inputmode="numeric" value="${h(p?.orden ?? 50)}"></div>
      <div class="fcampo" style="grid-column:span 4"><label>Clave</label><input name="clave" maxlength="40" value="${h(p?.clave || '')}" placeholder="para paquetes"></div>
      <div class="fcampo" style="grid-column:span 4"><label>Estado</label><label class="fchk"><input type="checkbox" name="activo" ${!p || p.activo ? 'checked' : ''}> Visible</label></div>
      <div class="fcampo" style="grid-column:span 12" id="eVw"><label>Configuración del tipo (JSON)</label><textarea name="vista" rows="9" style="font-family:monospace;font-size:12px" placeholder='{"activo": "CÓDIGO", …}'>${h(p?.vista ? JSON.stringify(p.vista, null, 1) : '')}</textarea>
        <small class="fayuda">Energía: activo, curvas, referencias y energia. Equipos: grupos (o activos), tarjeta, etiquetas, nombres, curvas y analisis. Sinóptico: activo, plantilla (osmosis o lista), slots y curvas. Eventos: activos (vacío = todos).</small></div></div>
      <h3>Señales del panel <span class="mini" style="font-weight:400">(tarjetas y energía)</span></h3>${puntos.length ? `<div class="filtros"><div style="flex:1"><input type="search" id="eB" placeholder="Buscar equipo o señal"></div></div><div class="editor-s" id="eS">${puntos.map((q) => { const t = tiene(q);
        return `<div class="es ${t ? 'on' : ''}" data-k="${h(k(q))}" data-t="${h(((q.nombre || '') + ' ' + q.punto + ' ' + (q.codigo || '') + ' ' + (q.etiqueta || '')).toLowerCase())}" ${t ? '' : 'style="display:none"'}><input type="checkbox" ${t ? 'checked' : ''} aria-label="Incluir ${h(q.punto)}">
          <div><b>${h(q.nombre || q.codigo || q.activo_id)}</b> · ${h(q.etiqueta || q.punto)} <span class="mini">(${h(q.codigo || '')} ${h(q.punto)} · ${num(q.valor, 1)} ${h(q.unidad)} · ${hace(q.ts)})</span>
          <div class="ec"><input data-c="titulo" placeholder="Título en la tarjeta" maxlength="80" value="${h(t?.titulo || '')}"><input data-c="minimo" inputmode="decimal" placeholder="Mínimo normal" value="${h(t?.minimo ?? '')}"><input data-c="maximo" inputmode="decimal" placeholder="Máximo normal" value="${h(t?.maximo ?? '')}"></div></div></div>`; }).join('')}</div><p class="mini">Se muestran las señales elegidas; buscá para agregar otras (${puntos.length} disponibles).</p>`
        : '<p class="mini">Todavía no llegó ninguna medición. Cuando un equipo o una fuente de datos empiece a informar vas a poder elegir sus señales acá.</p>'}
      <div class="acciones"><button class="b" type="submit">${p ? 'Guardar cambios' : 'Crear panel'}</button>${p ? '<button class="b peligro" type="button" id="eX">Borrar panel</button>' : ''}</div><div class="fmsg"></div></form>`);
    const f = cuerpo.querySelector('#eF'), msg = f.querySelector('.fmsg');
    f.querySelectorAll('.es').forEach((d) => { const ch = d.querySelector('input[type=checkbox]'); ch.onchange = () => d.classList.toggle('on', ch.checked); });
    const bus = f.querySelector('#eB'); if (bus) bus.oninput = () => { const t = bus.value.trim().toLowerCase(); f.querySelectorAll('.es').forEach((d) => { d.style.display = (t && d.dataset.t.includes(t)) || d.classList.contains('on') ? '' : 'none'; }); };
    const n = (v) => { const t = String(v).trim().replace(',', '.'); return t === '' ? null : t; };
    f.onsubmit = async (e) => { e.preventDefault();
      let vista = null; const tv = f.vista.value.trim(); if (tv) { try { vista = JSON.parse(tv); } catch (z) { return aviso(msg, 'mal', 'La configuración del tipo no es un JSON válido: ' + z.message); } }
      const items = [...f.querySelectorAll('.es.on')].map((d) => { const [activo_id, punto] = d.dataset.k.split('|'); const cc = (y) => d.querySelector(`[data-c=${y}]`).value;
        const ant = p?.items.find((i) => i.activo_id === activo_id && i.punto === punto);
        return { activo_id, punto, titulo: cc('titulo'), minimo: n(cc('minimo')), maximo: n(cc('maximo')), decimales: ant?.decimales ?? 1, orden: ant?.orden ?? 999 }; }).sort((a, b) => a.orden - b.orden);
      const d = { nombre: f.nombre.value, color: f.color.value, descripcion: f.descripcion.value, roles: [...f.querySelectorAll('#eR input:checked')].map((z) => z.value), usuarios: f.usuarios.value, orden: parseInt(f.orden.value || '50', 10) || 0,
        activo: f.activo.checked, items, area: f.area.value, tipo: f.tipo.value, vista, clave: f.clave.value.trim() };
      try { const r = p ? await api('PUT', 'm/paneles/paneles/' + p.id, d) : await api('POST', 'm/paneles/paneles', d); aviso(msg, 'ok', 'Panel guardado.'); setTimeout(() => { Nucleo.cerrar(); alGuardar?.(r.panel.id); }, 500); }
      catch (z) { aviso(msg, 'mal', z.message); } };
    const bx = f.querySelector('#eX'); if (bx) bx.onclick = async () => { if (!bx.dataset.ok) { bx.dataset.ok = '1'; bx.textContent = 'Tocá de nuevo para borrar'; return; }
      try { await api('POST', `m/paneles/paneles/${p.id}/borrar`, {}); Nucleo.cerrar(); alGuardar?.(null); } catch (z) { aviso(msg, 'mal', z.message); } };
  }

  // ------------------------------------------------------------------ Configuración › Paneles IoT: áreas, quién ve cada una, y paquete (exportar / importar)
  Nucleo.configuracion({ id: 'paneles', titulo: 'Paneles IoT', render: async (el) => {
    if (!puede('paneles.configurar')) { el.innerHTML = '<p class="mini">Sin permiso para configurar paneles.</p>'; return; }
    const J = await api('GET', 'm/paneles/areas'), O = J.opciones;
    const regTxt = (r) => [r.rol && `rol ${O.roles[r.rol] || r.rol}`, r.perfil && `perfil ${(O.perfiles.find((z) => z.id === r.perfil) || {}).nombre || r.perfil}`, r.organizacion && `organización ${r.organizacion}`, r.usuario && `usuario ${r.usuario}`].filter(Boolean).join(' y ');
    el.innerHTML = `<div class="tarjeta"><h2>Áreas de Paneles IoT</h2><p class="mini">Cada área es una tarjeta de la portada. La ve quien cumpla alguna de sus reglas (cada regla exige todo lo que nombra). Un área sin reglas la ven todos los que tienen acceso a Paneles IoT; quien configura paneles ve todas.</p>
      ${Nucleo.tabla([{ t: 'Área', html: true, v: (a) => `<span style="color:${h(a.color)}">${ico(a.icono)}</span> <b>${h(a.nombre)}</b>` }, { t: 'Clave', v: (a) => a.clave }, { t: 'Paneles', v: (a) => a.paneles },
        { t: 'La ven', v: (a) => a.reglas.length ? a.reglas.map(regTxt).join(' · o ') : 'todos' }, { t: 'Estado', html: true, v: (a) => a.activo ? '<span class="et verde">visible</span>' : '<span class="et gris">oculta</span>' }], J.areas, (a) => form(a))}
      <div class="acciones"><button class="b" id="cN">＋ Nueva área</button></div><div id="cF"></div></div>
      <div class="tarjeta"><h2>Paquete de áreas y paneles</h2><p class="mini">Un paquete (JSON, formato iproy-paneles/1) lleva las áreas con sus reglas y los paneles con su configuración; las señales van por código de activo. Al importar, lo que tiene la misma clave se actualiza.</p>
        <div class="acciones"><button class="b sec" id="cX">${icono('bajar')} Exportar paquete</button><label class="b sec" style="cursor:pointer">Importar paquete<input type="file" id="cI" accept=".json,application/json" hidden></label></div><div id="cM"></div></div>`;
    const ICS = [...Object.keys(ICO), 'paneles', 'telemetria', 'alarmas', 'config', 'stock', 'preventivos'];
    function form(a) {
      const R = (a?.reglas || []).map((r) => ({ ...r }));
      const fila = (r, i) => `<div class="fgrid" data-r="${i}" style="align-items:end;border-top:1px dashed var(--borde);padding-top:6px">
        <div class="fcampo" style="grid-column:span 3"><label>Rol</label><select data-k="rol"><option value="">(cualquiera)</option>${Object.entries(O.roles).map(([v, t]) => `<option value="${v}" ${r.rol === v ? 'selected' : ''}>${h(t)}</option>`).join('')}</select></div>
        <div class="fcampo" style="grid-column:span 3"><label>Perfil</label><select data-k="perfil"><option value="">(cualquiera)</option>${O.perfiles.map((z) => `<option value="${h(z.id)}" ${r.perfil === z.id ? 'selected' : ''}>${h(z.nombre)}</option>`).join('')}</select></div>
        <div class="fcampo" style="grid-column:span 3"><label>Organización</label><input data-k="organizacion" list="cOrg" value="${h(r.organizacion || '')}"></div>
        <div class="fcampo" style="grid-column:span 2"><label>Usuario</label><input data-k="usuario" value="${h(r.usuario || '')}"></div>
        <div class="fcampo" style="grid-column:span 1"><button type="button" class="b sec" data-q="${i}" aria-label="Quitar la regla">✕</button></div></div>`;
      const cf = el.querySelector('#cF');
      cf.innerHTML = `<form class="form" id="aF" style="margin-top:12px;border-top:1px solid var(--borde);padding-top:10px"><h3>${a ? 'Área: ' + h(a.nombre) : 'Nueva área'}</h3><div class="fgrid">
        <div class="fcampo" style="grid-column:span 4"><label>Clave <b class="req">*</b></label><input name="clave" required maxlength="40" value="${h(a?.clave || '')}" placeholder="ej.: energia"></div>
        <div class="fcampo" style="grid-column:span 8"><label>Nombre <b class="req">*</b></label><input name="nombre" required maxlength="80" value="${h(a?.nombre || '')}"></div>
        <div class="fcampo" style="grid-column:span 12"><label>Descripción</label><input name="descripcion" maxlength="200" value="${h(a?.descripcion || '')}"></div>
        <div class="fcampo" style="grid-column:span 4"><label>Ícono</label><select name="icono">${ICS.map((z) => `<option ${(a?.icono || 'paneles') === z ? 'selected' : ''}>${z}</option>`).join('')}</select></div>
        <div class="fcampo" style="grid-column:span 3"><label>Color</label><input name="color" type="color" value="${h(a?.color || '#1e88e5')}" style="height:38px;padding:3px"></div>
        <div class="fcampo" style="grid-column:span 2"><label>Orden</label><input name="orden" inputmode="numeric" value="${h(a?.orden ?? 50)}"></div>
        <div class="fcampo" style="grid-column:span 3"><label>Estado</label><label class="fchk"><input type="checkbox" name="activo" ${!a || a.activo ? 'checked' : ''}> Visible</label></div></div>
        <h3 style="margin-top:10px">Quién la ve</h3><datalist id="cOrg">${O.organizaciones.map((z) => `<option value="${h(z)}">`).join('')}</datalist><div id="aR"></div><button type="button" class="b sec" id="aMas">＋ Agregar regla</button>
        <div class="acciones"><button class="b" type="submit">${a ? 'Guardar cambios' : 'Crear área'}</button>${a ? '<button class="b peligro" type="button" id="aX">Borrar área</button>' : ''}<button class="b sec" type="button" id="aC">Cancelar</button></div><div class="fmsg"></div></form>`;
      const f = cf.querySelector('#aF'), msg = f.querySelector('.fmsg');
      const leer = () => f.querySelectorAll('[data-r]').forEach((d) => { const r = R[+d.dataset.r]; d.querySelectorAll('[data-k]').forEach((z) => { r[z.dataset.k] = z.value.trim(); }); });
      const reglas = () => { f.querySelector('#aR').innerHTML = R.length ? R.map(fila).join('') : '<p class="mini">Sin reglas: la ven todos los que tienen acceso a Paneles IoT.</p>';
        f.querySelectorAll('[data-q]').forEach((b) => b.onclick = () => { leer(); R.splice(+b.dataset.q, 1); reglas(); }); };
      f.querySelector('#aMas').onclick = () => { leer(); R.push({}); reglas(); }; f.querySelector('#aC').onclick = () => { cf.innerHTML = ''; };
      reglas(); cf.scrollIntoView({ behavior: 'smooth', block: 'start' });
      f.onsubmit = async (e) => { e.preventDefault(); leer();
        const d = { clave: f.clave.value.trim(), nombre: f.nombre.value, descripcion: f.descripcion.value, icono: f.icono.value, color: f.color.value, orden: parseInt(f.orden.value || '50', 10) || 0, activo: f.activo.checked, reglas: R.filter((r) => Object.values(r).some(Boolean)) };
        try { a ? await api('PUT', 'm/paneles/areas/' + a.id, d) : await api('POST', 'm/paneles/areas', d); aviso(msg, 'ok', 'Área guardada.'); setTimeout(() => Nucleo.ir('configuracion'), 600); } catch (z) { aviso(msg, 'mal', z.message); } };
      const bx = f.querySelector('#aX'); if (bx) bx.onclick = async () => { if (!bx.dataset.ok) { bx.dataset.ok = '1'; bx.textContent = `Tocá de nuevo: sus ${a.paneles} paneles quedan sin área`; return; }
        try { await api('POST', `m/paneles/areas/${a.id}/borrar`, {}); Nucleo.ir('configuracion'); } catch (z) { aviso(msg, 'mal', z.message); } };
    }
    el.querySelector('#cN').onclick = () => form(null);
    el.querySelector('#cX').onclick = async () => { const x = await api('GET', 'm/paneles/paquete'); delete x.ok; const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([JSON.stringify(x, null, 1)], { type: 'application/json' }));
      a.download = `paneles_${new Date().toISOString().slice(0, 10)}.json`; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000); };
    el.querySelector('#cI').onchange = async (e) => { const m = el.querySelector('#cM'), fl = e.target.files[0]; if (!fl) return;
      try { const x = JSON.parse(await fl.text()); aviso(m, 'info', 'Importando…'); const r = await api('POST', 'm/paneles/paquete', x);
        m.innerHTML = `<div class="aviso ok">Importado: ${r.areas} áreas, ${r.paneles} paneles y ${r.senales} señales.</div>${r.avisos.length ? `<div class="aviso info"><b>Avisos (${r.avisos.length})</b><br>${r.avisos.slice(0, 40).map(h).join('<br>')}</div>` : ''}`; }
      catch (z) { aviso(m, 'mal', z.message); } e.target.value = ''; };
  } });
})();
