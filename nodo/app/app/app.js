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
  const pestanas = []; const widgets = [];
  let S = null; let V = null; let actual = null; let pub = null; const cargados = new Set();

  // ---------------------------------------------------------------- íconos (SVG en línea: funciona sin Internet)
  const P = {
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
    diario: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z"/><path d="M9 7h7M9 11h5"/>',
  };
  const ic = (n, cls = 'i') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true">${P[n] || P.modulo}</svg>`;

  // ---------------------------------------------------------------- categorías y metadatos de los accesos
  const GRUPOS = { mant: ['Mantenimiento', '#1e88e5'], plan: ['Planificación', '#43a047'], moni: ['Monitoreo', '#8e24aa'], red: ['Red de instituciones', '#00897b'],
    adm: ['Administración', '#546e7a'], otros: ['Otros módulos', '#8d6e63'] };
  const META = { ordenes: ['mant', 'ordenes', 'Seguimiento de trabajos'], solicitud: ['mant', 'solicitud', 'Pedir un trabajo'], inventario: ['mant', 'inventario', 'Activos y ubicaciones'],
    preventivos: ['plan', 'preventivos', 'Planes por tiempo, uso o condición'], alarmas: ['moni', 'alarmas', 'ISA-18.2'], telemetria: ['moni', 'telemetria', 'Sensores y equipos'],
    colmena: ['red', 'colmena', 'Vínculos con otros nodos'], sistema: ['adm', 'sistema', 'Módulos, tareas y diario'], usuarios: ['adm', 'usuarios', 'Altas y roles'],
    configuracion: ['adm', 'config', 'Logo, fondos y tablero'] };
  const meta = (p) => { const m = META[p.id] || []; return { grupo: p.grupo || m[0] || 'otros', icono: p.icono || m[1] || 'modulo', desc: p.desc ?? m[2] ?? '' }; };

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
    if (r.status === 401 && ruta !== 'sesion') { S = null; pintar(); throw new Error('La sesión venció: ingresá de nuevo'); }
    if (!r.ok || j.ok === false) { const e = new Error(j.error || 'HTTP ' + r.status); e.codigo = j.codigo; e.http = r.status; throw e; }
    return j;
  }
  async function pim(metodo, ruta, cuerpo, cab = {}) {
    const o = { method: metodo, headers: Object.assign({ 'X-Nucleo': '1', Accept: 'application/fhir+json' }, cab), credentials: 'same-origin' };
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
    return `<table id="${id}"><thead><tr>${cols.map((c) => `<th>${h(c.t)}</th>`).join('')}</tr></thead><tbody>${filas.map((f, i) =>
      `<tr data-i="${i}" class="${alClic ? 'clic' : ''}">${cols.map((c) => `<td>${c.html ? c.v(f) : h(c.v(f))}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  }
  function panel(titulo, html) {
    $('#panel').innerHTML = `<div style="display:flex;align-items:center;gap:8px"><h2 style="margin:0;color:var(--az);font-size:18px;flex:1">${h(titulo)}</h2><button class="b sec" id="pCerrar">Cerrar</button></div><div id="pCuerpo" style="margin-top:12px">${html}</div>`;
    $('#panel').classList.add('abierto'); $('#velo').classList.add('abierto');
    $('#pCerrar').onclick = cerrar; return $('#pCuerpo');
  }
  function cerrar() { $('#panel').classList.remove('abierto'); $('#velo').classList.remove('abierto'); $('.lateral')?.classList.remove('abierto'); }
  /** Formulario simple: campos [{n, t, tipo: text|number|date|select|textarea|check|password, op: [[v, texto]], v, req}] */
  function form(campos, boton, alEnviar) {
    const id = 'f' + Math.random().toString(36).slice(2);
    setTimeout(() => {
      const f = document.getElementById(id); if (!f) return;
      f.onsubmit = async (e) => {
        e.preventDefault(); const d = {}; const msg = f.querySelector('.fmsg'); const b = f.querySelector('button[type=submit]');
        campos.forEach((c) => { const x = f.elements[c.n]; if (!x) return; d[c.n] = c.tipo === 'number' ? (x.value === '' ? null : Number(x.value)) : c.tipo === 'check' ? x.checked : x.value; });
        b.disabled = true; try { await alEnviar(d, msg); } catch (x) { aviso(msg, 'mal', x.message); } finally { b.disabled = false; }
      };
    });
    return `<form id="${id}"><div class="grid2">${campos.map((c) => `<div style="${c.tipo === 'textarea' ? 'grid-column:1/-1' : ''}"><label>${h(c.t)}${c.req ? ' *' : ''}</label>${
      c.tipo === 'select' ? `<select name="${c.n}">${c.op.map(([v, t]) => `<option value="${h(v)}" ${String(v) === String(c.v ?? '') ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`
      : c.tipo === 'textarea' ? `<textarea name="${c.n}">${h(c.v ?? '')}</textarea>`
      : c.tipo === 'check' ? `<input type="checkbox" name="${c.n}" style="width:auto;min-height:0" ${c.v ? 'checked' : ''}>`
      : `<input name="${c.n}" type="${c.tipo || 'text'}" ${c.tipo === 'number' ? 'step="any"' : ''} value="${h(c.v ?? '')}" ${c.req ? 'required' : ''}>`}</div>`).join('')}</div>
      <div class="acciones"><button class="b" type="submit">${h(boton)}</button></div><div class="fmsg"></div></form>`;
  }
  const puede = (cap) => !!S && (S.usuario.rol === 'AD' || S.capacidades.includes(cap));
  /** Tarjetas de indicadores con el estilo del tablero: [{n, t, sub, icono, color}] */
  const kpis = (l) => `<div class="kpis">${l.map((k) => `<div class="kpi" style="--kc:${h(k.color || 'var(--az3)')}"><div class="ico">${ic(k.icono || 'modulo')}</div>
    <div class="tx"><small>${h(k.t)}</small><b>${h(k.n ?? '—')}</b>${k.sub ? `<span>${h(k.sub)}</span>` : ''}</div></div>`).join('')}</div>`;

  window.Nucleo = {
    api, pim, h, fecha, aviso, tabla, panel, cerrar, form, puede, kpis, icono: ic,
    get sesion() { return S; },
    /** Un módulo registra su acceso: {id, titulo, orden, cap, render(el), grupo?, icono?, desc?} */
    pestana(p) { pestanas.push(p); pestanas.sort((a, b) => (a.orden ?? 50) - (b.orden ?? 50)); },
    /** Un módulo agrega un widget al «Resumen» del inicio: tablero(async (el) => {...}, {id, titulo}) */
    tablero(fn, m = {}) { widgets.push({ fn, id: m.id || 'w' + widgets.length, titulo: m.titulo || 'Widget ' + (widgets.length + 1) }); },
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
    const est = { activo: 'verde', entrante: 'nar', saliente: 'ama', revocado: 'gris' };
    el.innerHTML = `<div class="tarjeta"><h2>Este nodo</h2><dl><dt>Nodo</dt><dd>${h(j.yo.node_id)} · ${h(j.yo.nombre)} (${h(j.yo.producto)} ${h(j.yo.version)})</dd>
      <dt>Huella</dt><dd><code>${h(j.yo.huella)}</code> <span class="mini">— dictala por teléfono al otro administrador antes de que acepte</span></dd>
      <dt>Dirección</dt><dd>${h(j.yo.url)}</dd><dt>Hub de relevo</dt><dd>${j.hub.url ? h(j.hub.url) + (j.hub.activo ? ' · activo' : ' · apagado') : 'sin configurar (solo intranet)'}</dd></dl></div>
      <div class="tarjeta"><h2>Vínculos</h2>${tabla([
        { t: 'Nodo', v: (v) => v.par + ' · ' + (v.nombre || '') }, { t: 'Producto', v: (v) => v.producto || '' },
        { t: 'Estado', html: true, v: (v) => `<span class="et ${est[v.estado] || ''}">${h(v.estado)}</span>` }, { t: 'Rol PIM que le damos', v: (v) => v.rol_pim },
        { t: 'Huella', v: (v) => v.huella }, { t: 'Último contacto', v: (v) => fecha(v.ultimo_contacto) }], j.vinculos, (v) => detalleVinculo(v))}
      ${puede('colmena.admin') ? `<h2 style="margin-top:16px">Pedir un vínculo</h2>${form([{ n: 'destino', t: 'URL del otro nodo (https://IP:8443) o su identificador (por el hub)', req: true }], 'Pedir vínculo', async (d, m) => {
        const r = await api('POST', 'colmena/vincular', d); aviso(m, 'ok', `Pedido a ${r.par} (${r.producto || ''}). Huella del otro nodo: ${r.huella}. ${r.aviso}`); })}` : ''}</div>`;
  } });
  function detalleVinculo(v) {
    const p = panel('Vínculo con ' + v.par, `<dl><dt>Nombre</dt><dd>${h(v.nombre)}</dd><dt>Producto</dt><dd>${h(v.producto)}</dd><dt>Estado</dt><dd>${h(v.estado)}</dd>
      <dt>Huella</dt><dd><code>${h(v.huella)}</code></dd><dt>Dirección</dt><dd>${h(v.url || 'por el hub')}</dd><dt>Rol PIM</dt><dd>${h(v.rol_pim)}</dd></dl>
      <div class="acciones">${v.estado === 'entrante' && puede('colmena.admin') ? '<button class="b" data-a="aceptar">Aceptar (rol consulta)</button>' : ''}
      ${v.estado === 'activo' ? '<button class="b sec" data-a="ping">Probar</button><button class="b sec" data-a="rpc">Contar sus activos y órdenes</button>' : ''}
      ${v.estado !== 'revocado' && puede('colmena.admin') ? '<button class="b peligro" data-a="revocar">Revocar</button>' : ''}</div><div id="vMsg"></div>`);
    p.querySelectorAll('button[data-a]').forEach((b) => b.onclick = async () => {
      const m = $('#vMsg');
      try {
        if (b.dataset.a === 'aceptar') { await api('POST', `colmena/vinculos/${v.par}/aceptar`, { rol: 'CI' }); aviso(m, 'ok', 'Vínculo activo.'); }
        if (b.dataset.a === 'revocar') { await api('POST', `colmena/vinculos/${v.par}/revocar`); aviso(m, 'ok', 'Vínculo revocado.'); }
        if (b.dataset.a === 'ping') { const r = await api('POST', `colmena/vinculos/${v.par}/ping`); aviso(m, 'ok', `Respondió (${r.via}, ${r.ms} ms).`); }
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

  Nucleo.pestana({ id: 'usuarios', titulo: 'Usuarios', orden: 95, cap: 'usuarios.admin', render: async (el) => {
    const j = await api('GET', 'usuarios'); const roles = Object.entries(j.roles);
    el.innerHTML = `<div class="tarjeta"><h2>Usuarios del nodo</h2>${tabla([{ t: 'Usuario', v: (u) => u.usuario }, { t: 'Nombre', v: (u) => u.nombre }, { t: 'Rol', v: (u) => u.rol + ' · ' + j.roles[u.rol] },
      { t: 'Organización', v: (u) => u.organizacion || '' }, { t: 'Activo', v: (u) => +u.activo ? 'sí' : 'no' }, { t: 'Último ingreso', v: (u) => fecha(u.ultimo_ingreso) }], j.usuarios)}
      <h2 style="margin-top:16px">Nuevo usuario</h2>${form([{ n: 'usuario', t: 'Usuario', req: true }, { n: 'nombre', t: 'Nombre visible', req: true },
        { n: 'rol', t: 'Rol', tipo: 'select', op: roles }, { n: 'organizacion', t: 'Organización (código PIM, solo Ejecutor)' }, { n: 'clave', t: 'Contraseña inicial (10+)', tipo: 'password', req: true }],
      'Crear', async (d, m) => { await api('POST', 'usuarios', d); aviso(m, 'ok', 'Usuario creado.'); setTimeout(() => ir('usuarios'), 800); })}</div>`;
  } });

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
      <div class="tarjeta"><h2>Cambiar contraseña</h2>${form([{ n: 'actual', t: 'Contraseña actual', tipo: 'password', req: true }, { n: 'nueva', t: 'Nueva (10 o más caracteres)', tipo: 'password', req: true }, { n: 'repite', t: 'Repetila', tipo: 'password', req: true }],
        'Cambiar', async (d, m) => { if (d.nueva !== d.repite) throw new Error('Las contraseñas nuevas no coinciden'); await api('POST', 'sesion/clave', { actual: d.actual, nueva: d.nueva }); aviso(m, 'ok', 'Contraseña cambiada.'); })}</div>`;
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
    const tabs = { identidad: 'Identidad institucional', fondos: 'Fondos', tablero: 'Tablero' };
    el.innerHTML = `<div class="pest">${Object.entries(tabs).map(([k, t]) => `<button data-t="${k}" class="${pestCfg === k ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="cfgC"></div>`;
    $$('.pest button', el).forEach((b) => b.onclick = () => { pestCfg = b.dataset.t; ir('configuracion'); });
    const c = $('#cfgC');
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

  // ---------------------------------------------------------------- inicio: saludo, resumen y accesos rápidos
  const visibles = () => pestanas.filter((p) => !p.oculta && p.id !== 'inicio' && (!p.cap || puede(p.cap)));
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
      <div class="buscar"><div class="caja">${ic('buscar')}<input id="qMod" placeholder="Buscar módulo…" autocomplete="off" aria-label="Buscar módulo"><kbd>/</kbd></div>
        ${rec.length ? `<div class="recientes">${ic('hist')} <b>Recientes:</b> ${rec.map((p) => `<button class="chipr" data-p="${p.id}" style="--gc:${GRUPOS[meta(p).grupo][1]}">${ic(meta(p).icono)} ${h(p.titulo)}</button>`).join('')}</div>` : ''}</div>
      <div class="grupos">${Object.keys(GRUPOS).filter((g) => porG[g]).map((g) => `<section class="grupo" style="--gc:${GRUPOS[g][1]}"><h3><i></i>${h(GRUPOS[g][0])}<em></em><span>${porG[g].length}</span></h3>
        <div class="tiles">${porG[g].map((p) => `<button class="tile" data-p="${p.id}" data-q="${h((p.titulo + ' ' + meta(p).desc + ' ' + GRUPOS[g][0]).toLowerCase())}"><span class="ico">${ic(meta(p).icono)}</span><span>${h(p.titulo)}${meta(p).desc ? `<small>${h(meta(p).desc)}</small>` : ''}</span></button>`).join('')}</div></section>`).join('')}</div>`}`;
    $$('[data-p]', el).forEach((b) => b.onclick = () => ir(b.dataset.p));
    const bp = $('#bPleg'); if (bp) bp.onclick = () => { const w = $('#wids'); const pl = w.classList.toggle('plegado'); try { localStorage.setItem('iproy-resumen', pl ? '0' : '1'); } catch (x) { /* nada */ }
      bp.innerHTML = `${pl ? 'Mostrar' : 'Ocultar'} ${ic(pl ? 'abajo' : 'arriba')}`; bp.setAttribute('aria-expanded', String(!pl)); };
    const q = $('#qMod'); if (q) q.oninput = () => { const t = q.value.trim().toLowerCase(); $$('.tile', el).forEach((x) => x.classList.toggle('oculto', !!t && !x.dataset.q.includes(t)));
      $$('.grupo', el).forEach((g) => g.style.display = $$('.tile:not(.oculto)', g).length ? '' : 'none'); };
    if (q) q.onkeydown = (e) => { if (e.key === 'Enter') { const f = $('.tile:not(.oculto)', el); if (f) f.click(); } };
    const cont = $('#wids');
    for (const w of lw) { const d = document.createElement('div'); d.className = 'widget'; cont.appendChild(d); try { await w.fn(d); } catch (x) { aviso(d, 'mal', `${w.titulo}: ${x.message}`); } }
  } });

  // ---------------------------------------------------------------- ingreso
  function pintarIngreso() {
    const a = pub || {}; const f = a.fondo_login || { tipo: 'tema', tema: 'cielo' };
    document.body.style.background = ''; document.documentElement.style.setProperty('--op', '1');
    $('#raiz').innerHTML = `<div class="ingreso" style="background:${(cssFondo(f) || TEMAS.cielo[1]).replace(/"/g, "'")};background-size:cover;background-position:center">
      <div class="vidrio"><img class="logo" src="${h(a.logo || 'logo.png')}" alt="">
        <h1>${h(a.institucion || a.producto || 'Ingresar')}</h1><p class="sub">${h(a.lema || '')}${a.lema ? ' · ' : ''}${h(a.producto || '')}${a.nodo ? ' · ' + h(a.nodo) : ''}</p>
        <form id="fLogin"><div class="campo"><label>Usuario</label>${ic('perfil')}<input name="u" autocomplete="username" required autofocus></div>
          <div class="campo"><label>Contraseña</label>${ic('candado')}<input name="c" type="password" autocomplete="current-password" required><button type="button" class="ojo" title="Mostrar u ocultar">${ic('ojo')}</button></div>
          <button class="b" style="width:100%">Ingresar</button><div id="lMsg"></div></form>
        <div class="pie2">${h(a.producto || '')} ${h(a.version || '')} · software libre · estándar PIM</div></div></div>`;
    $('.ojo').onclick = () => { const i = $('#fLogin').c; i.type = i.type === 'password' ? 'text' : 'password'; };
    $('#fLogin').onsubmit = async (e) => {
      e.preventDefault(); const b = e.target.querySelector('button.b'); b.disabled = true;
      try { await api('POST', 'sesion', { usuario: e.target.u.value.trim(), clave: e.target.c.value }); await iniciar(); } catch (x) { aviso($('#lMsg'), 'mal', x.message); } finally { b.disabled = false; }
    };
  }

  // ---------------------------------------------------------------- cáscara: barra, menú lateral y navegación
  const logoNodo = () => V?.logo || 'logo.png';
  function pintarBarra() { const n = $('#bUsr'); if (n) n.textContent = (S.usuario.nombre || S.usuario.usuario).split(' ').slice(0, 2).join(' '); }
  const vista = () => (V?.vista === 'menu' ? 'menu' : 'widgets');
  const menuUsuario = () => `<div class="menuu" id="mUsr"><button data-p="perfil">${ic('perfil')} Mi perfil, vista y fondo</button>${puede('sistema.admin') ? `<button data-p="configuracion">${ic('config')} Configuración</button>` : ''}<hr><button id="bSalir">${ic('salir')} Salir</button></div>`;
  /** «Vista menú»: encabezado de color con el logo y el menú de módulos arriba (como la versión anterior), con efectos. */
  function pintarMenu() {
    const vis = visibles(); const prod = S.producto;
    $('#raiz').innerHTML = `<div id="capa" class="v-menu">
      <header class="cabm"><div class="cabm-fila"><img class="cabm-logo" src="${h(logoNodo())}" alt=""><div class="cabm-tit"><h1>${h(V?.institucion || prod.nombre)}</h1><small>${h(V?.lema ? V.lema + ' · ' : '')}${h(prod.nombre)}</small></div>
        <span class="chipm">Nodo ${h(S.nodo.id)} · ${h(S.nodo.nombre)}</span><div class="der"><button class="pildora claro" id="bUsr" aria-haspopup="true"></button></div>${menuUsuario()}</div>
        <nav class="navm" id="navm"><button data-p="inicio" style="--gc:#ffffff">${ic('inicio')}<span>Tablero</span></button>${vis.map((p) => `<button data-p="${p.id}" style="--gc:${GRUPOS[meta(p).grupo][1]}" title="${h(meta(p).desc)}">${ic(meta(p).icono)}<span>${h(p.titulo)}</span></button>`).join('')}</nav></header>
      <main id="main"></main><footer id="pie"></footer></div>`;
    $$('#navm [data-p]').forEach((b) => b.onclick = () => ir(b.dataset.p));
  }
  function pintar() {
    if (!S) return pintarIngreso();
    if (vista() === 'menu') { pintarMenu(); comunes(); return; }
    const vis = visibles(); const porG = {}; vis.forEach((p) => { const g = meta(p).grupo; (porG[g] = porG[g] || []).push(p); });
    $('#raiz').innerHTML = `<div id="capa">
      <header class="barra"><button class="ib sin" id="bMenu" title="Menú">${ic('menu')}</button>
        <div class="tit"><b id="tTit">Inicio</b><small id="tMiga">General / Inicio</small></div>
        <div class="navb"><button class="ib" id="bAtras" title="Atrás">${ic('izq')}</button><button class="ib" id="bInicio" title="Inicio">${ic('inicio')}</button><button class="ib" id="bAdel" title="Adelante">${ic('der')}</button></div>
        <div class="der"><button class="pildora" id="bUsr" aria-haspopup="true"></button></div>
        ${menuUsuario()}</header>
      <aside class="lateral" id="lat"><div class="cab"><img src="${h(logoNodo())}" alt=""><div><b>${h(V?.institucion || S.producto.nombre)}</b><small>${h(S.producto.nombre)} · Nodo ${h(S.nodo.id)}</small></div></div>
        <nav><button data-p="inicio">${ic('inicio')} Inicio</button>${Object.keys(GRUPOS).filter((g) => porG[g]).map((g) => `<h6><i style="background:${GRUPOS[g][1]}"></i>${h(GRUPOS[g][0])}</h6>${porG[g].map((p) =>
          `<button data-p="${p.id}">${ic(meta(p).icono)} ${h(p.titulo)}</button>`).join('')}`).join('')}<h6>Cuenta</h6><button data-p="perfil">${ic('perfil')} Mi perfil</button></nav></aside>
      <main id="main"></main><footer id="pie"></footer></div>`;
    $('#bMenu').onclick = () => { $('#lat').classList.add('abierto'); $('#velo').classList.add('abierto'); };
    $$('#lat [data-p]').forEach((b) => b.onclick = () => { cerrar(); ir(b.dataset.p); });
    $('#bAtras').onclick = () => history.back(); $('#bAdel').onclick = () => history.forward(); $('#bInicio').onclick = () => ir('inicio');
    comunes();
  }
  function comunes() {
    pintarBarra(); pie();
    $$('#mUsr [data-p]').forEach((b) => b.onclick = () => { cerrar(); $('#mUsr').classList.remove('abierto'); ir(b.dataset.p); });
    $('#bUsr').onclick = (e) => { e.stopPropagation(); $('#mUsr').classList.toggle('abierto'); };
    $('#bSalir').onclick = async () => { await api('DELETE', 'sesion').catch(() => {}); S = null; V = null; await cargarPublica(); pintar(); };
    aplicarFondo(V?.fondo, V?.opacidad);
    ir((location.hash.slice(1) || actual || 'inicio'), true);
  }
  function pie() {
    const p = S.producto; const f = $('#pie'); if (!f) return;
    f.innerHTML = `${h(p.nombre)} ${h(p.version)} · software libre <a href="/api/fuente">${h(p.licencia)}</a>${p.fuente ? ` · <a href="${h(p.fuente)}" rel="noopener">código fuente</a>` : ''}${p.base ? ` · basado en ${h(p.base)}` : ''} · estándar PIM`;
  }
  let guardarRec;
  async function ir(id, sinHist) {
    const p = pestanas.find((x) => x.id === id && (!x.cap || puede(x.cap))) || pestanas.find((x) => x.id === 'inicio');
    actual = p.id; if (location.hash.slice(1) !== p.id) { sinHist ? history.replaceState(null, '', '#' + p.id) : history.pushState(null, '', '#' + p.id); }
    const m = meta(p); if ($('#tTit')) { $('#tTit').textContent = p.titulo; $('#tMiga').textContent = (p.id === 'inicio' ? 'General' : p.id === 'perfil' ? 'Cuenta' : GRUPOS[m.grupo][0]) + ' / ' + p.titulo; }
    $$('#navm button').forEach((b) => b.classList.toggle('activo', b.dataset.p === p.id));
    document.title = `${p.titulo} · ${V?.institucion || S.producto.nombre}`;
    $$('#lat nav button').forEach((b) => b.classList.toggle('activo', b.dataset.p === p.id));
    if (!['inicio', 'perfil', 'configuracion'].includes(p.id) && V) {
      V.recientes = [p.id, ...(V.recientes || []).filter((x) => x !== p.id)].slice(0, 6);
      clearTimeout(guardarRec); guardarRec = setTimeout(() => api('PUT', 'perfil', { recientes: V.recientes }).catch(() => {}), 800);
    }
    const el = $('#main'); el.innerHTML = '<p class="mini">Cargando…</p>'; window.scrollTo({ top: 0 });
    try { await p.render(el); } catch (x) { aviso(el, 'mal', x.message); }
  }
  Nucleo.ir = (id) => ir(id);
  addEventListener('popstate', () => { if (S) ir(location.hash.slice(1) || 'inicio', true); });
  addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { cerrar(); $('#mUsr')?.classList.remove('abierto'); }
    if (e.key === '/' && S && actual === 'inicio' && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); $('#qMod')?.focus(); }
  });
  addEventListener('click', () => $('#mUsr')?.classList.remove('abierto'));
  async function refrescarVista() { try { V = (await api('GET', 'perfil')).vista; aplicarFondo(V.fondo, V.opacidad); const l = $('#lat .cab img'); if (l) l.src = logoNodo(); } catch (x) { /* sigue */ } }
  async function cargarPublica() { try { pub = await api('GET', 'apariencia'); } catch (x) { pub = null; } }
  async function iniciar() {
    const j = await api('GET', 'sesion');
    if (!j.ok) { S = null; await cargarPublica(); pintar(); return; }
    S = j;
    for (const u of j.ui) {   // cada módulo con interfaz aporta su ui.js (se carga en orden, una sola vez)
      if (cargados.has(u.modulo)) continue; cargados.add(u.modulo);
      await new Promise((ok) => { const s = document.createElement('script'); s.src = `/api/modulos/${u.modulo}/ui.js?v=${encodeURIComponent(u.version)}`; s.onload = ok; s.onerror = ok; document.head.appendChild(s); });
    }
    try { V = (await api('GET', 'perfil')).vista; } catch (x) { V = null; }
    pintar();
  }
  $('#velo').onclick = cerrar;
  iniciar().catch(async () => { S = null; await cargarPublica(); pintar(); });
})();
