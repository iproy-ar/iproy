// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Aplicación del nodo · cáscara (1.2.0): ingreso con la identidad de la institución, tablero con saludo, «Resumen» (widgets
// de los módulos), accesos rápidos por categoría con buscador y recientes, barra con navegación, menú lateral, perfil del
// usuario (fondo propio como los temas de Gmail) y configuración institucional (logo, fondos, widgets).
// API para los módulos (estable): window.Nucleo.{api, pim, h, fecha, aviso, tabla, panel, cerrar, form, puede, sesion, pestana, tablero, ir, kpis}
'use strict';
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const h = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pestanas = []; const widgets = []; const alIniciar = []; const cfgExtra = []; let CAT = null;
  let S = null; let instalable = null; let V = null; let actual = null; let pub = null; const cargados = new Set();

  // ---------------------------------------------------------------- íconos (SVG en línea: funciona sin Internet)
  const P = {
    manual: '<path d="M12 6.5C10.3 5 7.8 4.5 4 4.5v13c3.8 0 6.3.5 8 2 1.7-1.5 4.2-2 8-2v-13c-3.8 0-6.3.5-8 2z"/><path d="M12 6.5v13"/>',
    inicio: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    ordenes: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v3h6V3"/><path d="M9 11h6M9 15h4"/>',
    solicitud: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 11v6M9 14h6"/>',
    inventario: '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    preventivos: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M9 15l2 2 4-4"/>',
    alarmas: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    telemetria: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    colmena: '<path d="M12 2l8.7 5v10L12 22l-8.7-5V7z"/><path d="M12 8l3.5 2v4L12 16l-3.5-2v-4z"/>',
    sistema: '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
    usuarios: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    config: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    perfil: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    menu: '<path d="M3 6h18M3 12h18M3 18h18"/>', izq: '<path d="M15 18l-6-6 6-6"/>', der: '<path d="M9 18l6-6-6-6"/>',
    buscar: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>', salir: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    candado: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', imagen: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', ojo: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
    ok: '<path d="M20 6L9 17l-5-5"/>', hist: '<path d="M3 3v5h5"/><path d="M3.1 13a9 9 0 1 0 .5-4.5L3 8"/><path d="M12 7v5l4 2"/>', arriba: '<path d="M18 15l-6-6-6 6"/>', abajo: '<path d="M6 9l6 6 6-6"/>',
    nodo: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>', modulo: '<path d="M12 2l9 5-9 5-9-5 9-5z"/><path d="M3 12l9 5 9-5M3 17l9 5 9-5"/>',
    stock: '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    diario: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z"/><path d="M9 7h7M9 11h5"/>',
    avisos: '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>', reportes: '<path d="M3 3v18h18"/><path d="M7 15v3M12 9v9M17 5v13"/>',
    paneles: '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
    filtro: '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>', bajar: '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>', imprimir: '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/>',
    estrella: '<path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
  };
  const ic = (n, cls = 'i') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true">${P[n] || P.modulo}</svg>`;

  // ---------------------------------------------------------------- categorías y metadatos de los accesos
  const GRUPOS = { mant: ['Mantenimiento', '#1e88e5'], panol: ['Pañol', '#6d4c41'], plan: ['Planificación', '#43a047'], moni: ['Monitoreo', '#8e24aa'], red: ['Red de instituciones', '#00897b'],
    adm: ['Administración', '#546e7a'], otros: ['Otros módulos', '#8d6e63'] };   // 1.7.0: el pañol al lado de mantenimiento
  const META = { ordenes: ['mant', 'ordenes', 'Seguimiento de trabajos'], solicitud: ['mant', 'solicitud', 'Pedir un trabajo'], inventario: ['mant', 'inventario', 'Activos y ubicaciones'], stock: ['panol', 'stock', 'Insumos, ingresos y egresos'],
    preventivos: ['plan', 'preventivos', 'Planes por tiempo, uso o condición'], alarmas: ['moni', 'alarmas', 'ISA-18.2'], telemetria: ['moni', 'telemetria', 'Sensores y equipos'],
    colmena: ['red', 'colmena', 'Vínculos con otros nodos'], sistema: ['adm', 'sistema', 'Módulos, tareas y diario'], usuarios: ['adm', 'usuarios', 'Altas y roles'],
    configuracion: ['adm', 'config', 'Logo, fondos y tablero'], avisos: ['moni', 'avisos', 'Correo, Telegram y webhook'],
    reportes: ['adm', 'reportes', 'Auditoría y reporte por empresa'], paneles: ['moni', 'paneles', 'Instalaciones por área'] };
  const GRUPOS0 = JSON.parse(JSON.stringify(GRUPOS));
  const GICON = { mant: 'ordenes', plan: 'preventivos', moni: 'telemetria', red: 'colmena', panol: 'stock', adm: 'sistema', otros: 'modulo' };
  /** 1.7.0: los accesos visibles agrupados por tipo, en el orden de los grupos: [{g, nombre, color, icono, items}] (menú, lateral y tablero). */
  const porGrupo = (vis) => Object.keys(GRUPOS).map((g) => ({ g, nombre: GRUPOS[g][0], color: GRUPOS[g][1], items: vis.filter((p) => meta(p).grupo === g) }))
    .filter((x) => x.items.length).map((x) => ({ ...x, icono: GICON[x.g] || meta(x.items[0]).icono }));
  const meta = (p) => { const m = META[p.id] || []; const c = CAT?.w[p.id]; const g = c?.grupo || p.grupo || m[0] || 'otros';
    return { grupo: GRUPOS[g] ? g : 'otros', icono: (c?.icono && P[c.icono] ? c.icono : '') || p.icono || m[1] || 'modulo', desc: (c ? c.descripcion : null) || (p.desc ?? m[2] ?? '') }; };
  /** Catálogo del nodo (módulo «widgets»): grupos propios y, por acceso, nombre, grupo, ícono, orden y si se ve. Con null vuelve a lo que trae el código. */
  function aplicarCatalogo(c) {
    pestanas.forEach((p) => { if (p._t0 === undefined) { p._t0 = p.titulo; p._o0 = p.orden; } p.titulo = p._t0; p.orden = p._o0; });
    Object.keys(GRUPOS).forEach((k) => delete GRUPOS[k]);
    const gs = (c?.grupos || []).filter((g) => g.activo !== 0);
    if (gs.length) [...gs].sort((a, b) => a.orden - b.orden).forEach((g) => { GRUPOS[g.id] = [g.nombre, g.color]; }); else Object.assign(GRUPOS, JSON.parse(JSON.stringify(GRUPOS0)));
    if (!GRUPOS.otros) GRUPOS.otros = GRUPOS0.otros;
    CAT = c ? { w: Object.fromEntries((c.widgets || []).map((w) => [w.id, w])), visibles: c.visibles || null } : null;
    if (CAT) pestanas.forEach((p) => { const w = CAT.w[p.id]; if (w) { p.titulo = w.nombre || p._t0; p.orden = w.orden ?? p._o0; } });
    pestanas.sort((a, b) => (a.orden ?? 50) - (b.orden ?? 50));
  }

  // ---------------------------------------------------------------- fondos (temas sin Internet: degradés y patrones SVG)
  const svgUrl = (s) => `url("data:image/svg+xml,${encodeURIComponent(s)}")`;
  const TEMAS = {
    cielo: ['Cielo', 'linear-gradient(160deg,#e3f2fd 0%,#bbdefb 45%,#e1f5fe 100%)'],
    oceano: ['Océano', 'linear-gradient(135deg,#0d47a1 0%,#1976d2 45%,#26c6da 100%)'],
    atardecer: ['Atardecer', 'linear-gradient(135deg,#ffb74d 0%,#f06292 50%,#7e57c2 100%)'],
    bosque: ['Bosque', 'linear-gradient(135deg,#2e7d32 0%,#66bb6a 50%,#dcedc8 100%)'],
    montana: ['Montaña', `${svgUrl('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMax slice"><path d="M0 620 280 380 470 540 720 300 980 560 1180 420 1600 640V900H0z" fill="#90a4ae"/><path d="M0 720 360 520 600 690 880 470 1150 680 1400 560 1600 700V900H0z" fill="#607d8b"/><path d="M720 300 790 360 760 370 700 330z" fill="#fff"/></svg>')} bottom/100% auto no-repeat,linear-gradient(180deg,#81d4fa 0%,#e1f5fe 70%)`],
    aurora: ['Aurora', 'radial-gradient(circle at 20% 20%,#b2ebf2 0,transparent 45%),radial-gradient(circle at 80% 30%,#d1c4e9 0,transparent 45%),radial-gradient(circle at 50% 90%,#c8e6c9 0,transparent 50%),#e8eaf6'],
    arena: ['Arena', 'linear-gradient(160deg,#fff8e1 0%,#ffe0b2 60%,#ffccbc 100%)'],
    grafito: ['Grafito', 'linear-gradient(160deg,#eceff1 0%,#cfd8dc 60%,#b0bec5 100%)'],
    hexagonos: ['Colmena', `${svgUrl('<svg xmlns="http://www.w3.org/2000/svg" width="56" height="97" viewBox="0 0 56 97"><path d="M28 0l28 16v32L28 64 0 48V16zM28 64l28 16v32M28 64L0 80v32" fill="none" stroke="#90caf9" stroke-width="1.4" opacity=".55"/></svg>')},linear-gradient(160deg,#e3f2fd,#f3f9ff)`],
    quirofano: ['Quirófano', 'linear-gradient(150deg,#e0f2f1 0%,#b2dfdb 55%,#80cbc4 100%)'],
  };
  const cssFondo = (f) => !f ? '' : f.tipo === 'imagen' ? `url("${f.url}")` : (TEMAS[f.tema]?.[1] || '');
  function aplicarFondo(f, op) {
    const b = document.body; const css = cssFondo(f);
    b.style.background = css || ''; if (f?.tipo === 'imagen') { b.style.backgroundSize = 'cover'; b.style.backgroundPosition = 'center'; } b.style.backgroundAttachment = 'fixed';
    document.documentElement.style.setProperty('--op', css ? String((op ?? 82) / 100) : '1');
  }

  // ---------------------------------------------------------------- API
  async function api(metodo, ruta, cuerpo, crudo) {
    const o = { method: metodo, headers: { 'X-Nucleo': '1', Accept: 'application/json' }, credentials: 'same-origin' };
    if (crudo) { o.headers['Content-Type'] = crudo.type || 'application/octet-stream'; o.body = crudo; }
    else if (cuerpo !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(cuerpo); }
    const r = await fetch('/api/' + ruta, o);
    const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta inválida (HTTP ' + r.status + ')' }));
    if (r.status === 401 && ruta !== 'sesion' && !ruta.startsWith('recuperar/')) { S = null; pintar(); throw new Error('La sesión venció: ingresá de nuevo'); }
    if (r.status === 428 && j.codigo === 'cambiar-clave') { pintarCambioObligatorio(); throw new Error(j.error); }
    if (!r.ok || j.ok === false) { const e = new Error(j.error || 'HTTP ' + r.status); e.codigo = j.codigo; e.http = r.status; throw e; }
    return j;
  }
  async function pim(metodo, ruta, cuerpo, cab = {}) {
    const o = { method: metodo, headers: Object.assign({ 'X-Nucleo': '1', Accept: 'application/fhir+json' }, cab), credentials: 'same-origin', cache: 'no-store' };   // nunca una lectura guardada: tras decidir sobre una orden hay que ver su versión nueva
    if (cuerpo !== undefined) { o.headers['Content-Type'] = 'application/fhir+json'; o.body = JSON.stringify(cuerpo); }
    const r = await fetch('/api/pim/' + ruta, o);
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error((j.issue && j.issue[0] && j.issue[0].diagnostics) || j.error || 'HTTP ' + r.status);
    return { cuerpo: j, cab: r.headers };
  }
  const fecha = (s) => { if (!s) return '—'; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? h(s) : d.toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }); };
  const aviso = (el, tipo, msg) => { if (el) el.innerHTML = `<div class="aviso ${tipo}">${h(msg)}</div>`; };
  function tabla(cols, filas, alClic) {
    if (!filas.length) return '<p class="mini">Sin datos.</p>';
    const id = 't' + Math.random().toString(36).slice(2);
    setTimeout(() => { const t = document.getElementById(id); if (t && alClic) t.querySelectorAll('tr[data-i]').forEach((tr) => tr.onclick = () => alClic(filas[+tr.dataset.i])); });
    // 1.6.0: en el celular cada fila es una tarjeta (la primera columna es el título; el resto, «etiqueta: valor»)
    return `<table id="${id}" class="resp"><thead><tr>${cols.map((c) => `<th>${h(c.t)}</th>`).join('')}</tr></thead><tbody>${filas.map((f, i) =>
      `<tr data-i="${i}" class="${alClic ? 'clic' : ''}">${cols.map((c) => { const v = c.html ? c.v(f) : h(c.v(f)); return `<td data-l="${h(c.t)}" class="${v === '' || v == null ? 'vacio' : ''}"><span class="tv">${v ?? ''}</span></td>`; }).join('')}</tr>`).join('')}</tbody></table>`;
  }
  function panel(titulo, html) {
    $('#panel').innerHTML = `<div style="display:flex;align-items:center;gap:8px"><h2 style="margin:0;color:var(--az);font-size:18px;flex:1">${h(titulo)}</h2><button class="b sec" id="pCerrar">Cerrar</button></div><div id="pCuerpo" style="margin-top:12px">${html}</div>`;
    $('#panel').classList.add('abierto'); $('#velo').classList.add('abierto');
    $('#pCerrar').onclick = cerrar; return $('#pCuerpo');
  }
  function cerrar() { $('#panel').classList.remove('abierto'); $('#velo').classList.remove('abierto'); $('.lateral')?.classList.remove('abierto'); }
  // ---------------------------------------------------------------- listas desplegables (catálogos editables, 1.6.0)
  const LISTAS = {}; let permisosListas = { agregar: false, admin: false };
  /** Trae (y guarda) varias listas de una vez: {clave: {def, valores}} */
  async function listas(claves, refrescar) {
    const faltan = claves.filter((k) => refrescar || !LISTAS[k]);
    if (faltan.length) {
      const j = await api('GET', 'catalogos?valores=' + encodeURIComponent(faltan.join(',')));
      Object.assign(LISTAS, j.listas); permisosListas = { agregar: j.puede_agregar, admin: j.puede_admin };
    }
    const o = {}; claves.forEach((k) => { o[k] = LISTAS[k]; }); return o;
  }
  const textoLista = (k, cod) => (LISTAS[k]?.valores.find((v) => v.codigo === cod)?.texto) || cod || '';
  const numero = (s) => { if (s === '' || s == null) return null; const t = String(s).trim().replace(/\s/g, ''); const n = Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t); return Number.isFinite(n) ? n : NaN; };

  /** Columnas según el ancho del formulario; cada fila se completa (la última se reparte): 12 pistas. */
  function simetria(f) {
    const g = f.querySelector('.fgrid'); if (!g) return;
    const w = g.clientWidth; const cols = w >= 980 ? 4 : w >= 700 ? 3 : w >= 440 ? 2 : 1;
    const items = [...g.children]; let fila = []; const filas = [];
    items.forEach((it) => { if (it.dataset.todo) { if (fila.length) filas.push(fila); filas.push([it]); fila = []; return; } fila.push(it); if (fila.length === cols) { filas.push(fila); fila = []; } });
    if (fila.length) filas.push(fila);
    filas.forEach((fl) => fl.forEach((it) => { it.style.gridColumn = `span ${fl.length === 1 && it.dataset.todo ? 12 : 12 / (fl.length === cols ? cols : fl.length) | 0}`; }));
  }
  /** Llena un <select> de lista con sus valores (filtrados por el padre si corresponde). */
  function llenarLista(sel, c, valorPadre) {
    const L = LISTAS[c.lista]; if (!L) return;
    const elegido = sel.value || sel.dataset.v || '';
    let vals = L.valores; if (c.padre) vals = valorPadre ? vals.filter((v) => v.padre === valorPadre) : [];
    const otro = (c.otro ?? true) && (+L.def.permite_otro ? permisosListas.agregar : permisosListas.admin);
    sel.innerHTML = `<option value="">${h(c.padre && !valorPadre ? '— elegí primero ' + (c.padreT || 'la anterior') + ' —' : (c.vacio || '— elegí —'))}</option>`
      + vals.map((v) => `<option value="${h(v.codigo)}" ${v.codigo === elegido ? 'selected' : ''}>${h(v.texto)}${c.sigla && v.sigla ? ' · ' + h(v.sigla) : ''}</option>`).join('')
      + (otro && (!c.padre || valorPadre) ? '<option value="__otro">＋ Otro… (agregar a la lista)</option>' : '');
    if (elegido && !vals.some((v) => v.codigo === elegido)) sel.value = '';
    const caja = sel.closest('.fcampo')?.querySelector('.fbus'); if (caja) caja.hidden = vals.length < 20;
  }
  /**
   * Formulario. Campos: {n, t, tipo, v, req, ayuda, ph, ancho:'todo'}
   *   tipo: text | number | decimal | date | select (op: [[v, t]]) | lista (lista: clave del catálogo, padre: campo, sigla) |
   *         opciones (cargar: async () => [[v, t]]) | textarea | check | password
   */
  function form(campos, boton, alEnviar, o = {}) {
    const id = 'f' + Math.random().toString(36).slice(2);
    const ctl = (c) => {
      const req = c.req ? 'required' : ''; const ph = c.ph ? `placeholder="${h(c.ph)}"` : '';
      if (c.tipo === 'select') return `<select name="${c.n}" ${req}>${c.op.map(([v, t]) => `<option value="${h(v)}" ${String(v) === String(c.v ?? '') ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`;
      if (c.tipo === 'lista' || c.tipo === 'opciones') return `<div class="fbus" hidden><input type="search" placeholder="Buscar en la lista…" aria-label="Buscar en ${h(c.t)}"></div><select name="${c.n}" ${req} data-v="${h(c.v ?? '')}"><option value="">Cargando…</option></select><div class="fotro" hidden></div>`;
      if (c.tipo === 'textarea') return `<textarea name="${c.n}" ${req} ${ph}>${h(c.v ?? '')}</textarea>`;
      if (c.tipo === 'check') return `<label class="fchk"><input type="checkbox" name="${c.n}" ${c.v ? 'checked' : ''}> ${h(c.ayudaCheck || 'Sí')}</label>`;
      if (c.tipo === 'number' || c.tipo === 'decimal') return `<div class="fnum"><input name="${c.n}" inputmode="decimal" autocomplete="off" value="${h(c.v ?? '')}" ${req} ${ph}>${c.unidad ? `<span>${h(c.unidad)}</span>` : ''}</div>`;
      return `<input name="${c.n}" type="${c.tipo || 'text'}" value="${h(c.v ?? '')}" ${req} ${ph} ${c.tipo === 'password' ? 'autocomplete="new-password"' : ''}>`;
    };
    setTimeout(async () => {
      const f = document.getElementById(id); if (!f) return;
      simetria(f); if (window.ResizeObserver) new ResizeObserver(() => simetria(f)).observe(f);
      // listas de catálogo y opciones cargadas
      const cl = campos.filter((c) => c.tipo === 'lista');
      if (cl.length) {
        try { await listas([...new Set(cl.map((c) => c.lista))]); } catch (x) { cl.forEach((c) => { f.elements[c.n].innerHTML = `<option value="">(no se pudo cargar: ${h(x.message)})</option>`; }); }
        cl.forEach((c) => { const pc = campos.find((x) => x.n === c.padre); c.padreT = pc ? '«' + pc.t + '»' : ''; llenarLista(f.elements[c.n], c, c.padre ? f.elements[c.padre]?.value : null); });
      }
      for (const c of campos.filter((x) => x.tipo === 'opciones')) {
        const s = f.elements[c.n];
        try { const op = await c.cargar(); s.innerHTML = `<option value="">${h(c.vacio || '— elegí —')}</option>` + op.map(([v, t]) => `<option value="${h(v)}" ${String(v) === String(c.v ?? '') ? 'selected' : ''}>${h(t)}</option>`).join('');
          s.closest('.fcampo').querySelector('.fbus').hidden = op.length < 20; } catch (x) { s.innerHTML = `<option value="">(no se pudo cargar)</option>`; }
      }
      // búsqueda dentro de la lista: filtra las opciones (el <select> nativo sigue funcionando con teclado y en el celular)
      f.querySelectorAll('.fbus input').forEach((q) => { q.oninput = () => { const s = q.closest('.fcampo').querySelector('select'); const t = q.value.trim().toLowerCase();
        let primero = null; [...s.options].forEach((op) => { const ok = !t || !op.value || op.value === '__otro' || op.text.toLowerCase().includes(t); op.hidden = !ok; if (ok && op.value && op.value !== '__otro' && !primero) primero = op; });
        if (t && primero && (s.selectedOptions[0]?.hidden || !s.value)) s.value = primero.value; s.dispatchEvent(new Event('change')); }; });
      // dependientes y «Otro…»
      cl.forEach((c) => {
        const s = f.elements[c.n];
        s.addEventListener('change', async () => {
          campos.filter((x) => x.tipo === 'lista' && x.padre === c.n).forEach((hijo) => { f.elements[hijo.n].dataset.v = ''; llenarLista(f.elements[hijo.n], hijo, s.value === '__otro' ? '' : s.value); });
          if (s.value !== '__otro') return;
          const caja = s.closest('.fcampo').querySelector('.fotro'); const L = LISTAS[c.lista];
          caja.hidden = false; caja.innerHTML = `<div class="fotro-in"><input placeholder="Nuevo valor para «${h(L.def.nombre)}»" maxlength="160">${+L.def.con_sigla ? '<input class="sig" placeholder="Sigla" maxlength="6" title="Sigla de 2 a 6 letras (vacía: se genera)">' : ''}
            <button type="button" class="b">Agregar</button><button type="button" class="b sec">Cancelar</button></div><div class="fotro-msg"></div>`;
          const [inp, sig] = caja.querySelectorAll('input'); const [bA, bC] = caja.querySelectorAll('button'); inp.focus();
          bC.onclick = () => { caja.hidden = true; s.value = ''; };
          const agregar = async () => { try { bA.disabled = true;
              const r = await api('POST', 'catalogos/' + c.lista, { texto: inp.value, padre: c.padre ? f.elements[c.padre].value : undefined, sigla: sig?.value || undefined });
              await listas([c.lista], true); s.dataset.v = r.valor.codigo; llenarLista(s, c, c.padre ? f.elements[c.padre].value : null); s.value = r.valor.codigo; caja.hidden = true;
              s.dispatchEvent(new Event('change'));
            } catch (x) { aviso(caja.querySelector('.fotro-msg'), 'mal', x.message); } finally { bA.disabled = false; } };
          bA.onclick = agregar; inp.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); agregar(); } };
        });
      });
      if (o.alCargar) o.alCargar(f);
      f.onsubmit = async (e) => {
        e.preventDefault(); const d = {}; const msg = f.querySelector('.fmsg'); const b = f.querySelector('button[type=submit]');
        try {
          campos.forEach((c) => { const x = f.elements[c.n]; if (!x) return;
            if (c.tipo === 'number' || c.tipo === 'decimal') { const n = numero(x.value); if (Number.isNaN(n)) throw new Error(`«${c.t}»: escribí un número (la coma decimal vale)`); d[c.n] = n; }
            else if (c.tipo === 'check') d[c.n] = x.checked;
            else if (x.value === '__otro') throw new Error(`«${c.t}»: escribí el valor nuevo y tocá «Agregar», o elegí uno de la lista`);
            else d[c.n] = x.value; });
          b.disabled = true; await alEnviar(d, msg, f);
        } catch (x) { aviso(msg, 'mal', x.message); } finally { b.disabled = false; }
      };
    });
    return `<form id="${id}" class="form"><div class="fgrid">${campos.map((c) => `<div class="fcampo" ${c.tipo === 'textarea' || c.ancho === 'todo' ? 'data-todo="1"' : ''}>
      <label title="${h(c.t)}">${h(c.t)}${c.req ? ' <b class="req">*</b>' : ''}</label>${ctl(c)}${c.ayuda ? `<small class="fayuda">${h(c.ayuda)}</small>` : ''}</div>`).join('')}</div>
      <div class="acciones"><button class="b" type="submit">${h(boton)}</button>${o.extra || ''}</div><div class="fmsg"></div></form>`;
  }
  /** Bloque plegable con un botón claro («＋ Nuevo activo»): lo que no se usa siempre, queda guardado. */
  const seccion = (titulo, html, abierta) => `<details class="desp" ${abierta ? 'open' : ''}><summary><span class="mas">＋</span>${h(titulo)}</summary><div class="desp-c">${html}</div></details>`;
  const puede = (cap) => !!S && (S.usuario.rol === 'AD' || S.capacidades.includes(cap));
  /** Tarjetas de indicadores con el estilo del tablero: [{n, t, sub, icono, color}] */
  /** Con «k» la tarjeta es un botón: el módulo la enlaza con alClicKpi(el, (k) => …) para ir al detalle de ese número (1.7.0). */
  const kpis = (l) => `<div class="kpis">${l.map((k) => `<div class="kpi${k.k ? ' kclic' : ''}${k.activo ? ' activo' : ''}" ${k.k ? `data-k="${h(k.k)}" role="button" tabindex="0" title="Ver el detalle"` : ''} style="--kc:${h(k.color || 'var(--az3)')}"><div class="ico">${ic(k.icono || 'modulo')}</div>
    <div class="tx"><small>${h(k.t)}</small><b>${h(k.n ?? '—')}</b>${k.sub ? `<span>${h(k.sub)}</span>` : ''}</div></div>`).join('')}</div>`;

  const alClicKpi = (el, fn) => $$('.kpi.kclic', el).forEach((k) => { k.onclick = () => fn(k.dataset.k, k); k.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(k.dataset.k, k); } }; });
  /** Descarga una tabla como CSV que Excel abre bien (UTF-8 con BOM, separador «;»): csv('archivo', [{t, v}], filas) (1.7.0). */
  function csv(nombre, cols, filas) {
    const c = (x) => { let t = x == null ? '' : String(x); if (/^[=+\-@]/.test(t) && Number.isNaN(Number(t.replace(',', '.')))) t = "'" + t;   // que Excel no lo tome como fórmula
      return /[";\n\r]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; };
    const txt = [cols.map((k) => c(k.t)).join(';'), ...filas.map((f) => cols.map((k) => c(k.v(f))).join(';'))].join('\r\n');
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + txt], { type: 'text/csv;charset=utf-8' }));
    a.download = nombre.replace(/[^\w.-]+/g, '_') + '.csv'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  /**
   * Gráfico SVG liviano, sin bibliotecas (ola 3): grafico({tipo, etiquetas, series, fmt, alto}) → HTML con el dibujo y su leyenda.
   *  tipo 'columnas': columnas agrupadas (una por serie) · 'apiladas': columnas apiladas · 'lineas': líneas (serie con punteada: true = proyección)
   *  · 'barras': barras horizontales (primera serie) · 'torta': anillo (primera serie, una porción por etiqueta).
   *  series: [{n: nombre, valores: [..], color?, punteada?}] · fmt(v) da el texto de los valores. Sin datos devuelve un aviso.
   */
  const PALETA = ['#1e88e5', '#fb8c00', '#43a047', '#8e24aa', '#e53935', '#00897b', '#6d4c41', '#3949ab', '#c0ca33', '#546e7a'];
  function grafico(o) {
    const tipo = o.tipo || 'columnas', E = (o.etiquetas || []).map(String), S0 = (o.series || []).map((s, i) => ({ ...s, color: s.color || PALETA[i % PALETA.length], valores: (s.valores || []).map((v) => +v || 0) }));
    const f = o.fmt || ((v) => Number(v).toLocaleString('es-AR', { maximumFractionDigits: 1 }));
    if (!E.length || !S0.length || S0.every((s) => s.valores.every((v) => !v))) return '<p class="mini graf-vacio">Sin datos para este período.</p>';
    const ley = (l) => `<div class="graf-ley">${l.map((x) => `<span><i style="background:${x.color}${x.punteada ? ';opacity:.55' : ''}"></i>${h(x.n)}</span>`).join('')}</div>`;
    const tit = (t) => `<title>${h(t)}</title>`;
    if (tipo === 'barras') {
      const s = S0[0], mx = Math.max(...s.valores, 0) || 1;
      return `<div class="barras graf-b">${E.map((e, i) => `<div class="br" style="--bc:${s.color}" title="${h(e)}: ${h(f(s.valores[i]))}"><span>${h(e)}</span><i style="width:${Math.max(1, s.valores[i] / mx * 100)}%"></i><b>${h(f(s.valores[i]))}</b></div>`).join('')}</div>`;
    }
    if (tipo === 'torta') {
      const v = S0[0].valores, tot = v.reduce((a, b) => a + b, 0) || 1; let a0 = -Math.PI / 2; const R = 70, r = 42, cx = 90, cy = 90;
      const arcos = v.map((x, i) => { if (!x) return ''; if (x === tot) return `<circle cx="${cx}" cy="${cy}" r="${(R + r) / 2}" fill="none" stroke="${PALETA[i % PALETA.length]}" stroke-width="${R - r}">${tit(`${E[i]}: ${f(x)} (100 %)`)}</circle>`;   // una sola porción: anillo entero
       const a1 = a0 + (x / tot) * Math.PI * 2 - (x === tot ? 1e-4 : 0), g = a1 - a0 > Math.PI ? 1 : 0;
        const P = (rr, an) => `${(cx + rr * Math.cos(an)).toFixed(2)},${(cy + rr * Math.sin(an)).toFixed(2)}`;
        const d = `M${P(R, a0)}A${R},${R} 0 ${g} 1 ${P(R, a1)}L${P(r, a1)}A${r},${r} 0 ${g} 0 ${P(r, a0)}Z`; a0 = a1 + (x === tot ? 1e-4 : 0);
        return `<path d="${d}" fill="${PALETA[i % PALETA.length]}">${tit(`${E[i]}: ${f(x)} (${Math.round(x / tot * 100)} %)`)}</path>`; }).join('');
      return `<div class="graf graf-t"><svg viewBox="0 0 180 180" role="img" aria-label="${h(o.titulo || 'Gráfico de torta')}">${arcos}<text x="90" y="95" text-anchor="middle" class="graf-c">${h(f(tot))}</text></svg>
        ${ley(E.map((e, i) => ({ n: `${e} · ${Math.round(v[i] / tot * 100)} %`, color: PALETA[i % PALETA.length] })).filter((_, i) => v[i]))}</div>`;
    }
    const W = 560, H = o.alto || 240, L = 62, R = 10, T = 12, B = 34, n = E.length, iw = W - L - R, ih = H - T - B;
    const apil = tipo === 'apiladas';
    const mx = Math.max(1e-9, ...(apil ? E.map((_, i) => S0.reduce((a, s) => a + s.valores[i], 0)) : S0.flatMap((s) => s.valores)));
    const Y = (v) => T + ih - (v / mx) * ih, paso = iw / n, cada = Math.ceil(n / 12);
    let ejes = ''; for (let k = 0; k <= 4; k++) { const v = mx * k / 4, y = Y(v).toFixed(1); ejes += `<line x1="${L}" x2="${W - R}" y1="${y}" y2="${y}" class="graf-g"/><text x="${L - 6}" y="${(+y + 4).toFixed(1)}" text-anchor="end" class="graf-e">${h(f(v))}</text>`; }
    const xs = E.map((e, i) => (i % cada ? '' : `<text x="${(L + paso * (i + .5)).toFixed(1)}" y="${H - 12}" text-anchor="middle" class="graf-e">${h(e.length > 10 ? e.slice(0, 9) + '…' : e)}</text>`)).join('');
    let cuerpo = '';
    if (tipo === 'lineas') cuerpo = S0.map((s) => { const pts = s.valores.map((v, i) => (s.desde != null && i < s.desde ? null : `${(L + paso * (i + .5)).toFixed(1)},${Y(v).toFixed(1)}`)).filter(Boolean);
      return `<polyline points="${pts.join(' ')}" fill="none" stroke="${s.color}" stroke-width="2.4" ${s.punteada ? 'stroke-dasharray="6 5"' : ''} stroke-linejoin="round"/>`
        + s.valores.map((v, i) => (s.desde != null && i < s.desde ? '' : `<circle cx="${(L + paso * (i + .5)).toFixed(1)}" cy="${Y(v).toFixed(1)}" r="3.2" fill="#fff" stroke="${s.color}" stroke-width="2">${tit(`${s.n} · ${E[i]}: ${f(v)}`)}</circle>`)).join(''); }).join('');
    else E.forEach((e, i) => { const w = Math.min(paso * .72, (apil ? 1 : S0.length) * 46), x0 = L + paso * i + (paso - w) / 2; let acum = 0;   // columnas de ancho acotado (con un solo mes no ocupan todo)
      S0.forEach((s, j) => { const v = s.valores[i]; if (!v) return;
        const bw = apil ? w : w / S0.length, x = apil ? x0 : x0 + bw * j, y = apil ? Y(acum + v) : Y(v), hh = apil ? Y(acum) - Y(acum + v) : T + ih - Y(v); acum += v;
        cuerpo += `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${Math.max(1, bw - 1.5).toFixed(1)}" height="${Math.max(.5, hh).toFixed(1)}" rx="2.5" fill="${s.color}" ${s.punteada ? 'opacity=".5"' : ''}>${tit(`${s.n} · ${e}: ${f(v)}`)}</rect>`; }); });
    return `<div class="graf"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${h(o.titulo || 'Gráfico')}">${ejes}${cuerpo}${xs}</svg>${S0.length > 1 || o.leyenda ? ley(S0) : ''}</div>`;
  }
  let PARAM = new URLSearchParams();
  /** SVG del código QR de un texto corto (lo arma el núcleo; ola 3c). */
  const qr = async (texto) => (await api('GET', 'qr?texto=' + encodeURIComponent(texto))).svg;
  window.Nucleo = {
    api, pim, h, fecha, aviso, tabla, panel, cerrar, form, puede, kpis, alClicKpi, csv, icono: ic, listas, textoLista, numero, seccion, grafico,
    get sesion() { return S; },
    /** Parámetros del enlace directo (#acceso?clave=valor) con que se abrió el acceso actual; se consumen con limpiarParametros(). */
    get parametros() { return PARAM; }, limpiarParametros() { PARAM = new URLSearchParams(); history.replaceState(null, '', '#' + actual); }, qr,
    /** Un módulo registra su acceso: {id, titulo, orden, cap, render(el), grupo?, icono?, desc?, sinCap?} */
    pestana(p) { pestanas.push(p); pestanas.sort((a, b) => (a.orden ?? 50) - (b.orden ?? 50)); },
    /** Un módulo agrega un widget al «Resumen» del inicio: tablero(async (el) => {...}, {id, titulo}) */
    tablero(fn, m = {}) { widgets.push({ fn, id: m.id || 'w' + widgets.length, titulo: m.titulo || 'Widget ' + (widgets.length + 1) }); },
    /** (módulo widgets) Quita un acceso registrado: lo usa el módulo «widgets» al volver a leer los widgets creados. */
    quitarPestana(id) { const i = pestanas.findIndex((p) => p.id === id); if (i >= 0) pestanas.splice(i, 1); },
    /** (módulo widgets) Aplica el catálogo del nodo y vuelve a dibujar el menú. */
    catalogo(c, redibujar) { aplicarCatalogo(c); if (redibujar && S) pintar(); },
    /** (módulo widgets) Una función que se espera en cada ingreso, antes de dibujar: alIniciar(async () => {...}) */
    alIniciar(fn) { alIniciar.push(fn); },
    /** (módulo widgets) Un módulo agrega una solapa a «Configuración»: configuracion({id, titulo, render(el)}) */
    configuracion(x) { cfgExtra.push(x); },
    get iconos() { return Object.keys(P); }, get accesos() { return pestanas.filter((p) => !p.oculta && p.id !== 'inicio').map((p) => ({ id: p.id, titulo: p._t0 ?? p.titulo, grupo: p.grupo || (META[p.id] || [])[0] || 'otros', icono: p.icono || (META[p.id] || [])[1] || 'modulo', orden: p._o0 ?? p.orden, definido: !!p.definido })); },
    get grupos0() { return GRUPOS0; },
  };

  // ---------------------------------------------------------------- vistas del núcleo
  Nucleo.tablero(async (el) => {
    if (!puede('sistema.ver')) { el.remove(); return; }
    const s = (await api('GET', 'proc/sistema')).sistema;
    el.innerHTML = `${kpis([{ t: 'Nodo', n: s.nodo.id, sub: s.nodo.nombre, icono: 'nodo', color: '#1e88e5' },
      { t: 'Módulos', n: s.modulos.cargados.length, sub: 'cargados', icono: 'modulo', color: '#43a047' },
      { t: 'Colmena', n: s.vinculos_activos, sub: 'vínculos activos', icono: 'colmena', color: '#00897b' },
      { t: 'Diario firmado', n: s.diario, sub: 'asientos', icono: 'diario', color: '#8e24aa' }])}
      ${s.contaminado.length ? `<div class="aviso mal">Nodo «contaminado»: hay módulos con licencia no libre (${h(s.contaminado.join(', '))}).</div>` : ''}`;
  }, { id: 'nucleo.nodo', titulo: 'Estado del nodo' });

  Nucleo.pestana({ id: 'colmena', titulo: 'Colmena', orden: 80, cap: 'colmena.ver', render: async (el) => {
    const j = await api('GET', 'colmena/vinculos');
    const cola = await api('GET', 'colmena/cola').catch(() => ({ cola: [], horas: 72 }));
    const ml = await api('GET', 'colmena/malla').catch(() => null);   // 1.7.0 (malla): malla (hub propio, hubs alcanzados, caminos)
    const est = { activo: 'verde', entrante: 'nar', saliente: 'ama', revocado: 'gris' };
    const MODO = { malla: ['verde', 'Malla'], central: ['ama', 'Solo hub central'], aislado: ['rojo', 'Aislado'] };
    const HEST = { ok: ['verde', 'en uso'], apagado: ['gris', 'apagado'], 'sin-hub': ['gris', 'sin hub (versión anterior)'], caido: ['ama', 'no responde'], rechaza: ['rojo', 'no acepta a este nodo'],
                   'sin uso': ['gris', 'sin uso'], 'sin registro': ['ama', 'falta registrar'], nuevo: ['gris', 'probando'] };
    const ms = (n) => n == null ? 'sin medir' : (n >= 1000 ? (n / 1000).toFixed(1).replace('.', ',') + ' s' : n + ' ms');
    const mallaHtml = !ml ? '' : `<div class="tarjeta" id="cMalla"><h2>Red de la colmena <span class="et ${MODO[ml.modo][0]}">${MODO[ml.modo][1]}</span></h2>
      <p class="mini">Cada nodo es hub, puente, emisor y receptor. Este nodo escucha a la vez en el hub central y en el hub de cada nodo vinculado que alcanza, y para cada envío usa el camino más rápido que ande; si uno cae pasa al siguiente y, si no queda ninguno, el envío espera en la cola de salida.</p>
      ${ml.modo === 'aislado' ? '<div class="aviso mal"><b>Este nodo está aislado.</b> No manda ni recibe nada de la colmena; lo que sale espera en la cola. Para reconectarlo elegí «Malla» o «Solo hub central».</div>' : ''}
      ${puede('colmena.admin') ? `<div class="malla-ctl"><div class="malla-sw"><button type="button" class="conm ${ml.hub_propio.encendido ? 'si' : ''}" id="mHub" role="switch" aria-checked="${ml.hub_propio.encendido}" aria-label="Hub propio"><span></span></button>
          <div><b>Hub propio: ${ml.hub_propio.encendido ? 'encendido' : 'apagado'}</b>${ml.hub_propio.encendido && !ml.hub_propio.activo ? ' <span class="et gris">en pausa por el modo de red</span>' : ''}
          <div class="mini">Encendido, este nodo guarda y reenvía sobres para los nodos vinculados que lo usan de relevo o de puente (no puede leerlos: van sellados). Apagalo si sospechás un ataque por ese lado: el nodo sigue en la colmena usando los caminos de los demás.</div></div></div>
        <div class="malla-modo"><div class="mini" style="margin-bottom:6px"><b>Qué caminos usa este nodo</b></div><div class="seg3" id="mModo">${[['malla', 'Malla', 'todos los caminos'], ['central', 'Solo hub central', 'nada entra directo a este equipo'], ['aislado', 'Aislado', 'sin colmena hasta que lo reconectes']]
          .map(([k, t, d]) => `<button type="button" data-m="${k}" class="${ml.modo === k ? 'sel ' + k : ''}">${t}<small>${d}</small></button>`).join('')}</div></div></div><div id="mMsg"></div>` : ''}
      ${ml.modo === 'aislado' ? '' : `<dl style="margin-top:12px"><dt>Hub propio</dt><dd>${ml.hub_propio.activo ? ml.hub_propio.urls.map(h).join(' · ') + (ml.hub_propio.oyentes.length ? ' — escuchan acá: <b>' + ml.hub_propio.oyentes.map(h).join(', ') + '</b>' : ' — todavía no escucha ningún nodo') : 'apagado'}
          ${ml.hub_propio.trabajo && ml.hub_propio.trabajo.ultimo ? `<div class="mini">Pasaron por su buzón ${ml.hub_propio.trabajo.buzon || 0} sobres y reenvió ${ml.hub_propio.trabajo.reenviados || 0} como puente${ml.hub_propio.en_buzon ? ' · ' + ml.hub_propio.en_buzon + ' esperan a su destinatario' : ''}.</div>` : ''}</dd></dl>
        ${ml.hubs.length ? '<h3 class="malla-h">Hubs que usa este nodo</h3>' + tabla([{ t: 'Hub', v: (x) => x.nombre }, { t: 'Estado', html: true, v: (x) => `<span class="et ${(HEST[x.estado] || HEST.nuevo)[0]}">${(HEST[x.estado] || [0, x.estado])[1]}</span>` },
          { t: 'Escucha', html: true, v: (x) => x.estado !== 'ok' ? '' : (x.escucha ? '<span class="et verde">abierta</span>' : (x.espera_max >= 2 ? '<span class="et ama">todavía no</span>' : '<span class="et gris">cada 15 s</span>')) },
          { t: 'Por ahí se llega a', v: (x) => x.clave === 'central' ? 'los nodos registrados en él' : [...new Set([...(x.oyentes || []), ...(x.alcanza || [])])].filter((n) => n !== j.yo.node_id).join(', ') }], ml.hubs) : ''}
        ${ml.caminos.length ? '<h3 class="malla-h">Camino a cada nodo vinculado</h3>' + tabla([{ t: 'Nodo', v: (x) => x.par + ' · ' + (x.nombre || '') },
          { t: 'Camino elegido', html: true, v: (x) => { const c = x.caminos.find((y) => y.elegido); return c ? `<b>${h(c.nombre)}</b> <span class="mini">${ms(c.ms)}</span>` : '<span class="et rojo">sin camino</span>'; } },
          { t: 'Alternativas', html: true, v: (x) => x.caminos.filter((y) => !y.elegido).map((y) => y.en_pausa ? `<span class="mini" title="${h(y.error || '')}">${h(y.nombre)} (en pausa)</span>` : `${h(y.nombre)} <span class="mini">${ms(y.ms)}</span>`).join(' · ') },
          { t: 'En cola', v: (x) => x.en_cola || '' }], ml.caminos) : '<p class="mini">Todavía no hay vínculos activos.</p>'}
        <div class="acciones"><button class="b sec" id="mSond">Volver a medir los caminos</button></div>`}</div>`;
    el.innerHTML = `<div class="tarjeta"><h2>Este nodo</h2><dl><dt>Nodo</dt><dd>${h(j.yo.node_id)} · ${h(j.yo.nombre)} (${h(j.yo.producto)} ${h(j.yo.version)})</dd>
      <dt>Huella</dt><dd><code>${h(j.yo.huella)}</code> <span class="mini">— dictala por teléfono al otro administrador antes de que acepte</span></dd>
      <dt>Dirección</dt><dd>${h(j.yo.url)}</dd><dt>Hub central</dt><dd>${j.hub.url ? h(j.hub.url) + (j.hub.activo ? ' · activo' : ' · apagado') : 'sin configurar (solo la red local y los hubs de otros nodos)'}</dd></dl></div>
      ${mallaHtml}
      <div class="tarjeta"><h2>Probar el camino</h2><p class="mini">Los nodos de la misma red se hablan directo o por el hub de cualquiera de ellos; los de otra red (otro edificio, otra ciudad) se encuentran en el hub central: cada nodo solo hace salidas HTTPS, como AnyDesk, sin abrir puertos ni configurar el router. Los sobres van firmados y sellados: ningún hub ni puente puede leerlos.</p>
        <div class="acciones"><button class="b" id="cDiag">Probar camino</button>${j.vinculos.some((v) => v.estado === 'activo') ? '<label class="fchk" style="margin-left:8px"><input type="checkbox" id="cDiagV" checked> con un pedido de prueba a cada vínculo</label>' : ''}</div><div id="cDiagR"></div>
        ${puede('colmena.admin') ? `<details class="desp" ${j.hub.url ? '' : 'open'}><summary><span class="mas">＋</span>Configurar el hub central</summary><div class="desp-c"><form class="form" id="cHub"><div class="fgrid">
          <div class="fcampo" style="grid-column:span 12"><label>Dirección del hub</label><input name="url" value="${h(j.hub.url || 'https://iproy.com.ar/colmena-hub/')}" placeholder="https://iproy.com.ar/colmena-hub/"></div>
          <div class="fcampo" style="grid-column:span 8"><label>Código de registro</label><input name="codigo" type="password" autocomplete="off" placeholder="${j.hub.url ? 'dejalo vacío para conservar el guardado' : 'lo da el administrador del hub'}"><small class="fayuda">Se escribe acá, en cada PC; no se muestra ni se manda por chat ni correo.</small></div>
          <div class="fcampo" style="grid-column:span 4"><label>Estado</label><label class="fchk"><input type="checkbox" name="activo" ${j.hub.url ? (j.hub.activo ? 'checked' : '') : 'checked'}> Activo</label></div></div>
          <div class="acciones"><button class="b" type="submit">Guardar y registrar</button></div><div class="fmsg"></div></form></div></details>` : ''}</div>
      <div class="tarjeta"><h2>Vínculos</h2>${tabla([
        { t: 'Nodo', v: (v) => v.par + ' · ' + (v.nombre || '') }, { t: 'Producto', v: (v) => v.producto || '' },
        { t: 'Estado', html: true, v: (v) => `<span class="et ${est[v.estado] || ''}">${h(v.estado)}</span>` }, { t: 'Rol PIM que le damos', v: (v) => v.rol_pim },
        { t: 'Huella', v: (v) => v.huella }, { t: 'Último contacto', v: (v) => fecha(v.ultimo_contacto) }], j.vinculos, (v) => detalleVinculo(v))}
      ${puede('colmena.admin') ? `<h2 style="margin-top:16px">Pedir un vínculo</h2>${form([{ n: 'destino', t: 'URL del otro nodo (https://IP:8443) o su identificador (lo busca en el hub central y en el hub de los nodos ya vinculados)', req: true }], 'Pedir vínculo', async (d, m) => {
        const r = await api('POST', 'colmena/vincular', d); aviso(m, 'ok', `Pedido a ${r.par} (${r.producto || ''}). Huella del otro nodo: ${r.huella}. ${r.aviso}`); })}` : ''}</div>`
      + (cola.cola.length ? `<div class="tarjeta"><h2>Cola de salida <span class="et ama">${cola.cola.length} sin entregar</span></h2>
        <p class="mini">Envíos que esperan a que haya algún camino (el otro nodo, un hub, un puente o la red de este equipo). Salen solos, en orden; si pasan ${cola.horas} horas sin poder entregarse, se cierran y queda asentado en el diario.</p>
        ${tabla([{ t: 'Para', v: (c) => c.par }, { t: 'Qué', v: (c) => c.tipo }, { t: 'Desde', v: (c) => fecha(c.creado) }, { t: 'Intentos', v: (c) => c.intentos },
                 { t: 'Último motivo', v: (c) => c.ultimo_error || '' }], cola.cola)}
        <div class="acciones"><button class="b sec" id="colaYa">Reintentar ahora</button></div><div id="colaMsg"></div></div>` : '');
    // 1.7.0: «Probar camino» y configuración del hub desde la pantalla (antes, solo por comando)
    const ESTD = { ok: ['verde', 'bien'], aviso: ['ama', 'atención'], mal: ['rojo', 'falla'], info: ['gris', 'dato'] };
    $('#cDiag').onclick = async (e) => { const b = e.target, r0 = $('#cDiagR'), conV = $('#cDiagV')?.checked; b.disabled = true;
      aviso(r0, 'info', conV ? 'Probando el camino y mandando un pedido de prueba a cada vínculo (hasta 25 s cada uno)…' : 'Probando el camino…');
      try { const d = await api('GET', 'colmena/diagnostico' + (conV ? '?vinculos=1' : ''));
        r0.innerHTML = `<div class="aviso ${d.camino === 'ok' ? 'ok' : 'mal'}">${d.camino === 'ok' ? 'El camino está bien.' : 'Hay algo para arreglar: mirá las filas en rojo.'}</div>
          <table class="resp"><thead><tr><th>Prueba</th><th>Resultado</th><th>Detalle</th><th>Qué hacer</th></tr></thead><tbody>${d.pruebas.map((x) => `<tr><td data-l="Prueba"><b>${h(x.titulo)}</b></td>
          <td data-l="Resultado"><span class="et ${(ESTD[x.estado] || ESTD.info)[0]}">${(ESTD[x.estado] || ESTD.info)[1]}</span></td><td data-l="Detalle">${h(x.detalle)}</td><td data-l="Qué hacer">${h(x.remedio || '')}</td></tr>`).join('')}</tbody></table>`; }
      catch (x) { aviso(r0, 'mal', x.message); } b.disabled = false; };
    // 1.7.0 (malla): interruptores de la malla
    const mmsg = $('#mMsg'), malla = async (cambio, texto) => { try { aviso(mmsg, 'info', texto); await api('PUT', 'colmena/config', { malla: cambio }); ir('colmena'); } catch (x) { aviso(mmsg, 'mal', x.message); } };
    if ($('#mHub')) $('#mHub').onclick = () => { const on = ml.hub_propio.encendido;
      if (on && !confirm('¿Apagar el hub propio de este nodo?\n\nDeja de guardar y reenviar sobres para los demás (los que lo usaban buscan otro camino). El nodo sigue en la colmena.')) return;
      malla({ hub_propio: !on }, on ? 'Apagando el hub propio…' : 'Encendiendo el hub propio…'); };
    document.querySelectorAll('#mModo button').forEach((b) => b.onclick = () => { const m = b.dataset.m; if (m === ml.modo) return;
      const q = { aislado: '¿AISLAR este nodo?\n\nNo va a mandar ni recibir nada de la colmena hasta que lo reconectes desde esta misma pantalla. Lo que salga espera en la cola (72 horas).',
                  central: '¿Usar solo el hub central?\n\nNada va a entrar directo a este equipo y no va a usar ni ofrecer hubs de nodos: solo saca y trae sobres del hub central.', malla: '' }[m];
      if (q && !confirm(q)) return;
      malla({ modo: m }, 'Cambiando el modo de red…'); });
    if ($('#mSond')) $('#mSond').onclick = async (e) => { e.target.disabled = true; e.target.textContent = 'Midiendo…'; try { await api('POST', 'colmena/malla/sondear'); await new Promise((r) => setTimeout(r, 2500)); } catch (x) {} ir('colmena'); };
    const fh = $('#cHub');
    if (fh) fh.onsubmit = async (e) => { e.preventDefault(); const m = fh.querySelector('.fmsg'), hub = { url: fh.url.value.trim(), activo: fh.activo.checked };
      if (fh.codigo.value) hub.codigo = fh.codigo.value;
      try { await api('PUT', 'colmena/config', { hub }); fh.codigo.value = '';
        if (hub.activo && hub.url) { aviso(m, 'info', 'Guardado. Registrando el nodo en el hub…'); const r = await api('POST', 'colmena/hub/registrar'); aviso(m, 'ok', r.hub?.nuevo ? 'Nodo registrado en el hub.' : 'El nodo ya estaba registrado en el hub: quedó al día.'); }
        else aviso(m, 'ok', 'Guardado.');
        setTimeout(() => ir('colmena'), 1500); }
      catch (x) { aviso(m, 'mal', x.message); } };
    const by = $('#colaYa');
    if (by) by.onclick = async () => { const r = await api('POST', 'colmena/cola/reintentar'); aviso($('#colaMsg'), r.pendientes ? 'info' : 'ok', `${r.entregados} entregados, ${r.pendientes} siguen en cola.`); };
  } });
  function detalleVinculo(v) {
    const p = panel('Vínculo con ' + v.par, `<dl><dt>Nombre</dt><dd>${h(v.nombre)}</dd><dt>Producto</dt><dd>${h(v.producto)}</dd><dt>Estado</dt><dd>${h(v.estado)}</dd>
      <dt>Huella</dt><dd><code>${h(v.huella)}</code></dd><dt>Dirección</dt><dd>${h(v.url_ok || v.url || 'por hubs (no se lo alcanza directo)')}</dd><dt>Rol PIM</dt><dd>${h(v.rol_pim)}</dd></dl>
      <div class="acciones">${v.estado === 'entrante' && puede('colmena.admin') ? '<button class="b" data-a="aceptar">Aceptar (rol consulta)</button>' : ''}
      ${v.estado === 'activo' ? '<button class="b sec" data-a="ping">Probar</button><button class="b sec" data-a="rpc">Contar sus activos y órdenes</button>' : ''}
      ${v.estado === 'activo' && pestanas.some((x) => x.id === 'pedidos_red') && puede('pedidos_red.pedir') ? '<button class="b sec" data-a="pedir">Pedirle un trabajo</button>' : ''}</div>
      ${v.estado === 'activo' && puede('colmena.admin') ? `<div class="fcampo" style="margin-top:10px"><label>Rol que este nodo le da a ${h(v.nombre || v.par)}</label><select id="vRol">${[['CI', 'CI · consulta: puede ver el PIM de este nodo'], ['SO', 'SO · solicitante: además puede pedir trabajos (crea órdenes acá)']].map(([k, t]) => `<option value="${k}" ${v.rol_pim === k ? 'selected' : ''}>${t}</option>`).join('')}</select></div><div class="acciones"><button class="b sec" data-a="rol">Guardar el rol</button>` : '<div class="acciones">'}
      ${v.estado !== 'revocado' && puede('colmena.admin') ? '<button class="b peligro" data-a="revocar">Revocar</button>' : ''}</div><div id="vMsg"></div>`);
    p.querySelectorAll('button[data-a]').forEach((b) => b.onclick = async () => {
      const m = $('#vMsg');
      try {
        if (b.dataset.a === 'aceptar') { await api('POST', `colmena/vinculos/${v.par}/aceptar`, { rol: 'CI' }); aviso(m, 'ok', 'Vínculo activo.'); }
        if (b.dataset.a === 'revocar') { await api('POST', `colmena/vinculos/${v.par}/revocar`); aviso(m, 'ok', 'Vínculo revocado.'); }
        if (b.dataset.a === 'pedir') { cerrar(); return ir('pedidos_red'); }
        if (b.dataset.a === 'rol') { const r = await api('POST', `colmena/vinculos/${v.par}/rol`, { rol: $('#vRol').value }); aviso(m, 'ok', `Rol guardado: ${r.rol_pim}.`); }
        if (b.dataset.a === 'ping') { const r = await api('POST', `colmena/vinculos/${v.par}/ping`); aviso(m, 'ok', r.respuesta === 'pong' ? `Respondió (${r.ruta || r.via}, ${r.ms} ms).` : `Salió por ${r.ruta || r.via}: la respuesta llega por el buzón.`); }
        if (b.dataset.a === 'rpc') {
          aviso(m, 'info', 'Consultando la API PIM del otro nodo…');
          const a = (await api('POST', 'colmena/rpc', { par: v.par, metodo: 'GET', ruta: 'Device?_summary=count', esperar: 60 })).rpc;
          const t = (await api('POST', 'colmena/rpc', { par: v.par, metodo: 'GET', ruta: 'Task?_summary=count', esperar: 60 })).rpc;
          aviso(m, a.estado === 'respondido' ? 'ok' : 'mal', a.estado === 'respondido' ? `Activos: ${a.respuesta.cuerpo.total ?? '?'} · Órdenes: ${t.respuesta?.cuerpo?.total ?? '?'} (vía ${a.via})` : `Estado: ${a.estado}`);
        }
      } catch (x) { aviso(m, 'mal', x.message); }
    });
  }

  Nucleo.pestana({ id: 'sistema', titulo: 'Sistema', orden: 90, cap: 'sistema.ver', render: async (el) => {
    const [m, t, d] = await Promise.all([api('GET', 'proc/modulos'), api('GET', 'proc/tareas'), api('GET', 'proc/diario?n=40')]);
    const ce = { cargado: 'verde', desactivado: 'gris', error: 'rojo', dependencia: 'nar', ciclo: 'rojo' };
    el.innerHTML = `<div class="tarjeta"><h2>Módulos</h2>${tabla([{ t: 'Módulo', v: (x) => x.id + ' ' + x.version }, { t: 'Nombre', v: (x) => x.nombre },
        { t: 'Estabilidad', v: (x) => x.estabilidad }, { t: 'Licencia', v: (x) => x.licencia + (x.libre ? '' : ' ⚠') },
        { t: 'Estado', html: true, v: (x) => `<span class="et ${ce[x.estado] || ''}">${h(x.estado)}</span>` + (x.error ? `<div class="mini">${h(x.error)}</div>` : '') }], m.modulos, (x) => detalleModulo(x))}</div>
      <div class="tarjeta"><h2>Tareas programadas</h2>${tabla([{ t: 'Tarea', v: (x) => x.id }, { t: 'Cada', v: (x) => x.cada_s + ' s' }, { t: 'Última', v: (x) => fecha(x.ultima) },
        { t: 'Duración', v: (x) => (x.duracion_ms ?? '—') + ' ms' }, { t: 'Resultado', html: true, v: (x) => `<span class="et ${+x.ok ? 'verde' : 'rojo'}">${h(x.resultado || '')}</span>` }], t.tareas)}</div>
      <div class="tarjeta"><h2>Diario firmado</h2><div class="acciones" style="margin:0 0 10px"><button class="b sec" id="dVer">Verificar cadena y firmas</button></div><div id="dMsg"></div>
      ${tabla([{ t: '#', v: (x) => x.id }, { t: 'Fecha', v: (x) => fecha(x.ts) }, { t: 'Tipo', v: (x) => x.tipo }, { t: 'Quién', v: (x) => x.actor }, { t: 'Sobre', v: (x) => x.objeto || '' },
        { t: 'Detalle', v: (x) => JSON.stringify(x.detalle).slice(0, 140) }], d.diario)}</div>`;
    $('#dVer').onclick = async () => { const r = await api('POST', 'proc/diario/verificar'); aviso($('#dMsg'), r.ok ? 'ok' : 'mal', r.ok ? `Cadena íntegra: ${r.asientos} asientos firmados.` : `Alteración en el asiento ${r.roto_en}: ${r.motivo}`); };
  } });
  async function detalleModulo(x) {
    const p = panel('Módulo ' + x.id, `<p>${h(x.descripcion)}</p><dl><dt>Versión</dt><dd>${h(x.version)} · ABI ${h(x.abi)} · ${h(x.estabilidad)}</dd><dt>Licencia</dt><dd>${h(x.licencia)}</dd>
      <dt>Requiere</dt><dd>${h((x.requiere || []).join(', ') || '—')}</dd><dt>Migraciones</dt><dd>${h((x.migraciones || []).map((m) => m.archivo).join(', ') || '—')}</dd></dl>
      ${puede('modulos.admin') ? `<h3>Configuración</h3><textarea id="mCfg" style="min-height:220px;font-family:monospace;font-size:13px"></textarea>
      <div class="acciones"><button class="b" id="mGuardar">Guardar configuración</button><button class="b sec" id="mAct">${x.estado === 'desactivado' ? 'Activar' : 'Desactivar'}</button></div>` : ''}<div id="mMsg"></div>`);
    if (!puede('modulos.admin')) return;
    $('#mCfg').value = JSON.stringify((await api('GET', `modulos/${x.id}/config`)).config, null, 2);
    $('#mGuardar').onclick = async () => { try { await api('PUT', `modulos/${x.id}/config`, { config: JSON.parse($('#mCfg').value) }); aviso($('#mMsg'), 'ok', 'Guardada. Se aplica en el próximo ciclo.'); } catch (e) { aviso($('#mMsg'), 'mal', e.message); } };
    $('#mAct').onclick = async () => { try { await api('POST', `modulos/${x.id}/${x.estado === 'desactivado' ? 'activar' : 'desactivar'}`); location.reload(); } catch (e) { aviso($('#mMsg'), 'mal', e.message); } };
  }

  /** Organizaciones del directorio PIM (empresas y áreas) para elegir en vez de escribir el código. */
  async function opcionesOrg() {
    const l = ((await pim('GET', 'Organization?_count=300')).cuerpo.entry || []).map((x) => x.resource);
    return l.map((o) => [o.identifier?.[0]?.value || o.id, `${o.name || o.id}${o.identifier?.[0]?.value ? ' · ' + o.identifier[0].value : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  }
  const ROLES_AYUDA = { AD: 'Todo, incluida la configuración', GM: 'Gestiona órdenes, inventario, stock y preventivos', EJ: 'Ejecuta trabajos (técnico o empresa)', SO: 'Pide trabajos', FT: 'Sistemas y sensores', CI: 'Solo consulta' };
  Nucleo.pestana({ id: 'usuarios', titulo: 'Usuarios', orden: 95, cap: 'usuarios.admin', render: async (el) => {
    const j = await api('GET', 'usuarios'); const roles = Object.entries(j.roles);
    const act = j.usuarios.filter((u) => +u.activo).length;
    el.innerHTML = `${kpis([{ t: 'Usuarios', n: j.usuarios.length, sub: `${act} activos`, icono: 'usuarios', color: '#546e7a' },
        { t: 'Con correo', n: j.usuarios.filter((u) => u.email).length, sub: 'pueden recuperar por enlace', icono: 'perfil', color: '#1e88e5' },
        { t: 'Clave temporal', n: j.usuarios.filter((u) => +u.cambiar_clave).length, sub: 'deben cambiarla al entrar', icono: 'candado', color: '#ef6c00' }])}
      <div class="tarjeta" style="margin-top:12px"><div class="tcab"><h2>Usuarios del nodo</h2><span class="mini">Tocá un usuario para editarlo o darle una clave temporal.</span></div>
      ${tabla([{ t: 'Usuario', v: (u) => u.usuario }, { t: 'Nombre', v: (u) => u.nombre }, { t: 'Rol', html: true, v: (u) => `<span class="et">${h(u.rol)}</span> ${h(j.roles[u.rol] || '')}` },
        { t: 'Organización', v: (u) => u.organizacion || '' }, { t: 'Correo', v: (u) => u.email || '' },
        { t: 'Estado', html: true, v: (u) => !+u.activo ? '<span class="et gris">baja</span>' : +u.cambiar_clave ? '<span class="et nar">clave temporal</span>' : '<span class="et verde">activo</span>' },
        { t: 'Último ingreso', v: (u) => fecha(u.ultimo_ingreso) }], j.usuarios, (u) => editarUsuario(u, roles))}
      ${seccion('Nuevo usuario', form([{ n: 'usuario', t: 'Usuario', req: true, ph: 'ej.: ana.perez', ayuda: 'Minúsculas, números, punto o guion' }, { n: 'nombre', t: 'Nombre visible', req: true, ph: 'Ana Pérez' },
        { n: 'rol', t: 'Rol', tipo: 'select', op: roles.map(([k, t]) => [k, `${k} · ${t}`]), v: 'SO' },
        { n: 'organizacion', t: 'Empresa o área', tipo: 'opciones', cargar: opcionesOrg, vacio: '— ninguna / técnico propio —', ayuda: 'Un Ejecutor queda en la lista de «Responsable» de las órdenes: si no elegís una empresa, se crea una con su nombre' },
        { n: 'email', t: 'Correo (opcional)', tipo: 'email', ph: 'para recuperar la clave por enlace' }, { n: 'clave', t: 'Contraseña inicial', tipo: 'password', req: true, ayuda: '10 o más caracteres' },
        { n: 'cambiar_clave', t: 'Primer ingreso', tipo: 'check', v: true, ayudaCheck: 'Que elija su propia contraseña al entrar' }],
      'Crear usuario', async (d, m) => { await api('POST', 'usuarios', d); aviso(m, 'ok', 'Usuario creado.'); setTimeout(() => ir('usuarios'), 800); }))}</div>
      <div class="tarjeta"><h2>Roles</h2><div class="grid2">${roles.map(([k, t]) => `<div class="rolc"><span class="et">${h(k)}</span> <b>${h(t)}</b><br><small class="mini">${h(ROLES_AYUDA[k] || '')}</small></div>`).join('')}</div></div>`;
  } });
  function editarUsuario(u, roles) {
    const p = panel('Usuario ' + u.usuario, `${form([{ n: 'nombre', t: 'Nombre visible', v: u.nombre, req: true }, { n: 'rol', t: 'Rol', tipo: 'select', op: roles.map(([k, t]) => [k, `${k} · ${t}`]), v: u.rol },
        { n: 'organizacion', t: 'Empresa o área', tipo: 'opciones', cargar: opcionesOrg, v: u.organizacion || '', vacio: '— ninguna / técnico propio —', ayuda: u.rol === 'EJ' ? 'Es lo que aparece como «Responsable» al asignarle órdenes' : '' }, { n: 'email', t: 'Correo', tipo: 'email', v: u.email || '' },
        { n: 'activo', t: 'Estado', tipo: 'check', v: +u.activo, ayudaCheck: 'Puede ingresar' }], 'Guardar cambios', async (d, m) => {
          await api('PUT', 'usuarios/' + encodeURIComponent(u.usuario), d); aviso(m, 'ok', 'Guardado.'); setTimeout(() => { cerrar(); ir('usuarios'); }, 700); })}
      ${u.rol === 'AD' ? '' : `<h3>Permisos de esta persona</h3><p class="mini">Por defecto vale lo que permite su rol. Acá podés sumarle o quitarle funciones puntuales, sin cambiarle el rol.</p><div id="uPerm"><p class="mini">Cargando…</p></div>
      <div class="acciones"><button class="b sec" id="bPerm">Guardar permisos</button></div><div id="pMsg"></div>`}
      <h3>¿Olvidó su contraseña?</h3><p class="mini">Generá una <b>clave temporal</b> de un solo uso (vence en 72 h). Dásela en persona o por teléfono: al ingresar, el sistema le pide elegir una propia. Queda registrado en el diario firmado.</p>
      <div class="acciones"><button class="b sec" id="bTemp">${ic('candado')} Generar clave temporal</button></div><div id="tMsg"></div>`);
    if (u.rol !== 'AD') (async () => {   // 1.7.0: permisos propios (lo que se suma y lo que se quita respecto del rol)
      const caps = (await api('GET', 'proc/capacidades')).capacidades.capacidades, ex = (u.cap_extra || '').split(',').filter(Boolean), bl = (u.cap_bloqueadas || '').split(',').filter(Boolean);
      const porM = {}; Object.keys(caps).sort().forEach((c) => { (porM[c.split('.')[0]] = porM[c.split('.')[0]] || []).push(c); });
      p.querySelector('#uPerm').innerHTML = Object.entries(porM).map(([m, l]) => `<details class="desp" ${l.some((c) => ex.includes(c) || bl.includes(c)) ? 'open' : ''}><summary><span class="mas">＋</span>${h(m)} <span class="mini" style="font-weight:400">(${l.length})</span></summary><div class="desp-c">${l.map((c) => { const rol = caps[c].includes(u.rol), dable = caps[c].length && !c.startsWith('pim.');
        return `<div class="filtros" style="margin:0 0 6px;align-items:center"><div style="flex:2 1 200px"><b>${h(c.split('.').slice(1).join('.') || c)}</b> <span class="mini">· por su rol: ${rol ? 'sí' : 'no'}</span></div>
          <select data-cap="${h(c)}" style="flex:1 1 150px"><option value="">Según su rol</option>${dable && !rol ? `<option value="e" ${ex.includes(c) ? 'selected' : ''}>Permitir</option>` : ''}${rol ? `<option value="b" ${bl.includes(c) ? 'selected' : ''}>Bloquear</option>` : ''}</select></div>`; }).join('')}</div></details>`).join('');
      p.querySelector('#bPerm').onclick = async () => { const s = [...p.querySelectorAll('#uPerm select')];
        try { await api('PUT', 'usuarios/' + encodeURIComponent(u.usuario), { cap_extra: s.filter((x) => x.value === 'e').map((x) => x.dataset.cap), cap_bloqueadas: s.filter((x) => x.value === 'b').map((x) => x.dataset.cap) });
          aviso(p.querySelector('#pMsg'), 'ok', 'Permisos guardados. Valen desde el próximo ingreso de la persona a cada pantalla.'); setTimeout(() => { cerrar(); ir('usuarios'); }, 900);
        } catch (x) { aviso(p.querySelector('#pMsg'), 'mal', x.message); } };
    })().catch((x) => { p.querySelector('#uPerm').innerHTML = `<div class="aviso mal">${h(x.message)}</div>`; });
    p.querySelector('#bTemp').onclick = async () => {
      if (!confirm(`¿Generar una clave temporal para ${u.usuario}? Su contraseña actual deja de servir.`)) return;
      try { const r = await api('POST', `usuarios/${encodeURIComponent(u.usuario)}/clave-temporal`);
        p.querySelector('#tMsg').innerHTML = `<div class="aviso ok imprimible">Clave temporal de <b>${h(u.usuario)}</b> (vence en ${r.vence_horas} h):<div class="codigos" style="grid-template-columns:1fr"><span>${h(r.clave)}</span></div>
          <small>Se muestra una sola vez. No la mandes por un canal inseguro.</small></div><div class="acciones"><button class="b sec" id="bCopT">Copiar</button></div>`;
        p.querySelector('#bCopT').onclick = () => navigator.clipboard?.writeText(r.clave);
      } catch (x) { aviso(p.querySelector('#tMsg'), 'mal', x.message); }
    };
  }

  // ---------------------------------------------------------------- imágenes: se achican en el navegador antes de subir
  function elegirImagen(max, png) {
    return new Promise((ok, mal) => {
      const i = document.createElement('input'); i.type = 'file'; i.accept = 'image/png,image/jpeg,image/webp';
      i.onchange = () => {
        const f = i.files[0]; if (!f) return; if (f.size > 25 * 1048576) return mal(new Error('La imagen es demasiado grande'));
        const img = new Image(); const u = URL.createObjectURL(f);
        img.onload = () => { const k = Math.min(1, max / Math.max(img.width, img.height)); const c = document.createElement('canvas');
          c.width = Math.round(img.width * k); c.height = Math.round(img.height * k); c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(u);
          c.toBlob((b) => b ? ok(b) : mal(new Error('No se pudo procesar la imagen')), png ? 'image/png' : 'image/jpeg', 0.86); };
        img.onerror = () => mal(new Error('El archivo no es una imagen válida')); img.src = u;
      };
      i.click();
    });
  }
  function galeria(sel, alElegir, conSubir, extra = []) {
    const id = 'g' + Math.random().toString(36).slice(2);
    setTimeout(() => $$(`#${id} [data-f]`).forEach((d) => d.onclick = () => alElegir(d.dataset.f)));
    return `<div class="galeria" id="${id}">${extra.map(([v, t, css]) => `<div class="mini-fondo ${sel === v ? 'sel' : ''}" data-f="${h(v)}" style="background:${css}"><span>${h(t)}</span></div>`).join('')}
      ${Object.entries(TEMAS).map(([k, [t, css]]) => `<div class="mini-fondo ${sel === k ? 'sel' : ''}" data-f="${k}" style="background:${css.replace(/"/g, "'")}"><span>${h(t)}</span></div>`).join('')}
      ${conSubir ? `<div class="mini-fondo subir" data-f="__subir">${ic('imagen')}<br>Subir mi imagen</div>` : ''}</div>`;
  }

  /** Tarjetas para elegir la vista, con una miniatura de cada una. */
  function selectorVista(sel, deNodo, conNodo) {
    const op = [['widgets', 'Vista widgets', 'Inicio con indicadores y accesos rápidos por categoría; menú lateral.'], ['menu', 'Vista menú', 'Encabezado de color con todos los módulos arriba, siempre a mano.']];
    if (conNodo) op.unshift(['__nodo', 'La de la institución', `Usar la que eligió el administrador (hoy: ${deNodo === 'menu' ? 'menú' : 'widgets'}).`]);
    return `<div class="vistas" id="selVista">${op.map(([v, t, d]) => `<button class="vista-op ${sel === v ? 'sel' : ''}" data-v="${v}"><span class="vmini ${v === '__nodo' ? deNodo : v}"><i></i><i></i><i></i><i></i></span><b>${t}</b><small>${d}</small></button>`).join('')}</div>`;
  }

  // ---------------------------------------------------------------- Mi perfil (cualquier usuario)
  Nucleo.pestana({ id: 'perfil', titulo: 'Mi perfil', orden: 998, oculta: true, render: async (el) => {
    const j = await api('GET', 'perfil'); const v = j.vista; const pf = j.perfil;
    const selF = pf.fondo === null ? '__nodo' : (v.fondo.tipo === 'tema' ? v.fondo.tema : '__propio');
    el.innerHTML = `<div class="tarjeta"><h2>Tus datos</h2>${form([{ n: 'nombre', t: 'Nombre visible', v: S.usuario.nombre, req: true }], 'Guardar nombre', async (d, m) => {
        await api('PUT', 'perfil', { nombre: d.nombre }); S.usuario.nombre = d.nombre; aviso(m, 'ok', 'Listo.'); pintarBarra(); })}
      <p class="mini">Usuario <b>${h(S.usuario.usuario)}</b> · rol ${h(S.usuario.rol)} · ${h(S.roles?.[S.usuario.rol] || '')}</p></div>
      <div class="tarjeta"><h2>Tu vista</h2><p class="mini">Elegí cómo querés ver el sistema. Lo que hagas acá solo cambia tu pantalla.</p>${selectorVista(pf.vista ?? '__nodo', v.vista_nodo, true)}<div id="vMsg"></div></div>
      <div class="tarjeta"><h2>Tu fondo</h2><p class="mini">Como los temas del correo: elegí un fondo para tu tablero o subí una foto propia. Solo lo ves vos.</p>
        ${galeria(selF, async (f) => {
          try {
            if (f === '__subir') { const b = await elegirImagen(2560, false); V = (await api('POST', 'perfil/fondo', undefined, b)).vista; }
            else if (f === '__nodo') V = (await api('PUT', 'perfil', { fondo: null })).vista;
            else if (f !== '__propio') V = (await api('PUT', 'perfil', { fondo: f })).vista;
            aplicarFondo(V.fondo, V.opacidad); ir('perfil');
          } catch (x) { aviso($('#pfMsg'), 'mal', x.message); }
        }, true, [['__nodo', 'El de la institución', 'linear-gradient(135deg,#fff,#e3f2fd)'], ...(v.fondo_propio && v.fondo.tipo === 'imagen' ? [['__propio', 'Mi imagen', `url('${v.fondo.url}') center/cover`]] : [])])}
        <div style="margin-top:14px;max-width:420px"><label>Transparencia del tablero sobre el fondo: <b id="opV">${100 - v.opacidad}%</b></label><input type="range" id="opR" min="0" max="60" value="${100 - v.opacidad}"></div><div id="pfMsg"></div></div>
      <div class="tarjeta"><h2>Widgets de tu inicio</h2><p class="mini">Ocultá los que no uses. El administrador define el orden.</p><ul class="orden-w" id="wLista">${widgets.filter((w) => !v.widgets.ocultos_nodo.includes(w.id)).map((w) =>
        `<li><input type="checkbox" data-w="${h(w.id)}" ${v.widgets.ocultos.includes(w.id) ? '' : 'checked'}><b>${h(w.titulo)}</b></li>`).join('')}</ul></div>
      <div class="tarjeta"><h2>Contraseña y recuperación</h2><p class="mini">Si un día te olvidás la contraseña, vas a poder cambiarla vos mismo con un <b>código de recuperación</b> (funciona sin Internet) o con un <b>enlace por correo</b> (si el nodo tiene Internet).</p>
        <div id="recEst" class="mini">…</div>
        ${seccion('Generar códigos de recuperación', form([{ n: 'actual', t: 'Tu contraseña actual', tipo: 'password', req: true }], 'Generar 8 códigos nuevos', async (d, m) => {
          const r = await api('POST', 'sesion/codigos', { actual: d.actual });
          m.innerHTML = `<div class="aviso info imprimible"><b>Tus códigos de recuperación</b> · usuario ${h(S.usuario.usuario)} · ${h(S.producto.nombre)} nodo ${h(S.nodo.id)}
            <div class="codigos">${r.codigos.map((c) => `<span>${h(c)}</span>`).join('')}</div><small>Cada uno sirve una sola vez. Los anteriores dejaron de valer. Guardalos impresos o en un lugar seguro: se muestran solo ahora.</small></div>
            <div class="acciones"><button type="button" class="b sec" id="cImp">Imprimir</button><button type="button" class="b sec" id="cTxt">Descargar .txt</button></div>`;
          $('#cImp').onclick = () => print();
          $('#cTxt').onclick = () => { const a2 = document.createElement('a'); a2.href = URL.createObjectURL(new Blob([`Códigos de recuperación · ${S.usuario.usuario} · ${S.producto.nombre} ${S.nodo.id}\n\n${r.codigos.join('\n')}\n\nCada código sirve una sola vez.\n`], { type: 'text/plain' })); a2.download = `codigos-${S.usuario.usuario}.txt`; a2.click(); };
          estadoRec();
        }))}
        ${seccion('Correo para recuperar por enlace', form([{ n: 'email', t: 'Tu correo', tipo: 'email', v: '', ph: 'nombre@institucion.org' }], 'Guardar correo', async (d, m) => { await api('PUT', 'perfil', { email: d.email }); aviso(m, 'ok', 'Guardado.'); estadoRec(); }, { alCargar: async (f) => { try { f.email.value = (await api('GET', 'sesion/codigos')).email || ''; } catch (x) { /* nada */ } } }))}
        ${seccion('Cambiar la contraseña', form([{ n: 'actual', t: 'Contraseña actual', tipo: 'password', req: true }, { n: 'nueva', t: 'Nueva', tipo: 'password', req: true, ayuda: '10 o más caracteres' }, { n: 'repite', t: 'Repetila', tipo: 'password', req: true }],
        'Cambiar', async (d, m) => { if (d.nueva !== d.repite) throw new Error('Las contraseñas nuevas no coinciden'); await api('POST', 'sesion/clave', { actual: d.actual, nueva: d.nueva }); aviso(m, 'ok', 'Contraseña cambiada. Las otras sesiones abiertas se cerraron.'); }))}</div>`;
    const estadoRec = async () => { try { const r = await api('GET', 'sesion/codigos'); const c = r.codigos;
      $('#recEst').innerHTML = `${c.libres ? `<span class="et verde">${c.libres} códigos disponibles</span>` : '<span class="et nar">sin códigos</span>'} ${r.email ? `<span class="et">correo: ${h(r.email)}</span>` : '<span class="et gris">sin correo</span>'}${!c.libres ? ' — <b>generá tus códigos</b> para no depender del administrador.' : ''}`; } catch (x) { /* nada */ } };
    estadoRec();
    $$('#selVista [data-v]').forEach((b) => b.onclick = async () => { try { V = (await api('PUT', 'perfil', { vista: b.dataset.v === '__nodo' ? null : b.dataset.v })).vista; actual = 'perfil'; pintar(); } catch (x) { aviso($('#vMsg'), 'mal', x.message); } });
    const r = $('#opR'); let t; r.oninput = () => { $('#opV').textContent = r.value + '%'; document.documentElement.style.setProperty('--op', String((100 - r.value) / 100)); clearTimeout(t);
      t = setTimeout(async () => { V = (await api('PUT', 'perfil', { opacidad: 100 - +r.value })).vista; }, 500); };
    $$('#wLista input').forEach((c) => c.onchange = async () => { V = (await api('PUT', 'perfil', { widgets_ocultos: $$('#wLista input').filter((x) => !x.checked).map((x) => x.dataset.w) })).vista; });
  } });

  // ---------------------------------------------------------------- Configuración (administrador, con contraseña confirmada 2 h)
  let pestCfg = 'identidad';
  Nucleo.pestana({ id: 'configuracion', titulo: 'Configuración', orden: 99, cap: 'sistema.admin', render: async (el) => {
    let j; try { j = await api('GET', 'config/apariencia'); } catch (x) {
      if (x.http !== 428) throw x;
      el.innerHTML = `<div class="tarjeta candado"><div class="ico">${ic('candado')}</div><h2>Configuración del sistema</h2><p>Por seguridad, confirmá tu contraseña de administrador. La sesión de configuración dura 2 horas.</p>
        <form id="fCfg"><input type="password" name="c" placeholder="Contraseña" autocomplete="current-password" required autofocus><button class="b" style="width:100%;margin-top:10px">${ic('candado')} Ingresar</button><div id="cMsg"></div></form></div>`;
      $('#fCfg').onsubmit = async (e) => { e.preventDefault(); try { await api('POST', 'sesion/config', { clave: e.target.c.value }); ir('configuracion'); } catch (y) { aviso($('#cMsg'), 'mal', y.message); } };
      return;
    }
    const a = j.apariencia; const fsel = (v) => (/^[a-f0-9]{24}\.(png|jpg)$/.test(v) ? '__img' : v);
    const imgDe = (v) => (/^[a-f0-9]{24}\.(png|jpg)$/.test(v) ? `/api/apariencia/imagen/${v}` : '');
    const guardar = async (d, m) => { const r = await api('PUT', 'config/apariencia', d); pub = r.publica; aviso(m, 'ok', 'Guardado.'); await refrescarVista(); return r; };
    const subir = async (tipo, max, png, m) => { try { const b = await elegirImagen(max, png); const r = await api('POST', `config/imagen/${tipo}`, undefined, b); pub = r.publica; await refrescarVista(); ir('configuracion'); } catch (x) { aviso(m, 'mal', x.message); } };
    const tabs = { identidad: 'Identidad institucional', fondos: 'Fondos', tablero: 'Tablero', listas: 'Listas desplegables', correo: 'Correo y recuperación', actualizaciones: 'Actualizaciones', ...Object.fromEntries(cfgExtra.map((x) => [x.id, x.titulo])) };
    el.innerHTML = `<div class="pest">${Object.entries(tabs).map(([k, t]) => `<button data-t="${k}" class="${pestCfg === k ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="cfgC"></div>`;
    $$('.pest button', el).forEach((b) => b.onclick = () => { pestCfg = b.dataset.t; ir('configuracion'); });
    const c = $('#cfgC');
    const exCfg = cfgExtra.find((x) => x.id === pestCfg); if (exCfg) { await exCfg.render(c); return; }
    if (pestCfg === 'identidad') {
      c.innerHTML = `<div class="tarjeta"><h2>Logo de la institución</h2><p class="mini">Se muestra en la pantalla de ingreso, en el menú y en la barra. PNG con fondo transparente queda mejor (se ajusta a 512 px).</p>
        <div class="vista-logo"><div class="marco">${a.logo ? `<img src="${imgDe(a.logo)}" alt="Logo">` : '<span class="mini">Sin logo propio</span>'}</div>
        <div class="acciones"><button class="b" id="lSub">${ic('imagen')} ${a.logo ? 'Cambiar logo' : 'Subir logo'}</button>${a.logo ? '<button class="b peligro" id="lQui">Quitar</button>' : ''}</div></div><div id="lMsg"></div></div>
        <div class="tarjeta"><h2>Vista del sistema</h2><p class="mini">La que ven todos al ingresar (se elige al instalar). Cada usuario puede cambiar la suya en «Mi perfil».</p>${selectorVista(a.vista || 'widgets', null, false)}<div id="vsMsg"></div></div>
        <div class="tarjeta"><h2>Nombre y lema</h2>${form([{ n: 'institucion', t: 'Nombre de la institución', v: a.institucion }, { n: 'lema', t: 'Lema o área (aparece debajo del nombre)', v: a.lema },
          { n: 'saludo', t: 'Mostrar el saludo con el nombre en el inicio', tipo: 'check', v: a.saludo }], 'Guardar', (d, m) => guardar(d, m))}</div>`;
      $$('#selVista [data-v]').forEach((b) => b.onclick = async () => { await guardar({ vista: b.dataset.v }, $('#vsMsg')); actual = 'configuracion'; pintar(); });
      $('#lSub').onclick = () => subir('logo', 512, true, $('#lMsg'));
      if ($('#lQui')) $('#lQui').onclick = async () => { await guardar({ logo: '' }, $('#lMsg')); ir('configuracion'); };
    }
    if (pestCfg === 'fondos') {
      const ex = (k) => (a[k] && imgDe(a[k]) ? [['__img', 'Imagen subida', `url('${imgDe(a[k])}') center/cover`]] : []);
      c.innerHTML = `<div class="tarjeta"><h2>Fondo de la pantalla de ingreso</h2><p class="mini">Una foto del hospital o centro de salud da identidad desde el primer momento.</p>
          ${galeria(fsel(a.fondo_login), async (f) => { if (f === '__subir') return subir('fondo_login', 2560, false, $('#fMsg')); if (f !== '__img') { await guardar({ fondo_login: f }, $('#fMsg')); ir('configuracion'); } }, true, ex('fondo_login'))}</div>
        <div class="tarjeta"><h2>Fondo del tablero para todos</h2><p class="mini">Cada usuario puede elegir el suyo en «Mi perfil»; este es el que ven quienes no eligieron.</p>
          ${galeria(fsel(a.fondo_tablero), async (f) => { if (f === '__subir') return subir('fondo_tablero', 2560, false, $('#fMsg')); if (f !== '__img') { await guardar({ fondo_tablero: f === '__liso' ? '' : f }, $('#fMsg')); ir('configuracion'); } }, true,
            [['__liso', 'Liso', '#f3f6fb'], ...ex('fondo_tablero')])}
          <div style="margin-top:14px;max-width:420px"><label>Transparencia por defecto: <b id="oV">${100 - a.opacidad}%</b></label><input type="range" id="oR" min="0" max="60" value="${100 - a.opacidad}"></div></div><div id="fMsg"></div>`;
      const r = $('#oR'); let t; r.oninput = () => { $('#oV').textContent = r.value + '%'; clearTimeout(t); t = setTimeout(() => guardar({ opacidad: 100 - +r.value }, $('#fMsg')), 500); };
    }
    if (pestCfg === 'listas') await cfgListas(c);
    if (pestCfg === 'correo') await cfgCorreo(c);
    if (pestCfg === 'actualizaciones') await cfgActualizaciones(c);
    if (pestCfg === 'tablero') {
      const base = a.widgets.orden.length ? a.widgets.orden : ['pim.ordenes', 'alarmas.activas', 'nucleo.nodo'];
      const orden = [...base.filter((id) => widgets.some((w) => w.id === id)), ...widgets.map((w) => w.id).filter((id) => !base.includes(id))];
      c.innerHTML = `<div class="tarjeta"><h2>Widgets del inicio</h2><p class="mini">Orden y visibilidad para todo el nodo. Cada usuario puede ocultar los que no use.</p><ul class="orden-w" id="ow">${orden.map((id) => {
          const w = widgets.find((x) => x.id === id); return `<li data-id="${h(id)}"><input type="checkbox" ${a.widgets.ocultos.includes(id) ? '' : 'checked'}><b>${h(w.titulo)}</b>
          <button class="ib" data-m="-1" title="Subir">${ic('arriba')}</button><button class="ib" data-m="1" title="Bajar">${ic('abajo')}</button></li>`; }).join('')}</ul>
        <div class="acciones"><button class="b" id="wGuardar">Guardar</button></div><div id="wMsg"></div></div>`;
      $$('#ow button[data-m]').forEach((b) => b.onclick = () => { const li = b.closest('li'); const s = +b.dataset.m < 0 ? li.previousElementSibling : li.nextElementSibling; if (s) +b.dataset.m < 0 ? s.before(li) : s.after(li); });
      $('#wGuardar').onclick = () => guardar({ widgets: { orden: $$('#ow li').map((x) => x.dataset.id), ocultos: $$('#ow li').filter((x) => !x.querySelector('input').checked).map((x) => x.dataset.id) } }, $('#wMsg'));
    }
  } });

  // ---------------------------------------------------------------- Configuración › Listas desplegables (1.6.0)
  let catSel = null;
  async function cfgListas(c) {
    const j = await api('GET', 'catalogos'); if (!catSel || !j.catalogos.some((x) => x.clave === catSel)) catSel = j.catalogos[0]?.clave;
    const ORIG = (o) => o === 'nucleo' ? 'Núcleo' : 'Módulo ' + o;
    c.innerHTML = `<div class="tarjeta"><h2>Listas desplegables</h2><p class="mini">Todo lo que se elige de una lista en el sistema sale de acá. Corregí textos, ordená, agregá valores o desactivá los que no se usan.
      Los valores <b>no se borran</b>: al desactivarlos dejan de ofrecerse, pero los registros viejos siguen mostrando su texto. Las listas marcadas «＋ Otro» se completan también desde los formularios.</p>
      <div class="cats">${j.catalogos.map((x) => `<button class="cat ${x.clave === catSel ? 'sel' : ''}" data-k="${h(x.clave)}"><b>${h(x.nombre)}</b><small>${h(ORIG(x.origen))}${x.norma ? ' · ' + h(x.norma) : ''}</small>
        <span><em>${+x.activos || 0}</em> valores${+x.permite_otro ? ' · ＋ Otro' : ''}${x.padre ? ' · depende de ' + h(x.padre) : ''}</span></button>`).join('')}</div></div><div id="catV"></div>`;
    $$('.cat', c).forEach((b) => b.onclick = () => { catSel = b.dataset.k; cfgListas(c); });
    if (!catSel) return;
    const v = await api('GET', `catalogos/${catSel}?todos=1`); const cat = v.catalogo; let padres = [];
    if (cat.padre) padres = (await api('GET', `catalogos/${cat.padre}`)).valores;
    const tp = (cod) => padres.find((x) => x.codigo === cod)?.texto || cod || '';
    $('#catV').innerHTML = `<div class="tarjeta"><div class="tcab"><h2>${h(cat.nombre)}</h2><span class="mini">${h(cat.descripcion || '')}</span></div>
      ${tabla([{ t: 'Texto', v: (x) => x.texto }, ...(cat.padre ? [{ t: 'Pertenece a', v: (x) => tp(x.padre) }] : []), ...(+cat.con_sigla ? [{ t: 'Sigla', v: (x) => x.sigla || '' }] : []),
        { t: 'Código', html: true, v: (x) => `<code>${h(x.codigo)}</code>` }, { t: 'Estado', html: true, v: (x) => +x.activo ? '<span class="et verde">activo</span>' : '<span class="et gris">desactivado</span>' },
        { t: 'Origen', v: (x) => +x.semilla ? 'valor de fábrica' : 'agregado' }], v.valores, (x) => editarValor(cat, x))}
      ${seccion('Agregar un valor', form([{ n: 'texto', t: 'Texto', req: true }, ...(cat.padre ? [{ n: 'padre', t: 'Pertenece a', tipo: 'lista', lista: cat.padre, otro: false }] : []),
        ...(+cat.con_sigla ? [{ n: 'sigla', t: 'Sigla (2 a 6)', ph: 'vacía: se genera' }] : []), { n: 'codigo', t: 'Código (opcional)', ph: 'vacío: se genera del texto' }], 'Agregar', async (d, m) => {
          const r = await api('POST', 'catalogos/' + cat.clave, d); delete LISTAS[cat.clave]; aviso(m, 'ok', `Agregado: ${r.valor.texto} (${r.valor.codigo}).`); setTimeout(() => cfgListas(c), 600); }))}</div>`;
  }
  function editarValor(cat, x) {
    const p = panel(cat.nombre, `<dl><dt>Código</dt><dd><code>${h(x.codigo)}</code> <span class="mini">(no cambia nunca: los registros lo guardan)</span></dd></dl>
      ${form([{ n: 'texto', t: 'Texto', v: x.texto, req: true }, ...(+cat.con_sigla ? [{ n: 'sigla', t: 'Sigla', v: x.sigla || '' }] : []), { n: 'orden', t: 'Orden', tipo: 'number', v: x.orden, ayuda: 'Menor = más arriba' },
        { n: 'activo', t: 'Estado', tipo: 'check', v: +x.activo, ayudaCheck: 'Se ofrece en las listas' }], 'Guardar', async (d, m) => {
          await api('PUT', `catalogos/${cat.clave}/${encodeURIComponent(x.codigo)}`, d); delete LISTAS[cat.clave]; aviso(m, 'ok', 'Guardado.'); setTimeout(() => { cerrar(); const c = $('#cfgC'); if (c) cfgListas(c); }, 600); })}`);
    return p;
  }
  // ---------------------------------------------------------------- Configuración › Correo y recuperación (1.6.0)
  async function cfgCorreo(c) {
    const k = (await api('GET', 'config/correo')).correo;
    c.innerHTML = `<div class="tarjeta"><h2>Recuperación de contraseña</h2><div class="grid2">
        <div class="rolc"><b>Códigos de recuperación</b><br><small class="mini">Cada usuario los genera en «Mi perfil» y los guarda impresos. Funcionan <b>sin Internet</b>.</small></div>
        <div class="rolc"><b>Clave temporal</b><br><small class="mini">La das vos desde Usuarios (vence en 72 h) y obliga a elegir una propia al entrar.</small></div>
        <div class="rolc"><b>Enlace por correo</b><br><small class="mini">Solo si el nodo tiene salida a Internet y configurás el correo abajo. ${k.activo ? '<span class="et verde">activo</span>' : '<span class="et gris">apagado</span>'}</small></div>
        <div class="rolc"><b>Último recurso</b><br><small class="mini">Si el único administrador olvidó su clave: en la PC del nodo, <code>${h(S.producto.nombre)}-Control usuario clave admin</code>.</small></div></div></div>
      <div class="tarjeta"><h2>Correo saliente (SMTP)</h2><p class="mini">Los datos del servidor de correo los da el área de sistemas o el proveedor (por ejemplo, el correo institucional). La contraseña se guarda en el nodo y no se vuelve a mostrar.</p>
      ${form([{ n: 'host', t: 'Servidor SMTP', v: k.host, ph: 'smtp.institucion.org' }, { n: 'puerto', t: 'Puerto', tipo: 'select', v: String(k.puerto), op: [['587', '587 (STARTTLS)'], ['465', '465 (SSL)'], ['25', '25 (sin cifrar)'], ['2525', '2525']] },
        { n: 'seguridad', t: 'Seguridad', tipo: 'select', v: k.seguridad, op: [['tls', 'STARTTLS'], ['ssl', 'SSL/TLS directo'], ['ninguna', 'Ninguna (solo intranet)']] },
        { n: 'usuario', t: 'Usuario SMTP', v: k.usuario }, { n: 'clave', t: 'Contraseña SMTP', tipo: 'password', ph: k.clave_cargada ? '•••••• (cargada; vacía = no cambia)' : '' },
        { n: 'remitente', t: 'Correo del remitente', tipo: 'email', v: k.remitente, ph: 'mantenimiento@institucion.org' }, { n: 'nombre', t: 'Nombre del remitente', v: k.nombre, ph: 'Mantenimiento' },
        { n: 'url_app', t: 'Dirección de la aplicación', v: k.url_app || location.origin, ayuda: 'La que va en el enlace; tiene que abrirse desde la red de los usuarios' },
        { n: 'activo', t: 'Estado', tipo: 'check', v: k.activo, ayudaCheck: 'Ofrecer «Por correo» en el ingreso' }], 'Guardar', async (d, m) => {
          d.puerto = +d.puerto; await api('PUT', 'config/correo', d); aviso(m, 'ok', 'Guardado.'); })}
      ${seccion('Probar el envío', form([{ n: 'para', t: 'Mandar un correo de prueba a', tipo: 'email', req: true }], 'Enviar prueba', async (d, m) => { await api('POST', 'config/correo/prueba', d); aviso(m, 'ok', 'Enviado: revisá la bandeja (y el spam).'); }))}</div>`;
  }

  // ---------------------------------------------------------------- inicio: saludo, resumen y accesos rápidos
  const FIJAS = ['configuracion', 'usuarios', 'sistema'];   // el catálogo no puede dejar al administrador sin sus accesos
  const enCatalogo = (p) => !CAT || p.oculta || FIJAS.includes(p.id) || ((CAT.w[p.id]?.activo ?? 1) !== 0 && (!CAT.visibles || CAT.visibles.includes(p.id)));
  const visibles = () => pestanas.filter((p) => !p.oculta && p.id !== 'inicio' && (!p.cap || puede(p.cap)) && (!p.sinCap || !puede(p.sinCap)) && enCatalogo(p));   // sinCap (ola 3): acceso para quien NO tiene ese permiso (p. ej. la versión de solo lectura)
  Nucleo.pestana({ id: 'inicio', titulo: 'Inicio', orden: -1, oculta: true, render: async (el) => {
    const hr = new Date().getHours(); const sal = hr < 6 ? 'Buenas noches' : hr < 13 ? 'Buenos días' : hr < 20 ? 'Buenas tardes' : 'Buenas noches';
    const hoy = new Date().toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });
    const nombre = (S.usuario.nombre || S.usuario.usuario).split(' ').slice(0, 2).join(' ');
    const ocultos = new Set([...(V?.widgets?.ocultos_nodo || []), ...(V?.widgets?.ocultos || [])]);
    const orden = V?.widgets?.orden?.length ? V.widgets.orden : ['pim.ordenes', 'alarmas.activas', 'nucleo.nodo']; const lw = [...widgets].sort((a, b) => ((orden.indexOf(a.id) + 1 || 999) - (orden.indexOf(b.id) + 1 || 999))).filter((w) => !ocultos.has(w.id));
    const vis = visibles(); const porG = {};
    vis.forEach((p) => { const g = meta(p).grupo; (porG[g] = porG[g] || []).push(p); });
    const rec = (V?.recientes || []).map((id) => vis.find((p) => p.id === id)).filter(Boolean).slice(0, 5);
    const menu = vista() === 'menu'; let plegado = false; try { plegado = localStorage.getItem('iproy-resumen') === '0'; } catch (x) { /* sin almacenamiento */ }
    el.innerHTML = `${V?.saludo !== false ? `<div class="saludo"><h1>${sal}, ${h(nombre)} 👋</h1><p>${h(hoy.charAt(0).toUpperCase() + hoy.slice(1))} · ${h(V?.institucion || S.nodo.nombre)}</p></div>` : ''}
      ${lw.length ? `<div class="secc secc-btn"><span>Resumen</span><button class="plegar" id="bPleg" aria-expanded="${!plegado}">${plegado ? 'Mostrar' : 'Ocultar'} ${ic(plegado ? 'abajo' : 'arriba')}</button></div><div id="wids" class="${plegado ? 'plegado' : ''}"></div>` : ''}
      ${menu ? '' : `<div class="secc">Accesos rápidos</div>
      <div class="buscar"><div class="caja">${ic('buscar')}<input id="qMod" placeholder="${puede('reportes.auditoria') && pestanas.some((x) => x.id === 'audit_sabanas') ? 'Buscar módulo o una orden por su código…' : 'Buscar módulo…'}" autocomplete="off" aria-label="Buscar módulo"><kbd>/</kbd></div><div id="qCod"></div>
        ${rec.length ? `<div class="recientes">${ic('hist')} <b>Recientes:</b> ${rec.map((p) => `<button class="chipr" data-p="${p.id}" style="--gc:${GRUPOS[meta(p).grupo][1]}">${ic(meta(p).icono)} ${h(p.titulo)}</button>`).join('')}</div>` : ''}</div>
      <div class="grupos">${Object.keys(GRUPOS).filter((g) => porG[g]).map((g) => `<section class="grupo" style="--gc:${GRUPOS[g][1]}"><h3><i></i>${h(GRUPOS[g][0])}<em></em><span>${porG[g].length}</span></h3>
        <div class="tiles">${porG[g].map((p) => `<button class="tile" data-p="${p.id}" data-q="${h((p.titulo + ' ' + meta(p).desc + ' ' + GRUPOS[g][0]).toLowerCase())}"><span class="ico">${ic(meta(p).icono)}</span><span>${h(p.titulo)}${meta(p).desc ? `<small>${h(meta(p).desc)}</small>` : ''}</span></button>`).join('')}</div></section>`).join('')}</div>`}`;
    $$('[data-p]', el).forEach((b) => b.onclick = () => ir(b.dataset.p));
    const bp = $('#bPleg'); if (bp) bp.onclick = () => { const w = $('#wids'); const pl = w.classList.toggle('plegado'); try { localStorage.setItem('iproy-resumen', pl ? '0' : '1'); } catch (x) { /* nada */ }
      bp.innerHTML = `${pl ? 'Mostrar' : 'Ocultar'} ${ic(pl ? 'abajo' : 'arriba')}`; bp.setAttribute('aria-expanded', String(!pl)); };
    // Ola P (P4): una orden por su código a la vista (368, 368-APA-HEJa-GAS-001-TAN-1) o su número PIM: va a la auditoría que corresponde, filtrada por esa orden
    const esCod = (t) => puede('reportes.auditoria') && pestanas.some((x) => x.id === 'audit_sabanas') && /^(\d{1,9}(-\S+)?|[A-Za-z0-9]{2,8}-0*\d{1,9})$/.test(t);
    const irCod = async (t) => { const c = $('#qCod'); aviso(c, 'info', 'Buscando la orden…');
      try { const r = await api('GET', 'm/reportes/codigo?q=' + encodeURIComponent(t)); if (!r.ordenes.length) return aviso(c, 'mal', `No hay ninguna orden con el número ${r.numero}.`);
        const o = r.ordenes[0]; ir(`${o.clase === 'equipo' && pestanas.some((x) => x.id === 'bio_auditor') ? 'bio_auditor' : 'audit_sabanas'}?q=${encodeURIComponent(t)}`); }
      catch (x) { aviso(c, 'mal', x.message); } };
    const q = $('#qMod'); if (q) q.oninput = () => { const t = q.value.trim().toLowerCase(); $$('.tile', el).forEach((x) => x.classList.toggle('oculto', !!t && !x.dataset.q.includes(t)));
      $$('.grupo', el).forEach((g) => g.style.display = $$('.tile:not(.oculto)', g).length ? '' : 'none');
      const c = $('#qCod'); const v = q.value.trim(); c.innerHTML = esCod(v) ? `<button class="b sec" id="qCodB" style="margin-top:8px">${ic('buscar')} Buscar la orden con el código «${h(v)}»</button>` : '';
      if (esCod(v)) $('#qCodB').onclick = () => irCod(v); };
    if (q) q.onkeydown = (e) => { if (e.key === 'Enter') { const v = q.value.trim(); if (esCod(v)) return irCod(v); const f = $('.tile:not(.oculto)', el); if (f) f.click(); } };
    const cont = $('#wids');
    for (const w of lw) { const d = document.createElement('div'); d.className = 'widget'; cont.appendChild(d); try { await w.fn(d); } catch (x) { aviso(d, 'mal', `${w.titulo}: ${x.message}`); } }
  } });

  // ---------------------------------------------------------------- ingreso
  const vidrio = (a, cuerpo) => {
    const f = a.fondo_login || { tipo: 'tema', tema: 'cielo' };
    document.body.style.background = ''; document.documentElement.style.setProperty('--op', '1');
    $('#raiz').innerHTML = `<div class="ingreso" style="background:${(cssFondo(f) || TEMAS.cielo[1]).replace(/"/g, "'")};background-size:cover;background-position:center">
      <div class="vidrio"><img class="logo" src="${h(a.logo || 'logo.png')}" alt="">
        <h1>${h(a.institucion || a.producto || 'Ingresar')}</h1><p class="sub">${h(a.lema || '')}${a.lema ? ' · ' : ''}${h(a.producto || '')}${a.nodo ? ' · ' + h(a.nodo) : ''}</p>
        ${cuerpo}<div class="pie2">${h(a.producto || '')} ${h(a.version || '')} · software libre · estándar PIM</div></div></div>`;
  };
  const campoClave = (n, t, ac) => `<div class="campo"><label>${t}</label>${ic('candado')}<input name="${n}" type="password" autocomplete="${ac || 'new-password'}" required minlength="10"><button type="button" class="ojo" title="Mostrar u ocultar">${ic('ojo')}</button></div>`;
  const ojos = () => $$('.ojo').forEach((b) => b.onclick = () => { const i = b.parentElement.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; });
  function pintarIngreso() {
    const a = pub || {};
    const tok = (location.hash.match(/^#recuperar=([A-Za-z0-9_-]{20,})$/) || [])[1];
    if (tok) return pintarEnlace(tok);
    vidrio(a, `<form id="fLogin"><div class="campo"><label>Usuario</label>${ic('perfil')}<input name="u" autocomplete="username" required autofocus></div>
          ${campoClave('c', 'Contraseña', 'current-password')}
          <button class="b" style="width:100%">Ingresar</button><div id="lMsg"></div></form>
        <button type="button" class="enlace" id="bOlvido">¿Olvidaste tu contraseña?</button>`);
    ojos();
    $('#bOlvido').onclick = () => pintarRecuperar('codigo', $('#fLogin').u.value.trim());
    $('#fLogin').onsubmit = async (e) => {
      e.preventDefault(); const b = e.target.querySelector('button.b'); b.disabled = true;
      try { await api('POST', 'sesion', { usuario: e.target.u.value.trim(), clave: e.target.c.value }); await iniciar(); } catch (x) { aviso($('#lMsg'), 'mal', x.message); } finally { b.disabled = false; }
    };
  }
  /** «¿Olvidaste tu contraseña?»: con un código guardado, por correo (si el nodo tiene Internet) o con el administrador. */
  async function pintarRecuperar(modo, usuario) {
    const a = pub || {}; let op = { codigo: true, email: false, lanzador: (a.producto || 'iProy') + '-Control' };
    try { op = await api('GET', 'recuperar/opciones'); } catch (x) { /* se ofrece lo que funciona sin red */ }
    const tabs = [['codigo', 'Tengo un código'], ...(op.email ? [['email', 'Por correo']] : []), ['admin', 'No tengo nada']];
    const cuerpo = {
      codigo: `<p class="mini">Usá uno de los <b>códigos de recuperación</b> que generaste en «Mi perfil» (cada uno sirve una sola vez).</p>
        <form id="fRec"><div class="campo"><label>Usuario</label>${ic('perfil')}<input name="u" value="${h(usuario || '')}" autocomplete="username" required></div>
          <div class="campo"><label>Código de recuperación</label>${ic('candado')}<input name="cod" placeholder="XXXXX-XXXXX" autocomplete="one-time-code" required style="text-transform:uppercase;letter-spacing:.08em"></div>
          ${campoClave('n', 'Contraseña nueva (10 o más caracteres)')}${campoClave('r', 'Repetila')}
          <button class="b" style="width:100%">Cambiar la contraseña</button><div id="rMsg"></div></form>`,
      email: `<p class="mini">Te mandamos un enlace al correo que tenés cargado. Funciona solo si el nodo tiene Internet.</p>
        <form id="fRec"><div class="campo"><label>Usuario</label>${ic('perfil')}<input name="u" value="${h(usuario || '')}" autocomplete="username" required></div>
          <button class="b" style="width:100%">Enviarme el enlace</button><div id="rMsg"></div></form>`,
      admin: `<div class="pasos"><p><b>1.</b> Pedile al <b>administrador</b> una <b>clave temporal</b> (la genera en Usuarios). Al ingresar con ella, el sistema te pide elegir una propia.</p>
        <p><b>2.</b> Si sos <b>el único administrador</b>, en la PC donde está instalado el nodo abrí una terminal en su carpeta y escribí:</p>
        <pre>bash ${h(op.lanzador)}.sh usuario clave ${h(usuario || 'admin')}</pre><p class="mini">En Windows: <code>${h(op.lanzador)}.bat usuario clave ${h(usuario || 'admin')}</code>. Pide la contraseña nueva dos veces y no la muestra.</p>
        <p><b>3.</b> Para la próxima: en «Mi perfil» generá tus <b>códigos de recuperación</b> y guardalos impresos.</p></div>`,
    };
    vidrio(a, `<h2 class="rec-t">Recuperar el acceso</h2><div class="pest rec-p">${tabs.map(([k, t]) => `<button type="button" data-m="${k}" class="${k === modo ? 'activo' : ''}">${t}</button>`).join('')}</div>
      ${cuerpo[modo] || cuerpo.codigo}<button type="button" class="enlace" id="bVolver">← Volver al ingreso</button>`);
    ojos();
    $$('.rec-p [data-m]').forEach((b) => b.onclick = () => pintarRecuperar(b.dataset.m, $('#fRec')?.u?.value?.trim() || usuario));
    $('#bVolver').onclick = () => pintarIngreso();
    const f = $('#fRec'); if (!f) return;
    f.onsubmit = async (e) => {
      e.preventDefault(); const b = f.querySelector('button.b'); b.disabled = true;
      try {
        if (modo === 'email') { const r = await api('POST', 'recuperar/email', { usuario: f.u.value.trim() }); aviso($('#rMsg'), 'ok', r.mensaje); }
        else {
          if (f.n.value !== f.r.value) throw new Error('Las contraseñas nuevas no coinciden');
          const r = await api('POST', 'recuperar/codigo', { usuario: f.u.value.trim(), codigo: f.cod.value, nueva: f.n.value });
          vidrio(a, `<div class="aviso ok">Listo: tu contraseña cambió. ${r.quedan <= 2 ? `<b>Te quedan ${r.quedan} códigos:</b> generá nuevos en «Mi perfil».` : `Te quedan ${r.quedan} códigos.`}</div><button class="b" style="width:100%" id="bIr">Ingresar</button>`);
          $('#bIr').onclick = () => pintarIngreso();
        }
      } catch (x) { aviso($('#rMsg'), 'mal', x.message); } finally { b.disabled = false; }
    };
  }
  /** Enlace que llegó por correo: #recuperar=<token> */
  function pintarEnlace(tok) {
    const a = pub || {};
    vidrio(a, `<h2 class="rec-t">Elegí tu nueva contraseña</h2><form id="fEnl">${campoClave('n', 'Contraseña nueva (10 o más caracteres)')}${campoClave('r', 'Repetila')}
      <button class="b" style="width:100%">Guardar</button><div id="eMsg"></div></form><button type="button" class="enlace" id="bVolver">← Volver al ingreso</button>`);
    ojos(); const salirEnl = () => { history.replaceState(null, '', location.pathname); pintarIngreso(); };
    $('#bVolver').onclick = salirEnl;
    $('#fEnl').onsubmit = async (e) => {
      e.preventDefault(); const f = e.target;
      try { if (f.n.value !== f.r.value) throw new Error('Las contraseñas no coinciden');
        const r = await api('POST', 'recuperar/enlace', { token: tok, nueva: f.n.value }); history.replaceState(null, '', location.pathname);
        vidrio(a, `<div class="aviso ok">Listo, ${h(r.usuario)}: tu contraseña cambió.</div><button class="b" style="width:100%" id="bIr">Ingresar</button>`); $('#bIr').onclick = () => pintarIngreso();
      } catch (x) { aviso($('#eMsg'), 'mal', x.message); }
    };
  }
  /** Clave temporal del administrador: antes de cualquier otra cosa, el usuario elige la suya. */
  function pintarCambioObligatorio() {
    const a = pub || {};
    vidrio(a, `<h2 class="rec-t">Elegí tu contraseña</h2><p class="mini">Entraste con una <b>clave temporal</b>. Para seguir, cambiala por una tuya (10 o más caracteres). La temporal deja de servir.</p>
      <form id="fObl">${campoClave('t', 'Clave temporal', 'current-password')}${campoClave('n', 'Contraseña nueva')}${campoClave('r', 'Repetila')}
      <button class="b" style="width:100%">Guardar y entrar</button><div id="oMsg"></div></form><button type="button" class="enlace" id="bSalir2">Salir</button>`);
    ojos();
    $('#bSalir2').onclick = async () => { await api('DELETE', 'sesion').catch(() => {}); S = null; pintarIngreso(); };
    $('#fObl').onsubmit = async (e) => {
      e.preventDefault(); const f = e.target;
      try { if (f.n.value !== f.r.value) throw new Error('Las contraseñas nuevas no coinciden');
        await api('POST', 'sesion/clave', { actual: f.t.value, nueva: f.n.value }); await iniciar(); } catch (x) { aviso($('#oMsg'), 'mal', x.message); }
    };
  }

  // ---------------------------------------------------------------- cáscara: barra, menú lateral y navegación
  const logoNodo = () => V?.logo || 'logo.png';
  function pintarBarra() { const n = $('#bUsr'); if (n) n.textContent = (S.usuario.nombre || S.usuario.usuario).split(' ').slice(0, 2).join(' '); }
  const vista = () => (V?.vista === 'menu' ? 'menu' : 'widgets');
  const MANUAL = 'https://iproy.com.ar/aprende-iproy';   // manual de usuario (1.7.0); los productos incubados lo conservan
  const menuUsuario = () => `<div class="menuu" id="mUsr"><button data-p="perfil">${ic('perfil')} Mi perfil, vista y fondo</button>${instalable ? `<button id="bInst">${ic('bajar')} Instalar la aplicación</button>` : ''}${puede('sistema.admin') ? `<button data-p="configuracion">${ic('config')} Configuración</button>` : ''}<a class="menuu-enlace" href="${MANUAL}" target="_blank" rel="noopener">${ic('manual')} Manual de usuario</a><hr><button id="bSalir">${ic('salir')} Salir</button></div>`;
  /** «Vista menú»: encabezado de color con el logo y los módulos arriba, agrupados por tipo (1.7.0): cada grupo abre su submenú;
   *  un grupo de un solo acceso va directo. Si los grupos no entran a lo ancho (celular), queda un solo botón «Menú» con todos los grupos. */
  function pintarMenu() {
    const vis = visibles(); const prod = S.producto; const G = porGrupo(vis);
    const btnAcc = (p) => `<button class="navi" data-p="${p.id}" style="--gc:${GRUPOS[meta(p).grupo][1]}" title="${h(meta(p).desc)}">${ic(meta(p).icono)}<span>${h(p.titulo)}</span></button>`;
    $('#raiz').innerHTML = `<div id="capa" class="v-menu">
      <header class="cabm" id="cabm"><div class="cabm-fila"><img class="cabm-logo" src="${h(logoNodo())}" alt=""><div class="cabm-tit"><h1>${h(V?.institucion || prod.nombre)}</h1><small>${h(V?.lema ? V.lema + ' · ' : '')}${h(prod.nombre)}</small></div>
        <span class="chipm">Nodo ${h(S.nodo.id)} · ${h(S.nodo.nombre)}</span><div class="der"><button class="pildora claro" id="bUsr" aria-haspopup="true"></button></div>${menuUsuario()}</div>
        <nav class="navm" id="navm"><button data-p="inicio" style="--gc:#ffffff">${ic('inicio')}<span>Tablero</span></button>
          <button class="navtodo" id="navTodo" aria-haspopup="true" aria-expanded="false" style="--gc:#ffffff">${ic('menu')}<span>Menú</span><small id="navAqui"></small>${ic('abajo', 'i chev')}</button>
          ${G.map((x) => x.items.length === 1 ? btnAcc(x.items[0]) : `<button class="navg" data-g="${x.g}" aria-haspopup="true" aria-expanded="false" style="--gc:${x.color}">${ic(x.icono)}<span>${h(x.nombre)}</span>${ic('abajo', 'i chev')}</button>`).join('')}</nav>
        <div class="navd" id="navd" hidden></div></header>
      <main id="main"></main><footer id="pie"></footer></div>`;
    const nav = $('#navm'), d = $('#navd'), cab = $('#cabm');
    const item = (p) => `<button data-p="${p.id}" class="${p.id === actual ? 'activo' : ''}" style="--gc:${GRUPOS[meta(p).grupo][1]}">${ic(meta(p).icono)}<span><b>${h(p.titulo)}</b>${meta(p).desc ? `<small>${h(meta(p).desc)}</small>` : ''}</span></button>`;
    const cerrarD = () => { d.hidden = true; d.dataset.g = ''; $$('#navm [aria-expanded]').forEach((b) => b.setAttribute('aria-expanded', 'false')); };
    const abrir = (btn, html, ancho) => {
      d.innerHTML = html; d.hidden = false; btn.setAttribute('aria-expanded', 'true');
      const rc = cab.getBoundingClientRect(), rb = btn.getBoundingClientRect(), w = Math.min(ancho, window.innerWidth - 16);
      d.style.width = w + 'px'; d.style.top = (rb.bottom - rc.top) + 'px';
      d.style.left = Math.max(8, Math.min(rb.left - rc.left, window.innerWidth - w - 8)) + 'px';
      $$('[data-p]', d).forEach((b) => b.onclick = () => { cerrarD(); ir(b.dataset.p); });
      $$('details', d).forEach((x) => x.ontoggle = () => { if (x.open) $$('details', d).forEach((o) => { if (o !== x) o.open = false; }); });   // acordeón: un grupo abierto a la vez
    };
    $$('#navm .navi, #navm [data-p=inicio]').forEach((b) => b.onclick = () => { cerrarD(); ir(b.dataset.p); });
    $$('#navm .navg').forEach((b) => b.onclick = (e) => {
      e.stopPropagation(); if (!d.hidden && d.dataset.g === b.dataset.g) return cerrarD(); cerrarD();
      const x = G.find((y) => y.g === b.dataset.g); d.dataset.g = x.g;
      abrir(b, `<div class="navd-tit" style="--gc:${x.color}"><i></i>${h(x.nombre)}</div><div class="navd-items ${x.items.length > 6 ? 'dos' : ''}">${x.items.map(item).join('')}</div>`, x.items.length > 6 ? 560 : 330);
    });
    $('#navTodo').onclick = (e) => {
      e.stopPropagation(); if (!d.hidden && d.dataset.g === '*') return cerrarD(); cerrarD(); d.dataset.g = '*';
      const ga = meta(pestanas.find((p) => p.id === actual) || {}).grupo;
      abrir(e.currentTarget, G.map((x) => `<details class="navd-g" ${x.g === ga ? 'open' : ''} style="--gc:${x.color}"><summary>${ic(x.icono)}<span>${h(x.nombre)}</span><em>${x.items.length}</em>${ic('abajo', 'i chev')}</summary>
        <div class="navd-items">${x.items.map(item).join('')}</div></details>`).join(''), 420);
    };
    d.onclick = (e) => e.stopPropagation();
    // compacto: cuando los grupos no entran en el ancho, un solo botón «Menú» (se recalcula al cambiar el tamaño)
    const medir = () => { nav.classList.remove('compacto'); if (nav.scrollWidth > nav.clientWidth + 2) nav.classList.add('compacto'); if (!d.hidden) cerrarD(); };
    medir(); if (window.ResizeObserver) new ResizeObserver(() => medir()).observe(cab); else addEventListener('resize', medir);
  }
  function pintar() {
    if (!S) return pintarIngreso();
    if (vista() === 'menu') { pintarMenu(); comunes(); return; }
    const vis = visibles();
    $('#raiz').innerHTML = `<div id="capa">
      <header class="barra"><button class="ib sin" id="bMenu" title="Menú">${ic('menu')}</button>
        <div class="tit"><b id="tTit">Inicio</b><small id="tMiga">General / Inicio</small></div>
        <div class="navb"><button class="ib" id="bAtras" title="Atrás">${ic('izq')}</button><button class="ib" id="bInicio" title="Inicio">${ic('inicio')}</button><button class="ib" id="bAdel" title="Adelante">${ic('der')}</button></div>
        <div class="der"><button class="pildora" id="bUsr" aria-haspopup="true"></button></div>
        ${menuUsuario()}</header>
      <aside class="lateral" id="lat"><div class="cab"><img src="${h(logoNodo())}" alt=""><div><b>${h(V?.institucion || S.producto.nombre)}</b><small>${h(S.producto.nombre)} · Nodo ${h(S.nodo.id)}</small></div></div>
        <nav><button data-p="inicio">${ic('inicio')} Inicio</button>${porGrupo(vis).map((x) => `<details class="lgrupo" data-g="${x.g}" style="--gc:${x.color}"><summary><i></i>${h(x.nombre)}<em>${x.items.length}</em>${ic('abajo', 'i chev')}</summary>${x.items.map((p) =>
          `<button data-p="${p.id}">${ic(meta(p).icono)} ${h(p.titulo)}</button>`).join('')}</details>`).join('')}<h6>Cuenta</h6><button data-p="perfil">${ic('perfil')} Mi perfil</button></nav></aside>
      <main id="main"></main><footer id="pie"></footer></div>`;
    $('#bMenu').onclick = () => { $('#lat').classList.add('abierto'); $('#velo').classList.add('abierto'); };
    $$('#lat [data-p]').forEach((b) => b.onclick = () => { cerrar(); ir(b.dataset.p); });
    $('#bAtras').onclick = () => history.back(); $('#bAdel').onclick = () => history.forward(); $('#bInicio').onclick = () => ir('inicio');
    comunes();
  }
  // ---------------------------------------------------------------- actualizaciones (1.7.0): versión nueva sobre lo instalado, con los datos intactos
  const ACT_PASOS = [['pedido', 'Preparando'], ['respaldo', 'Respaldo de la base'], ['detener', 'Deteniendo el sistema'], ['preparar', 'Revisando el paquete'], ['codigo', 'Cambiando el código'], ['iniciar', 'Iniciando y aplicando los cambios de la base'], ['fin', 'Listo']];
  const ACT_TIPO = { nueva: ['verde', 'Versión nueva'], 'otra-compilacion': ['', 'Otra compilación'], igual: ['gris', 'Ya instalada'], anterior: ['ama', 'Versión anterior'], mayor: ['nar', 'Versión mayor'], incompatible: ['rojo', 'No se puede instalar'] };
  const fechaCorta = (s) => (s ? new Date(s).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }) : '—');
  // historial: las 5 últimas a la vista; el resto, plegado (en el celular cada fila es una tarjeta)
  const histTabla = (l) => tabla([{ t: 'Fecha', v: (x) => fechaCorta(x.fecha) }, { t: 'Qué', v: (x) => (x.accion === 'volver' ? 'Volver atrás' : 'Actualizar') }, { t: 'De', v: (x) => x.desde || '' }, { t: 'A', v: (x) => x.ahora || '' },
    { t: 'Resultado', html: true, v: (x) => `<span class="et ${x.resultado === 'ok' || x.resultado === 'vuelto' ? 'verde' : 'rojo'}">${h(x.resultado === 'ok' ? 'Instalada' : x.resultado === 'vuelto' ? 'Vuelta atrás' : 'Falló')}</span>${x.mensaje ? ` <span class="mini">${h(x.mensaje)}</span>` : ''}` },
    { t: 'Respaldo de la base', v: (x) => x.respaldo || '' }, { t: 'Por', v: (x) => x.por || '' }], l);
  async function cfgActualizaciones(c) {
    const A = await api('GET', 'actualizacion'); const I = A.instalado; const pr = S.producto;
    if (A.curso?.activo && (Date.now() / 1000 - (A.curso.ts || 0)) < 900) return seguirActualizacion(c);
    const D = A.disponible, P = A.paquete, cmp = P?.comparacion;
    const fin = A.curso && !A.curso.activo && A.curso.ts > Date.now() / 1000 - 600 ? A.curso : null;
    c.innerHTML = `${fin ? `<div class="aviso ${fin.resultado === 'ok' || fin.resultado === 'vuelto' ? 'ok' : 'mal'}">${h(fin.mensaje || fin.resultado)}</div>` : ''}
      <div class="tarjeta"><h2>Versión instalada</h2>
        <p style="font-size:18px;margin:4px 0"><b>${h(pr.nombre)} ${h(pr.version)}</b>${pr.base ? ` <span class="mini">· ${h(pr.base)}</span>` : ''}</p>
        <p class="mini">Compilación ${h(I.compilacion)}${I.fecha ? ` (${h(fechaCorta(I.fecha))})` : ''}. Las versiones nuevas se instalan sobre esta: usuarios, equipos, órdenes, configuración y vínculos de la colmena no se tocan.</p>
        ${A.lanzador ? '' : '<div class="aviso info">Este nodo no tiene el lanzador con actualizaciones: instalá esta versión una vez a mano (con el instalador o el actualizador del paquete).</div>'}</div>
      <div class="tarjeta"><h2>Versión publicada en GitHub</h2>
        ${D ? (D.es_nueva ? `<div class="aviso ok"><b>Hay una versión nueva: ${h(D.version)}</b>${D.fecha ? `, publicada el ${h(fechaCorta(D.fecha))}` : ''}.</div>${D.notas ? `<details class="desp"><summary><span class="mas">＋</span>Qué trae</summary><div class="desp-c" style="white-space:pre-wrap;font-size:13px">${h(D.notas)}</div></details>` : ''}`
          : `<p>Tenés la última versión publicada (${h(D.version)}).</p>`) : '<p class="mini">Todavía no se buscó.</p>'}
        <p class="mini">Repositorio ${h(A.repositorio)}${D?.revisado ? ` · revisado el ${h(fechaCorta(D.revisado))}` : ''}.</p>
        <div class="acciones"><button class="b ${D?.es_nueva ? 'sec' : ''}" id="aBus">${ic('buscar')} Buscar ahora</button>${D?.es_nueva && !(P && P.version === D.version) ? `<button class="b" id="aBaj">${ic('bajar')} Bajar la ${h(D.version)}</button>` : ''}</div>
        <label class="fchk" style="margin-top:8px"><input type="checkbox" id="aAuto" ${A.revisar_auto !== false ? 'checked' : ''}> Revisar una vez por día y avisar a los administradores</label><div id="aMsgB"></div></div>
      <div class="tarjeta"><h2>Subir un paquete (.zip)</h2><p class="mini">El .zip del código de una versión: el de GitHub, o el que te pasen para probar antes de publicarla. Sirve también sin Internet.</p>
        <div class="acciones"><input type="file" id="aArch" accept=".zip"><button class="b sec" id="aSub">${ic('bajar')} Subir</button></div><div id="aMsgS"></div></div>
      ${P ? `<div class="tarjeta" id="aListo" style="border:2px solid var(--az3)"><h2>Listo para instalar</h2>
        <p><b>${h(P.producto)} ${h(P.version)}</b> · compilación ${h(P.compilacion)}${P.fecha ? ` del ${h(fechaCorta(P.fecha))}` : ''} · <span class="et ${(ACT_TIPO[cmp.tipo] || [''])[0]}">${h((ACT_TIPO[cmp.tipo] || [0, cmp.tipo])[1])}</span></p>
        <p class="mini">${h(cmp.mensaje)} · ${h(P.origen)} · ${(P.bytes / 1048576).toFixed(1)} MB · SHA-256 ${h(String(P.sha256).slice(0, 16))}…</p>
        ${cmp.tipo === 'anterior' ? '<label class="fchk"><input type="checkbox" id="aForz"> Instalar una versión anterior igual</label>' : ''}
        ${cmp.tipo === 'mayor' ? '<label class="fchk"><input type="checkbox" id="aMay"> Leí qué cambia: instalar la versión mayor</label>' : ''}
        <ol class="mini" style="margin:10px 0 0 18px;padding:0;line-height:1.6"><li>Se hace un respaldo de la base.</li><li>El sistema se detiene alrededor de un minuto: avisá a quien lo esté usando.</li>
          <li>Se cambia solo el código. Los datos quedan como están.</li><li>Vuelve solo y aplica los cambios de la base.</li><li>Si no arranca, vuelve solo a la versión anterior.</li></ol>
        <div class="acciones">${['incompatible', 'igual'].includes(cmp.tipo) || !A.lanzador ? '' : `<button class="b" id="aApl">${ic('ok')} Actualizar ahora</button>`}<button class="b sec" id="aDes">Descartar el paquete</button></div><div id="aMsgA"></div></div>` : ''}
      <div class="tarjeta"><div class="tcab"><h2>Historial</h2>${A.puede_volver && A.lanzador ? `<button class="b peligro" id="aVol">Volver a la versión anterior</button>` : ''}</div>
        ${A.historial.length ? histTabla(A.historial.slice(0, 5)) + (A.historial.length > 5 ? `<details class="desp"><summary><span class="mas">＋</span>Ver las ${A.historial.length - 5} anteriores</summary><div class="desp-c">${histTabla(A.historial.slice(5))}</div></details>` : '')
          : '<p class="mini">Todavía no hubo actualizaciones en este nodo.</p>'}
        <div id="aMsgV"></div></div>`;
    const conBoton = (b, msg, fn) => async () => { b.disabled = true; try { await fn(); } catch (x) { aviso(msg, 'mal', x.message); } finally { b.disabled = false; } };
    $('#aBus').onclick = conBoton($('#aBus'), $('#aMsgB'), async () => { aviso($('#aMsgB'), 'info', 'Preguntando a GitHub…'); await api('POST', 'actualizacion/buscar'); cfgActualizaciones(c); });
    if ($('#aBaj')) $('#aBaj').onclick = conBoton($('#aBaj'), $('#aMsgB'), async () => { aviso($('#aMsgB'), 'info', 'Bajando la versión nueva…'); await api('POST', 'actualizacion/bajar'); cfgActualizaciones(c); });
    $('#aAuto').onchange = async (e) => { try { await api('PUT', 'actualizacion/config', { revisar_auto: e.target.checked }); } catch (x) { aviso($('#aMsgB'), 'mal', x.message); } };
    $('#aSub').onclick = conBoton($('#aSub'), $('#aMsgS'), async () => {
      const f = $('#aArch').files[0]; if (!f) throw new Error('Elegí el archivo .zip');
      const T = 3 * 1048576, n = Math.max(1, Math.ceil(f.size / T)), id = Math.random().toString(36).slice(2, 14).padEnd(12, '0');
      for (let i = 1; i <= n; i++) { aviso($('#aMsgS'), 'info', `Subiendo… ${Math.round((i - 1) / n * 100)} %`); await api('POST', `actualizacion/subir?id=${id}&parte=${i}&total=${n}&nombre=${encodeURIComponent(f.name)}`, undefined, f.slice((i - 1) * T, i * T)); }
      await cfgActualizaciones(c); $('#aListo')?.scrollIntoView({ behavior: 'smooth' });
    });
    if ($('#aDes')) $('#aDes').onclick = conBoton($('#aDes'), $('#aMsgA'), async () => { await api('DELETE', 'actualizacion/paquete'); cfgActualizaciones(c); });
    if ($('#aApl')) $('#aApl').onclick = conBoton($('#aApl'), $('#aMsgA'), async () => {
      await api('POST', 'actualizacion/aplicar', { forzar: !!$('#aForz')?.checked, confirmar_mayor: !!$('#aMay')?.checked }); seguirActualizacion(c);
    });
    if ($('#aVol')) $('#aVol').onclick = () => {
      const m = $('#aMsgV'); m.innerHTML = `<div class="aviso info">Vuelve el código de la versión anterior (${h(A.anterior || '')}). Los datos se quedan como están ahora. El sistema se detiene alrededor de un minuto.
        <div class="acciones" style="margin-top:8px"><button class="b peligro" id="aVolOk">Sí, volver</button><button class="b sec" id="aVolNo">Cancelar</button></div></div>`;
      $('#aVolNo').onclick = () => { m.innerHTML = ''; };
      $('#aVolOk').onclick = conBoton($('#aVolOk'), m, async () => { await api('POST', 'actualizacion/volver'); seguirActualizacion(c); });
    };
  }
  /** Mientras dura: el sistema se detiene y vuelve; se consulta cada 3 s y, al terminar, se recarga la página (puede venir pantalla nueva). */
  function seguirActualizacion(c) {
    const pintarPaso = (cur, caido) => {
      let i = Math.max(0, ACT_PASOS.findIndex((x) => x[0] === cur?.paso));
      if (caido) i = Math.max(i, ACT_PASOS.findIndex((x) => x[0] === 'codigo'));   // sin respuesta: el sistema está detenido mientras se cambia el código
      c.innerHTML = `<div class="tarjeta"><h2>${cur?.accion === 'volver' ? 'Volviendo a la versión anterior' : 'Actualizando'}</h2><p class="mini">No cierres esta pantalla. El sistema se detiene alrededor de un minuto y vuelve solo.</p>
        <ol style="margin:10px 0 0 18px;padding:0;line-height:1.9">${ACT_PASOS.map(([k, t], j) => `<li style="${j < i ? 'color:var(--ok)' : j === i ? 'font-weight:700;color:var(--az)' : 'color:var(--gris)'}">${j < i ? '✔ ' : ''}${h(t)}${j === i && caido ? ' · el sistema está detenido; vuelve solo en un momento…' : ''}</li>`).join('')}</ol></div>`;
    };
    pintarPaso({ paso: 'pedido' }); let ult = { paso: 'pedido' }; const t0 = Date.now();
    const vuelta = async () => {
      let A = null; try { A = await api('GET', 'actualizacion'); } catch (x) { pintarPaso(ult, true); }
      if (A) { if (A.curso) ult = A.curso; if (A.curso && !A.curso.activo) { pintarPaso({ paso: 'fin' }); aviso(c.querySelector('.tarjeta'), A.curso.resultado === 'ok' || A.curso.resultado === 'vuelto' ? 'ok' : 'mal', (A.curso.mensaje || '') + ' · Recargando…'); setTimeout(() => location.reload(), 2500); return; } pintarPaso(ult, false); }
      if (Date.now() - t0 > 15 * 60000) { aviso(c, 'mal', 'La actualización tarda más de lo normal: mirá data/logs/actualizacion.log en el equipo del nodo.'); return; }
      setTimeout(vuelta, 3000);
    };
    setTimeout(vuelta, 2500);
  }
  /** A los administradores: aviso de versión nueva (la revisión diaria la deja en el nodo). */
  async function avisoActualizacion() {
    let visto = false; try { visto = !!sessionStorage.getItem('iproy-act-visto'); } catch (x) { /* sin almacenamiento */ }
    if (!puede('sistema.admin') || visto) return;
    let A; try { A = await api('GET', 'actualizacion'); } catch (x) { return; }
    if (!A.disponible?.es_nueva || $('#avisoAct')) return;
    const d = document.createElement('div'); d.id = 'avisoAct'; d.className = 'aviso-act';
    d.innerHTML = `${ic('bajar')}<span>Hay una versión nueva: <b>${h((S.producto.base || S.producto.nombre).replace(/\s*[\d.]+$/, ''))} ${h(A.disponible.version)}</b></span><button class="b sec" id="avActVer">Ver</button><button class="ib" id="avActX" title="Cerrar">✕</button>`;
    document.body.appendChild(d);
    $('#avActVer').onclick = () => { d.remove(); pestCfg = 'actualizaciones'; ir('configuracion'); };
    $('#avActX').onclick = () => { d.remove(); try { sessionStorage.setItem('iproy-act-visto', '1'); } catch (x) { /* sin almacenamiento */ } };
  }

  function comunes() {
    pintarBarra(); pie();
    $$('#mUsr [data-p]').forEach((b) => b.onclick = () => { cerrar(); $('#mUsr').classList.remove('abierto'); ir(b.dataset.p); });
    $('#bUsr').onclick = (e) => { e.stopPropagation(); $('#mUsr').classList.toggle('abierto'); };
    $('#bSalir').onclick = async () => { await api('DELETE', 'sesion').catch(() => {}); S = null; V = null; await cargarPublica(); pintar(); };
    aplicarFondo(V?.fondo, V?.opacidad);
    ir((location.hash.slice(1) || actual || 'inicio'), true);
    avisoActualizacion();
  }
  function pie() {
    const p = S.producto; const f = $('#pie'); if (!f) return;
    f.innerHTML = `${h(p.nombre)} ${h(p.version)} · software libre <a href="/api/fuente">${h(p.licencia)}</a>${p.fuente ? ` · <a href="${h(p.fuente)}" rel="noopener">código fuente</a>` : ''}${p.base ? ` · basado en ${h(p.base)}` : ''} · estándar PIM`;
  }
  let guardarRec;
  async function ir(id, sinHist) {
    const [idB, qs = ''] = String(id).split('?');   // ola 3c: #acceso?clave=valor (enlace directo, p. ej. el QR de un pedido)
    const p = pestanas.find((x) => x.id === idB && (!x.cap || puede(x.cap)) && enCatalogo(x)) || pestanas.find((x) => x.id === 'inicio');
    PARAM = new URLSearchParams(p.id === idB ? qs : ''); const destino = p.id + (PARAM.toString() ? '?' + PARAM.toString() : '');
    actual = p.id; if (location.hash.slice(1) !== destino) { sinHist ? history.replaceState(null, '', '#' + destino) : history.pushState(null, '', '#' + destino); }
    const m = meta(p); if ($('#tTit')) { $('#tTit').textContent = p.titulo; $('#tMiga').textContent = (p.id === 'inicio' ? 'General' : p.id === 'perfil' ? 'Cuenta' : GRUPOS[m.grupo][0]) + ' / ' + p.titulo; }
    $$('#navm button').forEach((b) => b.classList.toggle('activo', b.dataset.p === p.id || (!!b.dataset.g && b.dataset.g === m.grupo && p.id !== 'inicio')));
    if ($('#navd')) { $('#navd').hidden = true; $$('#navm [aria-expanded]').forEach((b) => b.setAttribute('aria-expanded', 'false')); }
    if ($('#navAqui')) { $('#navAqui').textContent = p.id === 'inicio' ? '' : p.titulo; $('#navTodo').classList.toggle('activo', p.id !== 'inicio'); }
    $$('#lat details.lgrupo').forEach((x) => { if (x.dataset.g === m.grupo && p.id !== 'inicio') x.open = true; });
    document.title = `${p.titulo} · ${V?.institucion || S.producto.nombre}`;
    $$('#lat nav button').forEach((b) => b.classList.toggle('activo', b.dataset.p === p.id));
    if (!['inicio', 'perfil', 'configuracion'].includes(p.id) && V) {
      V.recientes = [p.id, ...(V.recientes || []).filter((x) => x !== p.id)].slice(0, 6);
      clearTimeout(guardarRec); guardarRec = setTimeout(() => api('PUT', 'perfil', { recientes: V.recientes }).catch(() => {}), 800);
    }
    const el = $('#main'); el.innerHTML = '<p class="mini">Cargando…</p>'; window.scrollTo({ top: 0 });
    try { await p.render(el); } catch (x) { aviso(el, 'mal', x.message); console.error(x); }
  }
  Nucleo.ir = (id) => ir(id);
  addEventListener('popstate', () => { if (S) ir(location.hash.slice(1) || 'inicio', true); });
  addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { cerrar(); $('#mUsr')?.classList.remove('abierto'); if ($('#navd')) { $('#navd').hidden = true; $$('#navm [aria-expanded]').forEach((b) => b.setAttribute('aria-expanded', 'false')); } }
    if (e.key === '/' && S && actual === 'inicio' && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); $('#qMod')?.focus(); }
  });
  addEventListener('click', () => { $('#mUsr')?.classList.remove('abierto'); if ($('#navd') && !$('#navd').hidden) { $('#navd').hidden = true; $$('#navm [aria-expanded]').forEach((b) => b.setAttribute('aria-expanded', 'false')); } });
  async function refrescarVista() { try { V = (await api('GET', 'perfil')).vista; aplicarFondo(V.fondo, V.opacidad); const l = $('#lat .cab img'); if (l) l.src = logoNodo(); } catch (x) { /* sigue */ } }
  async function cargarPublica() { try { pub = await api('GET', 'apariencia'); } catch (x) { pub = null; } }
  async function iniciar() {
    const j = await api('GET', 'sesion');
    if (!j.ok) { S = null; await cargarPublica(); pintar(); return; }
    S = j;
    if (j.cambiar_clave) { await cargarPublica(); pintarCambioObligatorio(); return; }
    for (const u of j.ui) {   // cada módulo con interfaz aporta su ui.js (se carga en orden, una sola vez)
      if (cargados.has(u.modulo)) continue; cargados.add(u.modulo);
      await new Promise((ok) => { const s = document.createElement('script'); s.src = `/api/modulos/${u.modulo}/ui.js?v=${encodeURIComponent(u.version)}`; s.onload = ok; s.onerror = ok; document.head.appendChild(s); });
    }
    try { V = (await api('GET', 'perfil')).vista; } catch (x) { V = null; }
    aplicarCatalogo(null); for (const f of alIniciar) { try { await f(); } catch (x) { /* el módulo avisa por su cuenta */ } }
    pintar();
  }
  // ---------------------------------------------------------------- aplicación instalable y aviso de «sin conexión» (1.7.0)
  if ('serviceWorker' in navigator && isSecureContext) navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => {});
  addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); instalable = e; });
  addEventListener('appinstalled', () => { instalable = null; });
  document.addEventListener('click', async (e) => { if (!e.target.closest?.('#bInst') || !instalable) return; const ev = instalable; instalable = null; try { await ev.prompt(); } catch (x) { /* el navegador ya lo mostró */ } });
  const sinRed = () => { let b = $('#sinred'); if (navigator.onLine) { b?.remove(); return; }
    if (!b) { b = document.createElement('div'); b.id = 'sinred'; b.className = 'sinred'; b.setAttribute('role', 'status'); b.textContent = 'Sin conexión con el nodo: lo que ves puede estar desactualizado.'; document.body.appendChild(b); } };
  addEventListener('online', sinRed); addEventListener('offline', sinRed); sinRed();
  $('#velo').onclick = cerrar;
  iniciar().catch(async () => { S = null; await cargarPublica(); pintar(); });
})();
